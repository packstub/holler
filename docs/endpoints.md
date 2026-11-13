# Endpoints

`Holler::routes()` registers nine JSON routes and the pop-out page. The widget and [`@packstub/holler`](https://github.com/packstub/holler-js) talk to them; this page is for writing another browser client, or another backend for `@packstub/holler`. Paths are relative to the prefix (`calls` by default) and names to `as` (`holler.`).

## Routes

| Method | Route | Name | Does |
| --- | --- | --- | --- |
| `POST` | `/` with `{callee, tab}` or `{callees: [...], tab}` | `store` | ring one person, or up to 16 ids at once (the call still stops at `max_participants`) |
| `POST` | `{id}/answer` with `{tab}` | `answer` | someone rung joins; the call becomes `active` the first time |
| `POST` | `{id}/decline` | `decline` | someone rung says no; someone already on the call leaves it |
| `POST` | `{id}/hangup` | `hangup` | leave the call; it goes on for the others and ends once fewer than two are left |
| `POST` | `{id}/invite` with `{callee}` | `invite` | add someone to the call I have joined |
| `POST` | `{id}/signals` with `{type, payload, to?}` | `signal` | queue an `offer`, `answer`, `ice`, `reset` or `state` (mute, camera off, screen share) for one participant (`to`) or for everyone else on the call |
| `POST` | `{id}/leave` with `{tab}` | `leave` | a beacon as the window unloads: the side is free for the next page |
| `GET` | `poll?after={cursor}&call={id}&tab={tab}&hold=1&kind=call` | `poll` | `{call, incoming, signals, cursor}`, see below |
| `GET` | `ice-servers` | `ice` | `{iceServers, iceTransportPolicy}`, asked when a call starts and never cached |
| `GET` | `window?call={id}&callee={user}` | `window` | the widget's [pop-out page](the-widget.md#the-pop-out-window); left out with `window: false` |

Every route answers 401 to someone who is not signed in, and the routes with a call id answer 403 to anyone who is not on that call (or is on it from another scope).

The browser files are served outside `Holler::routes()`, without auth: `GET holler/assets/{index.js|widget.js|widget.css}`, named `holler.asset`. `Holler::asset('widget.js')` gives a URL with a version in it, cached for a year.

## Polling

One request, repeated by every window: every 5 seconds without a call, every second while one rings or runs.

- `call`: the call this window names with `call=`, or else any call I have joined and not left, for a page that just loaded. A call named with `call=` is returned after it ended too, so the window learns the `end_reason`.
- `incoming`: a call ringing me, if any.
- `signals`: my new signals after `after`, as `{id, call, type, payload, from}`, at most 100 per poll. Only the window that holds my side gets them.
- `cursor`: the last signal id, for the next `after`.

`hold=1` asks for this window to hold my side of the call named by `call=`, and doubles as the heartbeat the other side watches. `kind` limits `call` and `incoming` to calls of that kind; the widget sends `kind=call`. Each poll also marks people who did not answer in time as missed and people who went quiet as dropped.

## Windows and reconnecting

Every window picks a random `tab` id. Each participant's side of a call belongs to one window at a time: the window that holds it gets the signals and keeps the side by polling. A window that stops polling for 5 seconds loses it, and a `leave` beacon frees it at once.

A fresh page (a reload, or the pop-out taking over) claims the side on its first poll, sends `reset` and a new offer to each peer, and they build new connections to it. Signals meant for the old connection are skipped. If nobody claims the side within `HOLLER_RECONNECT_TIMEOUT` (15 seconds), that person is dropped from the call.

Signals are deleted once the window holding the side has read them, and all of a call's signals when it ends.

## The call

`present()` returns a call as one person sees it, and every route that returns a call uses it:

```json
{
    "id": 12,
    "kind": "call",
    "status": "active",
    "end_reason": null,
    "role": "caller",
    "seat": 0,
    "joined": true,
    "peers": [
        {"id": "5", "name": "Ana", "avatar": null, "seat": 1, "status": "joined", "held": true, "seen_ago": 0}
    ],
    "peer": {"id": "5", "name": "Ana", "avatar": null},
    "peer_held": true,
    "peer_seen_ago": 0,
    "held": false,
    "answered_at": "2026-11-10T09:30:00+00:00"
}
```

- `role` is `caller` or `callee`; `status` is `ringing`, `active` or `ended`; a participant's `status` is `ringing`, `joined`, `left`, `declined`, `missed` or `dropped`.
- `end_reason` is `declined`, `cancelled`, `missed`, `hung_up` or `dropped`, or whatever your code passed to `Holler::end()`.
- Between two participants, the lower `seat` makes the WebRTC offer.
- `held` is true when another window of mine holds my side; `held` on a peer says whether a window holds theirs, and `seen_ago` is the seconds since it last polled.
- `peer`, `peer_held` and `peer_seen_ago` describe the first live peer, which is all a 1-on-1 client reads.

User ids are strings everywhere, so integer and UUID keys both work.

## Errors

Errors are `{message, reason}`. `message` is for people (from the `holler::messages` translations); `reason` is for code, so a client can tell the two 409s apart:

| Status | `reason` | When |
| --- | --- | --- |
| 422 | `not_allowed` | yourself, nobody, someone the context refuses, someone already on the call, inviting before you joined, or a signal from or to someone not on the call |
| 422 | `full` | more people than `max_participants` |
| 409 | `busy` | the person is on another call |
| 409 | `not_ringing` | answering a call that no longer rings you |
| 410 | `ended` | a signal or an invite for an ended call |
| 413 | `too_large` | a signal above `HOLLER_MAX_SIGNAL_KB` |
| 429 | `too_many` | more than `HOLLER_MAX_PENDING_SIGNALS` of your signals still unread on the call |

A request that fails validation gets Laravel's usual 422 with `errors`.
