# SmartPBX Development Roadmap

Status: implementation order for the Call Popup MVP. Planning documents are the current phase. Do not start a later phase by leaving unfinished schema or a second copy of call state behind.

## Current state

Phase 1 is implemented in `backend/`. The Python connector does not exist yet.

The local application uses SQLite so migrations and tests run without a database server. `.env.example` documents the MySQL settings from `DEPLOYMENT.md`. Switch the local `.env` to MySQL when that server exists. Tests keep using in-memory SQLite.

A local administrator is created only when `APP_ENV=local` and both `SEED_ADMIN_EMAIL` and `SEED_ADMIN_PASSWORD` are set. The password stays in `.env`, not in the seeder. Running `php artisan db:seed` again resets that account's password.

Sanctum is installed and its `personal_access_tokens` table is migrated. The connector token is still created in phase 3, together with the ingest route.

## Rules that bind every phase

- Smallest change that satisfies that phase.
- No Issabel source edits.
- No Asterisk logic inside Laravel.
- No business logic inside controllers.
- No MySQL access from Python.
- No hard-coded secrets.
- No new package without a sentence in the change summary saying why it is required.
- No callback, CRM, AI, SMS, survey, WebRTC, or auto dialer work, including empty modules "for later".

## Phase 0 — Plan

Exit criteria: the documents above describe one ingest path, one schema, and one UI approach. This phase is complete when those files are reviewed.

## Phase 1 — Laravel shell and authentication

Status: implemented.

Create `backend/` with the current stable Laravel release and the Breeze Blade stack.

- Session login and logout.
- `users.role` and `users.is_active`.
- Policies that distinguish administrator and operator.
- Administrator user management.
- A seeded local administrator, documented as a development seed.
- Feature tests for login and the user policy.

Packages: `laravel/breeze` for auth screens. Sanctum if the application skeleton does not already include it, because phase 3 needs a token. Adding it in phase 1 avoids a second auth pass.

Exit criteria: an administrator can sign in and create an inactive operator who cannot sign in. `php artisan test` for this phase passes.

## Phase 2 — Directory

- `customers` (`name`, `notes`) already exists so call logs can reference a customer. Do not create that table again.
- Migrations for `extensions` and `customer_phones`.
- `PhoneNormalizer` and its unit tests.
- Administrator screens for extensions.
- Shared screens for customers, including duplicate-number validation.
- Operator forbidden from extension and user screens.

Exit criteria: the Iran number examples in `TEST-PLAN.md` pass, and a duplicate `+98` form of an existing mobile is rejected.

## Phase 3 — Call ingest and history

- The `calls` table, call log page, and call log API already exist. Do not create a second call table. Add `call_events` and ingest on top of `calls`.
- `CallIngestService` with the state machine and transaction behavior from `DATABASE-DESIGN.md`.
- `POST /api/internal/v1/asterisk/events` exactly as in `API-DESIGN.md`.
- Sanctum token ability `asterisk:ingest`.
- History screen scoped by policy.
- Stale-call scheduled command.
- Feature tests for ingest, duplicates, unknown extensions, and history scope.
- Broadcast the popup event in this phase, asserted with a fake broadcaster. The websocket server can wait until phase 5.

Exit criteria: a test can post ringing, answered, and ended and read one history row with the right customer. No Python code yet.

## Phase 4 — Python connector

Create `connector/` with the standard library only.

- AMI login, read loop, reconnect, and secret redaction.
- Normalizer covered by fixture tests.
- HTTP publisher with retry and stable `event_id`.
- systemd unit file example, without installing it on a server unless a deploy is requested.

First task inside the phase, before expanding the parser: capture one real inbound call from the lab Issabel and save redacted fixtures. If that capture is impossible, ship against the documented fixtures and mark the lab check as open in the phase report.

Exit criteria: `python -m unittest discover -s tests` passes, and a connector process pointed at a fake AMI script can create a Laravel call through the real ingest URL on a developer machine.

## Phase 5 — Live popup

- Add Livewire for the operator page if phase 1's Blade screens have not already adopted it. Reason: the popup and customer form stay in PHP while Echo only signals an update.
- Add Laravel Reverb. Reason: the popup has a two-second delivery target and polling is a separate design we are not using.
- Private channel `private-operators.{userId}`.
- Operator page loads the active call from MySQL and updates on `CallPopupUpdated`.
- Unknown caller form reuses `CustomerService`.

Exit criteria: with Reverb running, the phase 3 HTTP fixture produces a visible popup for the assigned operator and not for anyone else. The manual PBX rehearsal in `TEST-PLAN.md` is the final check when a PBX is available.

## Phase 6 — Hardening and deploy

- Production env examples match `DEPLOYMENT.md` with empty secrets.
- Confirm `.env` and connector env files are gitignored.
- Run the security checks at the end of `SECURITY.md` on the lab host.
- Deploy only when explicitly asked.

Exit criteria: the acceptance list in `PRODUCT-REQUIREMENTS.md` is checked off, with the PBX items marked blocked if no Issabel lab exists.

## Suggested first coding session

Phase 1 only: create the Laravel app, auth, roles, and user tests. Do not scaffold the connector, customers, or Reverb in that session.

## Open points that do not block phase 1

These are confirmed during phase 4. They must not be "solved" by guessing inside Laravel.

- Whether the site's trunks emit `DialBegin` with `DialString` set to the extension.
- Whether channels are `SIP/` or `PJSIP/`.
- Whether empty AMI `write` permission still allows `Ping`.

If a capture contradicts this plan, update `ASTERISK-INTEGRATION.md` and the normalizer together. Leave the Laravel canonical event contract alone unless the capture cannot fill a required field.
