# Changelog

All notable changes to `packstub/holler` are documented in this file.

## Unreleased

First release of Holler for Laravel: the signaling backend for WebRTC calls between the users of a Laravel app, 1-on-1 or in groups. The media goes browser to browser; Laravel decides who calls whom and relays the signaling over polled HTTP, no websockets. Requires Laravel 12.69+ or 13.30+.

- A `Holler` service to ring one person or a group (up to 4 by default), invite, answer, decline and hang up
- Put a call on hold, ring someone else and swap back; the others stay on the call and see you on hold
- `Holler::routes()` for the JSON endpoints, with your own prefix, name and middleware (`auth` on the configured guard by default); ringing and the people search are rate limited per person
- Calls survive a page load, and missed or dropped people are handled without ending the call for the others
- A `CallContext` contract decides who may call whom, asked for every pair on a group call; `AppContext` covers a plain Laravel app
- A free public STUN server out of the box, TURN from config with credentials that expire per call (`HOLLER_TURN_SECRET`), events for every step, signals deleted once read and `holler:prune` for old calls
- An Add someone button with a search box in the call window (people are found by name; email is yours to add), and a question when Call is pressed during a call: add, hold and call, or end and call
- `<x-holler::widget />` and `<x-holler::button />` put calls on any page, with nothing to publish or bundle (minified, about 25 kB gzipped, with a nonce for a Content-Security-Policy): device settings, a call in a background tab noticed, in English, German, Romanian and Russian
