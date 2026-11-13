# Configuration

Everything lives in `config/holler.php`. Most keys read a `HOLLER_*` env variable, so few apps need to publish the file. When you want to edit it:

```bash
php artisan vendor:publish --tag=holler-config
```

The settings that take a closure (who may call whom, names, avatars, ICE servers, video, the group size) are on `AppContext`, covered in [Calling](calling.md).

## ICE servers (STUN / TURN)

Most calls connect directly with a STUN server, which is what the default config uses. Calls behind strict NATs, VPNs or corporate firewalls need a TURN relay; point the package at yours (coturn, or a hosted service):

```dotenv
HOLLER_STUN_URL=stun:stun.l.google.com:19302
HOLLER_TURN_URL=turn:turn.example.com:3478
HOLLER_TURN_USERNAME=...
HOLLER_TURN_CREDENTIAL=...
```

Or in code, for example with short-lived TURN credentials:

```php
app(AppContext::class)->iceServersUsing(fn () => [
    ['urls' => 'turn:turn.example.com:3478', 'username' => $user, 'credential' => $password],
]);
```

The browsers ask for these each time a call starts (the `ice-servers` route), never when the page loads: a closure with short-lived credentials runs per call, the credentials never sit in the page's HTML, and a tab left open all day still gets fresh ones.

ICE candidates show each side's network addresses to the other. To keep them private, send everything through your TURN server:

```dotenv
HOLLER_ICE_TRANSPORT_POLICY=relay
```

A TURN relay forwards packets it cannot decrypt (WebRTC media is encrypted end to end with DTLS-SRTP).

## Polling and timeouts

Every open window asks the server for incoming calls every 5 seconds and, while a call rings or runs, for signals every second; that poll is also the heartbeat that tells the other side you are still there. Hidden tabs without a call poll less often.

| Variable | Default | |
| --- | --- | --- |
| `HOLLER_POLL_IDLE` | 5000 | milliseconds between polls without a call |
| `HOLLER_POLL_ACTIVE` | 1000 | milliseconds between polls while a call rings or runs |
| `HOLLER_RING_TIMEOUT` | 45 | seconds before someone who does not answer is marked missed |
| `HOLLER_RECONNECT_TIMEOUT` | 15 | seconds a side may be away before that person is dropped from the call |
| `HOLLER_MAX_PARTICIPANTS` | 4 | people on one call, the caller included |
| `HOLLER_VIDEO` | true | `false` makes every call a voice call |
| `HOLLER_POP_OUT` | true | the widget opens each call in a [window of its own](the-widget.md#the-pop-out-window) |

The routes take whatever middleware you pass to `Holler::routes()`. Add a `throttle` there if your app throttles its other JSON routes, keeping in mind that a window polls once a second during a call.

## Limits

| Variable | Default | |
| --- | --- | --- |
| `HOLLER_MAX_SIGNAL_KB` | 64 | the largest signal the server relays, in KB (a session description is a few); 0 turns the check off |
| `HOLLER_MAX_PENDING_SIGNALS` | 500 | signals from one person that may wait unread on a call; past it the server answers 429; 0 turns the check off |

## Pruning

Signals are deleted once the other side's window has read them, and all of them when their call ends. `holler:prune` does the rest: it ends the calls everybody walked away from (nobody polls them any more, so nothing else would) and deletes ended calls older than `HOLLER_PRUNE_AFTER_DAYS` (90), with their participants. `HOLLER_PRUNE_AFTER_DAYS=null` keeps the call log forever.

```php
// routes/console.php
Schedule::command('holler:prune')->daily();
```

`--days=30` overrides the config for one run.

## Guards and databases

| Key | Default | |
| --- | --- | --- |
| `guard` | null | the guard people sign in with where calls happen; null for the default guard. The routes sit behind `auth:<guard>` and users are looked up through it |
| `connection` (`HOLLER_DB_CONNECTION`) | null | where the calls tables live; null for the default connection |

Calls are kept apart by guard: user 7 of `admin` is never rung for user 7 of `web`, even when both sign in to the same app. Under a database per tenant, set `HOLLER_DB_CONNECTION` to the central connection, so a call between two places meets in one table.

## Events and the call log

| Event | Carries | Fires when |
| --- | --- | --- |
| `CallStarted` | `call` | a call starts ringing |
| `CallAnswered` | `call` | the first person answers and the call becomes active |
| `ParticipantJoined` | `call`, `participant` | anyone answers |
| `ParticipantLeft` | `call`, `participant` | anyone hangs up, declines, is missed or is dropped |
| `CallEnded` | `call` | the call ends, for whatever reason |

The events live in `Packstub\Holler\Events`. A call log from `CallEnded`:

```php
use Packstub\Holler\Events\CallEnded;

Event::listen(CallEnded::class, function (CallEnded $event) {
    $event->call->caller_id;     // user ids as strings, so integer and UUID keys both fit
    $event->call->callee_id;     // the first person called
    $event->call->participants;  // everyone, with seat, status (joined, left, declined, missed, dropped) and times
    $event->call->end_reason;    // declined | cancelled | missed | hung_up | dropped, or what your code passed to Holler::end()
    $event->call->duration();    // seconds, null if never answered
});
```

`Packstub\Holler\Models\Call` is a plain Eloquent model over the `calls` table (with `CallParticipant` and `CallSignal` beside it), ready for a page or a report of your own.
