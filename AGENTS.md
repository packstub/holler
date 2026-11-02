# packstub/calls

**Calls for Laravel**: the server side of WebRTC calls (1-on-1 and groups up to `max_participants`, 4 by default) between the users of a Laravel app. Laravel only does signaling (who calls whom; offer, answer, ICE candidates, reset and state relayed through a table) over polled HTTP, no websockets, no media. Free, MIT. `packstub/filament-calls` (the sibling repo, `plugins/filament-calls`) puts it into a Filament panel and requires this package; the browser engine is `@packstub/calls` (`plugins/calls-js`). `filament-calls` lives in `Packstub\FilamentCalls\`, this package in `Packstub\Calls\` (one PSR-4 root per package). Split out of `filament-calls` on 2026-10-23; private repo, untagged.

## Commands

```bash
composer test               # Pest suite (Testbench, in-memory SQLite, no Filament, no Livewire)
composer test:filter <name>
composer lint               # Pint
```

## Layout

- `src/Calls.php` + `Facades\Calls`: the service. `routes(prefix, middleware, as, window)` registers the nine JSON routes and the pop-out page (`window: false` leaves it out). Keep the `@method` list on the facade in step with the public methods. `start()` (one callee or a list), `invite()`, `answer()`, `decline()`, `hangup()`, `withdraw()` (a participant is gone; the call ends once fewer than two people or nobody joined are left), `end()`, `signal()` (to one participant or to every other live one), `expire()` (missed and dropped per participant), `poll()`, `openCallsOf()`, `present()` are the whole signaling logic; `CallsController` only validates, resolves the participant (401/403) and maps `CallException` → `HttpException` with the exception's `status`. Macroable, so the Filament package can add panel helpers.
- `src/Contracts/CallContext.php`: what differs between a plain app and a panel (`scope`, `currentUserId`, `findUser`, `who`, `canCall`, `iceServers`, `hasVideo`, `maxParticipants`). `src/AppContext.php` is the plain-Laravel implementation: config keys plus fluent closures. The provider binds it only when nothing else is bound, so the Filament package's `PanelContext` wins whatever the provider order.
- `src/Exceptions`: `CallException` (abstract, `status`) → `CallNotAllowed` 422, `CallFull` 422, `CalleeBusy` 409, `CallNotRinging` 409, `CallHasEnded` 410, `SignalTooLarge` 413; each has a `reason` word the controller returns beside `message`.
- `src/Participant.php`: a guest made in code (`guest:<uuid>`, name, avatar stored on the participant row); ids with a colon are never looked up. Calls carry `kind` (`call` for the widgets) and `scope` (the context's guard; `openCallsOf()` and the routes filter on it, guests excepted). State changes (`answer`, `withdraw`, `end`, `invite`) run in `transaction()` on the configured connection and `lock()` the call row, re-reading it first. `calls:prune` (`src/Commands/PruneCommand.php`).
- `src/Models/Call.php` (`ringing | active | ended`, `end_reason`, `caller_id`, `callee_id` = the first person called, `participants`, `participant()`, `live()`, `peersOf()`, `peerOf()` = the first live other for the 1-on-1 fields, `duration()`, `end()` deletes the signals; `HOLD_SECONDS` = 5), `src/Models/CallParticipant.php` (`seat` in invitation order, the caller at 0; `ringing | joined | left | declined | missed | dropped`; `tab` / `seen_at` for the window that holds the side, `claim()` is one atomic UPDATE, `release()`, `isHeld()`), `src/Models/CallSignal.php` (`from_user_id`, `to_user_id`, `offer | answer | ice | reset | state`).
- `src/Events/{CallStarted,CallAnswered,ParticipantJoined,ParticipantLeft,CallEnded}.php`, `config/packstub-calls.php` (`CALLS_*` env), `resources/lang/en/messages.php` (`calls::messages.*`: server-side messages only; UI labels live in the consumer), `database/migrations/create_calls_table.php` (loaded by the package, `runsMigrations()`).
- `CallsServiceProvider::packageBooted()` skips `TrimStrings` for `*calls/*/signals` (an SDP ends in CRLF and the parser wants it there): a custom route prefix must end in `calls` or register its own skip.
- Tests: `tests/TestCase.php` registers `Calls::routes()` under `web` (so `auth` and sessions work as in an app); `CallsTest` (the endpoints as two users), `GroupCallsTest` (three and four people: seats, addressed signals, decline/leave/invite, the cap, missed and dropped participants; `keepPolling()` moves time while people poll, since a participant quiet past the timeout is dropped whoever triggers `expire()`), `ReconnectTest` (tabs, hold, leave, reclaim, timeouts), `ContextTest` (AppContext, the service without HTTP, exceptions, custom prefix, guard). Timing tests `freezeSecond()` first: seen-at times are stored to the second.

## Conventions

- PHP 8.3+, Laravel 12 and 13. Every change needs a Pest test and a `CHANGELOG.md` line; the tag is `v<version>` on `main`, the release workflow takes the notes from the tag's section. Keep the section short and human, since the workflow appends GitHub's list of merged pull requests under it: an optional lead sentence, then at most about six one-line bullets saying what a user gets, in plain words (class or method names only when a user needs the name, never lists of them). Always keep breaking changes with an `**Upgrading:**` line, security or privacy fixes, new minimum versions and migrations to run; CI, tests and refactors stay out.
- Nothing here may import from `Filament\` or `Livewire\`; what needs a panel belongs in `packstub/filament-calls`.
- User ids are strings everywhere (`caller_id`, `callee_id`, `user_id`, `from_user_id`, `to_user_id`, the JSON), so integer and UUID keys both work.
- The route names (`{as}poll`…), the JSON shapes of `present()` and `poll()`, the signal types and the `CallContext` methods are shared with `filament-calls` and `@packstub/calls` (its `FakeServer` mirrors the rules); they must not change inside a major. Schema changes follow the workspace rule (final shape in `create_*`, guarded `add_*`); the 2026-10-28 move to `call_participants` edited `create_calls_table` in place because nothing was tagged or installed yet.
- Authorization stays in the service (`start()` asks the context), never only in a controller.
- After a change here run the Filament package's suite too (`plugins/filament-calls`, `composer test`).

## The widget

`resources/dist/` holds the engine (`index.js`), the widget (`widget.js`) and its stylesheet (`widget.css`), copied from `@packstub/calls` by `npm run build:js` (until the npm package exists, `cp ../calls-js/src/{index.js,widget.js,widget.css} resources/dist/`) and committed, so Composer users need no npm. `AssetController` serves them (`Calls::asset()` adds a version), `<x-calls::widget />` (`resources/views/components/widget.blade.php`) builds the `CallWidget` config and `<x-calls::button />` renders a `data-calls-call` button; the pop-out page is `PopOutController` + `calls::window` (the name `WindowController` is taken by `filament-calls`, in its own namespace since 2026-10-28). The labels in `lang/en/ui.php` mirror `labels` in `widget.js`; keep the two lists the same.

## Release checklist

1.0.0 goes out together with `@packstub/calls` and `packstub/filament-calls`; the steps (visibility, Packagist, the `github:packstub/calls-js` dev dependency to `^1.0`, the changelog heading) are in `plugins/filament-calls/AGENTS.md`, section Release checklist. Agents never tag, publish or change visibility.
