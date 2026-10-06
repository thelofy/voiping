# SmartPBX System Architecture

Status: planning baseline for the Call Popup MVP.

## Current repository

The workspace has no application code, dependency manifests, or git history of a product. It contains only Cursor rules:

- `.cursor/rules/architecture.mdc`
- `.cursor/rules/laravel.mdc`
- `.cursor/rules/python.mdc`
- `.cursor/rules/security.mdc`
- `.cursor/rules/development-process.mdc`

This document is the architecture those rules describe. Implementation comes later, in the phases in `DEVELOPMENT-ROADMAP.md`.

## Decision

Use four runtime pieces and no extra services:

| Piece | Responsibility |
| --- | --- |
| Issabel/Asterisk | Existing telephony engine. SmartPBX does not change its source. |
| Python connector | AMI session, event normalization, HTTP delivery, reconnect. |
| Laravel | Auth, authorization, customers, extensions, call state, REST ingest, dashboards. |
| MySQL | System of record for users, customers, extensions, and calls. |

The operator UI is Laravel-native:

- Laravel session authentication
- Blade and Livewire for administrator and operator screens
- Laravel Reverb and Echo for the popup websocket

Livewire keeps screens and validation in PHP. Reverb is Laravel's first-party websocket server, used because a call popup is an explicit real-time requirement. The browser never talks to Asterisk or to the Python process.

### Why these dependencies, when implementation starts

Add a package only in the phase that needs it, and record the reason in the pull request.

| Package | Why it is necessary |
| --- | --- |
| `laravel/breeze` | Official session-auth scaffolding. Avoids a hand-rolled login. |
| `livewire/livewire` | Server-rendered forms and tables without a separate frontend application. |
| `laravel/reverb` | Official websocket server so Laravel can push one popup to one operator. |
| `laravel/sanctum` | Hashed API tokens for the connector. Often already present in a current Laravel app; do not add a second token library. |

The Python connector uses the standard library only: `asyncio` for AMI TCP, `urllib` for the Laravel POST, and `unittest` for parser tests. An AMI library is not required to read a text protocol and post JSON. Revisit that only if framing bugs consume real time, and write down the reason before adding it.

Do not add Redis, a second database, a message bus, or a JavaScript SPA for this MVP.

## Context

```
Operator browser
    |  HTTPS session, Blade/Livewire
    |  WSS private channel for popups
    v
Laravel  ---- MySQL
    ^
    |  HTTPS POST, bearer token
    |
Python connector
    ^
    |  AMI TCP 5038, private network
    |
Issabel / Asterisk
```

Business rules stay in Laravel services. AMI parsing stays in Python. Issabel remains the telephone switch.

## Proposed repository layout

Create this when implementation starts. Do not create it in the planning step.

```
voip-projects/
├── backend/                 Laravel application
│   ├── app/Services/        business logic
│   ├── app/Http/            thin controllers, form requests, resources
│   ├── app/Models/
│   ├── app/Policies/
│   └── resources/views/     Blade and Livewire views
├── connector/               Python AMI connector
│   ├── smartpbx_connector/
│   │   ├── ami.py           socket, login, framing, reconnect
│   │   ├── normalize.py     AMI events to canonical events
│   │   ├── publish.py       HTTP client to Laravel
│   │   └── main.py
│   └── tests/               parser fixtures
└── docs/
```

Laravel lives in `backend/` so PHP and Python dependency files do not collide at the repository root.

## Component boundaries

### Issabel

- Routes and rings calls exactly as it does today.
- Exposes one AMI manager user to the connector host.
- That user is read-only. The MVP never sends AMI actions that originate, transfer, or hang up calls.
- No SmartPBX module is installed into Issabel's web root.

### Python connector

Allowed work:

- Maintain one AMI TCP session.
- Log in with credentials from the environment.
- Parse AMI frames.
- Emit only canonical call events.
- POST those events to Laravel.
- Retry HTTP and reconnect AMI.
- Log operational failures without secrets.

Forbidden work:

- Reading or writing MySQL.
- Deciding which customer matched.
- Applying roles or permissions.
- Rendering UI.
- Call control.

The connector does not receive the extension directory. It forwards inbound dial events that look like a call to a local channel. Laravel drops unknown extensions. That keeps customer and extension data in one place.

### Laravel

Modules, each able to exist without the later ones:

| Module | MVP contents |
| --- | --- |
| Identity | Users, roles, session auth, policies |
| Directory | Extensions and customers |
| Calls | Ingest, state machine, lookup, history |
| Popup | Broadcast from the call service to one operator channel |

Controllers validate the request and call a service. Services own transactions and state changes. Eloquent models own persistence and relationships. Policies own authorization.

The ingest service is the only writer of call state. The UI reads that state. The Python process is not a second writer.

### Browser

- Administrator pages: users, extensions, customers, all-call history.
- Operator page: current popup plus own history.
- Echo subscribes to `private-operators.{userId}` after Laravel authorizes that channel.
- The Livewire page listens for the browser event and re-renders the popup from an HTML response. The websocket payload is a signal, not a second copy of business rules.

## Call path

```
AMI DialBegin
  -> Python normalizes call.ringing
  -> POST /api/internal/v1/asterisk/events
  -> CallIngestService
       -> resolve extension and operator
       -> normalize and look up the customer
       -> insert or update the call in one transaction
       -> load up to 5 previous calls
       -> broadcast CallPopupUpdated
  -> Reverb delivers to that operator
  -> popup renders
```

Answer and hangup use the same endpoint and the same call row.

The ingest request is synchronous on purpose. Putting the broadcast on a queue would add delay to the popup. Database queues can still be configured for later slow work, but the popup path does not use them.

## Canonical event

Python emits this JSON and nothing AMI-specific beyond the identifiers Laravel must store:

```json
{
  "event_id": "8f0c1d2e-7b3a-4e1f-9c2d-6a5b4c3d2e1f",
  "event_type": "call.ringing",
  "occurred_at": "2026-10-05T08:10:00Z",
  "asterisk": {
    "uniqueid": "1728000000.2",
    "linkedid": "1728000000.1",
    "channel": "SIP/1001-00000002"
  },
  "call": {
    "caller_number": "09121234567",
    "caller_name": "",
    "extension": "1001",
    "dial_status": "RINGING"
  }
}
```

`event_type` is one of `call.ringing`, `call.answered`, `call.ended`.

`event_id` is a UUID created by the connector. Laravel stores it on the call event row and ignores a repeat of the same id.

## Call state machine

Legal transitions, applied in `CallIngestService`:

| Current | Event | Next |
| --- | --- | --- |
| none | `call.ringing` | `ringing` |
| `ringing` | `call.ringing` | `ringing` |
| `ringing` | `call.answered` | `answered` |
| `ringing` | `call.ended` | `missed`, `busy`, or `failed` from `dial_status` |
| `answered` | `call.answered` | `answered` |
| `answered` | `call.ended` | `ended` |
| `ended`, `missed`, `busy`, `failed`, `incomplete` | any | no change |

Out-of-order rule: an event never moves a call backward. A late `call.ringing` after `call.answered` is acknowledged and ignored. `dial_status` on an end event uses this map:

| Dial status or hangup cause | Unanswered result |
| --- | --- |
| `BUSY`, cause 17 | `busy` |
| `NOANSWER`, `CANCEL`, cause 19 | `missed` |
| `CHANUNAVAIL`, `CONGESTION`, cause 1, 34 | `failed` |
| anything else, including normal clearing before answer | `missed` |

Correlation key: `(asterisk_linkedid, extension_number)`. One inbound call that rings two extensions is two rows and two popups. That matches how agents actually experience the ring.

## Failure behavior

| Failure | Behavior |
| --- | --- |
| AMI disconnect | Connector backs off 1, 2, 4, 8, then 30 seconds and logs in. It does not invent missed events. |
| Laravel 5xx or network error | Connector retries the same `event_id` three times. Then it logs the dropped event id and continues. |
| Laravel 4xx other than 401 | Connector does not retry. The payload is invalid. |
| Laravel 401 | Connector logs an authentication failure and keeps the AMI session. An operator must fix the token. |
| Duplicate `event_id` | Laravel returns 200 with `duplicate: true`. |
| Unknown extension | Laravel returns 202, stores the canonical event as not applied, and inserts no call. |
| Reverb down | Ingest and history still work. The operator sees the call after refresh or socket recovery. |
| Stale `ringing` or `answered` older than 3 minutes | Scheduler sets `incomplete`. |

## Broadcast

- Channel: `private-operators.{id}`
- Event name: `CallPopupUpdated`
- Authorization: the authenticated user id equals `{id}`
- Payload: call id, status, caller number, customer id and name or null, note excerpt, extension, timestamps, and up to five history items
- The payload contains no tokens and no AMI fields that are not already in the call row

## Processes in production

On the application host:

1. Nginx and PHP-FPM serve Laravel.
2. One `reverb` process serves websockets.
3. One `schedule:work` or cron entry runs the stale-call sweeper every minute.
4. One Python connector process runs under systemd.

On the PBX host: existing Issabel only, plus the AMI user documented in `ASTERISK-INTEGRATION.md`.

MySQL runs on the application host for a single-server MVP, or on a private database host. It is not reachable from the PBX.

## What this architecture refuses

- A Laravel AMI client "just for one event".
- The connector querying MySQL because an API call feels slower.
- A microservice per feature. Popup, history, and customers are modules inside one Laravel app.
- Browser access to port 5038.
- Implementing callback, CRM, AI, SMS, survey, WebRTC, or auto dialer behind a feature flag. Those modules are absent until a later product decision.
