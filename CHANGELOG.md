# Changelog

All notable changes to `packstub/calls` are documented in this file.

## Unreleased

First release: the signaling backend for WebRTC calls between the users of a Laravel app, one on one or in groups, plus a Blade widget that puts calls on any page. The media goes browser to browser; Laravel decides who calls whom and relays the signaling over polled HTTP. The core started inside `packstub/filament-calls`, which now requires this package for the panel UI.

### Calls and groups

- `Calls` service and facade: `start()` rings one person or several, `invite()` adds someone to a running call, `answer()`, `decline()`, `hangup()`, `withdraw()`, `end()`, `signal()`, `expire()`, `poll()` and `present()`. Every check runs before anything changes: a refused call (busy, not allowed, full) leaves the caller's current call alone, and two people ringing each other at once end up on one call.
- Up to `max_participants` people on a call (4 by default, `CALLS_MAX_PARTICIPANTS`, `AppContext::maxParticipantsUsing()`), each with a seat in invitation order; between two participants the lower seat makes the WebRTC offer. Someone who hangs up leaves the call for the others, and it ends once fewer than two people, or nobody who joined, are left.
- Someone rung past `ring_timeout` (45 s) is marked missed and someone quiet past `reconnect_timeout` (15 s) dropped, without ending a call that still has two people on it. Answers, hang-ups, timeouts and invites run under a lock on the call, so a late answer never revives a cancelled call, a seat is never given twice and events fire once, after the transaction commits.
- Calls survive a page load: each browser window holds its side through the poll (`tab`), a `leave` beacon frees it as the page unloads, and a fresh page claims it and starts after the signals meant for the old connection.
- Signals (`offer`, `answer`, `ice`, `reset`, `state`) go to one participant (`to`) or to every other live one, carry `from`, and come only from people in the call; `CALLS_MAX_SIGNAL_KB` (64) caps their size (413). `TrimStrings` is skipped on the signals route so a session description keeps its trailing CRLF.
- Typed exceptions with the status the endpoints answer with: `CallNotAllowed` and `CallFull` (422), `CalleeBusy` and `CallNotRinging` (409), `CallHasEnded` (410), `SignalTooLarge` (413). Error responses are `{message, reason}`.

### Routes and data

- `Calls::routes()` registers the nine JSON routes (`poll`, `ice-servers`, `store`, `answer`, `decline`, `hangup`, `invite`, `signals`, `leave`) and the widget's pop-out page (`window`, left out with `window: false`) under a prefix, name and middleware of your choice. Per-call routes answer 403 to anyone not on the call and take numeric ids only.
- `GET {prefix}/ice-servers` hands out the ICE servers and the transport policy when a call starts, so short-lived TURN credentials from `iceServersUsing()` are fresh per call. `CALLS_ICE_TRANSPORT_POLICY=relay` sends everything through your TURN server; `Calls::rtcConfiguration()` returns both.
- The call JSON: `id`, `kind`, `status`, `end_reason`, `role`, `seat`, `joined`, `peers` (id, name, avatar, seat, status, held, seen_ago), `held`, `answered_at`, plus `peer`, `peer_held` and `peer_seen_ago` for the first live peer, so a 1-on-1 client needs nothing else.
- `calls`, `call_participants` and `call_signals` tables, indexed for the queries every poll runs; `Call`, `CallParticipant` and `CallSignal` models; `CALLS_DB_CONNECTION` puts them on one connection, for a database per tenant.
- Events: `CallStarted`, `CallAnswered` (when the first person joins), `ParticipantJoined`, `ParticipantLeft` and `CallEnded`.
- `calls:prune` ends the calls everybody walked away from and deletes ended calls older than `CALLS_PRUNE_AFTER_DAYS` (90) with their participants and signals; `--days` overrides the age for one run.
- Config `packstub-calls.php` with `CALLS_*` env variables: STUN and TURN (`CALLS_STUN_URL`, `CALLS_TURN_URL`, `CALLS_TURN_USERNAME`, `CALLS_TURN_CREDENTIAL`), poll intervals (`CALLS_POLL_IDLE`, `CALLS_POLL_ACTIVE`), ring and reconnect timeouts, `CALLS_VIDEO`, the `guard`. Requires Laravel 12.69+ or 13.30+.

### Who is who

- `CallContext` contract: `scope()`, `currentUserId()`, `findUser()`, `who()`, `canCall()`, `iceServers()`, `hasVideo()` and `maxParticipants()`. `AppContext` implements it for a plain Laravel app from the `packstub-calls` config and `canCallUsing()`, `userNameUsing()`, `userAvatarUsing()`, `iceServersUsing()`, `video()` and `maxParticipantsUsing()`; it is bound only when nothing else is, so a panel package can bring its own.
- Calls are kept apart by scope (the guard's name in `AppContext`): two guards with overlapping ids never ring each other.
- `Calls::whoAre()` names a whole roster in one query through `AppContext::findUsers()` (`AppContext::retrieveMany()` does the same for any user provider). Calling yourself is always refused.

### Guests and kinds

- For packages with a UI of their own: `Calls::start()` takes a `Participant` (an id like `guest:<uuid>`, a name and an avatar, stored on the call) and rings it without asking the context. A string with a colon is never looked up, so no route can ring a guest, and nobody brings a third person into a call with a guest on it.
- A `kind` on every call (`call` by default, `start(kind:)`), in the call JSON and in `poll(kind:)` / `?kind=`, so a widget only hears of its own calls. `start(scope:)` sets up a call for users who poll from another guard; names and avatars are stored as people join, so each side sees the right name.

### The widget

- `<x-calls::widget />` in a layout and `<x-calls::button :user="$user" />` wherever there is someone to call: the incoming toast, the call window with a tile per person, mute, camera, screen share and hang up, a chip while the call runs in its pop-out window (`CALLS_POP_OUT=false` keeps calls in the page), and notices. It is `CallWidget` from `@packstub/calls`, without a framework.
- The stylesheet and modules are served by the package (`GET packstub-calls/assets/{file}`, `Calls::asset()`, versioned and cached), so there is nothing to publish or bundle. Texts in `lang/en/ui.php`; restyle through `--calls-*` custom properties, replace any view's markup with a template, or listen to `calls:*` events on the page.
