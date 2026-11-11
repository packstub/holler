# Calling

The settings on this page live on `Packstub\Calls\AppContext`, the context the package uses in a plain Laravel app. Set them from a service provider's `boot()` method; each one returns the context, so they chain.

## Who may call whom

By default any two different users of the guard can call each other. Narrow it with a closure:

```php
use Packstub\Calls\AppContext;

app(AppContext::class)->canCallUsing(
    fn (User $caller, User $callee): bool => $caller->team_id === $callee->team_id
);
```

The check runs on the server, whatever the browser asked for: when a call starts and when someone is added to one. Calling yourself is refused before the closure runs.

On a group call it runs for every pair. When a call starts, each callee is checked with the caller and with each other callee; when someone is added, they are checked with everyone already on the call. So a group never puts together two people your rule keeps apart, even when the caller may call both.

## How people appear

The other side sees a name and, if you give one, an avatar:

```php
app(AppContext::class)
    ->userNameUsing(fn (User $user): string => $user->display_name)
    ->userAvatarUsing(fn (User $user): ?string => $user->avatar_url);
```

Without these, the name is the user's `name`, then their `email`, and there is no avatar (the widget shows initials). Someone who cannot be found is shown as "Someone".

## Group calls

Everyone on a call has a seat, in the order they were invited, the caller first. The browsers connect to each other directly, in a full mesh: between two participants the lower seat makes the WebRTC offer. There is no media server.

- From the widget, a Call button rings one person; on a call, the same button adds that person to it (`POST calls/{id}/invite`).
- To ring several people at once, pass a list: `callUser([anaId, mihaiId])` on the engine's `CallClient`, or `Calls::start($caller, [$ana, $mihai])` on the server.
- Someone who hangs up leaves the call for the others. The call ends once fewer than two people are left on it, or nobody who answered. The caller hanging up before anyone answered cancels the call for everyone.
- Someone who does not answer within `CALLS_RING_TIMEOUT` is marked missed, and someone whose window stays away longer than `CALLS_RECONNECT_TIMEOUT` is marked dropped. Either way the call goes on for the others while two or more are left.

Four people is the default limit, the caller included:

```dotenv
CALLS_MAX_PARTICIPANTS=4
```

Or per request, for example from a plan:

```php
app(AppContext::class)->maxParticipantsUsing(fn (): int => auth()->user()->plan->call_seats);
```

In a mesh each browser uploads its media once per other participant, and four people is where that stops being comfortable on ordinary connections. Raise the limit only with a browser topology that relays through a media server (the `topology` option of [`@packstub/calls`](https://github.com/packstub/calls-js)). The limit never goes below two.

## Voice calls

```php
app(AppContext::class)->video(false);   // or CALLS_VIDEO=false
```

Calls then start with the microphone only. Either side can still share their screen. `video()` also takes a closure, for a rule per user.

## The Calls service

The HTTP endpoints are thin wrappers over the `Calls` service, so your own code can do the same work without HTTP: in your own endpoints, a job, or a test.

```php
use Packstub\Calls\Facades\Calls;

$call = Calls::start($caller, $callee);           // one user, a user id, or a list of them
Calls::invite($call, $byUserId, $newcomer);       // add someone; the inviter must have joined
Calls::answer($call, $userId, $tab);              // someone rung joins
Calls::decline($call, $userId);                   // someone rung says no
Calls::hangup($call, $userId);                    // leave the call
Calls::end($call, 'moderated');                   // end it for everyone, with a reason of your own
```

Each method checks the same rules as the routes and throws a `Packstub\Calls\Exceptions\CallException` when something is not allowed:

| Exception | When | HTTP status |
| --- | --- | --- |
| `CallNotAllowed` | yourself, nobody, someone the context says no to, or someone not on the call | 422 |
| `CallFull` | more people than `max_participants` | 422 |
| `CalleeBusy` | the person is on another call | 409 |
| `CallNotRinging` | answering a call that is no longer ringing you | 409 |
| `CallHasEnded` | a signal or an invite for an ended call | 410 |
| `SignalTooLarge` | a signal above `max_signal_kb` | 413 |
| `TooManySignals` | too many of one person's signals unread | 429 |

Starting a call leaves any call the caller is still on, once every check has passed: a refused call leaves the current one alone. `Calls` is macroable, so a package can add helpers of its own.

## Guests and call kinds

A package with a UI of its own (a support tool that calls a visitor of the site, say) can put someone on a call who is not a user of the guard:

```php
use Packstub\Calls\Facades\Calls;
use Packstub\Calls\Participant;

$guest = new Participant('guest:'.$visitorId, 'Visitor on /checkout');

$call = Calls::start($agent, $guest, kind: 'assist');
Calls::answer($call, $guest->id, $tab);
Calls::poll($guest->id, $after, $call->id, $tab, hold: true, kind: 'assist');
```

- A `Participant` has an id with a prefix and a colon (`guest:<uuid>`, up to 64 characters), a name and an optional avatar URL, stored on the call. The context is not asked about it: the code that created it has already decided.
- An id with a colon is never looked up as a user, so nobody can ring a guest from the HTTP routes. A guest also cannot bring anybody into a call, and nobody can be added to a call a guest is on.
- The `kind` (1 to 16 of `a-z`, `0-9`, `_` and `-`; `call` by default) keeps such calls away from the widget, which only polls for `call`.
- Your own routes authenticate the guest; the package's routes stay behind `auth`.

When the request that sets up the call is not where its users poll from (a visitor's request ringing an agent who signs in through an `admin` guard), name the users' scope and pass users or `Participant`s rather than ids:

```php
Calls::start($agent, $guest, kind: 'assist', scope: 'admin');
```

Everyone's name and avatar are stored as they join, so a guest sees those rather than names looked up in the wrong user table.

## Your own context

`AppContext` reads the guard from the config. When the signed-in user comes from somewhere the config cannot name (a multi-tenant app, an API token), implement `Packstub\Calls\Contracts\CallContext`:

| Method | Answers |
| --- | --- |
| `scope()` | whose ids these are, the guard's name in `AppContext`; calls are kept apart by scope |
| `currentUserId()` | the signed-in user's id as a string, or null |
| `findUser($id)` | the user with this id |
| `who($user)` | `['id' => ..., 'name' => ..., 'avatar' => ...]` as the other side sees them |
| `canCall($caller, $callee)` | whether the caller may ring the callee |
| `iceServers()` | the STUN and TURN servers, passed to `RTCPeerConnection` as they are |
| `hasVideo()` | whether calls start with the camera on |
| `maxParticipants()` | people on one call, the caller included |

An optional `findUsers(array $ids): array` names a whole roster in one query (`AppContext` has one); without it, people are looked up one by one. Bind your context in a service provider's `register()` method:

```php
$this->app->bind(\Packstub\Calls\Contracts\CallContext::class, MyCallContext::class);
```

The package binds `AppContext` only when nothing else is bound. [Filament Calls](https://packstub.dev/docs/filament-calls) binds a context of its own that answers from the current panel.
