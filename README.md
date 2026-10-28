# Calls for Laravel

Audio, video and screen-share calls between the users of a Laravel app, one on one or in small groups (up to four by default), peer to peer over WebRTC. The media goes straight from one browser to the other; Laravel only handles who calls whom and relays the few signaling messages the browsers need to find each other. Polling over plain HTTP, so there is no websocket server to run.

This package is the server side: the `calls`, `call_participants` and `call_signals` tables, eight JSON routes, the `Calls` service, a `CallContext` that decides who is who and who may call whom, and three events. The browser side is [`@packstub/calls`](https://github.com/packstub/calls-js), a framework-agnostic ES module. [Filament Calls](https://github.com/packstub/filament-calls) puts both into a Filament panel, with a widget, a `CallAction` and a pop-out window; use that one if your app is a panel.

- `Calls::routes()` registers the signaling endpoints behind your auth middleware.
- `Calls::start()`, `answer()`, `invite()`, `hangup()`… do the same work without HTTP, for your own endpoints or tests.
- Group calls: ring several people at once or add someone to a running call; every participant has a seat, the browsers connect to each other in a full mesh (no media server), and the call goes on for the others when someone hangs up. `CALLS_MAX_PARTICIPANTS` caps a call (4 by default, which is where a mesh stops being comfortable).
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
            invite: '/calls/__ID__/invite',
        },
        headers: { 'X-CSRF-TOKEN': csrfToken },
        beaconFields: { _token: csrfToken },
    }),
    iceServers: @json(Calls::context()->iceServers()),
    video: @json(Calls::context()->hasVideo()),
    poll: @json(config('packstub-calls.poll')),
    reconnectTimeout: {{ config('packstub-calls.reconnect_timeout') * 1000 }},
    maxParticipants: {{ Calls::context()->maxParticipants() }},
})

client.start()
```

The [`@packstub/calls` README](https://github.com/packstub/calls-js) documents the client's events (`incoming`, `localstream`, `remotestream`, `peers`, `statechange`, `ended`) and controls (`callUser`, `invite`, `accept`, `decline`, `hangUp`, `setMuted`, `setCameraOff`, `shareScreen`).

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

### Group calls

`callUser([$anaId, $mihaiId])` in the browser rings two people at once (`POST calls {callees}`), and `invite($danaId)` adds someone to a call that is running (`POST calls/{id}/invite`). Everyone on a call has a seat in the order they were invited; between two participants the lower seat makes the WebRTC offer. Someone who hangs up leaves the call for the others, and the call ends once fewer than two people are left on it.

```dotenv
CALLS_MAX_PARTICIPANTS=4
```

```php
app(AppContext::class)->maxParticipantsUsing(fn (): int => auth()->user()->plan->call_seats);
```

The browsers connect to each other directly (a full mesh), so each one uploads its media once per other participant: four people is where that stops being comfortable on ordinary connections. Raise the limit only with a browser topology that relays through a server (the `topology` option of `@packstub/calls`).

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
    $event->call->caller_id;     // user ids as strings, so integer and UUID keys both fit
    $event->call->callee_id;     // the first person called
    $event->call->participants;  // everyone, with seat, status (joined, left, declined, missed, dropped) and times
    $event->call->end_reason;    // declined | cancelled | missed | hung_up | failed | dropped
    $event->call->duration();    // seconds, null if never answered
});
```

`ParticipantJoined` and `ParticipantLeft` carry the call and the participant, for a group's comings and goings.

`Packstub\Calls\Models\Call` is a plain Eloquent model over the `calls` table.

### Your own context

Implement `Packstub\Calls\Contracts\CallContext` when the signed-in user does not come from a guard the config can name (a multi-tenant app, an API token): `currentUserId()`, `findUser()`, `who()`, `canCall()`, `iceServers()`, `hasVideo()`, `maxParticipants()`. Bind it in a service provider's `register()`; the package only binds `AppContext` when nothing else is bound.

## The endpoints

For another browser client, or another backend speaking to `@packstub/calls`:

| Method | Route | Does |
| --- | --- | --- |
| `POST` | `calls` `{callee, tab}` or `{callees: [...], tab}` | ring one person or several; 422 when not allowed or over `max_participants`, 409 when someone is on a call |
| `POST` | `calls/{id}/answer` `{tab}` | someone rung joins; the call becomes `active` the first time (409 once they are no longer rung) |
| `POST` | `calls/{id}/decline` | someone rung says no |
| `POST` | `calls/{id}/hangup` | leave the call; it goes on for the others and ends once fewer than two are left |
| `POST` | `calls/{id}/invite` `{callee}` | add someone to the call I am on; 422 when not allowed, full, or not joined yet, 409 when they are busy |
| `POST` | `calls/{id}/signals` `{type, payload, to?}` | queue an `offer`, `answer`, `ice`, `reset` or `state` (mute, camera off, screen share) for one participant (`to`) or for everyone else (410 after the call ended) |
| `POST` | `calls/{id}/leave` `{tab}` | a beacon as the window unloads: the side is free for the next page |
| `GET` | `poll?after={cursor}&call={id}&tab={tab}&hold=1` | the call I am on (held by this window when `hold` is set: its heartbeat), a call ringing me, and my new signals (each with `from`) |

A call is `{id, status, end_reason, role, seat, joined, peers: [{id, name, avatar, seat, status, held, seen_ago}], peer, peer_held, peer_seen_ago, held, answered_at}`; `peer` and its two fields describe the first live peer, for a 1-on-1 client. Every window has a random `tab` id; a participant's side belongs to one window at a time, and a window that stops polling for 5 seconds loses it. A fresh page claims the side, sends `reset` and a new offer to each peer, and they build new connections for it. Per-call routes answer 403 to anyone who is not on the call.

## Testing

```bash
composer test
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

MIT, see [LICENSE.md](LICENSE.md).
