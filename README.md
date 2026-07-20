# NexWAYPOINT

A self-hosted hotel-stay tracker and team travel dashboard for corporate
travelers. Built in plain PHP + MySQL/SQLite by design (no framework, no
Composer dependency at runtime) so it runs on ordinary shared hosting
(DreamHost) and is easy to maintain from the road.

## What's built (v1)

- **Hotel stay tracker** -- log stays with room number, desk/pool/hot tub/
  breakfast/gym/parking/shuttle, WiFi and noise ratings, unique features,
  last price, blacklist flag + reason, photos. Warns you before you book
  somewhere you've already blacklisted. Suggests criteria you might not be
  tracking yet, and flags recurring themes in your free-text notes.
- **Travel dashboard** -- a status engine that resolves each person's
  current state (Home / Office / Remote / In Flight / Layover in X /
  Delayed / At hotel in X) from trip segments and manual overrides.
- **Mail ingestion** -- DreamHost IMAP polling, sender/subject-based
  confirmation detection, and a generic hotel-confirmation parser. Parsed
  stays auto-create a draft `hotel_stays` row and notify the owner to
  review it. Flight/train/car parsers are not built yet (see Roadmap).
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
- **Local authentication** -- session-based username/password. Azure AD /
  M365 SSO is a documented future phase, not v1.

## What's NOT built yet (be aware before you rely on this)

- Flight, train, and rideshare/car-rental email parsers (only the generic
  hotel parser exists). `MailPoller` already routes those types to the
  PARSE_FAILED review queue with a clear reason rather than pretending to
  handle them.
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

The free-text project brief said "managers can see most if not all...
default for subordinates is visible unless marked private" -- which reads
like manager-viewing-subordinate (top-down) should default to full
visibility. But the structured "Org hierarchy" spec block in the same
message, and the detailed Phase 2 spec pasted alongside it, both
explicitly say TOP-DOWN defaults to city+date only and BOTTOM-UP defaults
to full visibility. Those two structured, precise statements agree with
each other and are more specific than the one loosely-worded sentence, so
`VisibilityEngine::DIRECTION_TOP_DOWN` implements city+date-only by
default. If that's not actually what you want, it's a one-line change in
`src/Visibility/VisibilityEngine.php` (`$defaultFields` assignment) --
flagged here instead of silently guessed.

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
| Panel Web Directory | `/home/dh_w9tij7/NexWAYPOINT/public` |

Keep the clone's Web Directory pointed at `public/` so `config/`, `src/`,
`.env`, and `storage/` stay outside HTTP reach. Set that path in the
DreamHost panel for the domain — do **not** symlink or replace
`/home/dh_w9tij7/nexwaypoint.area51consulting.com`.

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
- securely creates the first local user; and
- optionally installs the configured mail/flight cron jobs.

It is safe to rerun. Use `bash setup.sh --help` for options
(`--skip-user`, `--with-dev`). To add another
local user later:

```bash
php scripts/create_user.php
```

In the DreamHost panel, set the domain Web Directory to
`/home/dh_w9tij7/NexWAYPOINT/public`, force HTTPS, then open
https://nexwaypoint.area51consulting.com/login.php.

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

# Backup, then git fetch + fast-forward pull on the current branch
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
   address against `users.email` -- so the forwarded copy's From header
   needs to be the teammate's own address (true for a normal "Forward").
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
2. Add the sender's domain to `EmailConfirmationDetector::SENDER_DOMAINS`
   under the right type (`flight`, `hotel`, `train`, `car`).
3. Wire it into `MailPoller::processOne()` -- right now that method
   hardcodes `GenericHotelConfirmationParser` for `type === 'hotel'`; for a
   new type, add a `match ($detection['type'])` branch that selects the
   right parser and maps its output onto either `trip_segments` (flights/
   trains/cars) or `hotel_stays` (a new hotel brand parser).
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
Current coverage: hotel stay repository (CRUD + validation + blacklist
matching), visibility engine (all five directions + override precedence),
trip status engine (home/in-flight/layover/manual override), and the
generic hotel parser (5 synthetic fixtures). 24 tests, 56 assertions,
all passing as of this build.

## Project layout

```
config/bootstrap.php     Entry point every script includes first
database/schema.sql       MySQL schema (production)
database/schema.sqlite.sql SQLite schema (dev/test)
src/Core/                 Env, Database, Logger, Auth, Csrf, exceptions
src/Users/                User model + repository
src/Hotels/               Hotel stay tracker
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
- Every write to `hotel_stays`, `trips`, `trip_segments`, `users`, and
  `visibility_rules` goes through `Database::audit()`, which logs to
  `audit_log`. A DB administrator can see *that* something changed and
  *who* did it, without that requiring inbox access.
- CSRF tokens are required on every state-changing form (`Csrf::token()`/
  `Csrf::verify()`).
- Session cookies are `httponly`, `SameSite=Strict`, and `secure` when
  served over HTTPS.
- `.env` holds all secrets; nothing is hardcoded. Keep `storage/` and
  `config/` outside the web root's document root.
