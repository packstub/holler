# Calls for Laravel

![Calls for Laravel: a group video call in a floating window over a People page with a Call button on every row](https://raw.githubusercontent.com/packstub/art/main/calls/banner.jpg)

[![Latest Version on Packagist](https://img.shields.io/packagist/v/packstub/calls.svg?style=flat-square)](https://packagist.org/packages/packstub/calls)
[![Tests](https://img.shields.io/github/actions/workflow/status/packstub/calls/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/packstub/calls/actions/workflows/tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/packstub/calls.svg?style=flat-square)](https://packagist.org/packages/packstub/calls)
[![License](https://img.shields.io/packagist/l/packstub/calls.svg?style=flat-square)](https://github.com/packstub/calls/blob/main/LICENSE.md)
[![Sponsor](https://img.shields.io/badge/sponsor-%E2%9D%A4-ea4aaa?style=flat-square&logo=githubsponsors&logoColor=white)](https://github.com/sponsors/icaliman)

Audio, video and screen-share calls between the users of a Laravel app, one on one or in small groups, peer to peer over WebRTC. The media goes straight from one browser to the other; Laravel handles who calls whom and relays the few signaling messages the browsers need to find each other. It all runs over plain HTTP polling, so there is no websocket server to run.

![Ion calls Ana from her row on the Team page, she answers, and the call window shows her video with his in the corner](https://raw.githubusercontent.com/packstub/art/main/filament-calls/docs/calls.gif)

<sub>Shown in a Filament panel with [Filament Calls](https://github.com/packstub/filament-calls). Without Filament, the same call runs on any page through `<x-calls::widget />` and `<x-calls::button />`.</sub>

## Features

- **[Calls on any page](#the-widget)**: a widget in your layout and a button next to a user; nothing to publish or bundle.
- **[Group calls](#group-calls)**: ring several people at once or add someone to a running call, up to four by default.
- **[Calls that survive a page load](#the-widget)**: each call in a small window of its own, or in the page; a reload reconnects in seconds.
- **[Who may call whom](#who-may-call-whom)**: one closure, checked on the server for every pair of people on a call.
- **[No websocket server](#configuration)**: signaling over polled HTTP through three tables; the media goes browser to browser.
- **[STUN and TURN](#stun-and-turn)**: STUN out of the box, your own TURN relay through env variables or fresh credentials per call.
- **[The service without HTTP](#the-calls-service)**: `Calls::start()`, `answer()`, `invite()` for your own endpoints, guests and call kinds.
- **[A call log](#events-and-the-call-log)**: events for every step and a `Call` model with participants and durations.

## Compatibility

| Calls | Laravel | PHP |
| --- | --- | --- |
| 1.x | 12.69+, 13.30+ | 8.3+ |

Calls run in every current browser (WebRTC). The app must be served over HTTPS, or from `localhost`, since the camera and microphone need a secure context.

## Installation

```bash
composer require packstub/calls
php artisan migrate
```

Register the routes in `routes/web.php`:

```php
use Packstub\Calls\Facades\Calls;

Calls::routes();   // /calls/… behind `auth`, named calls.*
```

Put the widget in your layout, once, and a button wherever there is someone to call:

```blade
<x-calls::widget />

<x-calls::button :user="$user" />
```

And schedule the cleanup:

```php
Schedule::command('calls:prune')->daily();
```

Read more: [Installation](https://packstub.dev/docs/calls/installation), including your own prefix, middleware and route names.

## The widget

The widget draws the incoming-call toast, the call window (a tile per person, your own picture, mute, camera, screen share, hang up) and short notices. Each call opens in a small window of its own, so the page can navigate, reload or close while the call goes on; `CALLS_POP_OUT=false` keeps the call in the page instead, which suits a single-page app.

It is the `CallWidget` of [`@packstub/calls`](https://github.com/packstub/calls-js), plain ES modules served by the package. Restyle it with a few CSS variables, translate its texts, swap its markup, or use the engine on its own under your own UI:

```css
.calls { --calls-accent: #d97706; --calls-radius: 0.75rem; }
```

Read more: [The widget](https://packstub.dev/docs/calls/the-widget).

## Who may call whom

By default any two different users of the guard can call each other. Narrow it from a service provider's `boot()` method:

```php
use Packstub\Calls\AppContext;

app(AppContext::class)
    ->canCallUsing(fn (User $caller, User $callee): bool => $caller->team_id === $callee->team_id)
    ->userAvatarUsing(fn (User $user): ?string => $user->avatar_url);
```

The check runs on the server when a call starts and when someone is added to one. On a group call it runs for every pair, so a group never puts together two people your rule keeps apart.

Read more: [Calling](https://packstub.dev/docs/calls/calling#who-may-call-whom).

## Group calls

On a call, the same Call button adds that person to it. Everyone has a seat in the order they were invited, the browsers connect to each other directly (a full mesh, no media server), and someone who hangs up leaves the call for the others. Four people is the default limit, which is where a mesh stops being comfortable on ordinary connections:

```dotenv
CALLS_MAX_PARTICIPANTS=4
```

Read more: [Group calls](https://packstub.dev/docs/calls/calling#group-calls).

## STUN and TURN

Most calls connect directly with the default STUN server. Calls behind strict NATs or corporate firewalls need a TURN relay; point the package at yours:

```dotenv
CALLS_TURN_URL=turn:turn.example.com:3478
CALLS_TURN_USERNAME=...
CALLS_TURN_CREDENTIAL=...
```

Or hand out short-lived credentials with `iceServersUsing()`: the browsers ask for them each time a call starts. `CALLS_ICE_TRANSPORT_POLICY=relay` sends everything through the relay, so nobody sees anybody else's network address.

Read more: [ICE servers](https://packstub.dev/docs/calls/configuration#ice-servers-stun--turn).

## The Calls service

The HTTP endpoints are thin wrappers over the `Calls` service, so your own code can do the same work without HTTP:

```php
$call = Calls::start($caller, [$ana, $mihai]);
Calls::invite($call, (string) $caller->id, $dana);
Calls::hangup($call, (string) $caller->id);
```

A package with a UI of its own can put a guest on a call (`new Participant('guest:'.$id, 'Visitor')`) and give its calls a `kind` the widget never sees.

Read more: [The Calls service](https://packstub.dev/docs/calls/calling#the-calls-service), [Guests and call kinds](https://packstub.dev/docs/calls/calling#guests-and-call-kinds), [Endpoints](https://packstub.dev/docs/calls/endpoints).

## Events and the call log

`CallStarted`, `CallAnswered`, `ParticipantJoined`, `ParticipantLeft` and `CallEnded` fire for every step, and `Packstub\Calls\Models\Call` is a plain Eloquent model with its participants, `end_reason` and `duration()`.

Read more: [Events and the call log](https://packstub.dev/docs/calls/configuration#events-and-the-call-log).

## Configuration

Every setting reads an env variable; publish `config/packstub-calls.php` (`php artisan vendor:publish --tag=calls-config`) only to edit it.

```dotenv
CALLS_POLL_IDLE=5000              # ms between polls without a call
CALLS_POLL_ACTIVE=1000            # ms between polls during a call
CALLS_RING_TIMEOUT=45             # seconds before someone is marked missed
CALLS_RECONNECT_TIMEOUT=15        # seconds a side may be away before it is dropped
CALLS_MAX_PARTICIPANTS=4
CALLS_VIDEO=true                  # false: voice calls
CALLS_POP_OUT=true                # each call in a window of its own
CALLS_PRUNE_AFTER_DAYS=90
CALLS_DB_CONNECTION=              # the central connection under a database per tenant
```

Read more: [Configuration](https://packstub.dev/docs/calls/configuration).

## Documentation

Full docs at [packstub.dev/docs/calls](https://packstub.dev/docs/calls):

- [Installation](https://packstub.dev/docs/calls/installation)
- [Calling](https://packstub.dev/docs/calls/calling): who may call whom, group calls, voice calls, the `Calls` service, guests and call kinds, your own context
- [The widget](https://packstub.dev/docs/calls/the-widget): attributes, scripting, the pop-out window, styles, texts, your own markup
- [Endpoints](https://packstub.dev/docs/calls/endpoints): the JSON routes, errors and the call as a client sees it
- [Configuration](https://packstub.dev/docs/calls/configuration): ICE servers, polling, limits, pruning, guards, events

In a Filament panel, [Filament Calls](https://github.com/packstub/filament-calls) puts the same calls behind a `CallAction` and a widget drawn with Filament's components.

## Testing

```bash
composer test
```

## Changelog

See [CHANGELOG.md](https://github.com/packstub/calls/blob/main/CHANGELOG.md).

## Security

If you discover a security issue, email [security@packstub.dev](mailto:security@packstub.dev) instead of using the issue tracker. See [SECURITY.md](https://github.com/packstub/calls/blob/main/.github/SECURITY.md).

## Credits

- [Ion Caliman](https://github.com/icaliman)
- [All contributors](https://github.com/packstub/calls/contributors)

## License

MIT. See [LICENSE.md](https://github.com/packstub/calls/blob/main/LICENSE.md).
