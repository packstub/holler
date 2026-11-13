<?php

use Packstub\Holler\Models\Call;
use Packstub\Holler\Models\CallSignal;
use Packstub\Holler\Tests\Fixtures\User;

beforeEach(function (): void {
    // Seen-at times are stored to the second: a frozen clock keeps the arithmetic exact.
    $this->freezeSecond();
    $this->ion = user('Ion');
    $this->ana = user('Ana');
});

function ringFrom(User $caller, User $callee, string $tab): Call
{
    test()->actingAs($caller)->postJson(callsRoute('store'), ['callee' => $callee->getKey(), 'tab' => $tab])->assertCreated();

    return Call::query()->latest('id')->firstOrFail();
}

function pollAs(User $user, array $query = [])
{
    return test()->actingAs($user)->getJson(callsRoute('poll', $query));
}

it('hands a side to the window that started the call and tells other windows it is claimed', function (): void {
    $call = ringFrom($this->ion, $this->ana, 'win-a');
    $this->actingAs($this->ana)->postJson(callsRoute('answer', ['call' => $call]), ['tab' => 'ana-1'])->assertOk();

    // The pop-out keeps polling: it holds the side, signals flow to it.
    $this->actingAs($this->ana)->postJson(callsRoute('signal', ['call' => $call]), ['type' => 'ice', 'payload' => ['x' => 1]]);
    pollAs($this->ion, ['call' => $call->id, 'tab' => 'win-a', 'claim' => 1])->assertJsonPath('call.claimed_elsewhere', false)->assertJsonPath('call.peer_claimed', true)->assertJsonCount(1, 'signals');

    // The main tab, polling without a call, learns about it as claimed elsewhere and gets no signals.
    pollAs($this->ion, ['tab' => 'main'])->assertJsonPath('call.id', $call->id)->assertJsonPath('call.claimed_elsewhere', true)->assertJsonCount(0, 'signals');

    // Naming the call does not let it take the side away from a live window.
    pollAs($this->ion, ['call' => $call->id, 'tab' => 'main', 'claim' => 1])->assertJsonPath('call.claimed_elsewhere', true);
    expect(participantOf($call, $this->ion)->tab)->toBe('win-a');
});

it('does not hand a side to a tab that only watches the call', function (): void {
    $call = ringFrom($this->ion, $this->ana, 'win-a');

    // Ana's main tab opened a pop-out to answer and keeps polling for the chip: the side stays free for the pop-out.
    pollAs($this->ana, ['call' => $call->id, 'tab' => 'ana-main'])->assertJsonPath('call.status', 'ringing')->assertJsonPath('call.claimed_elsewhere', false);
    expect(participantOf($call, $this->ana)->tab)->toBeNull();

    pollAs($this->ana, ['call' => $call->id, 'tab' => 'ana-pop', 'claim' => 1])->assertJsonPath('call.claimed_elsewhere', false);
    expect(participantOf($call, $this->ana)->tab)->toBe('ana-pop');

    pollAs($this->ana, ['call' => $call->id, 'tab' => 'ana-main'])->assertJsonPath('call.claimed_elsewhere', true);
});

it('lets a new page claim the side after the old one left, skipping the signals meant for the old connection', function (): void {
    $call = ringFrom($this->ion, $this->ana, 'win-a');
    $this->actingAs($this->ana)->postJson(callsRoute('answer', ['call' => $call]), ['tab' => 'ana-1']);
    $this->actingAs($this->ana)->postJson(callsRoute('signal', ['call' => $call]), ['type' => 'ice', 'payload' => ['old' => true]]);

    // The beacon as the page unloads: a form post, no JSON.
    $this->actingAs($this->ion)->post(callsRoute('leave', ['call' => $call]), ['tab' => 'win-a'])->assertNoContent();
    expect(participantOf($call, $this->ion)->tab)->toBeNull();

    // The other side sees the window gone but the call still active.
    pollAs($this->ana, ['call' => $call->id, 'tab' => 'ana-1', 'claim' => 1])->assertJsonPath('call.status', 'active')->assertJsonPath('call.peer_claimed', false);

    // The reloaded page polls without naming the call, is handed it unheld, then names it and takes the side.
    pollAs($this->ion, ['tab' => 'win-b'])->assertJsonPath('call.id', $call->id)->assertJsonPath('call.claimed_elsewhere', false);
    $response = pollAs($this->ion, ['call' => $call->id, 'tab' => 'win-b', 'claim' => 1])->assertJsonPath('call.claimed_elsewhere', false)->assertJsonCount(0, 'signals');
    expect(participantOf($call, $this->ion)->tab)->toBe('win-b')
        ->and($response->json('cursor'))->toBe(CallSignal::query()->max('id'));

    // New signals reach the new page from here on.
    $this->actingAs($this->ana)->postJson(callsRoute('signal', ['call' => $call]), ['type' => 'reset', 'payload' => ['session' => 's2']]);
    pollAs($this->ion, ['call' => $call->id, 'tab' => 'win-b', 'claim' => 1, 'after' => $response->json('cursor')])->assertJsonPath('signals.0.type', 'reset');
});

it('treats a window that stopped polling as gone and lets another take the side', function (): void {
    $call = ringFrom($this->ion, $this->ana, 'win-a');
    $this->actingAs($this->ana)->postJson(callsRoute('answer', ['call' => $call]), ['tab' => 'ana-1']);

    pollAs($this->ion, ['call' => $call->id, 'tab' => 'win-b', 'claim' => 1])->assertJsonPath('call.claimed_elsewhere', true);

    $this->travel(Call::CLAIM_SECONDS + 1)->seconds();

    pollAs($this->ion, ['call' => $call->id, 'tab' => 'win-b', 'claim' => 1])->assertJsonPath('call.claimed_elsewhere', false)->assertJsonPath('call.peer_seen_ago', Call::CLAIM_SECONDS + 1);
    expect(participantOf($call, $this->ion)->tab)->toBe('win-b');
});

it('ends a call as lost when one side stays away past the reconnect timeout', function (): void {
    $call = ringFrom($this->ion, $this->ana, 'win-a');
    $this->actingAs($this->ana)->postJson(callsRoute('answer', ['call' => $call]), ['tab' => 'ana-1']);
    $this->actingAs($this->ion)->post(callsRoute('leave', ['call' => $call]), ['tab' => 'win-a']);

    $this->travel(config('holler.reconnect_timeout') - 1)->seconds();
    pollAs($this->ana, ['call' => $call->id, 'tab' => 'ana-1', 'claim' => 1])->assertJsonPath('call.status', 'active');

    $this->travel(2)->seconds();
    pollAs($this->ana, ['call' => $call->id, 'tab' => 'ana-1', 'claim' => 1])->assertJsonPath('call.status', 'ended')->assertJsonPath('call.end_reason', 'dropped');
});

it('ignores a leave from a window that does not have the side', function (): void {
    $call = ringFrom($this->ion, $this->ana, 'win-a');

    $this->actingAs($this->ion)->post(callsRoute('leave', ['call' => $call]), ['tab' => 'someone-else'])->assertNoContent();

    expect(participantOf($call, $this->ion)->tab)->toBe('win-a');
});
