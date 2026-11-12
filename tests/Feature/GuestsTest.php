<?php

use Illuminate\Support\Facades\DB;
use Packstub\Holler\AppContext;
use Packstub\Holler\Exceptions\CallNotAllowed;
use Packstub\Holler\Exceptions\SignalTooLarge;
use Packstub\Holler\Facades\Holler;
use Packstub\Holler\Participant;

beforeEach(function (): void {
    $this->ana = user('Ana');
    $this->ion = user('Ion');
    $this->guest = new Participant('guest:7f9c', 'Visitor on /checkout', 'https://example.com/v.png');
});

it('rings a participant made in code, named as given, and runs the call through the service', function (): void {
    $call = Holler::start($this->ana, $this->guest, 'ana-1', kind: 'assist');

    expect($call->kind)->toBe('assist')
        ->and($call->callee_id)->toBe('guest:7f9c')
        ->and(Holler::openCallsOf('guest:7f9c'))->toHaveCount(1);

    Holler::answer($call, 'guest:7f9c', 'v-1');
    Holler::signal($call, (string) $this->ana->getKey(), 'offer', ['sdp' => 'v=0']);

    $guest = Holler::poll('guest:7f9c', 0, $call->id, 'v-1', true);
    expect($guest['call']['kind'])->toBe('assist')
        ->and($guest['call']['peer']['name'])->toBe('Ana')
        ->and($guest['signals'])->toHaveCount(1)
        ->and($guest['signals'][0]['from'])->toBe((string) $this->ana->getKey());

    $agent = Holler::poll((string) $this->ana->getKey(), 0, $call->id, 'ana-1', true);
    expect($agent['call']['peer'])->toBe(['id' => 'guest:7f9c', 'name' => 'Visitor on /checkout', 'avatar' => 'https://example.com/v.png']);

    Holler::hangup($call, 'guest:7f9c');
    expect($call->fresh()->end_reason)->toBe('hung_up');
});

it('never looks up an id with a colon: no route can ring a guest or bring one in', function (): void {
    Holler::start($this->ana, $this->guest, kind: 'assist');

    $this->actingAs($this->ion)
        ->postJson(callsRoute('store'), ['callee' => 'guest:7f9c'])
        ->assertStatus(422);

    $mihai = user('Mihai');
    $call = Holler::start($this->ion, $mihai);
    Holler::answer($call, (string) $mihai->getKey());

    $this->postJson(callsRoute('invite', ['call' => $call->id]), ['callee' => 'guest:7f9c'])->assertStatus(422);

    expect(fn () => Holler::invite($call, (string) $this->ion->getKey(), 'guest:7f9c'))->toThrow(CallNotAllowed::class)
        ->and(fn () => new Participant('7f9c', 'No prefix'))->toThrow(InvalidArgumentException::class);
});

it('lets a guest on a call bring nobody in', function (): void {
    $call = Holler::start($this->ana, $this->guest, kind: 'assist');
    Holler::answer($call, 'guest:7f9c');

    expect(fn () => Holler::invite($call, 'guest:7f9c', $this->ion))->toThrow(CallNotAllowed::class);
});

it('polls for one kind of call: the widget never hears of another package\'s', function (): void {
    Holler::start($this->ion, $this->ana, kind: 'assist');
    $ana = (string) $this->ana->getKey();

    expect(Holler::poll($ana, kind: 'call')['incoming'])->toBeNull()
        ->and(Holler::poll($ana, kind: 'assist')['incoming']['kind'])->toBe('assist')
        ->and(Holler::poll($ana)['incoming']['kind'])->toBe('assist');

    $this->actingAs($this->ana)->getJson(callsRoute('poll', ['kind' => 'call']))->assertJsonPath('incoming', null);
    $this->getJson(callsRoute('poll', ['kind' => 'assist']))->assertJsonPath('incoming.kind', 'assist');
    // A kind that is no kind is ignored rather than matched.
    $this->getJson(callsRoute('poll', ['kind' => 'A B']))->assertJsonPath('incoming.kind', 'assist');
});

it('does not hand a window a call of another kind by its id', function (): void {
    $call = Holler::start($this->ion, $this->ana, 'ion-1', kind: 'assist');

    expect(Holler::poll((string) $this->ion->getKey(), 0, $call->id, 'ion-1', true, 'call')['call'])->toBeNull()
        ->and(Holler::poll((string) $this->ion->getKey(), 0, $call->id, 'ion-1', true, 'assist')['call']['id'])->toBe($call->id);
});

it('refuses a signal above max_signal_kb', function (): void {
    config()->set('holler.max_signal_kb', 1);
    $call = Holler::start($this->ion, $this->ana);

    expect(fn () => Holler::signal($call, (string) $this->ion->getKey(), 'offer', ['sdp' => str_repeat('a', 2048)]))->toThrow(SignalTooLarge::class);

    $this->actingAs($this->ion)
        ->postJson(callsRoute('signal', ['call' => $call->id]), ['type' => 'offer', 'payload' => ['sdp' => str_repeat('a', 2048)]])
        ->assertStatus(413);

    config()->set('holler.max_signal_kb', 0);
    expect(Holler::signal($call, (string) $this->ion->getKey(), 'offer', ['sdp' => str_repeat('a', 2048)]))->not->toBeNull();
});

it('hands the browsers the transport policy', function (): void {
    expect(Holler::rtcConfiguration()['iceTransportPolicy'])->toBe('all');

    config()->set('holler.ice_transport_policy', 'relay');
    expect(Holler::rtcConfiguration())->toBe(['iceServers' => [['urls' => 'stun:stun.l.google.com:19302']], 'iceTransportPolicy' => 'relay']);

    config()->set('holler.ice_transport_policy', 'nonsense');
    expect(Holler::rtcConfiguration()['iceTransportPolicy'])->toBe('all');
});

it('keeps the tables on the configured connection', function (): void {
    config()->set('database.connections.central', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    config()->set('holler.connection', 'central');

    $migration = include __DIR__.'/../../database/migrations/create_holler_tables.php';
    expect($migration->getConnection())->toBe('central');
    $this->artisan('migrate', ['--database' => 'central', '--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true])->run();

    $call = Holler::start($this->ion, $this->ana);

    expect($call->getConnectionName())->toBe('central')
        ->and(DB::connection('central')->table('holler_calls')->count())->toBe(1)
        ->and(DB::connection('central')->table('holler_call_participants')->count())->toBe(2);
});

it('hands a signed-in user the ICE servers when a call starts, never cached', function (): void {
    app(AppContext::class)->iceServersUsing(fn () => [['urls' => 'turn:t.example.com', 'username' => 'u'.now()->timestamp, 'credential' => 'c']]);

    $this->getJson(callsRoute('ice'))->assertUnauthorized();
    $this->actingAs($this->ion)->getJson(callsRoute('ice'))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('iceServers.0.urls', 'turn:t.example.com')
        ->assertJsonPath('iceTransportPolicy', 'all');
});

it('starts a call in a scope of its choosing and shows a guest the names stored at the start', function (): void {
    config()->set('auth.guards.staff', ['driver' => 'session', 'provider' => 'users']);

    // A visitor's request (default guard) rings an agent who polls from the staff guard.
    $call = Holler::start($this->ana, $this->guest, kind: 'assist', scope: 'staff');
    expect($call->scope)->toBe('staff')
        ->and(participantOf($call, $this->ana)->name)->toBe('Ana');

    // Ana is renamed; the guest still sees the name stored with the call, never a lookup in its own guard.
    $this->ana->forceFill(['name' => 'Ana Popescu'])->save();
    Holler::answer($call, 'guest:7f9c', 'v-1');
    expect(Holler::poll('guest:7f9c', 0, $call->id, 'v-1', true)['call']['peer']['name'])->toBe('Ana');

    config()->set('holler.guard', 'staff');
    expect(Holler::poll((string) $this->ana->getKey(), 0, $call->id)['call']['peer']['name'])->toBe('Visitor on /checkout')
        ->and(Holler::openCallsOf((string) $this->ana->getKey()))->toHaveCount(1);
});

it('takes no user ids for a call in another scope: they would be looked up in the wrong guard', function (): void {
    expect(fn () => Holler::start($this->ana, (string) $this->ion->getKey(), scope: 'staff'))->toThrow(CallNotAllowed::class)
        ->and(Holler::start($this->ana, $this->guest, kind: 'assist', scope: 'staff')->scope)->toBe('staff');
});

it('lets nobody bring a third person into a call with a guest on it', function (): void {
    $call = Holler::start($this->ana, $this->guest, kind: 'assist');
    Holler::answer($call, 'guest:7f9c');

    expect(fn () => Holler::invite($call, (string) $this->ana->getKey(), $this->ion))->toThrow(CallNotAllowed::class);
    $this->actingAs($this->ana)->postJson(callsRoute('invite', ['call' => $call->id]), ['callee' => $this->ion->getKey()])->assertStatus(422);
});

it('takes several URLs per ICE server and leaves out a server without one', function (): void {
    config()->set('holler.ice_servers', [
        ['urls' => ''],
        ['urls' => 'turn:t.example.com:3478, turns:t.example.com:443?transport=tcp', 'username' => 'u', 'credential' => 'c', 'secret' => null, 'ttl' => 3600],
        ['urls' => ['stun:a.example.com', 'stun:b.example.com']],
    ]);

    expect(Holler::iceServers())->toBe([
        ['urls' => ['turn:t.example.com:3478', 'turns:t.example.com:443?transport=tcp'], 'username' => 'u', 'credential' => 'c'],
        ['urls' => ['stun:a.example.com', 'stun:b.example.com']],
    ]);
});

it('signs a TURN username that expires from the shared secret, and never sends the secret', function (): void {
    $this->freezeSecond();
    config()->set('holler.ice_servers', [
        ['urls' => 'turn:t.example.com:3478', 'username' => 'fixed', 'credential' => 'fixed', 'secret' => 'shared-secret', 'ttl' => 600],
    ]);

    $server = $this->actingAs($this->ion)->getJson(callsRoute('ice'))->assertOk()->json('iceServers.0');
    $username = (now()->getTimestamp() + 600).':'.$this->ion->getKey();

    expect($server)->toBe([
        'urls' => 'turn:t.example.com:3478',
        'username' => $username,
        'credential' => base64_encode(hash_hmac('sha1', $username, 'shared-secret', true)),
    ]);

    // A closure can use the same scheme for a server of its own.
    app(AppContext::class)->iceServersUsing(fn () => [['urls' => 'turn:other.example.com', 'secret' => 's']]);
    expect(Holler::iceServers()[0])->toHaveKeys(['urls', 'username', 'credential'])->not->toHaveKey('secret');
    app(AppContext::class)->iceServersUsing(null);

    expect(Holler::turnCredentials('s', 30, 'guest:abc')['username'])->toBe((now()->getTimestamp() + 60).':guest-abc');
});
