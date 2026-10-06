# SmartPBX Test Plan

Status: what to automate and what to rehearse for the Call Popup MVP. Write the tests alongside each roadmap phase, not after the UI is finished.

## Layers

| Layer | Where | Proves |
| --- | --- | --- |
| Unit | `backend` PHPUnit | phone normalization and call state transitions |
| Feature | `backend` PHPUnit, HTTP and database | auth, policies, ingest, lookup, history scope |
| Unit | `connector` unittest | AMI fixture to canonical JSON |
| Manual | a real or lab Issabel | popup latency and event names on that PBX |

No browser end-to-end suite is required for the MVP. Feature tests cover the HTTP and service behavior. One manual call on the target PBX covers AMI reality, which fixtures cannot invent.

Use a separate MySQL or SQLite testing database. PHPUnit's `RefreshDatabase` is acceptable. Do not point tests at a PBX.

## PHP unit tests

### PhoneNormalizer

Country code fixed at 98 in the test.

| Input | Expected |
| --- | --- |
| `09121234567` | `9121234567` |
| `+98 912 123 4567` | `9121234567` |
| `00989121234567` | `9121234567` |
| `02188776655` | `2188776655` |
| `1001` | empty / invalid |
| `abc` | empty / invalid |
| empty | empty / invalid |

### Call state

Drive `CallIngestService` or a pure transition object with the rows below.

| Sequence | Final status |
| --- | --- |
| ringing | ringing |
| ringing, ringing | ringing, one row |
| ringing, answered, ended | ended, duration set |
| ringing, ended NOANSWER | missed |
| ringing, ended BUSY | busy |
| ringing, ended CHANUNAVAIL | failed |
| answered, then a late ringing | answered |
| ended, then a late answered | ended |

## PHP feature tests

### Authentication

- Unknown email cannot sign in.
- Inactive user cannot sign in.
- Active administrator reaches `/users`.
- Active operator opening `/users` receives 403.
- Guest opening `/calls` is redirected to login.

### Users and extensions

- Administrator creates an operator and an extension assigned to that operator.
- Assigning a second extension to the same user fails validation.
- Two extensions cannot share a number.
- Demoting the only administrator fails.

### Customers

- Creating a customer stores raw and normalized numbers.
- A second customer with the same number, typed in a different format, fails.
- An operator can create a customer.
- An operator deleting a customer receives 403.
- Deleting a customer nulls `calls.customer_id` and keeps the call.

### Ingest

Use a Sanctum token with ability `asterisk:ingest` and the fixture body from `API-DESIGN.md`.

- No token returns 401.
- A logged-in operator session without the bearer token returns 401.
- A token without the ability returns 403.
- `call.ringing` for a known extension inserts one `calls` row and one `call_events` row.
- When the number matches a customer, the response includes that customer id.
- When the number matches nothing, `customer` is null and the call still exists.
- Repeating the same `event_id` returns `duplicate: true` and does not change timestamps.
- A second ringing with a new `event_id` but the same linked id and extension does not insert a second call.
- Unknown extension returns 202 and inserts no call.
- Caller number equal to an existing extension number returns 202.
- Answer then end updates the same row to `ended` and sets `duration_seconds`.
- Invalid JSON fields return 422.

Broadcast assertion: the ringing case fakes Laravel's event bus and expects `CallPopupUpdated` for that operator's user id only.

### History

- Operator A does not see operator B's calls.
- Administrator sees both.
- Phone filter `09121234567` finds a call stored from `+989121234567`.
- Popup history returns at most five older calls and excludes the current id.

### Sweeper

- A `ringing` row with `started_at` 4 minutes ago becomes `incomplete`.
- A `ringing` row with `started_at` 1 minute ago stays `ringing`.

## Python unit tests

Fixtures are redacted AMI frames saved as text files under `connector/tests/fixtures/`. Start with the samples in `ASTERISK-INTEGRATION.md`, then add or replace them after the PBX capture.

| Fixture | Expected canonical result |
| --- | --- |
| `dialbegin_inbound.txt` | `call.ringing`, extension `1001`, caller unchanged |
| `dialend_answer.txt` | `call.answered` |
| `dialend_noanswer.txt` | `call.ended`, dial status `NOANSWER` |
| `hangup_agent.txt` | `call.ended`, extension from channel |
| `varset_noise.txt` | no event |
| `dialbegin_no_extension.txt` | no event |
| `pjsip_dialbegin.txt` | extension parsed from `PJSIP/1001-...` |

Also assert:

- The publisher builds an `Authorization` header and a UUID `event_id`.
- A second attempt after a mocked 503 reuses the same `event_id`.
- A mocked 422 is not retried.
- Log formatting of a login failure does not contain the secret string used in the test.

Do not open a real socket in unit tests. Pass frames into `normalize.py` as strings.

## Manual rehearsal

Run this once on the lab Issabel before calling the integration done.

1. Sign in as the operator who owns extension `1001`.
2. Place an external call from a mobile that exists on a customer.
3. Confirm the popup names that customer within two seconds of the ring.
4. Answer, then hang up. The popup or history shows `ended` and a duration.
5. Call again from an unsaved number. The popup says unknown. Save the customer from that screen and confirm the number is now stored once.
6. Let a call ring out. History shows `missed`.
7. Sign in as a second operator and confirm they did not see the popup.
8. Stop the connector, place a call, start the connector, and confirm a new call pops again. The call placed while it was down may be missing. That matches the no-disk-queue decision.
9. Inspect connector and Laravel logs for the secret and the bearer token. Neither string should appear.

Record the actual event names from step 2 in the integration doc if they differ from the plan.

## Commands, once the apps exist

From `backend/`:

```
php artisan test
```

From `connector/`:

```
python -m unittest discover -s tests
```

A change to ingest, normalization, policies, or phone matching is not complete until the related tests above have been run and their results are reported.

## Risks the tests do not remove

- The lab PBX may emit different channel names than production Issabel.
- Reverb through Nginx needs the manual browser check. PHPUnit does not prove the websocket proxy.
- A clock skew of more than a minute between connector and Laravel affects only `occurred_at`, which is informational. Call duration uses Laravel's database timestamps set from the ingest clock, so test that both answer and end are written by Laravel.
