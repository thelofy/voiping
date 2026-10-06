# SmartPBX Product Requirements

Status: approved for planning. No application code exists yet.

## Product

SmartPBX is a commercial VoIP management platform installed beside an existing Issabel/Asterisk PBX. The first release is a Call Popup MVP: when an outside caller rings an agent, that agent sees who is calling and the recent history of that number.

One installation serves one organization and one PBX. Multi-tenant SaaS, multiple PBXs, and call control are outside this MVP.

## Users

| Role | Who | Can do |
| --- | --- | --- |
| Administrator | PBX/IT owner | Sign in, manage users, extensions, and customers, view every call |
| Operator | Agent who answers calls | Sign in, receive popups for the assigned extension, view own call history |

There is no public registration. An administrator creates every account.

## Problem

Issabel already routes calls. Agents still answer without knowing which customer is on the line, and the business has no application-level record that ties a caller number to a customer and an agent.

## MVP scope

1. Authentication with email and password.
2. Users with the administrator and operator roles.
3. Extensions mapped from the PBX onto operator users.
4. Customers with one or more phone numbers.
5. Incoming call detection through Asterisk AMI.
6. Call logs with ringing, answered, missed, busy, failed, and ended states.
7. Customer lookup by an exact normalized phone number.
8. Real-time call popup on the operator desktop.
9. Basic call history for the signed-in operator, and for every extension when the viewer is an administrator.

An unknown number still produces a popup. The operator can open a short form and save that number as a customer. Saving a name and phone number is part of Customers. It is not a CRM.

## Explicitly out of scope

Do not design or build these in the MVP:

- Callback
- CRM pipelines, deals, tickets, or activity timelines
- AI summaries, transcription, or suggestions
- SMS
- Surveys
- WebRTC or a browser softphone
- Auto dialer, click-to-call, transfer, hangup, or any other call control
- Recording playback
- Billing, invoices, or subscriptions
- Multi-tenant organizations
- Multiple PBX connections
- Mobile apps
- Changes to Issabel source code

## Primary flow

1. An administrator creates an operator and assigns PBX extension `1001`.
2. An administrator saves customer "Sara Ahmadi" with mobile `09121234567`.
3. An outside call reaches Issabel and rings extension `1001`.
4. The Python connector reads AMI, normalizes the event, and posts it to Laravel.
5. Laravel stores the call, finds Sara by the normalized number, and broadcasts a popup to her agent.
6. The operator sees the customer name, number, and recent calls for that number.
7. When the call is answered and later cleared, the same call row moves to answered and then ended.
8. If nobody answers, the row becomes missed.

## Functional requirements

### Authentication

- Email and password sign-in through a Laravel session.
- Passwords are hashed by Laravel. They are never stored or logged in plaintext.
- Inactive users cannot sign in.
- Session cookies are HTTP-only and, in production, secure.
- Failed sign-in is rate-limited by Laravel.

### Users

- Fields: name, email, role, active flag.
- Email is unique.
- An administrator can create, update, deactivate, and reset access for users.
- An operator can update only their own display name and password.
- The last active administrator cannot be deactivated or demoted.

### Extensions

- An extension number matches the number dialed on Issabel, stored as a string so leading zeros are preserved if a site uses them.
- Extension numbers are unique.
- In this MVP, one extension belongs to at most one user, and one user has at most one extension.
- Only an active extension assigned to an active operator produces a popup.
- Deactivating an extension stops future popups. Existing call rows stay.

### Customers

- Fields: display name and an optional short note.
- A customer has one or more phone numbers.
- Each number stores the original value and a normalized value.
- A normalized number belongs to only one customer. The create/update form rejects duplicates and shows the existing customer.
- Administrators and operators can create and edit customers. Operators need this to attach an unknown caller during a live call.
- Only administrators can delete a customer. Deletion does not delete call history; call rows keep the dialed number and the customer link becomes empty.

### Incoming call detection

- Only calls that ring a known extension are stored.
- The MVP popup is for calls presented to an agent extension. Laravel treats the caller as external when the caller number is not itself an extension on this system.
- Extension-to-extension calls are ignored for popup and are not required in history.
- Detection is event-driven from AMI. SmartPBX does not poll Issabel's CDR database and does not read Issabel tables.

### Call logs

Each logical call to one extension is one row, correlated by Asterisk `Linkedid` plus extension number.

- Store raw caller number, normalized caller number, extension, agent, customer when found, status, start, answer, end, and duration.
- Duration is the time from answer to end. Unanswered calls have an empty duration.
- Repeated AMI events for the same transition do not create extra rows.
- A call that stays in `ringing` for more than 3 minutes is marked `incomplete` by a scheduled Laravel command. That covers a connector restart that missed the hangup.

### Customer lookup

- Lookup key is the normalized number, exact match only.
- Partial, suffix, and fuzzy matches are not part of the MVP. A wrong customer on a popup is worse than an unknown caller.
- The popup lookup runs inside the ingest request so the broadcast already contains the customer result.

### Real-time popup

- The signed-in operator receives a popup only for their own extension.
- Target delivery is under two seconds from the AMI event arriving at the connector, on a healthy local network.
- The popup shows status, caller number, customer name or "Unknown caller", the customer note when one exists, and up to five previous calls for that normalized number.
- Later events for the same call update the open popup (ringing, answered, ended) instead of stacking duplicates.
- A missed call remains visible until the operator dismisses it.
- If the websocket is disconnected, the operator page recovers the current ringing or answered call on the next page load. The page also refreshes active-call state when the socket reconnects.

### Call history

- An operator sees calls for their extension only.
- An administrator sees all calls and can filter by date, extension, status, and phone number.
- The default page is the latest 25 calls.
- History shows time, caller number, customer name, extension, agent, status, and duration.

## Acceptance criteria

The MVP is done when all of the following are true. Status is the Phase 6 lab check. No item below was treated as a deployment.

- An administrator can sign in and an unknown user cannot. **Passed** (`AuthenticationTest`).
- An administrator can create an operator, assign extension `1001`, and create a customer with `09121234567`. **Passed** (user, extension, and customer tests).
- A fixture AMI `DialBegin` for caller `09121234567` and destination `1001` creates one call row and one popup for that operator. **Passed** (ingest feature test).
- The same fixture sent twice does not create a second call. **Passed** (duplicate `event_id` stays one row).
- A following answer event and hangup event update that same row and the open popup. **Passed** (same linked id, duration set, popup payload on status change).
- A hangup without an answer stores `missed`. **Passed** (`NOANSWER` maps to missed).
- A call to an unknown extension is ignored. **Passed** (ingest returns ignored, no call row).
- An operator cannot open another operator's call history. **Passed** (the log is limited to that operator's `user_id`; the other number is absent).
- AMI secrets and the connector token do not appear in git or in application logs. **Passed** for the working tree and Laravel logs. This folder is not a git repository yet, and both `.gitignore` files exclude `.env`.
- Issabel's PHP and web source are untouched. The only PBX change is a restricted AMI manager account. **BLOCKED / Pending Physical Issabel** for the manager screen (`deny` all, `permit` the connector host, empty write). This repository contains no Issabel source changes.

## Assumptions to validate on the target PBX

These are planning assumptions, not observed facts. The first integration task is to capture real AMI events from the customer's Issabel and adjust only the Python normalizer.

- AMI is reachable from the connector host on TCP port 5038.
- Inbound calls emit `DialBegin` toward the agent channel, then `DialEnd` and `Hangup`.
- `Linkedid` stays stable for the trunk leg and the agent leg of one inbound call.
- The agent extension can be read from `DialString` or the destination channel name (`SIP/1001-...` or `PJSIP/1001-...`).
- The external caller number arrives in `CallerIDNum`.
- The default telephone region is Iran, country code 98. The country code is configuration, not a hard-coded business rule.

## Non-functional requirements

- The call-ingest path writes the database and broadcasts inline. It is not queued. Queues are reserved for work that is actually slow.
- The connector process is restartable and reconnects to AMI without a manual step.
- A connector outage does not take down the Laravel UI. Active popups freeze, and a sweeper later closes stale ringing rows.
- A Laravel outage does not change Issabel call routing. The connector retries event delivery and then drops the event after its retry budget, logging the loss.
- Normal pages remain usable for the expected MVP load: tens of agents, not thousands of concurrent popups.
