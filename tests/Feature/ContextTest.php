<?php

use Illuminate\Support\Facades\Route;
use Packstub\Calls\AppContext;
use Packstub\Calls\Contracts\CallContext;
use Packstub\Calls\Exceptions\CalleeBusy;
use Packstub\Calls\Exceptions\CallHasEnded;
use Packstub\Calls\Exceptions\CallNotAllowed;
use Packstub\Calls\Exceptions\CallNotRinging;
use Packstub\Calls\Facades\Calls;
use Packstub\Calls\Models\Call;
use Packstub\Calls\Tests\Fixtures\User;

beforeEach(function (): void {
    $this->ion = user('Ion');
    $this->ana = user('Ana');
});

it('binds the app context by default and answers from the config', function (): void {
    expect(Calls::context())->toBeInstanceOf(AppContext::class)
        ->and(app(CallContext::class))->toBe(app(AppContext::class))
        ->and(Calls::context()->iceServers())->toBe([['urls' => 'stun:stun.l.google.com:19302']])
        ->and(Calls::context()->hasVideo())->toBeTrue()
        ->and(Calls::context()->maxParticipants())->toBe(4)
        ->and(Calls::currentUserId())->toBeNull();

    config()->set('packstub-calls.video', false);
    config()->set('packstub-calls.ice_servers', []);

    expect(Calls::context()->hasVideo())->toBeFalse()
        ->and(Calls::context()->iceServers())->toBe([]);

    $this->actingAs($this->ion);

    expect(Calls::currentUserId())->toBe((string) $this->ion->getKey())
        ->and(Calls::findUser((string) $this->ana->getKey())?->name)->toBe('Ana')
        ->and(Calls::findUser('999'))->toBeNull();
});

it('lets closures override who is who, who may call whom and what the browsers connect through', function (): void {
    $this->ana->forceFill(['avatar_url' => 'https://example.com/ana.png'])->save();

    app(AppContext::class)
        ->userNameUsing(fn (User $user): string => strtoupper($user->name))
        ->userAvatarUsing(fn (User $user): ?string => $user->avatar_url)
        ->iceServersUsing(fn (): array => [['urls' => 'turn:turn.example.com', 'username' => 'u', 'credential' => 'c']])
        ->video(false);

    expect(Calls::who($this->ana))->toBe(['id' => (string) $this->ana->getKey(), 'name' => 'ANA', 'avatar' => 'https://example.com/ana.png'])
        ->and(Calls::who($this->ion)['avatar'])->toBeNull()
        ->and(Calls::context()->iceServers()[0]['urls'])->toBe('turn:turn.example.com')
        ->and(Calls::context()->hasVideo())->toBeFalse();

    app(AppContext::class)->userNameUsing(null)->userAvatarUsing(null)->iceServersUsing(null)->video(null);

    // Without a name the e-mail stands in, and without either, "Someone".
    expect(Calls::who($this->ion)['name'])->toBe('Ion');
    $this->ion->name = '';
    expect(Calls::who($this->ion)['name'])->toBe('ion@example.com');
    $this->ion->email = '';
    expect(Calls::who($this->ion)['name'])->toBe('Someone');
});

it('runs calls through the service without HTTP', function (): void {
    $call = Calls::start($this->ion, $this->ana, 'win-a');

    expect($call)->toBeInstanceOf(Call::class)
        ->and($call->isRinging())->toBeTrue()
        ->and($call->participant((string) $this->ion->getKey())->tab)->toBe('win-a')
        ->and(Calls::openCallsOf((string) $this->ana->getKey())->count())->toBe(1);

    Calls::answer($call, (string) $this->ana->getKey(), 'ana-1');
    expect($call->isActive())->toBeTrue();

    $signal = Calls::signal($call, (string) $this->ion->getKey(), 'offer', ['sdp' => 'v=0']);
    expect($signal->to_user_id)->toBe((string) $this->ana->getKey())
        ->and($signal->from_user_id)->toBe((string) $this->ion->getKey());

    $poll = Calls::poll((string) $this->ana->getKey(), 0, $call->id, 'ana-1', true);
    expect($poll['signals'])->toHaveCount(1)
        ->and($poll['call']['peer']['name'])->toBe('Ion')
        ->and($poll['cursor'])->toBe($signal->id);

    Calls::hangup($call, (string) $this->ion->getKey());
    expect($call->fresh()->end_reason)->toBe('hung_up');
});

it('throws typed exceptions with the HTTP status the endpoints answer with', function (): void {
    expect(fn () => Calls::start($this->ion, $this->ion))->toThrow(CallNotAllowed::class)
        ->and(fn () => Calls::start($this->ion, null))->toThrow(CallNotAllowed::class)
        ->and(fn () => Calls::start($this->ion, '999'))->toThrow(CallNotAllowed::class);

    $call = Calls::start($this->ion, (string) $this->ana->getKey());
    expect(fn () => Calls::start(user('Mihai'), $this->ana))->toThrow(CalleeBusy::class)
        ->and(fn () => Calls::answer($call, (string) $this->ion->getKey()))->toThrow(CallNotRinging::class);

    Calls::end($call, 'cancelled');
    Calls::end($call, 'hung_up');
    expect($call->end_reason)->toBe('cancelled')
        ->and(fn () => Calls::signal($call, (string) $this->ion->getKey(), 'ice', []))->toThrow(CallHasEnded::class)
        ->and((new CallNotAllowed)->status)->toBe(422)
        ->and((new CalleeBusy)->status)->toBe(409)
        ->and((new CallNotRinging)->status)->toBe(409)
        ->and((new CallHasEnded)->status)->toBe(410);
});

it('registers the routes under any prefix, name and middleware', function (): void {
    Route::middleware('web')->group(fn () => Calls::routes(prefix: 'team/calls', middleware: [], as: 'team.calls.'));

    expect(route('team.calls.poll'))->toEndWith('/team/calls/poll')
        ->and(route('team.calls.store'))->toEndWith('/team/calls')
        ->and(route('team.calls.signal', ['call' => 7]))->toEndWith('/team/calls/7/signals');

    // No auth middleware: a guest is refused by the controller itself.
    $this->getJson('/team/calls/poll')->assertUnauthorized();
});

it('uses the configured guard to find people', function (): void {
    config()->set('auth.guards.staff', ['driver' => 'session', 'provider' => 'users']);
    config()->set('packstub-calls.guard', 'staff');

    $this->actingAs($this->ion, 'staff');

    expect(Calls::currentUserId())->toBe((string) $this->ion->getKey())
        ->and(Calls::findUser((string) $this->ana->getKey())?->name)->toBe('Ana');
});
