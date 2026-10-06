# SmartPBX Deployment

Status: target layout for the first production installation of the Call Popup MVP. Nothing here is provisioned yet.

## Topology

Two hosts are the default.

| Host | Runs | Does not run |
| --- | --- | --- |
| PBX | Existing Issabel/Asterisk, AMI on a private IP | Laravel, MySQL, the connector |
| App | Nginx, PHP-FPM, MySQL, Reverb, the Python connector, cron | Asterisk |

Putting the connector on the app host keeps AMI as a single private TCP session from one IP. Do not run the connector on random workstations.

A single-server lab may colocate all of this except that production AMI must still be firewalled. Do not colocate SmartPBX inside Issabel's operating system packages. A failed web deploy must not be an Issabel upgrade.

```
Agents ---- HTTPS/WSS ---- App host (Nginx, Laravel, Reverb, connector, MySQL)
                                |
                                | AMI TCP 5038
                                v
                           Issabel host
```

## Software baseline

Use current stable versions at implementation time, pinned in lockfiles:

- PHP 8.3 or newer, with the extensions Laravel's installer requires
- Composer
- MySQL 8
- Nginx
- Python 3.11 or newer on the app host
- systemd

No Redis. No Docker requirement. Containers are optional later and are not the MVP deploy path.

## Nginx

- Public server name for the SmartPBX site.
- TLS certificate on this vhost.
- `root` points at `backend/public`.
- PHP requests go to PHP-FPM.
- `/app` websocket location proxies to Reverb on `127.0.0.1:8080`.
- Only Nginx is exposed on 443. PHP-FPM, MySQL, and Reverb bind to localhost.
- Port 5038 is not listened on this host. The connector is a client.

Trust `X-Forwarded-*` only from Nginx on the same machine.

## Laravel environment

`backend/.env` is created on the server and is not committed. Minimum values:

```
APP_NAME=SmartPBX
APP_ENV=production
APP_DEBUG=false
APP_URL=https://smartpbx.example.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=smartpbx
DB_USERNAME=smartpbx
DB_PASSWORD=

SESSION_DRIVER=database
QUEUE_CONNECTION=sync
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=
REVERB_APP_KEY=
REVERB_APP_SECRET=
REVERB_HOST=smartpbx.example.com
REVERB_PORT=443
REVERB_SCHEME=https

PHONE_DEFAULT_COUNTRY_CODE=98
```

`QUEUE_CONNECTION=sync` is deliberate. The popup path must not wait on a queue worker. If a later phase introduces slow jobs, switch to the database queue and add a worker then.

Generate `APP_KEY` with Artisan on the server. Generate Reverb credentials with Artisan. Create the connector token with Artisan and copy it once into the connector environment.

MySQL user `smartpbx` has rights only on database `smartpbx`.

## Connector environment

File `/etc/smartpbx/connector.env`, mode `0600`, owner the `smartpbx` user:

```
AMI_HOST=<pbx-private-ip>
AMI_PORT=5038
AMI_USERNAME=smartpbx
AMI_SECRET=
LARAVEL_INGEST_URL=https://smartpbx.example.com/api/internal/v1/asterisk/events
LARAVEL_API_TOKEN=
LOG_LEVEL=info
HTTP_TIMEOUT_SECONDS=3
```

`LARAVEL_INGEST_URL` uses the public HTTPS name only if the app host resolves it to itself. A localhost URL is also fine when Nginx serves it locally. Do not disable TLS verification to hide a bad certificate. Fix the certificate.

## systemd units

`php8.3-fpm`, `nginx`, and `mysql` use distribution units.

`smartpbx-reverb.service`:

- User `smartpbx`
- WorkingDirectory `/var/www/smartpbx/backend`
- ExecStart the Reverb Artisan command
- Restart always

`smartpbx-connector.service`:

- User `smartpbx`
- WorkingDirectory `/var/www/smartpbx/connector`
- EnvironmentFile `/etc/smartpbx/connector.env`
- ExecStart `/usr/bin/python3 -m smartpbx_connector`
- Restart always
- RestartSec 5, in addition to the connector's own AMI backoff

Cron for user `smartpbx`, every minute:

```
php /var/www/smartpbx/backend/artisan schedule:run
```

The schedule runs the stale-call command every minute and the `call_events` prune daily.

## PBX configuration

On Issabel, create the read-only AMI user from `ASTERISK-INTEGRATION.md`. Allow TCP 5038 from the app host only. Do not upgrade or replace Issabel as part of a SmartPBX deploy.

After creation, from the app host:

```
nc -vz <pbx-private-ip> 5038
```

From any other host, that connection must fail.

## Release steps

1. Deploy the `backend` and `connector` directories to `/var/www/smartpbx`.
2. Install PHP dependencies with Composer, without dev dependencies.
3. Confirm `backend/.env` and the connector env file exist and are not world-readable.
4. Run `php artisan migrate --force`.
5. Run `php artisan storage:link` if the framework layout needs it. The MVP stores no uploads.
6. Restart PHP-FPM and Reverb.
7. Restart the connector.
8. Sign in as the seeded administrator and change the initial password.
9. Create the real operator and extension.
10. Place the manual test call from `TEST-PLAN.md`.

There is no public seeder of demo customers in production. A local seeder may create an administrator and one extension for development.

## Backups

- Nightly dump of the `smartpbx` database, stored on a different disk or host.
- Backup retention follows the site owner's rule. The application does not delete call rows.
- Restoring a dump restores customers, users, and call history. It does not recreate the connector env file. Keep that file in the site's secret store as well.
- Issabel backups remain Issabel's procedure. SmartPBX does not back up the PBX.

## Monitoring

MVP monitoring is process supervision plus logs:

- systemd restarts Reverb and the connector.
- Connector logs AMI disconnects.
- Laravel logs ingest 401s and 422s.
- A simple external check fetches `/login` over HTTPS.

Dashboards, metrics products, and paging are out of scope. If the connector is down, new popups stop and phones keep ringing. That is the failure to notice first.

## Local development

- Laravel on `http://127.0.0.1:8000` with `APP_DEBUG=true`.
- MySQL or a local MariaDB the developer already runs.
- Reverb on localhost.
- Connector pointed at a lab PBX, or unit tests only when no PBX is available.
- Do not commit `.env`.

Document any local security exception next to the command that needs it, in the phase notes, and do not copy that setting into the production env file.

## Capacity expectation

This layout targets one PBX and tens of simultaneous agents. The ingest path is one short transaction and one broadcast per call event. Revisit the single connector and the synchronous broadcast only when a real site exceeds that, not before.
