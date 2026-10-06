# SmartPBX API Design

Status: internal ingest route implemented. Browser screens are still later.

There are two surfaces:

- A small internal JSON API for the Python connector.
- Browser screens implemented with Livewire and session authentication.

Operators and administrators do not get a public JSON API in the MVP. Livewire actions call the same services the internal API uses. That avoids a second authorization path.

## Internal ingest API

Base path: `/api/internal/v1`

Authentication: `Authorization: Bearer <token>`

The token is a Laravel Sanctum personal access token with ability `asterisk:ingest`. The route middleware is `auth:sanctum` plus an ability check. Session cookies are not accepted on this route. Sanctum is configured not to consult the web guard, so a signed-in browser session cannot call this route.

Issue the token on the application host. The plain token is printed once and is not written to the log or to the database:

```
php artisan smartpbx:issue-connector-token
```

The command uses the inactive user `connector@smartpbx.internal` so that account cannot sign in. Store the token in the connector environment. Revoke it by deleting the `personal_access_tokens` row. A token whose ability list is `*` is rejected.

Transport: HTTPS in production. The connector and Laravel share a private network.

Rate limit: 600 requests per minute per token. A burst of AMI events from a busy PBX must pass. A stolen token must not be an unlimited write key. Return `429` with `Retry-After` when limited. The connector treats `429` as retryable.

### POST /api/internal/v1/asterisk/events

Request body:

| Field | Rule |
| --- | --- |
| event_id | required UUID |
| event_type | required, in `call.ringing`, `call.answered`, `call.ended` |
| occurred_at | required ISO-8601 timestamp |
| asterisk.uniqueid | required string, max 64 |
| asterisk.linkedid | required string, max 64 |
| asterisk.channel | required string, max 120 |
| call.caller_number | required string, max 40, may be empty only on `call.ended` |
| call.caller_name | optional string, max 120 |
| call.extension | required string, max 20 |
| call.dial_status | optional string, max 32 |

Validation is a Form Request. Invalid JSON or failed validation returns `422` with Laravel's error structure. The connector must not retry `422`.

Success, event applied:

`200 OK`

```json
{
  "duplicate": false,
  "applied": true,
  "call": {
    "id": 41,
    "status": "ringing",
    "extension": "1001",
    "customer": {
      "id": 7,
      "name": "Sara Ahmadi"
    }
  }
}
```

`customer` is `null` when no exact phone match exists.

Duplicate `event_id`:

`200 OK`

```json
{
  "duplicate": true,
  "applied": true,
  "call": {
    "id": 41,
    "status": "ringing"
  }
}
```

Ignored because the extension is unknown, inactive, or the caller is an internal extension. Internal calls are stored when `RECORD_INTERNAL_CALLS` is true:

`202 Accepted`

```json
{
  "duplicate": false,
  "applied": false,
  "reason": "ignored"
}
```

`reason` stays generic. The connector does not need the business rule. Laravel still stores the canonical event with `applied = false`. It does not insert a `calls` row.

A legal repeat, such as a second `call.ringing` for the same linked id and extension, returns `200` with `applied: true` and does not insert another call.

An event that would move a call backward, such as `call.ringing` after `call.answered`, returns `200` with `applied: false` and the current call. The stored event is marked not applied. A later `call.answered` or `call.ended` after a terminal status does the same.

Other responses:

| HTTP | Meaning | Connector |
| --- | --- | --- |
| 401 | missing, revoked, or wrong token | log and stop retrying this process until restart |
| 403 | token lacks `asterisk:ingest` | same as 401 |
| 422 | bad payload | log payload without secrets, do not retry |
| 429 | rate limit | retry with backoff |
| 500, 502, 503, 504 | Laravel or proxy failure | retry up to 3 times |

No list, update, or delete route exists for the connector.

## Browser routes

All of these use the `web` middleware, session auth, and CSRF. Guest users can open only the login screen.

| Method and path | Screen | Authorization |
| --- | --- | --- |
| GET `/login` | sign-in | guest |
| POST `/login` | sign-in | guest, rate-limited |
| POST `/logout` | | authenticated |
| GET `/dashboard` | role home | authenticated |
| GET `/users` | user list | administrator |
| GET `/users/create` | create user | administrator |
| POST `/users` | create user | administrator |
| GET `/users/{user}/edit` | edit user | administrator |
| PUT `/users/{user}` | update user | administrator |
| GET `/extensions` | extension list | administrator |
| POST `/extensions` | create extension | administrator |
| PUT `/extensions/{extension}` | update extension | administrator |
| DELETE `/extensions/{extension}` | delete extension | administrator |
| GET `/customers` | customer search | administrator or operator |
| POST `/customers` | create customer | administrator or operator |
| PUT `/customers/{customer}` | update customer | administrator or operator |
| DELETE `/customers/{customer}` | delete customer | administrator |
| GET `/calls` | call log | authenticated; policy scopes the query |
| GET `/api/calls` | call log JSON | authenticated; same filters and scope; 60 requests per minute |
| POST `/api/calls` | record one call log | administrator; 60 requests per minute. This is not Asterisk ingest |
| GET `/popup` | operator popup page | operator |

Livewire components may post to their own endpoints under `/livewire`. Those requests still use the session and the same policies. Do not bypass a policy from a Livewire method.

Administrator dashboard links to users, extensions, customers, and all calls. Operator dashboard is the popup page plus a link to their history.

### Customer form payload

- `name`: required, max 160
- `notes`: optional, max 500
- `phones`: array, min 1, max 5 for the MVP
- `phones.*.raw_number`: required
- `phones.*.label`: optional, max 40

The form request runs `PhoneNormalizer` and adds an error when the result is empty or already belongs to another customer.

### User form payload

- `name`: required, max 120
- `email`: required, email, unique
- `role`: required, `administrator` or `operator`
- `is_active`: boolean
- `password`: required on create, minimum 12 characters; optional on update

### Extension form payload

- `number`: required, max 20, unique, digits only
- `user_id`: optional, must be an active operator who does not already own a different extension
- `label`: optional, max 120
- `is_active`: boolean

## Popup channel

Laravel broadcasting authorization:

`POST /broadcasting/auth`

Channel name: `private-operators.{userId}`

Rule: `auth()->id() === (int) userId` and the user is an active operator. Administrators do not subscribe to agent popups in the MVP. They use call history.

Event: `CallPopupUpdated`

```json
{
  "call": {
    "id": 41,
    "status": "ringing",
    "extension": "1001",
    "caller_number": "09121234567",
    "caller_name": "",
    "customer": {
      "id": 7,
      "name": "Sara Ahmadi",
      "notes": "Prefers morning calls"
    },
    "started_at": "2026-10-05T08:10:00Z",
    "answered_at": null,
    "history": [
      {
        "id": 12,
        "status": "ended",
        "started_at": "2026-09-30T09:00:00Z",
        "duration_seconds": 84
      }
    ]
  }
}
```

The operator page, on load and on socket reconnect, calls a Livewire action `loadActiveCall`. That action returns the newest `ringing` or `answered` call for the signed-in user from MySQL. The websocket is an accelerator. MySQL remains the source of truth.

### Call log payload

`POST /api/calls` fields:

| Field | Rule |
| --- | --- |
| customer_id | optional, existing customer |
| user_id | optional, existing user. Operators later see the row only when this is their id |
| phone | required, max 40 |
| extension | optional digits, max 20 |
| direction | required, `inbound` or `outbound` |
| status | required, one of the status values in `DATABASE-DESIGN.md` |
| unique_id | optional, max 64, unique. Omit when the PBX identifier is not available |
| started_at | required datetime |
| answered_at | required or forbidden according to status |
| ended_at | required or forbidden according to status |

`duration` is not accepted. The service sets it for `ended` calls. List filters on both the page and `GET /api/calls` are `phone`, `extension`, `direction`, `status`, `customer_id`, `unique_id`, `started_from`, `started_to`, and `per_page` (1–100, default 25).

## Service layer behind the routes

| Service | Used by |
| --- | --- |
| `UserService` | user screens |
| `ExtensionService` | extension screens |
| `CustomerService` | customer screens and the unknown-caller form |
| `CallIngestService` | internal events endpoint |
| `CallLogService` | call log page, `GET /api/calls`, and `POST /api/calls` |
| `PhoneNormalizer` | customers, ingest, history search |

Controllers and Livewire components do not query past a simple `find` that the policy already authorized. Lists and writes go through services.

## Errors shown to people

- Validation errors render beside the fields.
- A policy failure is `403`.
- A missing model is `404`.
- Ingest errors are not shown to operators. They appear in the connector log and the Laravel log as an event id, status code, and linked id.

## Versioning

The internal prefix is `/api/internal/v1`. A breaking change to the canonical event requires `v2` and a connector change together. Browser routes are not versioned.
