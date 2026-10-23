# packstub/calls

**Calls for Laravel**: the server side of 1-on-1 WebRTC calls between the users of a Laravel app. Laravel only does signaling (who calls whom; offer, answer, ICE candidates and reset relayed through a table) over polled HTTP, no websockets, no media. Free, MIT. `packstub/filament-calls` (the sibling repo, `plugins/filament-calls`) puts it into a Filament panel and requires this package; the browser engine is `@packstub/calls` (`plugins/calls-js`). The two PHP packages share the `Packstub\Calls\` namespace (Composer merges the PSR-4 directories), so class names must not collide across them. Split out of `filament-calls` on 2026-10-23; private repo, untagged.

## Commands

```bash
composer test               # Pest suite (Testbench, in-memory SQLite, no Filament, no Livewire)
composer test:filter <name>
composer lint               # Pint
```

## Layout

- `src/Calls.php` + `Facades\Calls`: the service. `routes(prefix, middleware, as)` registers the seven routes. `start()`, `answer()`, `decline()`, `hangup()`, `end()`, `signal()`, `expire()`, `poll()`, `openCallsOf()`, `present()` are the whole signaling logic; `CallsController` only validates, resolves the participant (401/403) and maps `CallException` → `HttpException` with the exception's `status`. Macroable, so the Filament package can add panel helpers.
- `src/Contracts/CallContext.php`: what differs between a plain app and a panel (`currentUserId`, `findUser`, `who`, `canCall`, `iceServers`, `hasVideo`). `src/AppContext.php` is the plain-Laravel implementation: config keys plus fluent closures. The provider binds it only when nothing else is bound, so the Filament package's `PanelContext` wins whatever the provider order.
- `src/Exceptions`: `CallException` (abstract, `status`) → `CallNotAllowed` 422, `CalleeBusy` 409, `CallNotRinging` 409, `CallHasEnded` 410.
- `src/Models/Call.php` (`ringing | active | ended`, `end_reason`, `*_tab` / `*_seen_at` per side, `claim()` is one atomic UPDATE, `release()`, `isHeld()`, `duration()`, `end()` deletes the signals; `HOLD_SECONDS` = 5), `src/Models/CallSignal.php` (`offer | answer | ice | reset`).
- `src/Events/{CallStarted,CallAnswered,CallEnded}.php`, `config/packstub-calls.php` (`CALLS_*` env), `resources/lang/en/messages.php` (`calls::messages.*`: server-side messages only; UI labels live in the consumer), `database/migrations/create_calls_table.php` (loaded by the package, `runsMigrations()`).
- `CallsServiceProvider::packageBooted()` skips `TrimStrings` for `*calls/*/signals` (an SDP ends in CRLF and the parser wants it there): a custom route prefix must end in `calls` or register its own skip.
- Tests: `tests/TestCase.php` registers `Calls::routes()` under `web` (so `auth` and sessions work as in an app); `CallsTest` (the endpoints as two users), `ReconnectTest` (tabs, hold, leave, reclaim, timeouts), `ContextTest` (AppContext, the service without HTTP, exceptions, custom prefix, guard).

## Conventions

- PHP 8.3+, Laravel 12 and 13. Every change needs a Pest test and a `CHANGELOG.md` line; the tag is `v<version>` on `main`, the release workflow takes the notes from the tag's section.
- Nothing here may import from `Filament\` or `Livewire\`; what needs a panel belongs in `packstub/filament-calls`.
- User ids are strings everywhere (`caller_id`, `callee_id`, `to_user_id`, the JSON), so integer and UUID keys both work.
- The route names (`{as}poll`…), the JSON shapes of `present()` and `poll()`, the signal types and the `CallContext` methods are shared with `filament-calls` and `@packstub/calls` (its `FakeServer` mirrors the rules); they must not change inside a major. Schema changes follow the workspace rule (final shape in `create_*`, guarded `add_*`).
- Authorization stays in the service (`start()` asks the context), never only in a controller.
- After a change here run the Filament package's suite too (`plugins/filament-calls`, `composer test`).
