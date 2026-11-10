# The widget

`<x-calls::widget />` draws everything a call needs on a plain Laravel page: the incoming-call toast with Accept and Decline, the call window (a tile per other person, your own picture in a corner, mute, camera, screen share and hang up) and short notices such as "Call declined". Put it once in your layout, after `Calls::routes()` is registered.

The widget is the `CallWidget` from [`@packstub/calls`](https://github.com/packstub/calls-js), running over its `CallClient` engine. Both are plain ES modules without a framework, served by the package from `packstub-calls/assets/`, so there is nothing to publish or bundle.

## Attributes

| Attribute | Default | Does |
| --- | --- | --- |
| `routes` | `calls.` | the route name prefix given to `Calls::routes()` |
| `pop-out` | `CALLS_POP_OUT` (true) | open each call in a window of its own, with a chip on the page |
| `styles` | `true` | load the package stylesheet; `false` to bring your own |
| `options` | `[]` | more `CallWidget` options, merged over the ones the component builds: `labels`, `video`, `windowFeatures`, `callSelector` and the rest |
| `name` | `calls` | the global the widget is kept in, `window.calls`, for your own scripts |

```blade
<x-calls::widget routes="team.calls." :pop-out="false" :options="['windowFeatures' => 'popup=yes,width=640,height=480']" />
```

## Call buttons

Any element with `data-calls-call="<user id>"` is a call button: a click rings that person, or adds them to the call you are on. `<x-calls::button>` is a shortcut for it:

```blade
<x-calls::button :user="$user" />
<x-calls::button :user="$user->id" class="btn btn-sm">Call {{ $user->first_name }}</x-calls::button>
```

It takes a user or an id, merges your attributes and slot (the label is "Call" without one), and renders nothing for the signed-in person. It does not ask whether the call is allowed; the server does when it is clicked.

## Scripting

The widget is kept in `window.calls` (or the `name` you gave it). `callUser(id)`, `accept()`, `decline()`, `hangUp()`, `toggleMute()`, `toggleCamera()` and `toggleShare()` do the same as its buttons:

```js
window.calls.callUser(5)
```

`calls:ready` fires on `window` with `{ widget }` once the widget is built. After that, every event of the engine bubbles from the widget as `calls:<type>` (`statechange`, `incoming`, `peers`, `peerstate`, `ended` and others), plus `calls:sharing` with `{ peer, sharing, spotlight }` when someone's screen takes the stage:

```js
document.addEventListener('calls:ended', (event) => console.log('call ended', event.detail))
```

## The pop-out window

A browser closes a WebRTC connection on every full page load, so by default each call opens in a small browser window of its own. The page shows a chip with the people on the call and the timer, a button to bring the window back and one to hang up, and is free to navigate, reload or close.

- Reloading the pop-out reconnects the call. Closing it hands the call back to the page, which continues it there.
- If the browser blocks the pop-out, the call opens in the page instead, with a short notice.
- The pop-out's address names the call (`/calls/window?call=12`) or the person to ring (`?callee=5`), but an address alone never rings anyone or answers a call. The page that opens the window leaves a one-time token in `localStorage` (valid for 60 seconds, read once), and the pop-out acts only when it finds it. Opened from a link, the page only shows the call.
- The pop-out page is never shown inside another site's frame.

`CALLS_POP_OUT=false` (or `:pop-out="false"`) keeps the call in the page, which suits a single-page app. A full page load then reconnects the call: the other side sees "Reconnecting…" for a few seconds, then the video is back. When one side stays away longer than `CALLS_RECONNECT_TIMEOUT` (15 seconds), it is dropped from the call.

## Restyling

Every class is `calls-*`, and the colours and sizes are custom properties on `.calls`:

```css
.calls {
    --calls-accent: #d97706;
    --calls-bg: #1c1917;
    --calls-radius: 0.75rem;
    --calls-width: 28rem;
}
```

The others are `--calls-fg`, `--calls-dim`, `--calls-stage`, `--calls-tile`, `--calls-control`, `--calls-danger`, `--calls-success` (each with a `-hover` where it has one), `--calls-z` and `--calls-font`. When a few variables are not enough, set `:styles="false"` and write your own stylesheet against the class names.

## Texts and translations

The widget's labels ship in English. Publish them to change a text or add a language:

```bash
php artisan vendor:publish --tag=calls-translations
```

They land in `lang/vendor/calls/en/ui.php` (the widget) and `messages.php` (the server's messages, such as "This person is on another call."); copy them to `lang/vendor/calls/<locale>/` for another language, and the app's locale picks them up. The widget gets its labels from these files. `options` can set them too, but a `labels` array passed there replaces all the translated ones, so change texts in the files.

## Your own markup

The widget renders five views from template functions: `incoming` (the toast), `chip`, `window`, `tile` (one per other participant) and `notice`. Each is rendered once when it appears and kept current through a few `data-calls-*` hooks: `text`, `show`, `class`, `attr`, `src`, `video` and `action`. Pass in your own markup and keep whichever hooks you need. Templates are functions, so they cannot come through a Blade attribute; set them from a script:

```blade
<x-calls::widget :options="['startPolling' => false]" />

<script type="module">
    window.addEventListener('calls:ready', ({ detail: { widget } }) => {
        widget.templates.incoming = (v) => `
            <div class="my-toast" data-calls-scope>
                <b data-calls-text="peerName"></b> ${v.labels.incoming}
                <button data-calls-action="accept">${v.labels.accept}</button>
                <button data-calls-action="decline">${v.labels.decline}</button>
            </div>`
        widget.start()
    })
</script>
```

`startPolling: false` keeps the widget idle until `widget.start()`, so the template is in place before the first call can ring. The view model each template and hook reads is documented in the [`@packstub/calls` README](https://github.com/packstub/calls-js#the-widget), together with subclassing `CallWidget` for deeper changes.

## Without the widget

For a UI of your own or a component in your frontend framework, use the engine on its own:

```js
import { CallClient, HttpSignaling } from '{{ Calls::asset('index.js') }}'
```

Or `npm install @packstub/calls`. Give it the nine routes, the CSRF token, `Calls::rtcConfiguration()`, `Calls::context()->hasVideo()` and `Calls::maxParticipants()`. Its README documents the events (`incoming`, `localstream`, `remotestream`, `peers`, `statechange`, `ended`) and the controls (`callUser`, `invite`, `accept`, `decline`, `hangUp`, `setMuted`, `setCameraOff`, `shareScreen`). The routes and their JSON are in [Endpoints](endpoints.md).
