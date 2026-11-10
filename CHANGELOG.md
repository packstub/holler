# Changelog

All notable changes to `packstub/calls` are documented in this file.

## Unreleased

First release of Calls for Laravel: the signaling backend for WebRTC calls between the users of a Laravel app, 1-on-1 or in groups. The media goes browser to browser; Laravel decides who calls whom and relays the signaling over polled HTTP, no websockets. Requires Laravel 12.69+ or 13.30+.

- A `Calls` service to ring one person or a group (up to 4 by default), invite, answer, decline and hang up
- `Calls::routes()` for the JSON endpoints, with your own prefix, name and middleware
- Calls survive a page load, and missed or dropped people are handled without ending the call for the others
- A `CallContext` contract decides who may call whom; `AppContext` covers a plain Laravel app
- STUN and TURN from config or fresh per call, events for every step and `calls:prune` for old calls
- `<x-calls::widget />` and `<x-calls::button />` put calls on any page, with nothing to publish or bundle
- The pop-out page cannot be framed by another site, so a hidden frame cannot trick someone into answering a call
