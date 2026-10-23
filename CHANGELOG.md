# Changelog

All notable changes to `packstub/calls` are documented in this file.

## Unreleased

First release. The signaling core moved here from `packstub/filament-calls`, which now requires this package and adds the panel UI.

- `Calls::routes()` registers the seven JSON signaling routes (`poll`, `store`, `answer`, `decline`, `hangup`, `signals`, `leave`) under a prefix, name and middleware of your choice.
- `Calls` service and facade: `start()` (cancels the caller's open call first, refuses yourself, nobody or anyone the context says no to, 409 when the callee is busy), `answer()`, `decline()`, `hangup()`, `end()` (idempotent, deletes the signals, fires `CallEnded` once), `signal()`, `expire()` (missed past `ring_timeout`, dropped past `reconnect_timeout`), `poll()` (the one request every window repeats: claims a side with `hold`, signals only to the holder, an id cursor), `present()`; typed `CallException`s with the HTTP status the endpoints answer with.
- `CallContext` contract: who is signed in, how to find and name people, who may call whom, ICE servers and whether calls start with video. `AppContext` for a plain Laravel app: the `packstub-calls` config file plus `canCallUsing()`, `userNameUsing()`, `userAvatarUsing()`, `iceServersUsing()` and `video()`; bound only when nothing else is bound so a panel package can take over.
- Reconnecting: each window holds its side of a call (`caller_tab`/`callee_tab` and a last-seen time from polling), a `leave` beacon frees it on unload, a new page claims it and starts after the signals meant for the old connection.
- `calls` and `call_signals` tables; `Call` model with `duration()`, `claim()`, `release()`, `isHeld()`; `CallSignal`; `CallStarted`, `CallAnswered`, `CallEnded` events.
- Signal types: `offer`, `answer`, `ice`, `reset` and `state` (a side tells the other that it muted, turned the camera off or shares the screen; the payload is the client's).
- `TrimStrings` is skipped on the signals route so a session description keeps its trailing CRLF.
- Config `packstub-calls.php` with `CALLS_*` env variables: STUN and TURN, poll intervals, ring and reconnect timeouts, video, guard.
