# AMI Hangup Debug

## Root cause

A completed call between extensions 101 and 102 produced `call.ringing` and `call.answered`, then stayed on `answered`. `ended_at` and `duration_seconds` stayed null.

Asterisk sends two `Hangup` frames, one per channel. On those frames `DialString` and `DestChannel` are empty. `CallerIDNum` is the extension of that same channel.

Captured legs:

```
Event: Hangup
CallerIDNum: 102
Channel: PJSIP/102-00000007
Cause: 16
Cause-txt: Normal Clearing

Event: Hangup
CallerIDNum: 101
Channel: PJSIP/101-00000008
Cause: 16
Cause-txt: Normal Clearing
```

The parser took the extension from `Channel`, so both frames had `extension == CallerIDNum`. The self-call check dropped both. That check is wrong for `Hangup`. It is not a call from an extension to itself. `DialBegin` still works because `DestChannel` is the other party, so the extension and `CallerIDNum` differ.

## Exact fix

In `connector/event_handler.py`, `Hangup` no longer uses `extension == caller`.

`DialBegin` and `DialEnd` still drop a frame when the parsed extension equals `CallerIDNum`.

`Hangup` still requires an extension from `Channel`:

- `PJSIP/<extension>-...`
- `SIP/<extension>-...`
- `Local/<extension>-...` or `Local/<extension>@...`

The frame becomes `call.ended`. `Cause` is still `call.dial_status`. `Cause-txt` stays in the diagnostic log. The ingest JSON has no separate cause-text field.

`event_id`, `linkedid`, `uniqueid`, `channel`, and caller number are unchanged. The connector still POSTs with the same token and still redacts the AMI secret and the bearer token.

Laravel was not changed. A call row is still one pair of `asterisk_linkedid` and `extension_number`, and that extension is the called party taken from `DestChannel` on `DialBegin`.

- The hangup whose `Channel` is the called extension matches that row and sets `ended`.
- The hangup whose `Channel` is the caller extension has no row for that pair, so Laravel returns 202 and does not insert a call.
- A second hangup for the called extension finds a terminal row. The state machine does not move it, and `ended_at` stays as it was.

## Limitation

There is no separate AMI field in these frames that means “this extension called itself.” The old `Hangup` check used `CallerIDNum` as if it were the other party. It is not. A true self-call is still dropped on `DialBegin` and `DialEnd` when the extension equals `CallerIDNum`. `Hangup` is no longer part of that check. No new self-call algorithm was added.

## Tests

Python `connector/tests/test_event_handler.py`:

- `test_hangup_legs_are_not_self_calls` — both captured legs become `call.ended`, extensions 102 and 101.
- `test_self_call_and_empty_ringing_caller_are_dropped` — `DialBegin` from 1001 to 1001 is still dropped.

Laravel `backend/tests/Feature/AsteriskEventIngestTest.php`:

- `test_both_hangup_legs_end_one_call` — ringing, answer, caller-leg hangup, called-leg hangup, then a repeat hangup. One row, status `ended`, `ended_at` set, `duration_seconds` 30. The repeat does not apply.

## Diagnostic logging

Dropped `DialBegin`, `DialEnd`, and `Hangup` frames still log:

```
===== AMI DIAGNOSTIC EVENT =====
```

and:

```
DROPPED AMI EVENT reason=... event=Hangup
```

Accepted hangups log the same block with `result=accepted`. Secrets and the `Authorization` header are not logged.

## Manual test

Restart the connector so it loads this parser. The process that is already running will keep the old filter until it is stopped.

```bash
cd /Users/abc/Desktop/voip-projects/connector
pkill -f "python3 main.py" || true
python3 main.py
```

Wait for `AMI login succeeded`. From Linphone, call between 101 and 102, answer, then hang up.

The terminal should show `event=Hangup` with `result=accepted` for both legs, then:

```
Ingest accepted ... event_type=call.ended ... http_status=200
```

for the called extension. The other leg can be `http_status=202`.

In the call log, that call should be `ended`, with `ended_at` and a duration. A second call row for the same linked id should not appear.
