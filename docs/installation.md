# Installation

## Requirements

| | |
| --- | --- |
| PHP | 8.3 or newer |
| Laravel | 12.69+ or 13.30+ |
| Browsers | any current one (WebRTC); the app served over HTTPS, or `localhost`, since the camera and microphone need a secure context |

## Install

```bash
composer require packstub/calls
php artisan migrate
```

The migration (the `calls`, `call_participants` and `call_signals` tables) runs from the package; there is nothing to publish. The config file is optional, since most of its keys read a `CALLS_*` env variable. Publish it when you want to edit it:

```bash
php artisan vendor:publish --tag=calls-config
```

## Register the routes

In `routes/web.php`:

```php
use Packstub\Calls\Facades\Calls;

Calls::routes();
```

That registers the signaling endpoints under `/calls/`, named `calls.*`, behind `auth` (or `auth:<guard>` when the config names a [guard](configuration.md#guards-and-databases)). Every argument is optional:

```php
Calls::routes(prefix: 'team/calls', middleware: ['auth', 'verified'], as: 'team.calls.');
```

- `middleware` replaces the default `auth`, so include it in your list.
- Keep the routes inside the `web` middleware group, as everything in `routes/web.php` is: they read the signed-in user from the session and check the CSRF token the widget sends.
- Keep the prefix ending in `calls`. Signals are relayed byte for byte (a session description ends in a line break, Firefox's last ICE candidate is an empty string), so the package turns off `TrimStrings` and `ConvertEmptyStringsToNull` for `*calls/*/signals`. With a prefix that ends differently, register the same exception yourself.
- `window: false` leaves out the widget's pop-out page (`GET {prefix}/window`), for a package that draws a window of its own.

The full list of routes is in [Endpoints](endpoints.md).

## Add the widget and a button

Put the widget in your layout, once, and a button wherever there is someone to call:

```blade
{{-- resources/views/layouts/app.blade.php, before </body> --}}
<x-calls::widget />

{{-- anywhere a signed-in person can call another --}}
<x-calls::button :user="$user" />
```

That is the whole setup. The widget renders nothing for someone who is not signed in, so a layout shared with the login page is fine, and the button renders nothing for the signed-in person themselves. The stylesheet and the ES modules are served by the package: no bundler, nothing to publish.

If you registered the routes with your own `as:` name, tell the widget: `<x-calls::widget routes="team.calls." />`. More in [The widget](the-widget.md).

## Schedule the cleanup

`calls:prune` ends the calls everybody walked away from and deletes ended calls older than `CALLS_PRUNE_AFTER_DAYS` (90 days). Run it daily:

```php
// routes/console.php
Schedule::command('calls:prune')->daily();
```

## Try it on one machine

Sign in as two different people on two hostnames, for example `localhost` and `127.0.0.1`: cookies are per host, so one browser holds both sessions, and both count as secure contexts for the camera.
