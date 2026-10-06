# SmartPBX Security

Status: security design for the Call Popup MVP. This document states the controls to build. It is not an authorization to weaken them during development.

## Assets

| Asset | Where it lives |
| --- | --- |
| User passwords | Laravel hash in `users.password` |
| Connector bearer token | Sanctum hashed token in `personal_access_tokens` |
| AMI username and secret | Connector environment and the PBX manager profile |
| Customer names, notes, and phone numbers | MySQL |
| Call history | MySQL |
| Session cookies | Operator and administrator browsers |

## Secrets

- No password, token, or AMI secret is hard-coded, committed, or placed in a fixture that gets committed.
- `.env`, connector env files, and PBX manager snippets with real secrets stay out of git. Commit `.env.example` with empty values.
- Production and development use different tokens and different AMI secrets.
- Logs and exception pages redact `password`, `secret`, `token`, `authorization`, and AMI `Secret` fields.
- A token pasted into a ticket is revoked and replaced.

Sanctum is installed in phase 1, including the `personal_access_tokens` table. The connector token itself is created with Artisan in phase 3, when the ingest route exists, and is shown once. Store it in the connector environment. Revoke it by deleting the Sanctum token row. Rotation is create-new, deploy, then delete-old. Overlap is allowed so a restart does not drop calls.

## Identity and sessions

- Breeze session guard, Laravel's password hasher, and Laravel's login throttle.
- Cookie: `HttpOnly`, `SameSite=Lax`, and `Secure` when `APP_ENV=production`.
- `APP_DEBUG=false` in production so validation and stack traces are not shown to browsers.
- Inactive users fail authentication even with the correct password.
- There is no self-registration and no public password reset in the MVP. An administrator sets a temporary password and the user changes it after sign-in. If that hand-off is too loose for a site, the administrator sets the password in person. Do not bolt on a mailbox flow unless a later task asks for it.

Password rule for created users: at least 12 characters. Laravel's default hash options stay as the framework ships them.

## Authorization

Policies, not controller `if` statements.

| Action | Administrator | Operator |
| --- | --- | --- |
| Manage users | yes | no |
| Manage extensions | yes | no |
| Create and edit customers | yes | yes |
| Delete customers | yes | no |
| View all call history | yes | no |
| View own call history | yes | yes |
| Record a call log via `POST /api/calls` | yes | no |
| See own popup | no | yes |
| Subscribe to another user's popup channel | no | no |
| Call the ingest API with a browser session | no | no |

The ingest route ignores session cookies. It accepts only a Sanctum bearer token with ability `asterisk:ingest`. A user personal token, if one is ever created, does not receive that ability.

The last active administrator cannot be deactivated or changed to operator. Enforce this in `UserService`, inside a transaction, so two admins cannot remove each other concurrently and leave zero administrators.

## Connector and AMI exposure

- AMI listens on a private address and a host firewall allows only the connector IP.
- The AMI user has no write permission for call control.
- The connector process runs as its own OS user, not root, with read access only to its env file.
- Laravel is the only component that holds MySQL credentials.
- The connector host does not have a MySQL account.
- Agent browsers cannot open port 5038 or the ingest URL without the bearer token. The ingest URL is still authenticated, in case the private network is wider than intended.

## Input and output

- Form Requests validate every browser write and the ingest body.
- Eloquent and the query builder send bound parameters. Do not interpolate request data into SQL.
- Blade escapes customer names, notes, and caller id names by default. Do not render those fields with raw HTML.
- Livewire and Blade forms keep CSRF protection on.
- Customer notes are plain text, maximum 500 characters.
- Phone search input is normalized before it is used as a lookup value.

## Rate limits

- Login: Laravel's `throttle` on the login POST.
- Ingest: 600 requests per minute per token.
- Customer create from the popup shares the normal authenticated route limits. It does not need a separate limiter for the MVP.

## Production transport

- HTTPS for the Laravel site and for `LARAVEL_INGEST_URL`.
- WSS for Reverb, proxied by the same Nginx server.
- TLS certificates are installed on Nginx. Laravel sees `X-Forwarded-Proto` from that local proxy only. Trust only the local proxy, not arbitrary forwarded headers.
- AMI stays on the private LAN. Wrapping AMI in TLS is optional and not required for the MVP when the path is a single private segment. If the connector and PBX cross a network that is not private, stop and add a VPN or TLS before go-live.

## Least privilege

| Principal | Privilege |
| --- | --- |
| Administrator user | application admin only, no SSH assumed |
| Operator user | own calls and shared customer book |
| Connector token | `asterisk:ingest` only |
| AMI manager | read call events from one source IP |
| MySQL application user | DML on SmartPBX tables, no global grants, no `FILE` |
| Deploy user | application directory, not the PBX |

## Development exceptions

A local HTTP ingest URL and `APP_DEBUG=true` are acceptable on a developer machine.

Any exception on a shared or production host is written into `DEPLOYMENT.md` for that site: what was disabled, why, who approved it, and when it will be removed. "It was easier" is not a recorded reason.

Do not point a development connector at a production PBX. Do not copy production customer data into laptops without the site owner's approval.

## Logging and audit

Log these at info:

- user id on sign-in and sign-out
- ingest `event_id`, `event_type`, linked id, extension, applied or ignored, HTTP result
- AMI connect, disconnect, and login failure without the secret

Log these at warning:

- authorization failures
- ingest validation failures
- stale calls marked `incomplete`

Do not log request bodies for ingest. The stored `call_events.payload` is the support record and is already limited to the canonical fields.

## Privacy of call data

Caller numbers and customer notes are operational personal data. The MVP shows them only to authenticated users of that installation. Backups of MySQL inherit the same access limits as the database host. There is no export endpoint in the MVP.

## Checks before the first production call

- `git grep` finds no live secrets in the tree.
- `.env` is gitignored.
- An unauthenticated `POST` to the ingest URL returns 401.
- An operator session calling the ingest URL returns 401.
- An operator opening another user's history or broadcast channel receives 403.
- From a laptop that is not the connector host, TCP 5038 to the PBX fails.
- A sample log line from a failed AMI login does not contain the secret.
