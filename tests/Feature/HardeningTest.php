<?php

use Illuminate\Support\Facades\Event;
use Packstub\Calls\Events\CallEnded;
use Packstub\Calls\Events\ParticipantLeft;
use Packstub\Calls\Exceptions\CalleeBusy;
use Packstub\Calls\Exceptions\CallNotAllowed;
use Packstub\Calls\Exceptions\CallNotRinging;
use Packstub\Calls\Facades\Calls;
use Packstub\Calls\Models\Call;
use Packstub\Calls\Models\CallParticipant;
use Packstub\Calls\Models\CallSignal;
use Packstub\Calls\Participant;
use Packstub\Calls\Tests\Fixtures\User;

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
    $call = Calls::start($this->ion, $this->ana);
    // The copy the answer request bound before the caller's cancel went through.
    $stale = Call::query()->with('participants')->find($call->id);

    Calls::hangup($call, id($this->ion));

    expect(fn () => Calls::answer($stale, id($this->ana)))->toThrow(CallNotRinging::class)
        ->and($call->fresh()->status)->toBe('ended')
        ->and($call->fresh()->end_reason)->toBe('cancelled');
});

it('fires the events once when two polls expire the same person', function (): void {
    $call = Calls::start($this->ion, [$this->ana, $this->mihai], 'ion-1');
    Calls::answer($call, id($this->ana), 'ana-1');
    Calls::answer($call, id($this->mihai), 'mihai-1');

    Event::fake([ParticipantLeft::class, CallEnded::class]);
    $this->travel(30)->seconds();
    CallParticipant::query()->where('user_id', id($this->ana))->update(['seen_at' => now()]);

    // Two copies of the call, loaded by two polls at once, both see Ion and Mihai as gone.
    $first = Call::query()->with('participants')->find($call->id);
    $second = Call::query()->with('participants')->find($call->id);
    Calls::expireCalls([$first]);
    Calls::expireCalls([$second]);

    Event::assertDispatchedTimes(ParticipantLeft::class, 2);
    Event::assertDispatchedTimes(CallEnded::class, 1);
    expect($call->fresh()->end_reason)->toBe('dropped');
});

it('keeps my call when the person I try to ring next is busy', function (): void {
    $call = Calls::start($this->ion, $this->ana);
    Calls::answer($call, id($this->ana));
    $elena = user('Elena');
    Calls::start($this->mihai, $elena);

    expect(fn () => Calls::start($this->ion, $elena))->toThrow(CalleeBusy::class)
        ->and($call->fresh()->status)->toBe('active');
});

it('lets two people who ring each other at once end up on one call', function (): void {
    Calls::start($this->ion, $this->ana);
    $second = Calls::start($this->ana, $this->ion);

    expect(Calls::openCallsOf(id($this->ion)))->toHaveCount(1)
        ->and(Calls::openCallsOf(id($this->ion))->first()->id)->toBe($second->id);
});

it('does not let someone joined on one call answer another', function (): void {
    $first = Calls::start($this->ion, $this->ana);
    Calls::answer($first, id($this->ana));

    // A second call reached Ana before her answer to the first landed.
    $second = Call::query()->create(['caller_id' => id($this->mihai), 'callee_id' => id($this->ana), 'status' => 'ringing', 'scope' => Calls::scope()]);
    $second->participants()->create(['user_id' => id($this->mihai), 'seat' => 0, 'status' => 'joined', 'joined_at' => now()]);
    $second->participants()->create(['user_id' => id($this->ana), 'seat' => 1, 'status' => 'ringing', 'invited_at' => now()]);

    expect(fn () => Calls::answer($second->load('participants'), id($this->ana)))->toThrow(CalleeBusy::class);
});

it('takes signals only from people in the call', function (): void {
    $call = Calls::start($this->ion, [$this->ana, $this->mihai]);
    Calls::answer($call, id($this->ana));

    // Mihai still rings, and Ana, once she left, is gone.
    expect(fn () => Calls::signal($call, id($this->mihai), 'offer', ['sdp' => 'x']))->toThrow(CallNotAllowed::class);
    Calls::answer($call, id($this->mihai));
    Calls::hangup($call, id($this->ana));
    expect(fn () => Calls::signal($call, id($this->ana), 'offer', ['sdp' => 'x']))->toThrow(CallNotAllowed::class);

    $this->actingAs($this->ana)->postJson(callsRoute('signal', ['call' => $call->id]), ['type' => 'ice', 'payload' => ['x' => 1]])->assertStatus(422);
});

it('re-checks an invite under the lock: a person already invited by someone else is refused', function (): void {
    $call = Calls::start($this->ion, $this->ana);
    Calls::answer($call, id($this->ana));
    $stale = Call::query()->with('participants')->find($call->id);

    Calls::invite($call, id($this->ion), $this->mihai);

    expect(fn () => Calls::invite($stale, id($this->ana), $this->mihai))->toThrow(CallNotAllowed::class)
        ->and(CallParticipant::query()->where('call_id', $call->id)->count())->toBe(3);
});

it('lets go of calls nobody came back to before calling someone busy', function (): void {
    $call = Calls::start($this->ion, $this->ana, 'ion-1');
    Calls::answer($call, id($this->ana), 'ana-1');

    // Both closed their windows; nobody polled since.
    $this->travel(60)->seconds();

    $next = Calls::start($this->mihai, $this->ana);
    expect($call->fresh()->status)->toBe('ended')
        ->and($next->isRinging())->toBeTrue();
});

it('drops a side that joined without naming a window once it is quiet past the timeout', function (): void {
    $call = Calls::start($this->ion, [$this->ana, $this->mihai], 'ion-1');
    Calls::answer($call, id($this->ana));
    Calls::answer($call, id($this->mihai), 'mihai-1');

    $this->travel(30)->seconds();
    CallParticipant::query()->where('user_id', '!=', id($this->ana))->update(['seen_at' => now()]);
    Calls::expire(id($this->ion));

    expect(participantOf($call, $this->ana)->status)->toBe('dropped')
        ->and($call->fresh()->status)->toBe('active');
});

it('answers 404 for a call id that is no number', function (): void {
    $this->actingAs($this->ion)->postJson('/calls/abc/answer')->assertNotFound();
});

it('keeps two guards apart: user 7 of one is never rung for user 7 of another', function (): void {
    $call = Calls::start($this->ion, $this->ana);

    config()->set('auth.guards.staff', ['driver' => 'session', 'provider' => 'users']);
    config()->set('packstub-calls.guard', 'staff');

    // The same ids, another guard: nothing ringing, not busy, the call's routes refuse.
    expect(Calls::openCallsOf(id($this->ana)))->toBeEmpty()
        ->and(Calls::poll(id($this->ana))['incoming'])->toBeNull()
        ->and(Calls::poll(id($this->ana), 0, $call->id)['call'])->toBeNull();

    $this->actingAs($this->ana, 'staff')->postJson(callsRoute('answer', ['call' => $call->id]))->assertForbidden();
    expect(Calls::start($this->mihai, $this->ana)->scope)->toBe('staff');
});

it('ends abandoned calls and prunes old ended ones', function (): void {
    $old = Calls::start($this->ion, $this->ana);
    Calls::hangup($old, id($this->ion));
    $old->forceFill(['ended_at' => now()->subDays(100)])->save();

    $abandoned = Calls::start($this->mihai, user('Elena'), 'mihai-1');
    Calls::answer($abandoned, id(User::query()->where('name', 'Elena')->first()), 'elena-1');
    Calls::signal($abandoned, id($this->mihai), 'offer', ['sdp' => 'x']);
    $this->travel(60)->seconds();

    $this->artisan('calls:prune')->assertSuccessful();

    expect(Call::query()->find($old->id))->toBeNull()
        ->and(CallParticipant::query()->where('call_id', $old->id)->count())->toBe(0)
        ->and($abandoned->fresh()->status)->toBe('ended')
        ->and(CallSignal::query()->count())->toBe(0);

    config()->set('packstub-calls.prune_after_days', null);
    $this->travel(200)->days();
    $this->artisan('calls:prune')->assertSuccessful();
    expect(Call::query()->count())->toBe(1);
});

it('tells the two 409s apart by reason', function (): void {
    $call = Calls::start($this->ion, $this->ana);

    $this->actingAs($this->mihai)->postJson(callsRoute('store'), ['callee' => id($this->ana)])
        ->assertStatus(409)->assertJsonPath('reason', 'busy')->assertJsonPath('message', 'This person is on another call.');

    Calls::hangup($call, id($this->ion));
    $this->actingAs($this->ana)->postJson(callsRoute('answer', ['call' => $call->id]))
        ->assertStatus(409)->assertJsonPath('reason', 'not_ringing');
});

it('does not mark someone missed who answered after the timeout was decided on an older copy', function (): void {
    $call = Calls::start($this->ion, $this->ana, 'ion-1');
    $this->travel(50)->seconds();
    $stale = Call::query()->with('participants')->find($call->id);

    Calls::answer($call, id($this->ana), 'ana-1');
    Calls::expireCalls([$stale]);

    expect(participantOf($call, $this->ana)->status)->toBe('joined')
        ->and($call->fresh()->status)->toBe('active');
});

it('does not drop someone whose heartbeat landed after the timeout was decided', function (): void {
    $call = Calls::start($this->ion, [$this->ana, $this->mihai], 'ion-1');
    Calls::answer($call, id($this->ana), 'ana-1');
    Calls::answer($call, id($this->mihai), 'mihai-1');
    $this->travel(30)->seconds();
    $stale = Call::query()->with('participants')->find($call->id);

    CallParticipant::query()->where('call_id', $call->id)->update(['seen_at' => now()]);
    Calls::expireCalls([$stale]);

    expect($call->fresh()->live()->count())->toBe(3);
});

it('counts someone in a call with others as busy, even on a call I ring on too', function (): void {
    $call = Calls::start($this->ion, $this->ana);
    Calls::answer($call, id($this->ana));
    Calls::invite($call, id($this->ion), $this->mihai);

    // Mihai, still ringing on Ion and Ana's call, rings Ana directly.
    expect(fn () => Calls::start($this->mihai, $this->ana))->toThrow(CalleeBusy::class)
        ->and($call->fresh()->status)->toBe('active');
});

it('refuses a callee id that cannot be a key with a reason, and a kind or a guest name that would not fit', function (): void {
    $this->actingAs($this->ion)->postJson(callsRoute('store'), ['callee' => 'abc'])->assertStatus(422)->assertJsonPath('reason', 'not_allowed');
    $this->postJson(callsRoute('store'), ['callee' => ['x']])->assertStatus(422)->assertJsonPath('reason', 'not_allowed');

    expect(Calls::findUser('abc'))->toBeNull()
        ->and(fn () => Calls::start($this->ion, $this->ana, kind: 'Not A Kind'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new Participant('guest:1', str_repeat('a', 256)))->toThrow(InvalidArgumentException::class);
});
