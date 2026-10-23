# Calls for Laravel

1-on-1 audio, video and screen-share calls between the users of a Laravel app, peer to peer over WebRTC. The media goes straight from one browser to the other; Laravel only handles who calls whom and relays the few signaling messages the browsers need to find each other. Polling over plain HTTP, so there is no websocket server to run.

This package is the server side: the `calls` and `call_signals` tables, seven JSON routes, the `Calls` service, a `CallContext` that decides who is who and who may call whom, and three events. The browser side is [`@packstub/calls`](https://github.com/packstub/calls-js), a framework-agnostic ES module. [Filament Calls](https://github.com/packstub/filament-calls) puts both into a Filament panel, with a widget, a `CallAction` and a pop-out window; use that one if your app is a panel.

- `Calls::routes()` registers the signaling endpoints behind your auth middleware.
- `Calls::start()`, `answer()`, `hangup()`… do the same work without HTTP, for your own endpoints or tests.
- `CallContext`: who is signed in, how people are named to the other side, who may call whom, which STUN and TURN servers the browsers use. The default `AppContext` reads the config file and a few closures.
- Calls survive a full page load: each browser window holds its side of a call through the poll, a `leave` beacon frees it as the page unloads, a fresh page claims the side again. A side gone longer than `reconnect_timeout` ends the call as dropped.
- `CallStarted`, `CallAnswered`, `CallEnded` events and a `Call` model with `duration()` for a call log.
- STUN out of the box; your own TURN server through four env variables for networks where a direct path fails (the relay only forwards encrypted packets).

## Requirements

- PHP 8.3+, Laravel 12 or 13
- Browsers with WebRTC (every current one); the app served over HTTPS (or `localhost`), as `getUserMedia` needs a secure context

## Installation

```bash
composer require packstub/calls
php artisan migrate
```

Register the routes, for example in `routes/web.php`:

```php
use Packstub\Calls\Facades\Calls;

Calls::routes();                                        // /calls/… behind `auth`, named calls.*
Calls::routes(prefix: 'team/calls', middleware: ['auth', 'verified'], as: 'team.calls.');
```

Then install the browser engine and give it the routes:

```bash
npm install @packstub/calls
```

```js
import { CallClient, HttpSignaling } from '@packstub/calls'

const client = new CallClient({
    signaling: new HttpSignaling({
        routes: {
            poll: '/calls/poll',
            store: '/calls',
            answer: '/calls/__ID__/answer',
            decline: '/calls/__ID__/decline',
            hangup: '/calls/__ID__/hangup',
            signal: '/calls/__ID__/signals',
            leave: '/calls/__ID__/leave',
        },
        headers: { 'X-CSRF-TOKEN': csrfToken },
        beaconFields: { _token: csrfToken },
    }),
    iceServers: @json(Calls::context()->iceServers()),
    video: @json(Calls::context()->hasVideo()),
    poll: @json(config('packstub-calls.poll')),
    reconnectTimeout: {{ config('packstub-calls.reconnect_timeout') * 1000 }},
})

client.start()
```

The [`@packstub/calls` README](https://github.com/packstub/calls-js) documents the client's events (`incoming`, `localstream`, `remotestream`, `statechange`, `ended`) and controls (`callUser`, `accept`, `decline`, `hangUp`, `setMuted`, `setCameraOff`, `shareScreen`).

## Usage

### Who may call whom

By default any two different users of the guard can call each other. Narrow it from a service provider's `boot()`:

```php
use Packstub\Calls\AppContext;

app(AppContext::class)->canCallUsing(fn (User $caller, User $callee): bool => $caller->team_id === $callee->team_id);
```

The check runs on the server when a call starts, whatever the browser asked for.

### How people appear to each other

```php
app(AppContext::class)
    ->userNameUsing(fn (User $user): string => $user->display_name)      // default: name, then email
    ->userAvatarUsing(fn (User $user): ?string => $user->avatar_url);   // default: none
```

### Voice calls

```php
app(AppContext::class)->video(false);   // or CALLS_VIDEO=false
```

Calls then start with the microphone only. Either side can still share the screen.

### ICE servers (STUN / TURN)

Most calls connect directly with a STUN server, which is what the default config uses. Calls behind strict NATs, VPNs or corporate firewalls need a TURN relay; point the package at yours (coturn, or a hosted service):

```dotenv
CALLS_STUN_URL=stun:stun.l.google.com:19302
CALLS_TURN_URL=turn:turn.example.com:3478
CALLS_TURN_USERNAME=...
CALLS_TURN_CREDENTIAL=...
```

Or in code, for example with short-lived TURN credentials:

```php
app(AppContext::class)->iceServersUsing(fn () => [
    ['urls' => 'turn:turn.example.com:3478', 'username' => $user, 'credential' => $password],
]);
```

A TURN relay forwards packets it cannot decrypt (WebRTC media is DTLS-SRTP end to end), so "the media never touches our servers" stays true in spirit; be accurate about it in your own privacy copy.

### Polling and timeouts

Every open window asks the server for incoming calls every 5 seconds and, during a call, for signals every second; that poll is also the heartbeat that tells the other side you are still there. `config/packstub-calls.php`: `CALLS_POLL_IDLE` and `CALLS_POLL_ACTIVE` (milliseconds), `CALLS_RING_TIMEOUT` (seconds before a call is marked missed, default 45), `CALLS_RECONNECT_TIMEOUT` (seconds a side may be away before the call ends as lost, default 15), `guard` (the guard people are signed in with, default guard when null).

### Events and the call log

```php
use Packstub\Calls\Events\CallEnded;

Event::listen(CallEnded::class, function (CallEnded $event) {
    $event->call->caller_id;   // user ids as strings, so integer and UUID keys both fit
    $event->call->callee_id;
    $event->call->end_reason;  // declined | cancelled | missed | hung_up | failed | dropped
    $event->call->duration();  // seconds, null if never answered
});
```

`Packstub\Calls\Models\Call` is a plain Eloquent model over the `calls` table.

### Your own context

Implement `Packstub\Calls\Contracts\CallContext` when the signed-in user does not come from a guard the config can name (a multi-tenant app, an API token): `currentUserId()`, `findUser()`, `who()`, `canCall()`, `iceServers()`, `hasVideo()`. Bind it in a service provider's `register()`; the package only binds `AppContext` when nothing else is bound.

## The endpoints

For another browser client, or another backend speaking to `@packstub/calls`:

| Method | Route | Does |
| --- | --- | --- |
| `POST` | `calls` `{callee, tab}` | ring a user; 422 when not allowed, 409 when they are on a call |
| `POST` | `calls/{id}/answer` `{tab}` | callee joins; the call becomes `active` (409 once it stopped ringing) |
| `POST` | `calls/{id}/decline` | callee says no |
| `POST` | `calls/{id}/hangup` | either side ends it |
| `POST` | `calls/{id}/signals` `{type, payload}` | queue an `offer`, `answer`, `ice`, `reset` or `state` (mute, camera off, screen share) for the other side (410 after the call ended) |
| `POST` | `calls/{id}/leave` `{tab}` | a beacon as the window unloads: the side is free for the next page |
| `GET` | `poll?after={cursor}&call={id}&tab={tab}&hold=1` | the call I am in (held by this window when `hold` is set: its heartbeat), an incoming call, and my new signals |

Every window has a random `tab` id; a side belongs to one window at a time, and a window that stops polling for 5 seconds loses it. A fresh page claims the side, sends `reset` and a new offer, and the other side builds a new connection for it. Per-call routes answer 403 to anyone who is not one of the two people on the call.

## Testing

```bash
composer test
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

MIT, see [LICENSE.md](LICENSE.md).
