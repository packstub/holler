{{-- The pop-out window: a bare page with nothing on it but the widget, which fills it. --}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
    <style>html, body { margin: 0; height: 100%; background: #09090b; font-family: ui-sans-serif, system-ui, sans-serif; }</style>
</head>
<body>
    <x-holler::widget :routes="$routes" :in-pop-out="true" />
</body>
</html>
