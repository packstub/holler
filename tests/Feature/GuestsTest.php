<?php

use Illuminate\Support\Facades\DB;
use Packstub\Calls\Exceptions\CallNotAllowed;
use Packstub\Calls\Exceptions\SignalTooLarge;
use Packstub\Calls\Facades\Calls;
use Packstub\Calls\Participant;

beforeEach(function (): void {
    $this->ana = user('Ana');
    $this->ion = user('Ion');
    $this->guest = new Participant('guest:7f9c', 'Visitor on /checkout', 'https://example.com/v.png');
});

it('rings a participant made in code, named as given, and runs the call through the service', function (): void {
    $call = Calls::start($this->ana, $this->guest, 'ana-1', kind: 'assist');

    expect($call->kind)->toBe('assist')
        ->and($call->callee_id)->toBe('guest:7f9c')
        ->and(Calls::openCallsOf('guest:7f9c'))->toHaveCount(1);

    Calls::answer($call, 'guest:7f9c', 'v-1');
    Calls::signal($call, (string) $this->ana->getKey(), 'offer', ['sdp' => 'v=0']);

    $guest = Calls::poll('guest:7f9c', 0, $call->id, 'v-1', true);
    expect($guest['call']['kind'])->toBe('assist')
        ->and($guest['call']['peer']['name'])->toBe('Ana')
        ->and($guest['signals'])->toHaveCount(1)
        ->and($guest['signals'][0]['from'])->toBe((string) $this->ana->getKey());

    $agent = Calls::poll((string) $this->ana->getKey(), 0, $call->id, 'ana-1', true);
    expect($agent['call']['peer'])->toBe(['id' => 'guest:7f9c', 'name' => 'Visitor on /checkout', 'avatar' => 'https://example.com/v.png']);

    Calls::hangup($call, 'guest:7f9c');
    expect($call->fresh()->end_reason)->toBe('hung_up');
});

it('never looks up an id with a colon: no route can ring a guest or bring one in', function (): void {
    Calls::start($this->ana, $this->guest, kind: 'assist');

    $this->actingAs($this->ion)
        ->postJson(callsRoute('store'), ['callee' => 'guest:7f9c'])
        ->assertStatus(422);

    $mihai = user('Mihai');
    $call = Calls::start($this->ion, $mihai);
    Calls::answer($call, (string) $mihai->getKey());

    $this->postJson(callsRoute('invite', ['call' => $call->id]), ['callee' => 'guest:7f9c'])->assertStatus(422);

    expect(fn () => Calls::invite($call, (string) $this->ion->getKey(), 'guest:7f9c'))->toThrow(CallNotAllowed::class)
        ->and(fn () => new Participant('7f9c', 'No prefix'))->toThrow(InvalidArgumentException::class);
});

it('lets a guest on a call bring nobody in', function (): void {
    $call = Calls::start($this->ana, $this->guest, kind: 'assist');
    Calls::answer($call, 'guest:7f9c');

    expect(fn () => Calls::invite($call, 'guest:7f9c', $this->ion))->toThrow(CallNotAllowed::class);
});

it('polls for one kind of call: the widget never hears of another package\'s', function (): void {
    Calls::start($this->ion, $this->ana, kind: 'assist');
    $ana = (string) $this->ana->getKey();

    expect(Calls::poll($ana, kind: 'call')['incoming'])->toBeNull()
        ->and(Calls::poll($ana, kind: 'assist')['incoming']['kind'])->toBe('assist')
        ->and(Calls::poll($ana)['incoming']['kind'])->toBe('assist');

    $this->actingAs($this->ana)->getJson(callsRoute('poll', ['kind' => 'call']))->assertJsonPath('incoming', null);
    $this->getJson(callsRoute('poll', ['kind' => 'assist']))->assertJsonPath('incoming.kind', 'assist');
    // A kind that is no kind is ignored rather than matched.
    $this->getJson(callsRoute('poll', ['kind' => 'A B']))->assertJsonPath('incoming.kind', 'assist');
});

it('does not hand a window a call of another kind by its id', function (): void {
    $call = Calls::start($this->ion, $this->ana, 'ion-1', kind: 'assist');

    expect(Calls::poll((string) $this->ion->getKey(), 0, $call->id, 'ion-1', true, 'call')['call'])->toBeNull()
        ->and(Calls::poll((string) $this->ion->getKey(), 0, $call->id, 'ion-1', true, 'assist')['call']['id'])->toBe($call->id);
});

it('refuses a signal above max_signal_kb', function (): void {
    config()->set('packstub-calls.max_signal_kb', 1);
    $call = Calls::start($this->ion, $this->ana);

    expect(fn () => Calls::signal($call, (string) $this->ion->getKey(), 'offer', ['sdp' => str_repeat('a', 2048)]))->toThrow(SignalTooLarge::class);

    $this->actingAs($this->ion)
        ->postJson(callsRoute('signal', ['call' => $call->id]), ['type' => 'offer', 'payload' => ['sdp' => str_repeat('a', 2048)]])
        ->assertStatus(413);

    config()->set('packstub-calls.max_signal_kb', 0);
    expect(Calls::signal($call, (string) $this->ion->getKey(), 'offer', ['sdp' => str_repeat('a', 2048)]))->not->toBeNull();
});

it('hands the browsers the transport policy', function (): void {
    expect(Calls::rtcConfiguration()['iceTransportPolicy'])->toBe('all');

    config()->set('packstub-calls.ice_transport_policy', 'relay');
    expect(Calls::rtcConfiguration())->toBe(['iceServers' => [['urls' => 'stun:stun.l.google.com:19302']], 'iceTransportPolicy' => 'relay']);

    config()->set('packstub-calls.ice_transport_policy', 'nonsense');
    expect(Calls::rtcConfiguration()['iceTransportPolicy'])->toBe('all');
});

it('keeps the tables on the configured connection', function (): void {
    config()->set('database.connections.central', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    config()->set('packstub-calls.connection', 'central');

    $migration = include __DIR__.'/../../database/migrations/create_calls_table.php';
    expect($migration->getConnection())->toBe('central');
    $this->artisan('migrate', ['--database' => 'central', '--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true])->run();

    $call = Calls::start($this->ion, $this->ana);

    expect($call->getConnectionName())->toBe('central')
        ->and(DB::connection('central')->table('calls')->count())->toBe(1)
        ->and(DB::connection('central')->table('call_participants')->count())->toBe(2);
});
