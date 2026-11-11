# Security Policy

Calls for Laravel decides who may ring whom and relays the signaling that connects people's cameras and microphones. Please report anything that lets someone ring, join, listen to or watch a call that the `CallContext` (`canCall()`, the guard, the scope) should keep them out of, read or send another person's signals, ring a guest from the HTTP routes, or start or answer a call without the person clicking.

If you discover a security issue, email [security@packstub.dev](mailto:security@packstub.dev) instead of using the issue tracker. We answer within a few days and credit reporters in the changelog unless they prefer otherwise.

The browser engine and the widget belong to [@packstub/calls](https://github.com/packstub/calls-js), and the Filament panel side to [packstub/filament-calls](https://github.com/packstub/filament-calls); their policies cover them.
