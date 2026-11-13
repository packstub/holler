# The widget

`<x-holler::widget />` draws everything a call needs on a plain Laravel page: the incoming-call toast with Accept and Decline, the call window (a tile per other person, your own picture in a corner, mute, camera, screen share, settings and hang up) and short notices such as "Call declined". Put it once in your layout, after `Holler::routes()` is registered.

The widget is the `CallWidget` from [`@packstub/holler`](https://github.com/packstub/holler-js), running over its `CallClient` engine. Both are plain ES modules without a framework, served by the package from `holler/assets/`, so there is nothing to publish or bundle.

## Attributes

| Attribute | Default | Does |
| --- | --- | --- |
| `routes` | `holler.` | the route name prefix given to `Holler::routes()` |
| `pop-out` | `HOLLER_POP_OUT` (true) | open each call in a window of its own, with a chip on the page |
| `styles` | `true` | load the package stylesheet; `false` to bring your own |
| `nonce` | `Vite::cspNonce()` | the Content-Security-Policy nonce put on the widget's script and stylesheet tags; set automatically when your app calls `Vite::useCspNonce()` |
| `options` | `[]` | more `CallWidget` options, merged key by key over the ones the component builds: `labels`, `video`, `windowFeatures`, `callSelector` and the rest |
| `name` | `holler` | the global the widget is kept in, `window.holler`, for your own scripts |

```blade
<x-holler::widget routes="team.calls." :pop-out="false" :options="['windowFeatures' => 'popup=yes,width=640,height=480']" />
```

## Call buttons

Any element with `data-holler-call="<user id>"` is a call button: a click rings that person. Clicked during a call, the widget asks what to do with the call you are on: add the person to it, put it on hold and ring them, or end it and ring them. `<x-holler::button>` is a shortcut for it:

```blade
<x-holler::button :user="$user" />
<x-holler::button :user="$user->id" class="btn btn-sm">Call {{ $user->first_name }}</x-holler::button>
```

It takes a user or an id, merges your attributes and slot (the label is "Call" without one), and renders nothing for the signed-in person. Given a user, it also puts their name in `data-holler-name`, which the widget shows with its question (`name="..."` sets it for an id). To add the person at once as before, pass `:options="['duringCall' => 'add']"` to the widget. It does not ask whether the call is allowed; the server does when it is clicked.

## Scripting

The widget is kept in `window.holler` (or the `name` you gave it). `callUser(id)`, `accept()`, `decline()`, `hangUp()`, `toggleMute()`, `toggleCamera()` and `toggleShare()` do the same as its buttons:

```js
window.holler.callUser(5)
```

`holler:ready` fires on `window` with `{ widget }` once the widget is built. After that, every event of the engine bubbles from the widget as `holler:<type>` (`statechange`, `incoming`, `peers`, `peerstate`, `quality`, `devices`, `ended` and others), plus `holler:sharing` with `{ peer, sharing, spotlight }` when someone's screen takes the stage:

```js
document.addEventListener('holler:ended', (event) => console.log('call ended', event.detail))
```

## Microphone, camera and speaker

The cog in the call window opens three menus: the microphone, the camera and, where the browser can choose one (Chrome, Edge and recent Firefox), the speaker. A choice applies at once, for everyone on the call and without the call reconnecting, and is remembered in that browser for the next call; nothing about it is sent to the server. A remembered device that is unplugged later falls back to the system's own. Browsers name the devices only after the person allowed the camera or the microphone, which on a call they have.

`:options="['devices' => false]"` leaves the settings out, and `'storageKey' => null` stops remembering the choice.

## A call in the background

The ring is easy to miss in a tab the person is not looking at, so the widget does two more things while a call rings there:

- The tab's title alternates with "Ana is calling you" until the call is answered, declined or given up, or the person is back on the tab.
- A system notification, once the person allowed notifications for your site. Browsers only show that question after a click, so the widget never asks by itself: the call's settings carry a link, "Notify me about calls when this tab is in the background", and a button of your own can ask at a better moment:

```html
<button type="button" onclick="window.holler.enableNotifications()">Notify me about calls</button>
```

`window.holler.canNotify` says whether there is still something to ask. One notification per call, however many tabs are open, and a click on it brings the tab back. `:options="['notifications' => false]"` turns both off. A notification needs a tab of your app to be open somewhere: the package runs no push service.

## The pop-out window

A browser closes a WebRTC connection on every full page load, so by default each call opens in a small browser window of its own. The page shows a chip with the people on the call and the timer, a button to bring the window back and one to hang up, and is free to navigate, reload or close.

- Reloading the pop-out reconnects the call. Closing it hands the call back to the page, which continues it there.
- If the browser blocks the pop-out, the call opens in the page instead, with a short notice.
- The pop-out's address names the call (`/calls/window?call=12`) or the person to ring (`?callee=5`), but an address alone never rings anyone or answers a call. The page that opens the window leaves a one-time token in `localStorage` (valid for 60 seconds, read once), and the pop-out acts only when it finds it. Opened from a link, the page only shows the call.
- The pop-out page is never shown inside another site's frame.

`HOLLER_POP_OUT=false` (or `:pop-out="false"`) keeps the call in the page, which suits a single-page app. A full page load then reconnects the call: the other side sees "Reconnecting…" for a few seconds, then the video is back. When one side stays away longer than `HOLLER_RECONNECT_TIMEOUT` (15 seconds), it is dropped from the call.

## Restyling

Every class is `holler-*`, and the colours and sizes are custom properties on `.holler`:

```css
.holler {
    --holler-accent: #d97706;
    --holler-bg: #1c1917;
    --holler-radius: 0.75rem;
    --holler-width: 28rem;
}
```

The others are `--holler-fg`, `--holler-dim`, `--holler-stage`, `--holler-tile`, `--holler-control`, `--holler-danger`, `--holler-success` (each with a `-hover` where it has one), `--holler-z` and `--holler-font`. When a few variables are not enough, set `:styles="false"` and write your own stylesheet against the class names.

## Texts and translations

The widget's labels ship in English, German, Romanian and Russian, and follow the app's locale. Publish them to change a text or add a language:

```bash
php artisan vendor:publish --tag=holler-translations
```

They land in `lang/vendor/holler/en/ui.php` (the widget) and `messages.php` (the server's messages, such as "This person is on another call."); copy them to `lang/vendor/holler/<locale>/` for another language, and the app's locale picks them up. The widget gets its labels from these files. `options` can change a few for one page (`:options="['labels' => ['accept' => 'Pick up']]"`); the rest keep their translations.

## Your own markup

The widget renders eight views from template functions: `incoming` (the toast), `chip`, `window`, `tile` (one per other participant), `person` (one per person the search found), `held` (the bar of a call on hold), `prompt` (the question when Call is pressed during a call) and `notice`. Each is rendered once when it appears and kept current through a few `data-holler-*` hooks: `text`, `show`, `class`, `attr`, `src`, `video` and `action`. Pass in your own markup and keep whichever hooks you need. Templates are functions, so they cannot come through a Blade attribute; set them from a script:

```blade
<x-holler::widget :options="['startPolling' => false]" />

<script type="module">
    window.addEventListener('holler:ready', ({ detail: { widget } }) => {
        widget.templates.incoming = (v) => `
            <div class="my-toast" data-holler-scope>
                <b data-holler-text="peerName"></b> ${v.labels.incoming}
                <button data-holler-action="accept">${v.labels.accept}</button>
                <button data-holler-action="decline">${v.labels.decline}</button>
            </div>`
        widget.start()
    })
</script>
```

`startPolling: false` keeps the widget idle until `widget.start()`, so the template is in place before the first call can ring. The view model each template and hook reads is documented in the [`@packstub/holler` README](https://github.com/packstub/holler-js#the-widget), together with subclassing `CallWidget` for deeper changes.

## Without the widget

For a UI of your own or a component in your frontend framework, use the engine on its own:

```js
import { CallClient, HttpSignaling } from '{{ Holler::asset('index.js') }}'
```

Or `npm install @packstub/holler`. Give it the routes, the CSRF token, `Holler::rtcConfiguration()`, `Holler::context()->hasVideo()` and `Holler::maxParticipants()`. Its README documents the events (`incoming`, `localstream`, `remotestream`, `peers`, `statechange`, `ended`) and the controls (`callUser`, `invite`, `search`, `hold`, `resumeHeld`, `accept`, `decline`, `hangUp`, `setMuted`, `setCameraOff`, `shareScreen`, `devices`, `setDevice`). The routes and their JSON are in [Endpoints](endpoints.md).
