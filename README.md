# NexWAYPOINT

A self-hosted hotel-stay tracker and team travel dashboard for corporate
travelers. Built in plain PHP + MySQL/SQLite by design (no framework, no
Composer dependency at runtime) so it runs on ordinary shared hosting
(DreamHost) and is easy to maintain from the road.

## What's built (v1)

- **Hotel property + stay tracker** -- `hotel_properties` is a site-wide
  directory (identity, amenities: desk/pool/hot tub/breakfast/gym/off-site
  gym/parking/shuttle/EV charging/on-site restaurant/walk-to-office, WiFi
  and noise, phone). Stays are per-user (dates, room, bed/bathroom, this
  stay's 0–5 rating, price, photos, visit privacy). Overall property rating
  is the public average of all users' stay ratings. Rate or edit a stay at
  `/hotels/edit-stay.php`. Personal blacklist lives
  in `user_hotel_blacklist`. Log a stay by picking **City, State** then a
  property (or Add New in a modal). Browse/filter/sort at
  `/hotels/properties.php` (destination fee, my blacklist, teammate adverse
  prefs). Any authenticated user can edit amenities; only site admins can
  delete a property. Teammates can see matching adverse preferences (name +
  reason) for the same hotel/location.
- **Reusable airline carriers** -- each carrier stores name + IATA; flight
  entry is carrier dropdown + flight number only. FlightAware enrichment
  builds the ident as IATA + number. Carriers and rail operators are a
  site-wide catalog under Settings → Site catalogs (`/settings/site.php`).
- **Travel dashboard** -- a status engine that resolves each person's
  current state (Home / Office / Remote / In Flight / Layover in X /
  Delayed / At hotel in X) from trip segments and manual overrides.
- **Mail ingestion** -- DreamHost IMAP polling with domain-suffix +
  forwarded-body vendor detection. Parsers for AA / Delta / United /
  Breeze flights, Amtrak, Hilton / Marriott, plus the generic hotel
  fallback. Confirmations upsert by PNR/confirmation code (find-or-create
  carriers and hotel properties); change emails replace legs; cancels
  mark trip segments cancelled or remove email-imported stays. Folio /
  bag-receipt / status mail is ignored. FlightAware AeroAPI is separate
  (live enrichment), not the importer.
- **FlightAware AeroAPI client** -- flight lookup, live track, airport
  delays, with a file-backed rate limiter and a 10-minute cache so you
  don't burn through your AeroAPI budget on every dashboard refresh.
- **Alerting** -- delay > 30 min, gate change, cancellation, diversion,
  and landing all write to a `notifications` table the dashboard polls.
- **Visibility engine** -- org-hierarchy-aware field-level sharing
  (top-down / bottom-up / lateral / user-to-user), with per-direction
  defaults and per-viewer overrides. See "A note on the visibility
  defaults" below -- this is the one place a real ambiguity in the
  requirements had to be resolved, and it's called out rather than
  silently guessed.
- **Local authentication** -- session-based username/password. Managers get
  a Users admin screen; each user can register multiple forward-from email
  aliases for mail import. Azure AD / M365 SSO is a documented future phase,
  not v1.

## What's NOT built yet (be aware before you rely on this)

- Rideshare / car-rental email parsers. Flight, train, and hotel brand
  parsers above are live; car still routes to PARSE_FAILED.
- Gmail API and Microsoft 365 Graph mail sources. Both classes exist and
  satisfy `MailSourceInterface` so swapping `MAIL_SOURCE` is a one-line
  config change once built, but every method currently throws
  `NotImplementedException` on purpose.
- Azure AD / SSO login (v1 uses local username/password auth).
- Map view, PWA/offline mode, push notifications -- none of that Phase 3
  scope was started. This build only covers hotel tracking + the mail-to-
  dashboard pipeline + sharing.
- A hotel-stay edit page (add/view/delete exist; editing an existing stay
  currently means deleting and re-adding it).
- Approval UI for auto-imported hotel stays -- right now the parsed stay
  is created directly and the owner gets a notification to go review/edit
  it, rather than a pending "confirm this trip?" screen.

## A note on the visibility defaults

Managers (solid or dotted line) default to **full exposure** of a report's
travel (TOP_DOWN = all fields). Reports default to **limited exposure** of
manager travel (BOTTOM_UP = city + dates only). Lateral/peer sharing
defaults to full fields. Unrelated users get city+dates only.

Org chart is who reports to whom (`manager_id` + dotted-line managers), not
RBAC roles; site admin is a separate `is_admin` flag.

Separately, any hotel stay or trip/flight can be marked **Private** (hidden
from everyone) or hidden from **selected users** via `visibility_blocks`,
without changing the org-wide field defaults above.

If you need to change the direction defaults, edit the `$defaultFields`
assignment in `src/Visibility/VisibilityEngine.php`.

## Requirements

- PHP 8.1+ with extensions: `pdo_mysql` or `pdo_sqlite`, `imap`, `curl`,
  `mbstring`, `json` (core).
- MySQL 8.0+/MariaDB 10.5+ for production, or SQLite for local/offline dev.
- A DreamHost (or any IMAP) mailbox to receive forwarded confirmations.
- A FlightAware AeroAPI key (personal tier is fine to start).
- Composer is **only** needed for optional PHPUnit runs -- the app itself
  runs with zero Composer dependencies. Production DreamHost has no sudo
  and does not need apt or Composer.

## Setup

### Production host (DreamHost)

| What | Path / URL |
|---|---|
| Public site | https://nexwaypoint.area51consulting.com |
| Git clone | `/home/dh_w9tij7/NexWAYPOINT` |
| DreamHost web dir | `/home/dh_w9tij7/nexwaypoint.area51consulting.com` |

Keep the clone outside HTTP reach for `config/`, `src/`, `.env`, and
`storage/`. Leave the domain Web Directory at DreamHost's default domain
folder. `setup.sh install` / `update` / `deploy` publish `public/` into that
folder with absolute symlinks (so PHP `__DIR__` still resolves into the
clone). The domain directory itself is never replaced.

1. Create an empty MySQL database + user in the DreamHost panel.
2. Clone and install:

```bash
cd /home/dh_w9tij7
git clone <repository-url> NexWAYPOINT
cd NexWAYPOINT
bash setup.sh
```

**No sudo, apt, or Composer is required for production.** DreamHost does
not give this account package privileges. Use the DreamHost panel for
PHP 8.1+ (with `pdo_mysql`, `imap`, `curl`, `mbstring`) and MySQL.
Composer is only for optional local PHPUnit runs (`bash setup.sh --with-dev`
on a machine that already has Composer). `setup.sh` then:

- creates `.env` without overwriting an existing one;
- generates the session secret and fills absolute storage paths under
  `/home/dh_w9tij7/NexWAYPOINT/storage/...`;
- prompts for MySQL/SQLite, optional IMAP, and optional FlightAware settings;
- creates writable storage directories and installs the database schema;
- auto-seeds the `admin` account with a random password if no users exist; and
- publishes `public/` into `/home/dh_w9tij7/nexwaypoint.area51consulting.com`; and
- optionally installs the configured mail/flight cron jobs.

It is safe to rerun. Use `bash setup.sh --help` for options
(`--skip-user`, `--skip-deploy`, `--web-root`, `--with-dev`). Additional
users:

```bash
php scripts/create_user.php
```

Settings hub is `/settings/index.php` (emails, sharing; admins also get
site catalogs and users/org chart at `/settings/users.php`).
Everyone manages their own forward addresses at `/settings/emails.php`.

Reset a password (prints a new random value once):

```bash
bash setup.sh reset-password
bash setup.sh reset-password --username admin
```

Force HTTPS for the subdomain in the DreamHost panel, then open
https://nexwaypoint.area51consulting.com/login.php.

To republish web files after a manual edit:

```bash
bash setup.sh deploy
```

### Backup / update / restore

Backups live under `storage/backups/<timestamp>/` (outside the web root)
and include `.env`, a `storage/` archive (excluding prior backups), a DB
dump when `mysqldump`/`sqlite` is available, and a manifest with the git
SHA.

```bash
# Snapshot current .env, storage, and database
bash setup.sh backup

# List backup IDs
bash setup.sh list-backups

# Backup, then git fetch + fast-forward pull on the current branch, then redeploy web
bash setup.sh update

# Update to a specific branch/tag/commit
bash setup.sh update --ref main

# Restore data from the newest backup (makes a safety backup first)
bash setup.sh restore latest

# Restore data and check out the recorded git SHA
bash setup.sh restore 20260719213000 --code

# Skip the automatic safety backup
bash setup.sh update --no-backup
bash setup.sh restore latest --no-backup
```

If the working tree has local edits, `update` refuses unless you pass
`--force`. Rollback after a bad update:

```bash
bash setup.sh restore latest          # data
git checkout <sha-from-update-output> # code, if needed
```

If cron must be configured through the DreamHost panel instead of the
installer:

```cron
*/10 * * * * php /home/dh_w9tij7/NexWAYPOINT/cron/poll_mail.php >> /home/dh_w9tij7/NexWAYPOINT/storage/logs/cron.log 2>&1
*/10 * * * * php /home/dh_w9tij7/NexWAYPOINT/cron/enrich_flights.php >> /home/dh_w9tij7/NexWAYPOINT/storage/logs/cron.log 2>&1
```

### DreamHost IMAP one-time setup

1. Create a dedicated mailbox (e.g. `travel@yourdomain.com`) in the
   DreamHost panel -- don't reuse a personal mailbox.
2. Set `IMAP_HOST=imap.dreamhost.com`, `IMAP_PORT=993`,
   `IMAP_ENCRYPTION=ssl`, `IMAP_USERNAME`/`IMAP_PASSWORD` in `.env`.
3. Have each teammate set up mail forwarding (or a filter that forwards)
   confirmation emails from their own inbox to that address. `MailPoller`
   attributes each email to a NexWAYPOINT user by matching the `From:`
   address against that user's rows in `user_emails` (primary plus any
   aliases). Add every address you send/forward from under **My emails**
   (`/settings/emails.php`), or have a site admin attach them under **Settings → Users**.
4. `IMAP_PROCESSED_FOLDER`/`IMAP_FAILED_FOLDER` are created automatically
   on first connect if they don't exist.

## Environment variables

See `.env.example` for the full list with inline comments. Highlights:

| Variable | Purpose |
|---|---|
| `DB_DRIVER` | `mysql` (production) or `sqlite` (local dev) |
| `MAIL_SOURCE` | `dreamhost_imap` (only working option in v1) |
| `MAIL_MIN_PARSE_CONFIDENCE` | Below this, a parsed email goes to review regardless of whether parsing "succeeded" (default 0.75) |
| `MAIL_DELETE_ON_SUCCESS` | Delete (vs. just move) processed emails |
| `FLIGHTAWARE_API_KEY` | AeroAPI key |
| `FLIGHTAWARE_RATE_LIMIT_PER_MINUTE` | Token-bucket cap shared across cron runs |
| `FLIGHTAWARE_CACHE_MINUTES` | Skip re-checking a flight within this window |
| `FLIGHTAWARE_MONTHLY_BUDGET_USD` | Tracked via `aeroapi_usage_log`; not yet enforced as a hard stop |
| `JWT_SECRET` / Azure AD vars | **Not used in v1** -- reserved for the future SSO phase |

## How to add a new parser module

1. Create `src/Mail/Parsers/YourAirlineParser.php` extending
   `NexWaypoint\Mail\ParserBase` and implementing `parse(EmailMessage $message): ?array`.
   Use `$this->extractPattern()` / `extractFirstMatch()` / `parseFlexibleDate()`
   from the base class -- they track confidence automatically.
2. Add the sender's domain (and a content hint if forwards are expected) to
   `EmailConfirmationDetector` under the right type (`flight`, `hotel`,
   `train`, `car`).
3. Wire the parser into `MailPoller::resolveParser()` so detection routes to
   the right parser; output maps onto `trip_segments` (flights/trains) or
   `hotel_stays` with upsert/cancel by confirmation code.
4. Write at least 5 unit tests with synthetic (not real) fixture emails
   covering: a clean match, an alternate date format, missing optional
   fields, a non-matching email (expect `null`), and a low-confidence
   partial match. See `tests/GenericHotelConfirmationParserTest.php` for
   the pattern.
5. Run `composer test` (or the manual PHPUnit invocation below) before
   committing.

## Running the poller manually for testing

```bash
# One-off run against your real .env config:
php cron/poll_mail.php

# Flight enrichment sweep:
php cron/enrich_flights.php
```

Both scripts print a summary line and log structured JSON to
`storage/logs/app.log`. Exit code is non-zero if there were failures.

## Running tests

```bash
composer install          # dev dependencies only (PHPUnit)
composer test              # or: vendor/bin/phpunit
```

Tests build a fresh in-memory SQLite database per test from
`database/schema.sqlite.sql` -- no external DB or network access required.
Current coverage: hotel property + stay repositories (CRUD, location
cascade helpers, stay rating → overall average across users, amenity/room
validation, global directory + per-user blacklist matching), reusable
carriers (IATA + flight ident), visibility
engine (all five directions + override precedence), trip status engine
(home/in-flight/layover/manual override), and the generic hotel parser
(5 synthetic fixtures).

## Project layout

```
config/bootstrap.php     Entry point every script includes first
database/schema.sql       MySQL schema (production)
database/schema.sqlite.sql SQLite schema (dev/test)
src/Core/                 Env, Database, Logger, Auth, Csrf, exceptions
src/Users/                User model + repository
src/Hotels/               Hotel properties + stays
src/Trips/                Trips, segments, status engine, FlightAware, alerts
src/Visibility/           Sharing/visibility engine
src/Mail/                 Mail sources, parsers, poller
public/                   Web root -- point Nginx/Apache here
cron/                     poll_mail.php, enrich_flights.php
tests/                    PHPUnit suite
storage/                  Logs, uploads, cache -- must be writable, must NOT be web-accessible
```

## Security notes

- Raw email bodies are never written to the database -- only structured
  fields extracted by a parser. `parse_log` stores metadata (from/subject/
  status/confidence) for audit purposes, never body content.
- Every write to `hotel_properties`, `hotel_stays`, `trips`, `trip_segments`,
  `users`, and `visibility_rules` goes through `Database::audit()`, which
  logs to `audit_log`. A DB administrator can see *that* something changed
  and *who* did it, without that requiring inbox access.
- CSRF tokens are required on every state-changing form (`Csrf::token()`/
  `Csrf::verify()`).
- Session cookies are `httponly`, `SameSite=Strict`, and `secure` when
  served over HTTPS.
- `.env` holds all secrets; nothing is hardcoded. Keep `storage/` and
  `config/` outside the web root's document root.
