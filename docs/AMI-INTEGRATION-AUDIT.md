# AMI Integration Audit

Status: inspection of the current Asterisk AMI path. No code was changed for this report.

The live check used the connector log and the local SQLite database after the 101-to-102 Linphone calls on 5 October 2026.

## Current architecture

```
Issabel / Asterisk
  -> AMI TCP 5038 (Login + event stream + Ping)
  -> Python connector (connector/)
  -> POST /api/internal/v1/asterisk/events
  -> Laravel CallIngestService
  -> calls + call_events
  -> CallPopupUpdated (broadcast driver is log)
```

The connector is read-only toward Asterisk. It sends `Login` and `Ping` only. Laravel owns call state. There is no popup screen.

There is no table named `call_logs`. Call history is the `calls` table. Each ingest attempt is a row in `call_events`.

## Existing files

Python:

- `connector/main.py` — load `.env`, start AMI, POST each kept frame
- `connector/config.py` — required `AMI_*` and `LARAVEL_*` settings
- `connector/ami_client.py` — TCP session, login, frame reader, reconnect, ping
- `connector/event_handler.py` — keep `DialBegin`, `DialEnd`, `Hangup` and map them
- `connector/laravel_client.py` — bearer POST, retry, stop on 401/403
- `connector/logger.py` — redact secrets
- `connector/.env` — local host, user, secret, ingest URL, token (not in git)
- `connector/.env.example` — the same variable names, empty secrets

Laravel:

- `backend/routes/api.php` — `POST /api/internal/v1/asterisk/events`
- `backend/app/Http/Middleware/EnsureAsteriskIngestToken.php`
- `backend/app/Http/Requests/StoreAsteriskEventRequest.php`
- `backend/app/Http/Controllers/Internal/AsteriskEventController.php`
- `backend/app/Services/CallIngestService.php`
- `backend/app/Support/CallStateMachine.php`
- `backend/app/Events/CallPopupUpdated.php`
- `backend/app/Broadcasting/OperatorPopupChannel.php`
- `backend/routes/channels.php`
- `backend/app/Models/Call.php`
- `backend/app/Models/CallEvent.php`
- `backend/app/Models/Extension.php`
- `backend/database/migrations/2026_10_05_120001_create_calls_table.php`
- `backend/database/migrations/2026_10_05_140000_create_asterisk_ingest_tables.php`

## AMI connection and authentication

`connector/.env` points at `192.168.37.106:5038` with username `smartpbx`. The process refuses to start if the host, username, secret, ingest URL, or token is empty.

Login sends `Action: Login`, `Username`, `Secret`, and `Events: on`. Success is `Response: Success`. The banner must contain `Asterisk Call Manager`. A failed login is logged and the socket is closed. Backoff is 1, 2, 4, 8, then 30 seconds.

After login, the client reads frames split on a blank line. After 30 idle seconds it sends `Ping`. A missing `Pong` reconnects. If AMI rejects Ping for permission, ping is disabled and the read timeout becomes 90 seconds.

The secret is sent only on the socket. The logger replaces that secret and the Laravel token if they appear in a log line.

## Events currently received

The connector does not log frames it drops. The only live evidence is what it POSTed.

After `AMI login succeeded` at 21:41:41, two calls produced four accepted posts:

| Time | Type | Linkedid | Extension | HTTP |
|---|---|---|---|---|
| 21:48:58 | `call.ringing` | `1791224333.7` | 102 | 200 |
| 21:49:00 | `call.answered` | `1791224333.7` | 102 | 200 |
| 21:49:25 | `call.ringing` | `1791224360.24` | 102 | 200 |
| 21:49:28 | `call.answered` | `1791224360.24` | 102 | 200 |

No `call.ended` was posted. The database matches that:

| id | status | direction | phone | extension | user | linkedid | uniqueid | ended | duration |
|---|---|---|---|---|---|---|---|---|---|
| 1 | answered | inbound | 101 | 102 | 4 | 1791224333.7 | 1791224333.9 | null | null |
| 2 | answered | inbound | 101 | 102 | 4 | 1791224360.24 | 1791224360.26 | null | null |

`call_events` has two `call.ringing` and two `call.answered` rows, all applied. There is no hangup row.

The parser only accepts AMI `DialBegin`, `DialEnd`, and `Hangup`. Every other event is discarded with no log.

## How call fields are taken

Caller number: AMI `CallerIDNum`, trimmed, max 40 characters. `CallerIDName` is stored separately. An empty caller drops `DialBegin`. Empty caller is allowed only on `call.ended`.

Called extension: `DialString` when it is 2–6 digits. Otherwise the first 2–6 digits of `DestChannel`, then `Channel`, for `SIP/`, `PJSIP/`, or `Local/`. A frame with no extension is dropped. A frame whose extension equals `CallerIDNum` is dropped.

Direction: not read from AMI. `CallIngestService` always writes `inbound`.

Linked id: `Linkedid`, or `Uniqueid` if `Linkedid` is missing.

Unique id and channel: for `DialBegin` and `DialEnd`, `DestUniqueid` and `DestChannel`, falling back to `Uniqueid` and `Channel`. For `Hangup`, `Uniqueid` and `Channel`.

Start time: the connector clock in UTC at the moment `normalize()` runs. No AMI time field is read. Laravel stores that value as `started_at`.

Answer: `DialEnd` with `DialStatus` `ANSWER` becomes `call.answered`. The first one sets `answered_at` to the connector clock. Another `DialEnd` becomes `call.ended` and carries `DialStatus` (`NOANSWER`, `BUSY`, `CANCEL`, and the rest).

Hangup: AMI `Hangup` becomes `call.ended`. `Cause` is sent as `dial_status` (fallback `DialStatus`). This path did not fire for the two live calls.

Duration: only when a terminal event is applied and `answered_at` already exists. Seconds are `ended_at - answered_at`, stored in both `duration_seconds` and `duration`. Unanswered endings leave duration null. Both live rows are still `answered`, so duration was never calculated.

Agent: the extension row must be active and belong to an active user whose role is `operator`. The call stores `user_id` and `extension_id`. Extension 102 is user 4.

Internal calls: if the caller digits match any extension, Laravel ignores the event unless `RECORD_INTERNAL_CALLS=true`. That flag is true in the local `.env` only. The Python self-call filter (`extension == caller`) still runs before the POST.

## Laravel receive path

`POST /api/internal/v1/asterisk/events` uses `auth:sanctum`, `EnsureAsteriskIngestToken`, and `throttle:asterisk-ingest` (600 requests per minute per token).

The body must be JSON. Required fields are `event_id` (UUID), `event_type` (`call.ringing`, `call.answered`, `call.ended`), `occurred_at`, Asterisk `uniqueid`, `linkedid`, `channel`, and `call.extension`. Caller number is required except on `call.ended`.

`CallIngestService` locks by `event_id`. A repeat returns the stored result and does not move timestamps. A new `call.ringing` inserts one `calls` row keyed by (`asterisk_linkedid`, `extension_number`). Later events for that pair update the same row. Unknown, inactive, or non-operator extensions return 202 and do not create a call. Answer or hangup before any ringing is ignored.

State machine:

- ringing + ringing stays ringing
- ringing + answered becomes answered
- ringing + ended becomes missed, busy, or failed from `dial_status`
- answered + ended becomes ended
- a terminal status does not move backward

After a real status change, Laravel dispatches `CallPopupUpdated` on private channel `operators.{userId}`. The payload includes call id, status, extension, caller number, caller name, customer, `started_at`, `answered_at`, and up to five older calls with the same normalized number. Channel join allows only that active operator.

`BROADCAST_CONNECTION=log`. The event is not delivered to a browser. No Blade, Livewire, or JavaScript listener subscribes to `CallPopupUpdated`. The call log page is a normal refresh of `/calls`.

## Schema

`calls` (original migration plus ingest columns):

- identity and links: `id`, `customer_id`, `user_id`, `extension_id`
- numbers: `phone`, `caller_number_raw`, `caller_number_normalized`, `caller_name`, `extension`, `extension_number`
- telephony: `direction`, `status`, `unique_id` (unique), `asterisk_uniqueid`, `asterisk_linkedid`
- times: `started_at`, `answered_at`, `ended_at`, `duration`, `duration_seconds`

`call_events`: `event_id` unique, nullable `call_id`, `event_type`, JSON `payload`, `applied`, `created_at`.

`extensions`: unique `number`, unique nullable `user_id`, `label`, `is_active`.

## Error handling and logging

AMI socket errors reconnect with backoff. Login failure logs host, port, and the AMI message. Unexpected banners raise a connection error.

Laravel 401/403 stops the connector until restart. 429, 500, 502, 503, 504, and connection errors retry three times (0.5s, 1s, 2s) with the same `event_id`. After that the event is dropped. 400, 404, and 422 are not retried.

Validation failures log the event id and field names, not values or the token. Ingest success logs event id, type, linked id, extension, outcome, and HTTP status. Broadcast exceptions are logged and do not fail the HTTP response.

Python and Laravel both redact bearer tokens and secret-like assignments. AMI login still logs the username.

## Security

What is in place:

- AMI secret and API token come from the environment, not from source.
- `connector/.env` and `backend/.env` are gitignored.
- The ingest user is the inactive account `connector@smartpbx.internal` and cannot sign in.
- The token must be a real Sanctum personal access token with ability `asterisk:ingest`. A wildcard ability is rejected. A browser session is not accepted.
- The route is rate-limited.
- Operator popup channel membership is limited to the active operator who owns that user id.

Concerns in this local setup:

- Ingest URL is `http://127.0.0.1:8000/...`. The token travels without TLS. Acceptable only on this machine.
- AMI is plaintext TCP. The manager secret crosses the LAN in clear text.
- `Events: on` asks for the full event stream. The planned manager `eventfilter` and empty `write` permission are not enforced by the connector. Ping is an AMI write. If the PBX user has write permission, the connector still only sends Login and Ping, but the PBX account may allow more.
- Dropped AMI frames leave no audit trail, so a missed hangup looks like a healthy session.
- `unique_id` is replaced on every applied event. On the live rows it changed from the ringing id to the answer `DestUniqueid`. A later event can hit the unique constraint if that id is already used.

## Available data for a popup

Present on the two live calls:

- caller number (`101`)
- called extension (`102`)
- operator user id (`4`)
- linked id
- unique id (the answer leg, not the first leg)
- start event and `started_at`
- answer event and `answered_at`
- a direction column, always `inbound`

Present in code, absent from the live calls:

- hangup / `call.ended`
- `ended_at`
- duration

Present as an event class, not on screen:

- `CallPopupUpdated` with caller, extension, status, times, customer, and history

## Missing data and problems

1. Hangup never reached Laravel for two completed calls. Both rows are stuck on `answered`. A popup would stay up after the call ends. The likely cause is `event_handler.py` dropping the `Hangup` frame: extension is taken from the channel, and the frame is discarded when that extension equals `CallerIDNum`. Dest-channel hangups on Issabel often look like that. Dropped frames are not logged, so this is not proven from a captured frame.
2. Direction is not detected. An extension-to-extension call and an outside inbound call are both stored as `inbound`. Outbound is never set by ingest.
3. Start and answer times are the connector clock, not an Asterisk timestamp.
4. The broadcast driver is `log`. Nothing in the browser receives `CallPopupUpdated`.
5. There is no popup view. `/calls` only shows history after a refresh.
6. The connector acknowledges one HTTP call before reading the next AMI frame. A slow Laravel response delays the following event. That is acceptable at this size, and it is not a queue.
7. `RECORD_INTERNAL_CALLS` is a local switch. With it off, 101 calling 102 is ignored again.

## Recommended changes

Stay on this AMI-to-Laravel path. Do not add another service or another telephony API.

1. Capture one raw `Hangup` (and the `DialEnd` that ends the call) from this Issabel, then adjust the extension and self-call checks so the called extension still emits `call.ended`. Log the AMI event name when a `Dial*` or `Hangup` frame is dropped, without logging secrets.
2. Keep direction honest: set it from the AMI context or channel role when the captured frames show it. Until then, do not treat the stored `inbound` value as the real direction.
3. Prefer an Asterisk timestamp when the captured frame has one. Keep the connector clock as the fallback.
4. Stop overwriting `unique_id` after the first event. Keep `asterisk_linkedid` as the call key.
5. A popup page can read the existing `CallPopupUpdated` payload. The screen and the current log broadcast driver are the gap. Do not invent a second event format.

## Status

**NOT READY FOR CALL POPUP**

Ringing and answer for extension 102 are real and stored, including caller `101`, linked id, and the operator user. Hangup is not. Both tested calls are still `answered`, with no end time and no duration, so a popup cannot clear. Direction is hardcoded to inbound. The broadcast event exists, but it is written to the log and no screen listens for it.
