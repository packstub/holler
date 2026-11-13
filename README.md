# Holler for Laravel

![Holler for Laravel: a group video call in a floating window over a People page with a Call button on every row](https://raw.githubusercontent.com/packstub/art/main/holler/banner.jpg)

[![Latest Version on Packagist](https://img.shields.io/packagist/v/packstub/holler.svg?style=flat-square)](https://packagist.org/packages/packstub/holler)
[![Tests](https://img.shields.io/github/actions/workflow/status/packstub/holler/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/packstub/holler/actions/workflows/tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/packstub/holler.svg?style=flat-square)](https://packagist.org/packages/packstub/holler)
[![License](https://img.shields.io/packagist/l/packstub/holler.svg?style=flat-square)](https://github.com/packstub/holler/blob/main/LICENSE.md)
[![Sponsor](https://img.shields.io/badge/sponsor-%E2%9D%A4-ea4aaa?style=flat-square&logo=githubsponsors&logoColor=white)](https://github.com/sponsors/icaliman)

Audio, video and screen-share calls between the users of a Laravel app, one on one or in small groups, peer to peer over WebRTC. The media goes straight from one browser to the other; Laravel handles who calls whom and relays the few signaling messages the browsers need to find each other. It all runs over plain HTTP polling, so there is no websocket server to run.

![Ion calls Ana from her row on the Team page, she answers, and the call window shows her video with his in the corner](https://raw.githubusercontent.com/packstub/art/main/filament-holler/docs/calls.gif)

<sub>Shown in a Filament panel with [Filament Holler](https://github.com/packstub/filament-holler). Without Filament, the same call runs on any page through `<x-holler::widget />` and `<x-holler::button />`.</sub>

## Features

- **[Calls on any page](#the-widget)**: a widget in your layout and a button next to a user; nothing to publish or bundle.
- **[Group calls](#group-calls)**: ring several people at once or add someone to a running call from a search box, up to four by default.
- **[Calls on hold](#calls-on-hold)**: step out of a call, ring someone else and swap back; the others stay on the call.
- **[Calls that survive a page load](#the-widget)**: each call in a small window of its own, or in the page; a reload reconnects in seconds.
- **[Who may call whom](#who-may-call-whom)**: one closure, checked on the server for every pair of people on a call.
- **[No websocket server](#configuration)**: signaling over polled HTTP through three tables; the media goes browser to browser.
- **[STUN and TURN](#stun-and-turn)**: a free public STUN server out of the box, your own TURN relay through env variables, with credentials that expire per call.
- **[Devices and notifications](#the-widget)**: people choose their microphone, camera and speaker mid-call, and a call in a background tab still gets noticed.
- **[The service without HTTP](#the-holler-service)**: `Holler::start()`, `answer()`, `invite()` for your own endpoints, guests and call kinds.
- **[A call log](#events-and-the-call-log)**: events for every step and a `Call` model with participants and durations.

## Compatibility

| Holler | Laravel | PHP |
| --- | --- | --- |
| 1.x | 12.69+, 13.30+ | 8.3+ |

Calls run in every current browser (WebRTC). The app must be served over HTTPS, or from `localhost`, since the camera and microphone need a secure context.

## Installation

```bash
composer require packstub/holler
php artisan migrate
```

Register the routes in `routes/web.php`:

```php
use Packstub\Holler\Facades\Holler;

Holler::routes();   // /calls/… behind `auth`, named calls.*
```

Put the widget in your layout, once, and a button wherever there is someone to call:

```blade
<x-holler::widget />

<x-holler::button :user="$user" />
```

And schedule the cleanup:

```php
Schedule::command('holler:prune')->daily();
```

Read more: [Installation](https://packstub.dev/docs/holler/installation), including your own prefix, middleware and route names.

## The widget

The widget draws the incoming-call toast, the call window (a tile per person, your own picture, mute, camera, screen share, hang up, and settings to choose the microphone, camera and speaker) and short notices. A call that rings while the tab is in the background shows in the tab's title and, once allowed, as a system notification. Each call opens in a small window of its own, so the page can navigate, reload or close while the call goes on; `HOLLER_POP_OUT=false` keeps the call in the page instead, which suits a single-page app.

It is the `CallWidget` of [`@packstub/holler`](https://github.com/packstub/holler-js), plain ES modules served by the package. Restyle it with a few CSS variables, translate its texts, swap its markup, or use the engine on its own under your own UI:

```css
.holler { --holler-accent: #d97706; --holler-radius: 0.75rem; }
```

Read more: [The widget](https://packstub.dev/docs/holler/the-widget).

## Who may call whom

By default any two different users of the guard can call each other. Narrow it from a service provider's `boot()` method:

```php
use Packstub\Holler\AppContext;

app(AppContext::class)
    ->canCallUsing(fn (User $caller, User $callee): bool => $caller->team_id === $callee->team_id)
    ->userAvatarUsing(fn (User $user): ?string => $user->avatar_url);
```

The check runs on the server when a call starts and when someone is added to one. On a group call it runs for every pair, so a group never puts together two people your rule keeps apart.

Read more: [Calling](https://packstub.dev/docs/holler/calling#who-may-call-whom).

## Group calls

On a call, the Add someone button of the call window finds people by name, and a Call button on the page asks whether to add that person, put the call on hold or end it. Everyone has a seat in the order they were invited, the browsers connect to each other directly (a full mesh, no media server), and someone who hangs up leaves the call for the others. Four people is the default limit, which is where a mesh stops being comfortable on ordinary connections:

```dotenv
HOLLER_MAX_PARTICIPANTS=4
```

Read more: [Group calls](https://packstub.dev/docs/holler/calling#group-calls).

## Calls on hold

Put on hold in the call window steps you out of a call without leaving it: your camera and microphone close, the others stay on the call and see that you put it on hold. The call waits in a small bar with Resume and End while you ring someone else, and Resume swaps the two calls. One call on hold at a time; someone who closes every window is let go of the call after `HOLLER_HOLD_TIMEOUT` seconds.

Read more: [Calls on hold](https://packstub.dev/docs/holler/calling#calls-on-hold).

## STUN and TURN

Calls work right after the install: the default is Google's public STUN server, free and without an account (`HOLLER_STUN_URL` takes another one), and most calls then go straight from browser to browser. People on one network (an office, a VPN) need no server at all. People behind a strict NAT, a VPN or a corporate firewall need a TURN relay; point the package at yours:

```dotenv
HOLLER_TURN_URL=turn:turn.example.com:3478,turns:turn.example.com:443?transport=tcp
HOLLER_TURN_SECRET=the-secret-coturn-shares-with-the-app
```

With the secret (coturn's `use-auth-secret`) every call gets credentials that expire, so no long-lived TURN password reaches a browser; a fixed `HOLLER_TURN_USERNAME` and `HOLLER_TURN_CREDENTIAL` work too. For a hosted TURN service, return its credentials from `iceServersUsing()`: the browsers ask for the servers each time a call starts. `HOLLER_ICE_TRANSPORT_POLICY=relay` sends everything through the relay, so nobody sees anybody else's network address. A call that cannot find a path ends with "Could not connect" and a hint in the browser console.

Read more: [ICE servers](https://packstub.dev/docs/holler/configuration#ice-servers-stun--turn).

## The Holler service

The HTTP endpoints are thin wrappers over the `Holler` service, so your own code can do the same work without HTTP:

```php
$call = Holler::start($caller, [$ana, $mihai]);
Holler::invite($call, (string) $caller->id, $dana);
Holler::hangup($call, (string) $caller->id);
```

A package with a UI of its own can put a guest on a call (`new Participant('guest:'.$id, 'Visitor')`) and give its calls a `kind` the widget never sees.

Read more: [The Holler service](https://packstub.dev/docs/holler/calling#the-holler-service), [Guests and call kinds](https://packstub.dev/docs/holler/calling#guests-and-call-kinds), [Endpoints](https://packstub.dev/docs/holler/endpoints).

## Events and the call log

`CallStarted`, `CallAnswered`, `ParticipantJoined`, `ParticipantHeld`, `ParticipantResumed`, `ParticipantLeft` and `CallEnded` fire for every step, and `Packstub\Holler\Models\Call` is a plain Eloquent model with its participants, `end_reason` and `duration()`.

Read more: [Events and the call log](https://packstub.dev/docs/holler/configuration#events-and-the-call-log).

## Configuration

Every setting reads an env variable; publish `config/holler.php` (`php artisan vendor:publish --tag=holler-config`) only to edit it.

```dotenv
HOLLER_POLL_IDLE=5000              # ms between polls without a call
HOLLER_POLL_ACTIVE=1000            # ms between polls during a call
HOLLER_RING_TIMEOUT=45             # seconds before someone is marked missed
HOLLER_RECONNECT_TIMEOUT=15        # seconds a side may be away before it is dropped
HOLLER_HOLD_TIMEOUT=120            # seconds a call stays on hold after its holder closed every window
HOLLER_PEOPLE_SEARCH=true          # the "Add someone" search in the call window
HOLLER_MAX_PARTICIPANTS=4
HOLLER_VIDEO=true                  # false: voice calls
HOLLER_POP_OUT=true                # each call in a window of its own
HOLLER_PRUNE_AFTER_DAYS=90
HOLLER_DB_CONNECTION=              # the central connection under a database per tenant
```

Read more: [Configuration](https://packstub.dev/docs/holler/configuration).

## Documentation

Full docs at [packstub.dev/docs/holler](https://packstub.dev/docs/holler):

- [Installation](https://packstub.dev/docs/holler/installation)
- [Calling](https://packstub.dev/docs/holler/calling): who may call whom, group calls, voice calls, the `Holler` service, guests and call kinds, your own context
- [The widget](https://packstub.dev/docs/holler/the-widget): attributes, scripting, the pop-out window, styles, texts, your own markup
- [Endpoints](https://packstub.dev/docs/holler/endpoints): the JSON routes, errors and the call as a client sees it
- [Configuration](https://packstub.dev/docs/holler/configuration): ICE servers, polling, limits, pruning, guards, events

In a Filament panel, [Filament Holler](https://github.com/packstub/filament-holler) puts the same calls behind a `CallAction` and a widget drawn with Filament's components.

## Testing

```bash
composer test
```

## Changelog

See [CHANGELOG.md](https://github.com/packstub/holler/blob/main/CHANGELOG.md).

## Security

If you discover a security issue, email [security@packstub.dev](mailto:security@packstub.dev) instead of using the issue tracker. See [SECURITY.md](https://github.com/packstub/holler/blob/main/.github/SECURITY.md).

## Credits

- [Ion Caliman](https://github.com/icaliman)
- [All contributors](https://github.com/packstub/holler/contributors)

## License

MIT. See [LICENSE.md](https://github.com/packstub/holler/blob/main/LICENSE.md).
