# SmartPBX Database Design

Status: MySQL schema for the Call Popup MVP. Create it later with Laravel migrations. Do not edit the database by hand.

## Engine and conventions

- MySQL 8, InnoDB, `utf8mb4` / `utf8mb4_unicode_ci`.
- Laravel owns every schema change through migrations.
- Primary keys are `bigint unsigned` identity columns.
- Timestamps are UTC (`created_at`, `updated_at`). Call times are `datetime` UTC as well. The UI converts them to the application timezone.
- Foreign keys are enforced. Use `restrict` on deletes except where this document names `nullOnDelete`.
- Phone numbers and extension numbers are strings, never integers.
- Multi-step writes in `CallIngestService` and customer phone replacement run inside a database transaction.

## Entity relationships

```
users 1──0..1 extensions
users 1──* calls
extensions 1──* calls
customers 1──* customer_phones
customers 1──* calls
calls 1──* call_events
```

A call may exist with a null `customer_id` when the number is unknown. A call may exist with a null `user_id` only if the extension was unassigned between ring and ingest; the ingest service rejects that case and does not insert. Both columns are nullable so historical rows survive later unassignment.

## Tables

### users

Laravel's default user table plus the role and active flag.

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint unsigned PK | |
| name | varchar(120) | |
| email | varchar(255) unique | login |
| password | varchar(255) | Laravel hash |
| role | varchar(20) | `administrator` or `operator` |
| is_active | boolean | default true |
| remember_token | varchar(100) null | |
| created_at, updated_at | timestamps | |

Index: `(role, is_active)`.

No separate roles table. Two roles do not justify a permission matrix yet. Policies read `users.role`.

### extensions

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint unsigned PK | |
| number | varchar(20) unique | PBX extension, exact |
| user_id | bigint unsigned unique null | FK users, nullOnDelete |
| label | varchar(120) null | desk name |
| is_active | boolean | default true |
| created_at, updated_at | timestamps | |

`user_id` unique enforces one extension per user. `number` unique enforces one row per PBX extension.

### customers

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint unsigned PK | |
| name | varchar(160) | indexed |
| company_name | varchar(160) null | display only, not a company record |
| email | varchar(255) null | |
| notes | varchar(500) null | popup excerpt only |
| is_active | boolean | default true. Directory status. Ingest still matches the number. |
| created_at, updated_at | timestamps | |

Index: `(name)` for the administrator search box.

### customer_phones

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint unsigned PK | |
| customer_id | bigint unsigned | FK customers, cascadeOnDelete |
| raw_number | varchar(40) | as the user typed it |
| normalized_number | varchar(20) unique | lookup key |
| label | varchar(40) null | mobile, office, other |
| created_at, updated_at | timestamps | |

The unique index on `normalized_number` is the lookup. It also prevents two customers from claiming one number.

Do not index `raw_number` for search. Agents and the PBX will not agree on formatting. Search history and customers through the normalized value.

### calls

The call log table. Manual history rows and Asterisk ingest share it. Ingest also fills `caller_number_raw`, `caller_number_normalized`, `extension_number`, `asterisk_linkedid`, and `asterisk_uniqueid`. For an ingested call those mirror `phone`, the normalized lookup key, `extension`, and `unique_id`. `duration_seconds` matches `duration`. Direction for an ingested call is `inbound`.

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint unsigned PK | |
| customer_id | bigint unsigned null | FK customers, nullOnDelete |
| user_id | bigint unsigned null | FK users, nullOnDelete. The agent. Operators see only their rows. |
| phone | varchar(40) | remote party, as recorded |
| extension | varchar(20) null | extension number snapshot |
| direction | varchar(16) | `inbound` or `outbound` |
| status | varchar(20) | see status list |
| unique_id | varchar(64) null unique | PBX or other call identifier, when available |
| started_at | datetime | |
| answered_at | datetime null | |
| ended_at | datetime null | |
| duration | int unsigned null | whole seconds from `answered_at` to `ended_at` |
| asterisk_linkedid | varchar(64) null | correlation key, with `extension_number` |
| asterisk_uniqueid | varchar(64) null | agent channel id |
| extension_number | varchar(20) null | same snapshot as `extension` for ingest |
| extension_id | bigint unsigned null | FK extensions, nullOnDelete |
| caller_number_raw | varchar(40) null | PBX caller string |
| caller_number_normalized | varchar(20) | lookup key, empty when the number is not a phone |
| caller_name | varchar(120) null | AMI caller id name |
| duration_seconds | int unsigned null | same value as `duration` |
| created_at, updated_at | timestamps | |

Direction:

| Value | Meaning |
| --- | --- |
| inbound | A call received by an extension. |
| outbound | A call placed from an extension. |

Status values, also defined on `App\Enums\CallStatus`:

| Value | Meaning | Times |
| --- | --- | --- |
| ringing | Alerting, not answered | `started_at` only |
| answered | Answered and still in progress | `answered_at` set, `ended_at` empty |
| ended | Answered, then finished | both answer and end set. `duration` is end minus answer |
| missed | Finished without an answer | `ended_at` set, `answered_at` empty |
| busy | Destination was busy | `ended_at` set, `answered_at` empty |
| failed | Could not be completed | `ended_at` set, `answered_at` empty |
| incomplete | No terminal result was recorded | `ended_at` empty |

Indexes:

- unique `(unique_id)`
- unique `(asterisk_linkedid, extension_number)`
- `(phone, started_at)`
- `(caller_number_normalized, started_at)`
- `(extension_id, started_at)`
- `(extension, started_at)`
- `(direction, started_at)`
- `(user_id, started_at)`
- `(status, started_at)`

`duration` is written only for `ended`. It is not computed in every history query.

### call_events

Diagnostic rows for idempotency and support. They store the canonical event, not the raw AMI frame.

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint unsigned PK | |
| event_id | char(36) unique | connector UUID |
| call_id | bigint unsigned null | FK calls, cascadeOnDelete |
| event_type | varchar(32) | |
| payload | json | canonical JSON only |
| applied | boolean | false when ignored as out of order or unknown extension |
| created_at | timestamp | no updated_at |

A nightly scheduled deletion removes `call_events` older than 14 days. `calls` are kept.

## Phone normalization

Implemented once in a Laravel class such as `App\Support\PhoneNormalizer`. The connector stores and forwards the PBX string unchanged.

Default configuration:

```
PHONE_DEFAULT_COUNTRY_CODE=98
```

Algorithm:

1. Trim the input.
2. If it contains `@` or letters, reject it as a phone number.
3. Keep a leading `+` only long enough to detect an international form, then work with digits.
4. Drop a leading `00` international prefix.
5. Drop a leading country code when the remaining national number is at least 8 digits.
6. Drop a single leading trunk `0`.
7. A result of exactly 3 digits is kept, so a lab extension such as `101` can be saved on a customer and matched. Any other result must be 8 to 15 digits. Otherwise the number cannot be stored on a customer, and a call still stores the raw value with an empty normalized value. Empty normalized values do not match a customer.

Examples for country code 98:

| Input | Normalized |
| --- | --- |
| `09121234567` | `9121234567` |
| `9121234567` | `9121234567` |
| `+98 912 123 4567` | `9121234567` |
| `00989121234567` | `9121234567` |
| `02188776655` | `2188776655` |
| `101` | `101` |
| `1001` | rejected for customer phones; too short |

Customer forms use the same class, so a number typed as `0912...` matches a PBX value of `+98912...`.

Lookup is `where normalized_number = ?` with a bound parameter. There is no `LIKE` suffix search in the MVP.

Changing `PHONE_DEFAULT_COUNTRY_CODE` after numbers are stored requires a one-off renormalization command. Do not change it casually.

## Ingest transaction

For a new `call.ringing`:

1. Insert `call_events` with the unique `event_id`. A duplicate key means stop and return the existing result.
2. Find the active extension and active operator. If missing, mark the event `applied = false` and commit.
3. Normalize the caller. If that value equals any `extensions.number` and `RECORD_INTERNAL_CALLS` is false, this is an internal call: mark `applied = false` and commit. When the flag is true, continue and store the call.
4. Look up `customer_phones.normalized_number` through `CustomerMatcher`. No `is_active` filter. No row means `customer_id` stays null and the call is still inserted. The latest five other calls with the same normalized number are included in the broadcast payload.
5. Insert the `calls` row with status `ringing`.
6. Commit, then broadcast.

Broadcast after commit so a listener cannot see a call that rolled back.

For `call.answered` and `call.ended`, lock the call row with `lockForUpdate`, apply the state machine, and commit before broadcasting.

Customer create with several phones is one transaction: insert the customer, insert the phones, and surface a unique-constraint failure as a validation error on the duplicate number.

## History query

Operator history:

```sql
select * from calls
where user_id = ?
order by started_at desc
limit 25 offset ?
```

The `(user_id, started_at)` index serves that query. Administrator filters add extension, direction, status, and phone only when those inputs are present. The phone filter is a substring match on `calls.phone` until phone normalization exists.

Popup history uses the same normalized number, excludes the current call id, and limits 5. It is loaded in the ingest transaction so the broadcast is complete.

## Retention and deletion

- Users are deactivated, not hard-deleted, when they own calls. Hard delete is allowed only for a user with no calls and no extension.
- Deleting a customer deletes `customer_phones` and nulls `calls.customer_id`.
- Deleting an extension nulls `calls.extension_id` and keeps `extension_number`.
- Call rows are not deleted by the application in the MVP.

## Migration order

1. `users` adjustments for `role` and `is_active`.
2. `extensions`.
3. `customers`.
4. `customer_phones`.
5. `calls`.
6. `call_events`.

Sanctum's `personal_access_tokens` migration ships with phase 1. The connector token is created in phase 3. It is a Sanctum token on a non-login service user, or on the first administrator, with the ability `asterisk:ingest`. The service user is preferable: `users.is_active = false` for interactive login, while the token ability still authorizes the ingest route. Document the chosen row in the seed so nobody uses that account in the browser.
