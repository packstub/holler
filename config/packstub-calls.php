<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Guard
    |--------------------------------------------------------------------------
    |
    | The guard that signs people in where calls happen: the signed-in user and
    | the callee are looked up through it, and `Calls::routes()` puts its
    | routes behind `auth:<guard>`. Leave null for the default guard.
    | Inside a Filament panel the panel's own guard is used instead.
    |
    */

    'guard' => null,

    /*
    |--------------------------------------------------------------------------
    | Database connection
    |--------------------------------------------------------------------------
    |
    | Where the calls tables live; null for the default connection. With a
    | database per tenant, point it at the central connection so a call
    | between two places (a panel and a visitor's page) meets in one table.
    |
    */

    'connection' => env('CALLS_DB_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | ICE servers
    |--------------------------------------------------------------------------
    |
    | Passed to RTCPeerConnection as is. A STUN server is enough for most
    | calls: the browsers find each other and the media goes straight between
    | them. Behind strict NATs or corporate firewalls a direct path fails and
    | the call needs a TURN relay; set the TURN variables to your own coturn
    | or a hosted TURN service. The relay only forwards encrypted packets
    | (DTLS-SRTP), it cannot see or hear the call.
    |
    */

    'ice_servers' => array_values(array_filter([
        ['urls' => env('CALLS_STUN_URL', 'stun:stun.l.google.com:19302')],
        env('CALLS_TURN_URL') ? [
            'urls' => env('CALLS_TURN_URL'),
            'username' => env('CALLS_TURN_USERNAME'),
            'credential' => env('CALLS_TURN_CREDENTIAL'),
        ] : null,
    ])),

    /*
    |--------------------------------------------------------------------------
    | ICE transport policy
    |--------------------------------------------------------------------------
    |
    | `all` lets the browsers connect directly whenever they can. `relay`
    | sends everything through your TURN server, so neither side ever learns
    | the other's network addresses; it needs a TURN server above.
    |
    */

    'ice_transport_policy' => env('CALLS_ICE_TRANSPORT_POLICY', 'all'),

    /*
    |--------------------------------------------------------------------------
    | Signal size
    |--------------------------------------------------------------------------
    |
    | The largest signal payload, in KB, the server relays. A session
    | description is a few KB; 0 turns the check off.
    |
    */

    'max_signal_kb' => (int) env('CALLS_MAX_SIGNAL_KB', 64),

    /*
    |--------------------------------------------------------------------------
    | Unread signals
    |--------------------------------------------------------------------------
    |
    | A signal is deleted once the window holding the other side read it.
    | This many signals from one person may wait unread on a call; past it the
    | server answers 429 until they are read. A call needs a few dozen; 0
    | turns the check off.
    |
    */

    'max_pending_signals' => (int) env('CALLS_MAX_PENDING_SIGNALS', 500),

    /*
    |--------------------------------------------------------------------------
    | Polling
    |--------------------------------------------------------------------------
    |
    | Signaling runs over plain HTTP: every open window asks for incoming
    | calls every `idle` milliseconds and, while a call rings or runs, for
    | signals every `active` milliseconds. No websocket server to run.
    |
    */

    'poll' => [
        'idle' => (int) env('CALLS_POLL_IDLE', 5000),
        'active' => (int) env('CALLS_POLL_ACTIVE', 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ringing
    |--------------------------------------------------------------------------
    |
    | Seconds a call rings before it is marked missed.
    |
    */

    'ring_timeout' => (int) env('CALLS_RING_TIMEOUT', 45),

    /*
    |--------------------------------------------------------------------------
    | Reconnecting
    |--------------------------------------------------------------------------
    |
    | A page reload or a dropped network leaves one side silent for a moment;
    | the call survives it and the other side sees "Reconnecting…". After this
    | many seconds without that side, the call ends as lost.
    |
    */

    'reconnect_timeout' => (int) env('CALLS_RECONNECT_TIMEOUT', 15),

    /*
    |--------------------------------------------------------------------------
    | Media
    |--------------------------------------------------------------------------
    |
    | What a call starts with. Either side can still turn the camera off,
    | mute or share the screen during the call.
    |
    */

    'video' => (bool) env('CALLS_VIDEO', true),

    /*
    |--------------------------------------------------------------------------
    | Group calls
    |--------------------------------------------------------------------------
    |
    | People on one call, the caller included. The browsers connect to each
    | other directly (a full mesh), and each one uploads its media once per
    | other participant, which is fine up to about four people. Raise it only
    | with a browser topology that relays through a server.
    |
    */

    'max_participants' => (int) env('CALLS_MAX_PARTICIPANTS', 4),

    /*
    |--------------------------------------------------------------------------
    | The widget's pop-out window
    |--------------------------------------------------------------------------
    |
    | `<x-calls::widget />` opens each call in a small window of its own, so
    | the page it started from can navigate, reload or close while the call
    | goes on; the page shows a chip for it. Off, the call lives in the page
    | (right for a single-page app) and a full page load reconnects it.
    |
    */

    'pop_out' => (bool) env('CALLS_POP_OUT', true),

    /*
    |--------------------------------------------------------------------------
    | Pruning
    |--------------------------------------------------------------------------
    |
    | `php artisan calls:prune` (schedule it daily) ends the calls everybody
    | walked away from and deletes ended calls older than this many days,
    | with their participants. Null keeps the call log forever.
    |
    */

    'prune_after_days' => env('CALLS_PRUNE_AFTER_DAYS', 90),

];
