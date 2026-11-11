<?php

use Illuminate\Support\Facades\Event;
use Packstub\Holler\Events\CallEnded;
use Packstub\Holler\Events\ParticipantLeft;
use Packstub\Holler\Exceptions\CalleeBusy;
use Packstub\Holler\Exceptions\CallNotAllowed;
use Packstub\Holler\Exceptions\CallNotRinging;
use Packstub\Holler\Facades\Holler;
use Packstub\Holler\Models\Call;
use Packstub\Holler\Models\CallParticipant;
use Packstub\Holler\Models\CallSignal;
use Packstub\Holler\Participant;
use Packstub\Holler\Tests\Fixtures\User;

beforeEach(function (): void {
    $this->freezeSecond();
    $this->ion = user('Ion');
    $this->ana = user('Ana');
    $this->mihai = user('Mihai');
});

function id($user): string
{
    return (string) $user->getKey();
}

it('does not bring an ended call back when an answer comes in after the caller gave up', function (): void {
    $call = Holler::start($this->ion, $this->ana);
    // The copy the answer request bound before the caller's cancel went through.
    $stale = Call::query()->with('participants')->find($call->id);

    Holler::hangup($call, id($this->ion));

    expect(fn () => Holler::answer($stale, id($this->ana)))->toThrow(CallNotRinging::class)
        ->and($call->fresh()->status)->toBe('ended')
        ->and($call->fresh()->end_reason)->toBe('cancelled');
});

it('fires the events once when two polls expire the same person', function (): void {
    $call = Holler::start($this->ion, [$this->ana, $this->mihai], 'ion-1');
    Holler::answer($call, id($this->ana), 'ana-1');
    Holler::answer($call, id($this->mihai), 'mihai-1');

    Event::fake([ParticipantLeft::class, CallEnded::class]);
    $this->travel(30)->seconds();
    CallParticipant::query()->where('user_id', id($this->ana))->update(['seen_at' => now()]);

    // Two copies of the call, loaded by two polls at once, both see Ion and Mihai as gone.
    $first = Call::query()->with('participants')->find($call->id);
    $second = Call::query()->with('participants')->find($call->id);
    Holler::expireCalls([$first]);
    Holler::expireCalls([$second]);

    Event::assertDispatchedTimes(ParticipantLeft::class, 2);
    Event::assertDispatchedTimes(CallEnded::class, 1);
    expect($call->fresh()->end_reason)->toBe('dropped');
});

it('keeps my call when the person I try to ring next is busy', function (): void {
    $call = Holler::start($this->ion, $this->ana);
    Holler::answer($call, id($this->ana));
    $elena = user('Elena');
    Holler::start($this->mihai, $elena);

    expect(fn () => Holler::start($this->ion, $elena))->toThrow(CalleeBusy::class)
        ->and($call->fresh()->status)->toBe('active');
});

it('lets two people who ring each other at once end up on one call', function (): void {
    Holler::start($this->ion, $this->ana);
    $second = Holler::start($this->ana, $this->ion);

    expect(Holler::openCallsOf(id($this->ion)))->toHaveCount(1)
        ->and(Holler::openCallsOf(id($this->ion))->first()->id)->toBe($second->id);
});

it('does not let someone joined on one call answer another', function (): void {
    $first = Holler::start($this->ion, $this->ana);
    Holler::answer($first, id($this->ana));

    // A second call reached Ana before her answer to the first landed.
    $second = Call::query()->create(['caller_id' => id($this->mihai), 'callee_id' => id($this->ana), 'status' => 'ringing', 'scope' => Holler::scope()]);
    $second->participants()->create(['user_id' => id($this->mihai), 'seat' => 0, 'status' => 'joined', 'joined_at' => now()]);
    $second->participants()->create(['user_id' => id($this->ana), 'seat' => 1, 'status' => 'ringing', 'invited_at' => now()]);

    expect(fn () => Holler::answer($second->load('participants'), id($this->ana)))->toThrow(CalleeBusy::class);
});

it('takes signals only from people in the call', function (): void {
    $call = Holler::start($this->ion, [$this->ana, $this->mihai]);
    Holler::answer($call, id($this->ana));

    // Mihai still rings, and Ana, once she left, is gone.
    expect(fn () => Holler::signal($call, id($this->mihai), 'offer', ['sdp' => 'x']))->toThrow(CallNotAllowed::class);
    Holler::answer($call, id($this->mihai));
    Holler::hangup($call, id($this->ana));
    expect(fn () => Holler::signal($call, id($this->ana), 'offer', ['sdp' => 'x']))->toThrow(CallNotAllowed::class);

    $this->actingAs($this->ana)->postJson(callsRoute('signal', ['call' => $call->id]), ['type' => 'ice', 'payload' => ['x' => 1]])->assertStatus(422);
});

it('re-checks an invite under the lock: a person already invited by someone else is refused', function (): void {
    $call = Holler::start($this->ion, $this->ana);
    Holler::answer($call, id($this->ana));
    $stale = Call::query()->with('participants')->find($call->id);

    Holler::invite($call, id($this->ion), $this->mihai);

    expect(fn () => Holler::invite($stale, id($this->ana), $this->mihai))->toThrow(CallNotAllowed::class)
        ->and(CallParticipant::query()->where('call_id', $call->id)->count())->toBe(3);
});

it('lets go of calls nobody came back to before calling someone busy', function (): void {
    $call = Holler::start($this->ion, $this->ana, 'ion-1');
    Holler::answer($call, id($this->ana), 'ana-1');

    // Both closed their windows; nobody polled since.
    $this->travel(60)->seconds();

    $next = Holler::start($this->mihai, $this->ana);
    expect($call->fresh()->status)->toBe('ended')
        ->and($next->isRinging())->toBeTrue();
});

it('drops a side that joined without naming a window once it is quiet past the timeout', function (): void {
    $call = Holler::start($this->ion, [$this->ana, $this->mihai], 'ion-1');
    Holler::answer($call, id($this->ana));
    Holler::answer($call, id($this->mihai), 'mihai-1');

    $this->travel(30)->seconds();
    CallParticipant::query()->where('user_id', '!=', id($this->ana))->update(['seen_at' => now()]);
    Holler::expire(id($this->ion));

    expect(participantOf($call, $this->ana)->status)->toBe('dropped')
        ->and($call->fresh()->status)->toBe('active');
});

it('answers 404 for a call id that is no number', function (): void {
    $this->actingAs($this->ion)->postJson('/calls/abc/answer')->assertNotFound();
});

it('keeps two guards apart: user 7 of one is never rung for user 7 of another', function (): void {
    $call = Holler::start($this->ion, $this->ana);

    config()->set('auth.guards.staff', ['driver' => 'session', 'provider' => 'users']);
    config()->set('holler.guard', 'staff');

    // The same ids, another guard: nothing ringing, not busy, the call's routes refuse.
    expect(Holler::openCallsOf(id($this->ana)))->toBeEmpty()
        ->and(Holler::poll(id($this->ana))['incoming'])->toBeNull()
        ->and(Holler::poll(id($this->ana), 0, $call->id)['call'])->toBeNull();

    $this->actingAs($this->ana, 'staff')->postJson(callsRoute('answer', ['call' => $call->id]))->assertForbidden();
    expect(Holler::start($this->mihai, $this->ana)->scope)->toBe('staff');
});

it('ends abandoned calls and prunes old ended ones', function (): void {
    $old = Holler::start($this->ion, $this->ana);
    Holler::hangup($old, id($this->ion));
    $old->forceFill(['ended_at' => now()->subDays(100)])->save();

    $abandoned = Holler::start($this->mihai, user('Elena'), 'mihai-1');
    Holler::answer($abandoned, id(User::query()->where('name', 'Elena')->first()), 'elena-1');
    Holler::signal($abandoned, id($this->mihai), 'offer', ['sdp' => 'x']);
    $this->travel(60)->seconds();

    $this->artisan('holler:prune')->assertSuccessful();

    expect(Call::query()->find($old->id))->toBeNull()
        ->and(CallParticipant::query()->where('call_id', $old->id)->count())->toBe(0)
        ->and($abandoned->fresh()->status)->toBe('ended')
        ->and(CallSignal::query()->count())->toBe(0);

    config()->set('holler.prune_after_days', null);
    $this->travel(200)->days();
    $this->artisan('holler:prune')->assertSuccessful();
    expect(Call::query()->count())->toBe(1);
});

it('tells the two 409s apart by reason', function (): void {
    $call = Holler::start($this->ion, $this->ana);

    $this->actingAs($this->mihai)->postJson(callsRoute('store'), ['callee' => id($this->ana)])
        ->assertStatus(409)->assertJsonPath('reason', 'busy')->assertJsonPath('message', 'This person is on another call.');

    Holler::hangup($call, id($this->ion));
    $this->actingAs($this->ana)->postJson(callsRoute('answer', ['call' => $call->id]))
        ->assertStatus(409)->assertJsonPath('reason', 'not_ringing');
});

it('does not mark someone missed who answered after the timeout was decided on an older copy', function (): void {
    $call = Holler::start($this->ion, $this->ana, 'ion-1');
    $this->travel(50)->seconds();
    $stale = Call::query()->with('participants')->find($call->id);

    Holler::answer($call, id($this->ana), 'ana-1');
    Holler::expireCalls([$stale]);

    expect(participantOf($call, $this->ana)->status)->toBe('joined')
        ->and($call->fresh()->status)->toBe('active');
});

it('does not drop someone whose heartbeat landed after the timeout was decided', function (): void {
    $call = Holler::start($this->ion, [$this->ana, $this->mihai], 'ion-1');
    Holler::answer($call, id($this->ana), 'ana-1');
    Holler::answer($call, id($this->mihai), 'mihai-1');
    $this->travel(30)->seconds();
    $stale = Call::query()->with('participants')->find($call->id);

    CallParticipant::query()->where('call_id', $call->id)->update(['seen_at' => now()]);
    Holler::expireCalls([$stale]);

    expect($call->fresh()->live()->count())->toBe(3);
});

it('counts someone in a call with others as busy, even on a call I ring on too', function (): void {
    $call = Holler::start($this->ion, $this->ana);
    Holler::answer($call, id($this->ana));
    Holler::invite($call, id($this->ion), $this->mihai);

    // Mihai, still ringing on Ion and Ana's call, rings Ana directly.
    expect(fn () => Holler::start($this->mihai, $this->ana))->toThrow(CalleeBusy::class)
        ->and($call->fresh()->status)->toBe('active');
});

it('refuses a callee id that cannot be a key with a reason, and a kind or a guest name that would not fit', function (): void {
    $this->actingAs($this->ion)->postJson(callsRoute('store'), ['callee' => 'abc'])->assertStatus(422)->assertJsonPath('reason', 'not_allowed');
    $this->postJson(callsRoute('store'), ['callee' => ['x']])->assertStatus(422)->assertJsonPath('reason', 'not_allowed');

    expect(Holler::findUser('abc'))->toBeNull()
        ->and(fn () => Holler::start($this->ion, $this->ana, kind: 'Not A Kind'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new Participant('guest:1', str_repeat('a', 256)))->toThrow(InvalidArgumentException::class);
});

it('deletes the signals a window read, and refuses more once too many wait unread', function (): void {
    config()->set('holler.max_pending_signals', 3);
    $call = Holler::start($this->ion, $this->ana, 'ion-1');
    Holler::answer($call, id($this->ana), 'ana-1');

    foreach (range(1, 3) as $n) {
        Holler::signal($call, id($this->ion), 'ice', ['n' => $n]);
    }

    $this->actingAs($this->ion)->postJson(callsRoute('signal', ['call' => $call->id]), ['type' => 'ice', 'payload' => ['n' => 4]])
        ->assertStatus(429)->assertJsonPath('reason', 'too_many');

    // Ana's window reads them; its next poll, with the cursor, lets go of them.
    $read = Holler::poll(id($this->ana), 0, $call->id, 'ana-1', true);
    expect($read['signals'])->toHaveCount(3)->and(CallSignal::query()->count())->toBe(3);

    // A poll that does not hold the side deletes nothing.
    Holler::poll(id($this->ana), $read['cursor'], $call->id, 'ana-2', true);
    expect(CallSignal::query()->count())->toBe(3);

    Holler::poll(id($this->ana), $read['cursor'], $call->id, 'ana-1', true);
    expect(CallSignal::query()->count())->toBe(0)
        ->and(Holler::signal($call, id($this->ion), 'ice', ['n' => 4]))->not->toBeNull();

    // Ion's own signals to Ana are not Ana's to delete for him, and nothing else is touched.
    Holler::signal($call, id($this->ana), 'ice', ['n' => 'a']);
    Holler::poll(id($this->ana), PHP_INT_MAX, $call->id, 'ana-1', true);
    expect(CallSignal::query()->pluck('from_user_id')->all())->toBe([id($this->ana)]);

    config()->set('holler.max_pending_signals', 0);
    foreach (range(1, 5) as $n) {
        Holler::signal($call, id($this->ion), 'ice', ['n' => $n]);
    }
    expect(CallSignal::query()->count())->toBe(6);
});

it('takes an end reason that fits the column, and ends a call once', function (): void {
    Event::fake([CallEnded::class]);
    $call = Holler::start($this->ion, $this->ana);

    expect(fn () => Holler::end($call, str_repeat('a', 17)))->toThrow(InvalidArgumentException::class)
        ->and($call->fresh()->status)->toBe('ringing');

    Holler::end($call, 'failed');
    Holler::end($call, 'failed');

    expect($call->fresh()->end_reason)->toBe('failed');
    Event::assertDispatchedTimes(CallEnded::class, 1);
});

it('tries a state change again after a deadlock', function (): void {
    $call = Holler::start($this->ion, $this->ana);
    $attempts = 0;

    Event::listen('eloquent.saving: '.CallParticipant::class, function () use (&$attempts): void {
        if (++$attempts === 1) {
            throw new PDOException('Deadlock found when trying to get lock; try restarting transaction');
        }
    });

    Holler::answer($call, id($this->ana), 'ana-1');

    expect($attempts)->toBe(2)
        ->and(participantOf($call, $this->ana)->status)->toBe('joined')
        ->and($call->fresh()->status)->toBe('active');
});
