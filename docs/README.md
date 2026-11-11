# Holler for Laravel

Audio, video and screen-share calls between the users of a Laravel app, one on one or in small groups, peer to peer over WebRTC. The media goes straight from one browser to the other; Laravel handles who calls whom and relays the few signaling messages the browsers need to find each other, over plain HTTP polling, so there is no websocket server to run. Free and open source (MIT).

- Repository: [github.com/packstub/holler](https://github.com/packstub/holler)
- Packagist: [packstub/holler](https://packagist.org/packages/packstub/holler)
- Support: [GitHub issues](https://github.com/packstub/holler/issues)
- Browser side: [`@packstub/holler`](https://github.com/packstub/holler-js), served by this package
- In a Filament panel: [Filament Holler](https://packstub.dev/docs/filament-holler), built on this package

![Ion calls Ana from her row on the Team page, she answers, and the call window shows her video with his in the corner](https://raw.githubusercontent.com/packstub/art/main/filament-holler/docs/calls.gif)

## Features

- **[Calls on any page](the-widget.md)**: `<x-holler::widget />` in your layout and `<x-holler::button />` next to a user; nothing to publish or bundle.
- **[Group calls](calling.md#group-calls)**: ring several people at once or add someone to a running call, up to four by default.
- **[Calls that survive a page load](the-widget.md#the-pop-out-window)**: each call in a small window of its own, or in the page; a reload reconnects in seconds.
- **[Who may call whom](calling.md#who-may-call-whom)**: one closure, checked on the server for every pair of people on a call.
- **[No websocket server](configuration.md#polling-and-timeouts)**: signaling over polled HTTP through three tables; the media goes browser to browser.
- **[STUN and TURN](configuration.md#ice-servers-stun--turn)**: STUN out of the box, your own TURN relay through env variables or fresh credentials per call.
- **[The service without HTTP](calling.md#the-holler-service)**: `Holler::start()`, `answer()`, `invite()` for your own endpoints, guests and call kinds.
- **[A call log](configuration.md#events-and-the-call-log)**: events for every step and a `Call` model with participants and durations.

## Guides

| Guide | What it covers |
| --- | --- |
| [Installation](installation.md) | Requirements, install, the routes, the widget and the button, the cleanup command |
| [Calling](calling.md) | Who may call whom, names and avatars, group calls, voice calls, the `Holler` service, guests and call kinds, your own context |
| [The widget](the-widget.md) | Attributes, scripting, the pop-out window, styles, texts, your own markup, the engine without the widget |
| [Endpoints](endpoints.md) | The JSON routes, errors, the call as a client sees it, windows and reconnecting |
| [Configuration](configuration.md) | ICE servers, polling and timeouts, limits, pruning, guards and databases, events and the call log |
