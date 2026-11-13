<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Guard
    |--------------------------------------------------------------------------
    |
    | The guard that signs people in where calls happen: the signed-in user and
    | the callee are looked up through it, and `Holler::routes()` puts its
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

    'connection' => env('HOLLER_DB_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | ICE servers
    |--------------------------------------------------------------------------
    |
    | What the browsers use to find a path to each other. A STUN server is
    | enough for most calls: the media then goes straight between them. The
    | default is Google's public STUN server, free and without an account;
    | set HOLLER_STUN_URL to another one, or leave it empty to use none.
    |
    | Behind strict NATs, VPNs or corporate firewalls a direct path fails and
    | the call needs a TURN relay: your own coturn or a hosted TURN service.
    | The relay only forwards encrypted packets (DTLS-SRTP), it cannot see or
    | hear the call.
    |
    | Every URL variable takes several URLs separated by commas, for example
    | "turn:turn.example.com:3478,turns:turn.example.com:443?transport=tcp".
    |
    | With HOLLER_TURN_SECRET (coturn's `static-auth-secret`) each call gets a
    | username and credential that expire after HOLLER_TURN_TTL seconds, so no
    | long-lived TURN password ever reaches a browser. Without it the fixed
    | HOLLER_TURN_USERNAME and HOLLER_TURN_CREDENTIAL are sent.
    |
    */

    'ice_servers' => array_values(array_filter([
        ['urls' => env('HOLLER_STUN_URL', 'stun:stun.l.google.com:19302')],
        env('HOLLER_TURN_URL') ? [
            'urls' => env('HOLLER_TURN_URL'),
            'username' => env('HOLLER_TURN_USERNAME'),
            'credential' => env('HOLLER_TURN_CREDENTIAL'),
            'secret' => env('HOLLER_TURN_SECRET'),
            'ttl' => (int) env('HOLLER_TURN_TTL', 3600),
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

    'ice_transport_policy' => env('HOLLER_ICE_TRANSPORT_POLICY', 'all'),

    /*
    |--------------------------------------------------------------------------
    | Signal size
    |--------------------------------------------------------------------------
    |
    | The largest signal payload, in KB, the server relays. A session
    | description is a few KB; 0 turns the check off.
    |
    */

    'max_signal_kb' => (int) env('HOLLER_MAX_SIGNAL_KB', 64),

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

    'max_pending_signals' => (int) env('HOLLER_MAX_PENDING_SIGNALS', 500),

    /*
    |--------------------------------------------------------------------------
    | Rate limits
    |--------------------------------------------------------------------------
    |
    | How many times a minute one person may ring or add someone (`calls`)
    | and search for people to add (`people`); past it the server answers
    | 429. Polls and signals are not limited here. 0 turns a limit off.
    |
    */

    'rate_limits' => [
        'calls' => (int) env('HOLLER_RATE_LIMIT_CALLS', 30),
        'people' => (int) env('HOLLER_RATE_LIMIT_PEOPLE', 60),
    ],

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
        'idle' => (int) env('HOLLER_POLL_IDLE', 5000),
        'active' => (int) env('HOLLER_POLL_ACTIVE', 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ringing
    |--------------------------------------------------------------------------
    |
    | Seconds a call rings before it is marked missed.
    |
    */

    'ring_timeout' => (int) env('HOLLER_RING_TIMEOUT', 45),

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

    'reconnect_timeout' => (int) env('HOLLER_RECONNECT_TIMEOUT', 15),

    /*
    |--------------------------------------------------------------------------
    | On hold
    |--------------------------------------------------------------------------
    |
    | Someone who puts a call on hold stays on it for as long as a window of
    | theirs is open. After this many seconds without one (they closed the
    | browser) they are let go of the call. 0 keeps the hold for good.
    |
    */

    'hold_timeout' => (int) env('HOLLER_HOLD_TIMEOUT', 120),

    /*
    |--------------------------------------------------------------------------
    | Adding people
    |--------------------------------------------------------------------------
    |
    | The call window has an "Add someone" button with a search box. It finds
    | the users of the guard by these columns, and only shows the ones the
    | person may call. Only the name by default: add 'email' when the people
    | who may call each other may know each other's address, since a search
    | that matches a column tells the person typing what is in it. Set
    | `people_search` to false to leave the button out, or answer the search
    | yourself with AppContext::searchUsersUsing().
    |
    */

    'people_search' => (bool) env('HOLLER_PEOPLE_SEARCH', true),

    'search_columns' => ['name'],

    /*
    |--------------------------------------------------------------------------
    | Media
    |--------------------------------------------------------------------------
    |
    | What a call starts with. Either side can still turn the camera off,
    | mute or share the screen during the call.
    |
    */

    'video' => (bool) env('HOLLER_VIDEO', true),

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

    'max_participants' => (int) env('HOLLER_MAX_PARTICIPANTS', 4),

    /*
    |--------------------------------------------------------------------------
    | The widget's pop-out window
    |--------------------------------------------------------------------------
    |
    | `<x-holler::widget />` opens each call in a small window of its own, so
    | the page it started from can navigate, reload or close while the call
    | goes on; the page shows a chip for it. Off, the call lives in the page
    | (right for a single-page app) and a full page load reconnects it.
    |
    */

    'pop_out' => (bool) env('HOLLER_POP_OUT', true),

    /*
    |--------------------------------------------------------------------------
    | Pruning
    |--------------------------------------------------------------------------
    |
    | `php artisan holler:prune` (schedule it daily) ends the calls everybody
    | walked away from and deletes ended calls older than this many days,
    | with their participants. Null keeps the call log forever.
    |
    */

    'prune_after_days' => env('HOLLER_PRUNE_AFTER_DAYS', 90),

];
