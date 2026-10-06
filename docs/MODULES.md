# Modules

## Customer module

Purpose: a small directory so a later call popup can name the person who owns a phone number. It is not a CRM. The Python connector never reads this table. Asterisk events still enter through the Laravel ingest API, and Laravel looks up the customer.

### Tables

`customers` already existed. Phone numbers are not stored on that row. They live in `customer_phones`, one or more per customer, which is what call ingest matches.

`customers`:

| Column | Notes |
| --- | --- |
| id | primary key |
| name | required, indexed |
| company_name | optional text. Not a separate companies module. |
| email | optional |
| notes | optional, max 500 characters |
| is_active | default true. Deactivate keeps the row and its numbers. |
| created_at, updated_at | |

`customer_phones`:

| Column | Notes |
| --- | --- |
| customer_id | cascade delete with the customer |
| raw_number | the number as typed, required |
| normalized_number | unique. This is the match key. |
| label | optional, such as mobile or office |

A customer needs at least one phone and can have at most five. The unique index on `normalized_number` rejects the same number on two customers, including a different written form of that number.

### Call matching

Laravel matches a caller when a new `call.ringing` row is created. Python does not look up customers.

```
caller number on the event
  → PhoneNormalizer (the same class used when a customer phone is saved)
  → customer_phones.normalized_number
  → customers.id
  → calls.customer_id
```

The lookup is an exact match. It does not compare the raw number and it does not require `customers.is_active`. An unknown number still creates the call with `customer_id` null. Answer, hangup, and a second hangup update that same row (`asterisk_linkedid` plus extension), so the customer stays on the one call.

### Normalization

`App\Support\PhoneNormalizer` builds `normalized_number` when a customer is saved and when a call is ingested. Search by phone uses the same value, so `09121234567` and `+98 912 123 4567` find the same row. A value that cannot be normalized is rejected on save and cannot be used as a phone search. The country code comes from `PHONE_DEFAULT_COUNTRY_CODE` (default `98`). This matcher was already in the call path, so it was not replaced.

### Relationships

- A customer has many `customer_phones`.
- A customer has many `calls`.
- Deleting a customer deletes the phone rows and sets `calls.customer_id` to null. Call rows stay.
- Deactivating a customer does not change calls and does not stop ingest from attaching that customer when the number matches.

`CallPopupUpdated` is sent on the private channel `operators.{userId}` when a call status changes. The payload has the call id, linked id, status, raw and normalized caller number, extension, and the matched customer name and company. An unknown caller sends `customer: null`. Local delivery uses Laravel Reverb. Tests keep the null broadcast driver. Only an active operator can join the channel. The signed-in layout shows a popup on ringing or answered, and hides it shortly after a terminal status.

When that payload has no customer, the popup shows a short form: name (required), company, and note. The phone is the caller's number and cannot be edited. `POST /api/customers/quick-add` creates the customer through `CustomerService` and sets `customer_id` on the operator's ringing or answered call with that `linkedid`. The popup then shows the saved name. A known customer does not see the form.

### Routes

Session pages, under `auth` and `active`:

| Method | Path | Name |
| --- | --- | --- |
| GET | `/customers` | `customers.index` |
| GET | `/customers/create` | `customers.create` |
| POST | `/customers` | `customers.store` |
| POST | `/api/customers/quick-add` | `api.customers.quick-add` |
| GET | `/customers/{customer}` | `customers.show` |
| GET | `/customers/{customer}/edit` | `customers.edit` |
| PUT | `/customers/{customer}` | `customers.update` |
| PATCH | `/customers/{customer}/active` | `customers.active` |
| DELETE | `/customers/{customer}` | `customers.destroy` |

The list searches by name (substring) and by phone (normalized exact match).

### Authorization

Administrators and active operators can list, view, create, update, activate, and deactivate. Only an administrator can delete, and delete requires a confirmation checkbox. Inactive users cannot open the pages.

### Tests

`backend/tests/Feature/CustomerManagementTest.php` covers create, a missing phone, normalized phone storage, duplicate normalized numbers, update, name search, phone search, deactivate, and activate.
