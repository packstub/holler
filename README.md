# Calls for Laravel

![Calls for Laravel: a group video call in a floating window over a People page with a Call button on every row](https://raw.githubusercontent.com/packstub/art/main/calls/banner.jpg)

Audio, video and screen-share calls between the users of a Laravel app, one on one or in small groups (up to four by default), peer to peer over WebRTC. The media goes straight from one browser to the other; Laravel only handles who calls whom and relays the few signaling messages the browsers need to find each other. Polling over plain HTTP, so there is no websocket server to run.

![Ion calls Ana from her row on the Team page, she answers, and the call window shows her video with his in the corner](https://raw.githubusercontent.com/packstub/art/main/filament-calls/docs/calls.gif)

<sub>Shown in a Filament panel, with [Filament Calls](https://github.com/packstub/filament-calls). Without Filament, the same call runs on any page through `<x-calls::button />` and `<x-calls::widget />`.</sub>

This package is the server side (the `calls`, `call_participants` and `call_signals` tables, the JSON routes, the `Calls` service, a `CallContext` that decides who is who and who may call whom, events) plus the two Blade components that put calls on any page: `<x-calls::widget />` and `<x-calls::button />`. The browser side is [`@packstub/calls`](https://github.com/packstub/calls-js), a framework-agnostic engine and widget, served by this package. [Filament Calls](https://github.com/packstub/filament-calls) puts the same engine into a Filament panel, with a `CallAction` and a widget of its own; use that one if your app is a panel.

- `Calls::routes()` registers the signaling endpoints behind your auth middleware.
- `Calls::start()`, `answer()`, `invite()`, `hangup()`… do the same work without HTTP, for your own endpoints or tests.
- Group calls: ring several people at once or add someone to a running call; every participant has a seat, the browsers connect to each other in a full mesh (no media server), and the call goes on for the others when someone hangs up. `CALLS_MAX_PARTICIPANTS` caps a call (4 by default, which is where a mesh stops being comfortable).
- `CallContext`: who is signed in, how people are named to the other side, who may call whom, which STUN and TURN servers the browsers use. The default `AppContext` reads the config file and a few closures.
- Calls survive a full page load: each browser window holds its side of a call through the poll, a `leave` beacon frees it as the page unloads, a fresh page claims the side again. A side gone longer than `reconnect_timeout` ends the call as dropped.
- `CallStarted`, `CallAnswered`, `ParticipantJoined`, `ParticipantLeft` and `CallEnded` events, and a `Call` model with `duration()` for a call log.
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

`window: false` leaves out the widget's pop-out page (`GET {prefix}/window`), for a package that draws a window of its own. Keep the prefix ending in `calls`: the package skips `TrimStrings` and `ConvertEmptyStringsToNull` on `*calls/*/signals`, since signals are relayed byte for byte (a session description keeps its trailing CRLF, Firefox's last ICE candidate is an empty string).

The migration runs from the package; the config file is optional (`php artisan vendor:publish --tag=calls-config`), most of its keys read the `CALLS_*` variables below.

Put the widget in your layout, once, and a button wherever there is someone to call:

```blade
{{-- resources/views/layouts/app.blade.php, before </body> --}}
<x-calls::widget />

{{-- anywhere a signed-in person can call another --}}
<x-calls::button :user="$user" />
```

That is the whole setup (the widget renders nothing for someone who is not signed in, so a layout shared with the login page is fine). The widget draws the incoming-call toast, the call window (a tile per person, your own picture, mute, camera, screen share, hang up) and short notices; the button rings that person, or adds them to the call you are on. Each call opens in a small window of its own, so the page can navigate, reload or close while the call goes on, and shows a chip with the timer meanwhile (`CALLS_POP_OUT=false` keeps the call in the page instead, right for a single-page app). The stylesheet and the ES modules are served by the package: nothing to publish, no bundler.

The widget is [`@packstub/calls`](https://github.com/packstub/calls-js)'s `CallWidget` over its `CallClient`; both are plain ES modules without a framework, so the same engine runs in a Filament panel through [Filament Calls](https://github.com/packstub/filament-calls). Section [The widget](#the-widget) shows how to restyle it, change its markup, or use the engine without it.

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

Or in code, for example with short-lived TURN credentials (the widget asks for them each time a call starts, from the `ice-servers` route):

```php
app(AppContext::class)->iceServersUsing(fn () => [
    ['urls' => 'turn:turn.example.com:3478', 'username' => $user, 'credential' => $password],
]);
```

A TURN relay forwards packets it cannot decrypt (WebRTC media is DTLS-SRTP end to end), so "the media never touches our servers" stays true in spirit; be accurate about it in your own privacy copy.

ICE candidates show each side's network addresses to the other. To keep them hidden, send everything through your TURN server with `CALLS_ICE_TRANSPORT_POLICY=relay` (`Calls::rtcConfiguration()` hands the browsers both settings).

### Polling and timeouts

Every open window asks the server for incoming calls every 5 seconds and, during a call, for signals every second; that poll is also the heartbeat that tells the other side you are still there. `config/packstub-calls.php`: `CALLS_POLL_IDLE` and `CALLS_POLL_ACTIVE` (milliseconds), `CALLS_RING_TIMEOUT` (seconds before a call is marked missed, default 45), `CALLS_RECONNECT_TIMEOUT` (seconds a side may be away before the call ends as lost, default 15), `guard` (the guard people are signed in with, default guard when null), `CALLS_MAX_SIGNAL_KB` (the largest signal the server relays, 64 KB; a session description is a few), `CALLS_MAX_PENDING_SIGNALS` (how many signals from one person may wait unread on a call, 500; past it the server answers 429).

The routes take whatever middleware you pass to `Calls::routes()`; add a `throttle` there if your app throttles its other JSON routes (a window polls once a second during a call).

### Keeping the tables small

Signals are deleted once the other side's window read them, and all of them when their call ends. Calls everybody walked away from, and ended calls older than `CALLS_PRUNE_AFTER_DAYS` (90; null keeps the log forever), go with `calls:prune`; schedule it daily:

```php
Schedule::command('calls:prune')->daily();
```

`--days=30` overrides the config for one run.

### Several guards, several databases

Calls are kept apart by guard: user 7 of `admin` is never rung for user 7 of `web`, even when both sign in to the same app. Under a database per tenant, set `CALLS_DB_CONNECTION` to the central connection so a call between two places meets in one table.

### Events and the call log

```php
use Packstub\Calls\Events\CallEnded;

Event::listen(CallEnded::class, function (CallEnded $event) {
    $event->call->caller_id;     // user ids as strings, so integer and UUID keys both fit
    $event->call->callee_id;     // the first person called
    $event->call->participants;  // everyone, with seat, status (joined, left, declined, missed, dropped) and times
    $event->call->end_reason;    // declined | cancelled | missed | hung_up | dropped, or what your code passed to Calls::end()
    $event->call->duration();    // seconds, null if never answered
});
```

`ParticipantJoined` and `ParticipantLeft` carry the call and the participant, for a group's comings and goings.

`Packstub\Calls\Models\Call` is a plain Eloquent model over the `calls` table.

### Your own context

Implement `Packstub\Calls\Contracts\CallContext` when the signed-in user does not come from a guard the config can name (a multi-tenant app, an API token): `scope()` (whose ids these are, the guard's name in the shipped context), `currentUserId()`, `findUser()`, `who()`, `canCall()`, `iceServers()`, `hasVideo()`, `maxParticipants()`. An optional `findUsers(array $ids): array` names a whole roster in one query (`AppContext` has one); without it people are looked up one by one. Bind it in a service provider's `register()`; the package only binds `AppContext` when nothing else is bound.

## The widget

`<x-calls::widget />` takes a few attributes:

| Attribute | Default | Does |
| --- | --- | --- |
| `routes` | `calls.` | the route name prefix given to `Calls::routes()` |
| `pop-out` | config `pop_out` | open each call in its own window, with a chip on the page |
| `styles` | `true` | load the package stylesheet; `false` to bring your own |
| `options` | `[]` | more `CallWidget` options, merged over the ones the component builds: `labels`, `video`, `windowFeatures`, `callSelector`… |
| `name` | `calls` | the global the widget is kept in, `window.calls`, for your own scripts |

Any element with `data-calls-call="<user id>"` is a call button, so `<x-calls::button>` is a convenience: it renders `<button data-calls-call="…">Call</button>` with your attributes and slot, and nothing for the signed-in person. From a script, `window.calls.callUser(id)`, `hangUp()`, `toggleMute()`… do the same as the buttons, and `document.addEventListener('calls:ended', …)` hears every event of the engine as `calls:<type>` (`statechange`, `incoming`, `peers`, `peerstate`, `ended`…) plus `calls:sharing` when someone's screen takes the stage.

### Restyling it

Every class is `calls-*` and the colours and sizes are custom properties on `.calls`:

```css
.calls { --calls-accent: #d97706; --calls-bg: #1c1917; --calls-radius: 0.75rem; --calls-width: 28rem; }
```

Set `:styles="false"` and write your own stylesheet against the class names when a few variables are not enough. The texts come from `lang/vendor/calls/en/ui.php` once you publish the translations (`php artisan vendor:publish --tag=calls-translations`).

### Your own markup

The widget renders five views from template functions, `incoming` (the toast), `chip`, `window`, `tile` (one per other participant) and `notice`, each once when it appears, and keeps it current through a few `data-calls-*` hooks: `text`, `show`, `class`, `attr`, `src`, `video` and `action`. Hand in your own markup with whichever hooks you keep, from a script of your own (templates are functions, so they cannot come through the Blade attribute):

```blade
<x-calls::widget :options="['startPolling' => false]" />
<script type="module">
    window.addEventListener('calls:ready', ({ detail: { widget } }) => {
        widget.templates.incoming = (v) => `
            <div class="my-toast" data-calls-scope>
                <b data-calls-text="peerName"></b> ${v.labels.incoming}
                <button data-calls-action="accept">${v.labels.accept}</button>
                <button data-calls-action="decline">${v.labels.decline}</button>
            </div>`
        widget.start()
    })
</script>
```

The view model a template and its hooks read is documented in the [`@packstub/calls` README](https://github.com/packstub/calls-js#the-widget), together with subclassing `CallWidget` for deeper changes.

### Calls for another package (guests, kinds)

A package with a UI of its own (a support tool that calls a visitor of the site, say) uses the service directly:

```php
use Packstub\Calls\Participant;

$call = Calls::start($agent, new Participant('guest:'.$request->id, 'Visitor on /checkout'), kind: 'assist');
Calls::answer($call, 'guest:'.$request->id, $tab);
Calls::poll('guest:'.$request->id, $after, $call->id, $tab, hold: true, kind: 'assist');
```

A `Participant` is someone no guard knows: its id has a prefix and a colon, its name and avatar are stored on the call, and the context is not asked about it (the code that made it decided). A string with a colon is never looked up, so nobody can ring a guest from the HTTP routes, and a guest brings nobody into a call. The `kind` (1 to 16 of `a-z`, `0-9`, `_` and `-`) keeps such calls away from the widget, which polls with `kind=call`. Your own routes authenticate the guest; the package's routes stay behind `auth`.

When the request that sets up the call is not where its users poll from (a visitor's request setting up a call with an agent who signs in through an `admin` guard), name the users' scope: `Calls::start($agent, $guest, kind: 'assist', scope: 'admin')`, with users or `Participant`s rather than ids. Everyone's name and avatar are stored as they join, so a guest sees those instead of a lookup in the wrong user table.

### Without the widget

The engine alone, for a UI of your own or a framework component: `import { CallClient, HttpSignaling } from '{{ Calls::asset('index.js') }}'` (or `npm install @packstub/calls`) and give it the nine routes, the CSRF token, `Calls::context()->iceServers()`, `hasVideo()` and `maxParticipants()`. Its README documents the events (`incoming`, `localstream`, `remotestream`, `peers`, `statechange`, `ended`) and controls (`callUser`, `invite`, `accept`, `decline`, `hangUp`, `setMuted`, `setCameraOff`, `shareScreen`).

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
| `GET` | `poll?after={cursor}&call={id}&tab={tab}&hold=1&kind=call` | `{call, incoming, signals, cursor}`: the call I am on (held by this window when `hold` is set: its heartbeat), a call ringing me, and my new signals as `{id, call, type, payload, from}`; only calls of `kind` when given |
| `GET` | `ice-servers` | `{iceServers, iceTransportPolicy}`, asked when a call starts (never cached) |
| `GET` | `window?call={id}&callee={user}` | the widget's pop-out page: a bare page with the widget, which rings `callee` or answers `call` |

The browser files are served outside `Calls::routes()`, without auth: `GET packstub-calls/assets/{index.js|widget.js|widget.css}` (named `calls.asset`); `Calls::asset('widget.js')` gives a versioned URL, cached for a year.

Errors are `{message, reason}`: `reason` is `not_allowed`, `full`, `busy`, `not_ringing`, `ended`, `too_large` (413) or `too_many` (429), so a client can tell the two 409s apart. Signals come only from people in the call (422 otherwise).

A call is `{id, kind, status, end_reason, role, seat, joined, peers: [{id, name, avatar, seat, status, held, seen_ago}], peer, peer_held, peer_seen_ago, held, answered_at}`; `peer` and its two fields describe the first live peer, for a 1-on-1 client. Every window has a random `tab` id; a participant's side belongs to one window at a time, and a window that stops polling for 5 seconds loses it. A fresh page claims the side, sends `reset` and a new offer to each peer, and they build new connections for it. Per-call routes answer 403 to anyone who is not on the call.

## Testing

```bash
composer test
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

MIT, see [LICENSE.md](LICENSE.md).
