# Configuration

Everything lives in `config/holler.php`. Most keys read a `HOLLER_*` env variable, so few apps need to publish the file. When you want to edit it:

```bash
php artisan vendor:publish --tag=holler-config
```

The settings that take a closure (who may call whom, names, avatars, ICE servers, video, the group size) are on `AppContext`, covered in [Calling](calling.md).

## ICE servers (STUN / TURN)

Two browsers need help to find a path to each other. A **STUN** server tells each one its public address, and for most calls that is enough: the media then goes straight from browser to browser. The package uses Google's public STUN server out of the box, free and without an account, so calls work right after the install:

```dotenv
HOLLER_STUN_URL=stun:stun.l.google.com:19302
```

Any other STUN server works the same way (`stun:stun.cloudflare.com:3478`, or your own), several can be listed with commas, and an empty value uses none. Public STUN servers come without a guarantee, so a production app that depends on calls usually runs its own beside the TURN server below (coturn does both).

Behind a strict NAT, a VPN or a corporate firewall no direct path exists, and the call needs a **TURN** relay that forwards the media. This is a minority of calls, but for the people on those networks it is every call. Point the package at a relay, your own [coturn](https://github.com/coturn/coturn) or a hosted service:

```dotenv
HOLLER_TURN_URL=turn:turn.example.com:3478,turns:turn.example.com:443?transport=tcp
HOLLER_TURN_SECRET=the-secret-coturn-shares-with-the-app
```

`HOLLER_TURN_URL` takes several addresses separated by commas. Listing a `turns:` address on port 443 over TCP beside the plain one is what gets a call through networks that only let web traffic out.

With `HOLLER_TURN_SECRET` every call gets a username and a credential that expire (after `HOLLER_TURN_TTL` seconds, 3600 by default), signed with the secret the TURN server shares with the app, so no long-lived password ever reaches a browser. It is coturn's `use-auth-secret` scheme:

```ini
# /etc/turnserver.conf
listening-port=3478
tls-listening-port=5349
realm=turn.example.com
use-auth-secret
static-auth-secret=the-secret-coturn-shares-with-the-app
cert=/etc/letsencrypt/live/turn.example.com/fullchain.pem
pkey=/etc/letsencrypt/live/turn.example.com/privkey.pem
```

A server with one fixed login takes `HOLLER_TURN_USERNAME` and `HOLLER_TURN_CREDENTIAL` instead of the secret.

A hosted TURN service (Cloudflare Realtime, Twilio, Metered and others) hands out short-lived credentials through its API. Ask for them in a closure and return what the browser should use:

```php
app(AppContext::class)->iceServersUsing(fn (): array => [
    ['urls' => 'stun:stun.example.com:3478'],
    ...Http::withToken(config('services.turn.token'))
        ->post(config('services.turn.url'), ['ttl' => 3600])
        ->throw()
        ->json('iceServers'),
]);
```

The browsers ask for the servers each time a call starts (the `ice-servers` route), never when the page loads: the closure runs per call, the credentials never sit in the page's HTML, and a tab left open all day still gets fresh ones. Any server in that list may carry `secret` (and `ttl`) instead of a username and credential, and is signed the same way; `Holler::turnCredentials($secret, $ttl)` returns the pair on its own.

ICE candidates show each side's network addresses to the other. To keep them private, send everything through your TURN server:

```dotenv
HOLLER_ICE_TRANSPORT_POLICY=relay
```

A TURN relay forwards packets it cannot decrypt (WebRTC media is encrypted end to end with DTLS-SRTP).

### When a call does not connect

A call that rings and is answered but never shows the other person ends after `HOLLER_RECONNECT_TIMEOUT` with "Could not connect" on both sides. When no TURN server is configured, the browser console says so too: that is nearly always the reason, since one of the two people is on a network that allows no direct path. Add a TURN server, with a `turns:` address on port 443 for the strictest networks. To check a TURN server on its own, set `HOLLER_ICE_TRANSPORT_POLICY=relay` for a moment: every call then has to go through it, also between two tabs on your own machine.

A call that connected and then ends with "Connection lost" had a path and lost it (a laptop that went to sleep, a network that dropped) for longer than the timeout. During a call a "Weak connection" badge on someone's picture means many packets to or from them are being lost or arrive late.

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

`Packstub\Holler\Models\Call` is a plain Eloquent model over the `holler_calls` table (with `CallParticipant` and `CallSignal` beside it), ready for a page or a report of your own.
