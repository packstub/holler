{{--
    The call widget for a plain Laravel page: put it once in your layout, after `Calls::routes()`
    is registered. It loads the stylesheet and the ES modules from the package (no bundler,
    nothing to publish) and mounts the widget at the end of the body.

    Attributes:
      routes   the route name prefix given to Calls::routes() ('calls.')
      pop-out  open each call in a window of its own (config `pop_out`)
      styles   include the package stylesheet (true); false to bring your own
      options  extra CallWidget options, e.g. ['labels' => [...], 'root' => null]; `templates` and
               `icons` cannot be JSON, pass those from a script of your own (see the README)
      name     the global the widget is kept in (window.calls), for your own scripts

    Renders nothing for a visitor who is not signed in, so it can sit in a layout the login page shares.
--}}
@props([
    'routes' => 'calls.',
    'popOut' => null,
    'styles' => true,
    'options' => [],
    'name' => 'calls',
    'inPopOut' => false,
])

@if (\Packstub\Calls\Facades\Calls::currentUserId() !== null)
@php
    $context = \Packstub\Calls\Facades\Calls::context();
    $popOut ??= (bool) config('packstub-calls.pop_out', true);
    // Recursive, so a partial `labels` (or `poll`) keeps the rest: the translations, the configured pace.
    $config = array_replace_recursive([
        'routes' => [
            'poll' => route($routes.'poll'),
            'store' => route($routes.'store'),
            'answer' => route($routes.'answer', ['call' => '__ID__']),
            'decline' => route($routes.'decline', ['call' => '__ID__']),
            'hangup' => route($routes.'hangup', ['call' => '__ID__']),
            'signal' => route($routes.'signal', ['call' => '__ID__']),
            'leave' => route($routes.'leave', ['call' => '__ID__']),
            'invite' => route($routes.'invite', ['call' => '__ID__']),
            'ice' => route($routes.'ice'),
        ],
        'windowUrl' => $popOut || $inPopOut ? route($routes.'window', ['call' => '__ID__', 'callee' => '__USER__']) : null,
        'popOut' => $popOut,
        'inPopOut' => $inPopOut,
        'csrf' => csrf_token(),
        'iceTransportPolicy' => \Packstub\Calls\Facades\Calls::rtcConfiguration()['iceTransportPolicy'],
        'kind' => \Packstub\Calls\Models\Call::KIND,
        'video' => $context->hasVideo(),
        'poll' => config('packstub-calls.poll'),
        'reconnectTimeout' => (int) config('packstub-calls.reconnect_timeout', 15) * 1000,
        'maxParticipants' => $context->maxParticipants(),
        'labels' => __('calls::ui'),
    ], $options);
@endphp

@if ($styles)
    <link rel="stylesheet" href="{{ \Packstub\Calls\Facades\Calls::asset('widget.css') }}">
@endif
<script type="module" data-calls-widget>
    import { CallWidget } from '{{ \Packstub\Calls\Facades\Calls::asset('widget.js') }}'

    window['{{ $name }}'] = new CallWidget({!! json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_THROW_ON_ERROR) !!})
    window.dispatchEvent(new CustomEvent('calls:ready', { detail: { widget: window['{{ $name }}'] } }))
</script>
@endif
