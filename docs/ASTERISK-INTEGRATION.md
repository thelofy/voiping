# SmartPBX Asterisk Integration

Status: integration plan for Issabel/Asterisk AMI. No PBX changes are applied by this document.

## Boundary

SmartPBX talks to Issabel through AMI only. Do not copy, patch, or install code into Issabel's web root, FreePBX modules, or Asterisk dialplan for this MVP.

Creating an AMI manager account is PBX configuration, not an Issabel source change. An administrator does it on the PBX. SmartPBX stores the resulting username and secret in the connector environment, not in git.

The connector is read-only. It sends AMI `Login` and then reads events. It does not send `Originate`, `Hangup`, `Redirect`, `Bridge`, or command actions.

## Network

- AMI binds on the PBX, TCP `5038`, to the private interface when Issabel allows a bind address.
- The PBX firewall allows `5038` only from the connector host.
- The manager user `deny`s `0.0.0.0/0.0.0.0` and `permit`s the connector host only.
- AMI is not published through the SmartPBX web nginx and is not reachable from agent browsers.

Example manager profile, to be created on the PBX and checked against that Issabel version's manager screen. Secret values stay on the PBX.

```
[smartpbx]
secret = <set on the PBX>
deny = 0.0.0.0/0.0.0.0
permit = <connector-host-ip>/255.255.255.255
read = system,call
write =
eventfilter = !Event: VarSet
eventfilter = !Event: Newexten
eventfilter = !Event: RTCPReceived
eventfilter = !Event: RTCPSent
```

`write` is empty on purpose. `read = call` is the minimum if the target Asterisk still delivers `DialBegin`, `DialEnd`, and `Hangup`. Add `system` only so `FullyBooted` can confirm a good login during setup. Drop either right if a capture proves it is unnecessary.

`eventfilter` lines reduce noise. Confirm the syntax on the installed Asterisk before relying on it. If the syntax differs, filter in Python and keep the AMI user.

## Connector environment

```
AMI_HOST=
AMI_PORT=5038
AMI_USERNAME=smartpbx
AMI_SECRET=
LARAVEL_INGEST_URL=https://smartpbx.example.com/api/internal/v1/asterisk/events
LARAVEL_API_TOKEN=
LOG_LEVEL=info
HTTP_TIMEOUT_SECONDS=3
```

The process refuses to start when `AMI_HOST`, `AMI_USERNAME`, `AMI_SECRET`, `LARAVEL_INGEST_URL`, or `LARAVEL_API_TOKEN` is empty.

Logs may include host, port, username, linked id, extension, event type, and HTTP status. Logs must not include `AMI_SECRET`, `LARAVEL_API_TOKEN`, or the `Authorization` header.

## Session behavior

1. Open TCP to `AMI_HOST:AMI_PORT`.
2. Read the `Asterisk Call Manager` banner.
3. Send `Login` with `Events: on`.
4. Wait for `Success`. On `Response: Error`, log the message text with secrets redacted and retry with backoff.
5. Read frames separated by a blank line. A frame is `Key: Value` lines.
6. Ignore every event that is not in the map below.
7. On socket error or EOF, close and reconnect. Backoff is 1, 2, 4, 8, then 30 seconds, reset after a successful login.
8. Ping with AMI `Ping` every 30 seconds if no frame arrives. A missed `Pong` within 10 seconds forces a reconnect. This is the one write-like action, and it does not control calls. If the empty `write` permission blocks `Ping`, skip the ping and rely on TCP errors plus a read timeout of 90 seconds.

The connector processes one event at a time and acknowledges it to Laravel before parsing the next. AMI does not wait for that, so the TCP buffer is the queue. That is acceptable for tens of agents. Do not add an internal broker.

## Events that matter

Capture these on the real PBX before freezing the parser. The samples below are planning fixtures, written in AMI text form, and must be replaced if the live PBX disagrees.

### DialBegin → candidate `call.ringing`

```
Event: DialBegin
CallerIDNum: 09121234567
CallerIDName: 
Uniqueid: 1728000000.1
Linkedid: 1728000000.1
DestUniqueid: 1728000000.2
DestChannel: SIP/1001-00000002
DialString: 1001
Context: from-trunk
```

### DialEnd ANSWER → `call.answered`

```
Event: DialEnd
DialStatus: ANSWER
CallerIDNum: 09121234567
Linkedid: 1728000000.1
DestUniqueid: 1728000000.2
DestChannel: SIP/1001-00000002
DialString: 1001
```

### DialEnd without answer → `call.ended`

```
Event: DialEnd
DialStatus: NOANSWER
CallerIDNum: 09121234567
Linkedid: 1728000000.1
DestUniqueid: 1728000000.2
DestChannel: SIP/1001-00000002
DialString: 1001
```

`BUSY`, `CANCEL`, `CHANUNAVAIL`, and `CONGESTION` are also `call.ended`. The dial status is copied into `call.dial_status`.

### Hangup → `call.ended` when DialEnd was missed

```
Event: Hangup
Cause: 16
CallerIDNum: 09121234567
Uniqueid: 1728000000.2
Linkedid: 1728000000.1
Channel: SIP/1001-00000002
```

## Normalization rules

Implemented in `connector/event_handler.py`. A live Issabel capture is still open, so the fixtures are the samples in this document.

On `DialBegin` and `DialEnd` the extension is the party being dialed, first non-empty match:

1. `DialString` when it is 2 to 6 digits.
2. The leading extension in an Issabel dial string such as `102/sip:102@host`.
3. `DestChannel` (`SIP/1001-abc`, `PJSIP/1001-abc`, or `Local/1001@...`).

`Channel` is the caller leg. It is used only for `Hangup`, or when the dialed party cannot be parsed. Caller number is `CallerIDNum`. If that value is the dialed extension and `Channel` is a different extension, the caller is taken from `Channel`.

`call.direction` is `inbound` when the extension was dialed, and `outbound` when that extension placed the call. Laravel stores the dialed extension as inbound and, for an internal caller, a second row as outbound for the caller. The popup is sent only for the inbound row.

If no extension can be parsed, drop the frame in Python. Laravel cannot use it.

Identifiers:

- `asterisk.linkedid` = `Linkedid`, else `Uniqueid`.
- `asterisk.uniqueid` = `DestUniqueid` for dial events, else `Uniqueid`.
- `asterisk.channel` = `DestChannel` for dial events, else `Channel`.

The connector does not normalize country codes.

`occurred_at` is the connector's UTC clock when the frame is parsed. AMI event times are not required for the MVP.

`event_id` is a new UUID4 per emitted canonical event. Retries reuse that same id.

Drop the frame in Python when:

- The event name is not `DialBegin`, `DialEnd`, or `Hangup`.
- No extension was parsed.
- `DialBegin` has an empty caller number.
- The parsed extension equals the caller number (a channel calling itself is noise).

Do not drop a frame because the extension is absent from SmartPBX. The connector does not know the directory.

Internal agent-to-agent calls may still be posted. Laravel ignores them when the caller number matches an extension. That business rule stays in Laravel.

## Mapping table

| AMI input | Canonical event |
| --- | --- |
| `DialBegin` with an extension | `call.ringing` |
| `DialEnd` and `DialStatus=ANSWER` | `call.answered` |
| `DialEnd` and any other `DialStatus` | `call.ended` |
| `Hangup` on a channel whose name yields an extension | `call.ended` |

`Hangup` after a successful `DialEnd` is a duplicate end. Laravel's state machine keeps the first terminal state.

## HTTP delivery

- Method `POST`, body JSON, header `Authorization: Bearer ...`, header `Accept: application/json`, header `Content-Type: application/json`.
- Timeout 3 seconds.
- Retry `429`, `500`, `502`, `503`, `504`, and connection errors three times, waiting 0.5s, 1s, and 2s.
- Do not retry `400`, `401`, `403`, `404`, or `422`.
- After the retry budget, log `event_id`, `event_type`, `linkedid`, and extension, then continue with the next AMI frame.
- There is no disk queue in the MVP. A long Laravel outage loses popups. Issabel still rings the phones. The stale-call sweeper closes rows that never receive an end event.

## First integration task

Before writing a large parser, capture a real inbound call on the target Issabel:

1. Turn AMI debug on for one test call, or run `tcpdump` on the connector to port 5038.
2. Record `DialBegin`, `DialEnd`, and `Hangup` for an external call to a known extension.
3. Save the redacted frames as Python fixture files. Remove secrets before they reach git.
4. Note whether channels use `SIP/` or `PJSIP/`, and which field actually holds the agent extension.
5. Change `connector/event_handler.py` to match those frames. Do not change Laravel's canonical contract unless a required field cannot be produced.

## Issabel items this MVP does not use

- Issabel MySQL CDR and CEL tables
- Issabel REST or browser sessions
- Call files and spool directories
- AGI and dialplan hooks
- Recording files
- The Issabel call-center or operator-panel modules

If a capture shows that this Asterisk build does not emit `DialBegin`, stop and revise this document. Do not paper over that by scraping the Issabel database.
