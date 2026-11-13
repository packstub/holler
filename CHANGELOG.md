# Changelog

All notable changes to `packstub/holler` are documented in this file.

## Unreleased

First release of Holler for Laravel: the signaling backend for WebRTC calls between the users of a Laravel app, 1-on-1 or in groups. The media goes browser to browser; Laravel decides who calls whom and relays the signaling over polled HTTP, no websockets. Requires Laravel 12.69+ or 13.30+.

- A `Holler` service and `Holler::routes()` to ring one person or a group (up to 4 by default), invite, answer, hang up, and put a call on hold to ring someone else
- A `CallContext` contract decides who may call whom, asked for every pair on a group call; `AppContext` covers a plain Laravel app
- An Add someone search in the call window, by name by default; a search column your users table lacks is skipped with a warning instead of failing
- STUN out of the box, TURN from config with credentials that expire per call (`HOLLER_TURN_SECRET`), rate limits on ringing and the search, events for every step and `holler:prune` for old calls
- `<x-holler::widget />` and `<x-holler::button />` put calls on any page with nothing to publish or bundle, in English, German, Romanian and Russian
- Calls survive a page load, and a session that expires mid-call ends the call with a notice
