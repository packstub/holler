<?php

use Illuminate\Support\Facades\Route;
use Packstub\Holler\AppContext;
use Packstub\Holler\Contracts\CallContext;
use Packstub\Holler\Exceptions\CalleeBusy;
use Packstub\Holler\Exceptions\CallHasEnded;
use Packstub\Holler\Exceptions\CallNotAllowed;
use Packstub\Holler\Exceptions\CallNotRinging;
use Packstub\Holler\Facades\Holler;
use Packstub\Holler\Models\Call;
use Packstub\Holler\Tests\Fixtures\User;

beforeEach(function (): void {
    $this->ion = user('Ion');
    $this->ana = user('Ana');
});

it('binds the app context by default and answers from the config', function (): void {
    expect(Holler::context())->toBeInstanceOf(AppContext::class)
        ->and(app(CallContext::class))->toBe(app(AppContext::class))
        ->and(Holler::context()->iceServers())->toBe([['urls' => 'stun:stun.l.google.com:19302']])
        ->and(Holler::context()->hasVideo())->toBeTrue()
        ->and(Holler::context()->maxParticipants())->toBe(4)
        ->and(Holler::currentUserId())->toBeNull();

    config()->set('holler.video', false);
    config()->set('holler.ice_servers', []);

    expect(Holler::context()->hasVideo())->toBeFalse()
        ->and(Holler::context()->iceServers())->toBe([]);

    $this->actingAs($this->ion);

    expect(Holler::currentUserId())->toBe((string) $this->ion->getKey())
        ->and(Holler::findUser((string) $this->ana->getKey())?->name)->toBe('Ana')
        ->and(Holler::findUser('999'))->toBeNull();
});

it('lets closures override who is who, who may call whom and what the browsers connect through', function (): void {
    $this->ana->forceFill(['avatar_url' => 'https://example.com/ana.png'])->save();

    app(AppContext::class)
        ->userNameUsing(fn (User $user): string => strtoupper($user->name))
        ->userAvatarUsing(fn (User $user): ?string => $user->avatar_url)
        ->iceServersUsing(fn (): array => [['urls' => 'turn:turn.example.com', 'username' => 'u', 'credential' => 'c']])
        ->video(false);

    expect(Holler::who($this->ana))->toBe(['id' => (string) $this->ana->getKey(), 'name' => 'ANA', 'avatar' => 'https://example.com/ana.png'])
        ->and(Holler::who($this->ion)['avatar'])->toBeNull()
        ->and(Holler::context()->iceServers()[0]['urls'])->toBe('turn:turn.example.com')
        ->and(Holler::context()->hasVideo())->toBeFalse();

    app(AppContext::class)->userNameUsing(null)->userAvatarUsing(null)->iceServersUsing(null)->video(null);

    // Without a name the e-mail stands in, and without either, "Someone".
    expect(Holler::who($this->ion)['name'])->toBe('Ion');
    $this->ion->name = '';
    expect(Holler::who($this->ion)['name'])->toBe('ion@example.com');
    $this->ion->email = '';
    expect(Holler::who($this->ion)['name'])->toBe('Someone');
});

it('runs calls through the service without HTTP', function (): void {
    $call = Holler::start($this->ion, $this->ana, 'win-a');

    expect($call)->toBeInstanceOf(Call::class)
        ->and($call->isRinging())->toBeTrue()
        ->and($call->participant((string) $this->ion->getKey())->tab)->toBe('win-a')
        ->and(Holler::openCallsOf((string) $this->ana->getKey())->count())->toBe(1);

    Holler::answer($call, (string) $this->ana->getKey(), 'ana-1');
    expect($call->isActive())->toBeTrue();

    $signal = Holler::signal($call, (string) $this->ion->getKey(), 'offer', ['sdp' => 'v=0']);
    expect($signal->to_user_id)->toBe((string) $this->ana->getKey())
        ->and($signal->from_user_id)->toBe((string) $this->ion->getKey());

    $poll = Holler::poll((string) $this->ana->getKey(), 0, $call->id, 'ana-1', true);
    expect($poll['signals'])->toHaveCount(1)
        ->and($poll['call']['peer']['name'])->toBe('Ion')
        ->and($poll['cursor'])->toBe($signal->id);

    Holler::hangup($call, (string) $this->ion->getKey());
    expect($call->fresh()->end_reason)->toBe('hung_up');
});

it('throws typed exceptions with the HTTP status the endpoints answer with', function (): void {
    expect(fn () => Holler::start($this->ion, $this->ion))->toThrow(CallNotAllowed::class)
        ->and(fn () => Holler::start($this->ion, null))->toThrow(CallNotAllowed::class)
        ->and(fn () => Holler::start($this->ion, '999'))->toThrow(CallNotAllowed::class);

    $call = Holler::start($this->ion, (string) $this->ana->getKey());
    expect(fn () => Holler::start(user('Mihai'), $this->ana))->toThrow(CalleeBusy::class)
        ->and(fn () => Holler::answer($call, (string) $this->ion->getKey()))->toThrow(CallNotRinging::class);

    Holler::end($call, 'cancelled');
    Holler::end($call, 'hung_up');
    expect($call->end_reason)->toBe('cancelled')
        ->and(fn () => Holler::signal($call, (string) $this->ion->getKey(), 'ice', []))->toThrow(CallHasEnded::class)
        ->and((new CallNotAllowed)->status)->toBe(422)
        ->and((new CalleeBusy)->status)->toBe(409)
        ->and((new CallNotRinging)->status)->toBe(409)
        ->and((new CallHasEnded)->status)->toBe(410);
});

it('registers the routes under any prefix, name and middleware', function (): void {
    Route::middleware('web')->group(fn () => Holler::routes(prefix: 'team/calls', middleware: [], as: 'team.calls.'));

    expect(route('team.calls.poll'))->toEndWith('/team/calls/poll')
        ->and(route('team.calls.store'))->toEndWith('/team/calls')
        ->and(route('team.calls.signal', ['call' => 7]))->toEndWith('/team/calls/7/signals');

    // No auth middleware: a guest is refused by the controller itself.
    $this->getJson('/team/calls/poll')->assertUnauthorized();
});

it('uses the configured guard to find people', function (): void {
    config()->set('auth.guards.staff', ['driver' => 'session', 'provider' => 'users']);
    config()->set('holler.guard', 'staff');

    $this->actingAs($this->ion, 'staff');

    expect(Holler::currentUserId())->toBe((string) $this->ion->getKey())
        ->and(Holler::findUser((string) $this->ana->getKey())?->name)->toBe('Ana');

    // The routes sign people in through the same guard unless told otherwise.
    Route::middleware('web')->group(fn () => Holler::routes(prefix: 'staff/calls', as: 'staff.calls.'));
    Route::middleware('web')->group(fn () => Holler::routes(prefix: 'open/calls', middleware: 'auth', as: 'open.calls.'));
    app('router')->getRoutes()->refreshNameLookups();

    expect(app('router')->getRoutes()->getByName('staff.calls.poll')->middleware())->toBe(['web', 'auth:staff'])
        ->and(app('router')->getRoutes()->getByName('open.calls.poll')->middleware())->toBe(['web', 'auth']);
    $this->actingAs($this->ion, 'staff')->getJson('/staff/calls/poll')->assertOk();
});
