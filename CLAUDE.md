# CLAUDE.md -- NexWAYPOINT project notes

This file is for whichever Claude session picks this project up next.
Keep it current: update the Status and Decisions sections whenever scope
changes, and don't let it drift from what's actually in the repo.

## Who this is for

David Mcferrin, broadcast engineer at NewsNation, travels ~60% of the
time, based in Huntsville, AL. Builds his own tools: Python/PHP/MySQL or
SQLite, self-hosted, reliability and low-maintenance-while-traveling over
feature breadth. Prefers direct technical pushback over agreement.

## Status as of this build (2026-07-19)

v1 scaffold is complete and passes lint + tests: hotel tracker split into
`hotel_properties` (identity, amenities including EV/restaurant/off-site
gym/walk-to-office, blacklist, overall rating) and `hotel_stays` (dates,
room/bed/bath, stay rating, price/privacy); add form can reuse a prior
property; mail ingestion (DreamHost IMAP working end-to-end, Gmail/M365
interfaces defined but throw `NotImplementedException`), airline/hotel/train
parsers (AA/Delta/United/Breeze, Hilton/Marriott/generic hotel, Amtrak)
with PNR/confirmation upsert + cancel, FlightAware AeroAPI client with
rate limiting + caching, trip status engine, alert evaluator +
notifications, and the visibility/sharing engine covering all five
directions with override precedence. Basic server-rendered PHP UI exists
for login, hotel list/add/view, dashboard, and sharing settings. VPS
deployment is bootstrapped by an idempotent `setup.sh`. Additional users
via `scripts/create_user.php`. Install auto-seeds `admin` with a random
password; `setup.sh reset-password` regenerates. Existing DBs need
`php scripts/migrate.php` after pull.

**Not started:** car/rideshare email parsers, Azure AD SSO, map view,
PWA/offline, push notifications, hotel-stay edit page, approval UI for
auto-imported stays (currently auto-creates + notifies instead of a
pending-confirmation flow).

## Key architecture decisions (and why)

- **PHP + MySQL/SQLite, zero Composer dependency at runtime.** David's
  explicit "standard M.O." over the heavier FastAPI/PostgreSQL/React/Azure
  AD/Celery/Redis stack that appeared in a pasted spec from a prior
  session (working title "WayPoint"). Chosen by the user explicitly via
  clarifying question, not assumed. Composer is used for dev-only tooling
  (PHPUnit).
- **DreamHost IMAP for v1 mail ingestion, designed for Gmail/M365 later.**
  `MailSourceInterface` is the seam; `GmailApiSource`/`M365GraphSource`
  exist and satisfy the interface but throw `NotImplementedException` on
  every method rather than silently pretending to work.
- **No React/SPA frontend.** Server-rendered PHP + vanilla CSS/JS. This
  wasn't explicitly asked for -- it's the natural consequence of dropping
  the FastAPI/React stack. Flagging it here since it's an implementation
  choice made without a direct question back to the user; revisit if a
  richer frontend becomes worth it.
- **Local username/password auth in v1**, not Azure AD. The user's brief
  said M365/Graph integration "would be cool in the future," implying it
  isn't a v1 blocker. Local auth unblocks everything else without an
  enterprise app registration in the loop.
- **Visibility defaults:** TOP_DOWN (manager viewing subordinate) defaults
  to full visibility; BOTTOM_UP (subordinate viewing manager) defaults to
  city+date only. Managers get total exposure of team travel; subordinates
  have limited exposure of managers. Per-hotel / per-trip `is_private` and
  `visibility_blocks` can hide an item from everyone or from selected users.
- **Hotels are properties vs stays.** Property identity/amenities/blacklist/
  phone live on `hotel_properties`; visit-specific room/bed/bath/`stay_rating`
  live on `hotel_stays`. `overall_rating` on the property is
  `AVG(stay_rating)` recomputed on stay create/update/delete. Add-stay UI
  filters by **City, State** then property; Add New is a modal. Edit via
  `public/hotels/edit-property.php`.
- **Carriers own IATA.** Per-user `carriers` table (name + iata_code);
  `trip_segments.carrier_id` links flights. Flight form asks for flight
  number only; enrichment builds FlightAware ident as IATA+number.
  Manage at `public/flights/carriers.php`.
- **Auto-import creates the hotel stay / trip segments directly + notifies**,
  rather than a pending-approval queue the user has to click through. The
  original "We found a trip... Confirm?" flow from the pasted spec is more
  UI than this pass covers. Documented as a gap, not silently dropped.
  Flights/trains upsert by confirmation/PNR (replace legs on change;
  cancel marks segments cancelled). Hotels upsert by confirmation code;
  Hilton cancels without the original conf # match on property name + dates.
- **Rate limiting for FlightAware is a file-backed token bucket**, not
  in-memory, because each cron invocation is a fresh PHP process with no
  persistent state between runs.
- **VPS setup is interactive, user-space, and DB-driver-aware.** Production
  DreamHost has no sudo/apt. `setup.sh` defaults to skipping package installs
  and Composer; it verifies the PHP DreamHost already provides, never
  overwrites an existing `.env`, skips an existing schema, and only installs
  cron jobs for services whose credentials are configured. Optional
  `--install-packages` / `--with-dev` exist for non-DreamHost hosts.
  Maintenance commands: `backup`, `update` (git pull with pre-backup),
  `restore`, and `list-backups`.
- **Production host layout keeps the DreamHost domain folder and deploys into
  it.** Code lives at `/home/dh_w9tij7/NexWAYPOINT`; the public site is
  `https://nexwaypoint.area51consulting.com` served from
  `/home/dh_w9tij7/nexwaypoint.area51consulting.com`. `setup.sh deploy`
  (also run by install/update) publishes `public/` into that folder with
  absolute symlinks so PHP bootstrap paths still resolve under the clone.
  Storage and secrets stay under the clone, never copied into the web dir.

## Things to watch out for

- `TripRepository::findActiveOrUpcoming()` takes an optional `$asOf`
  parameter specifically so `TripStatusEngine::resolveForUser($userId, $now)`
  can be tested with a fixed clock. If you add new callers, don't
  reintroduce a hardcoded `new DateTimeImmutable('today')` inside
  `TripStatusEngine` -- that was a real bug caught during test-writing
  (tests silently returned "Home" instead of the expected travel status
  because the trip repository was filtering on real wall-clock time while
  tests passed a fictional date to the engine).
- `hotel_stays` and `trip_segments` are intentionally decoupled --
  `trip_segments.hotel_stay_id` is a nullable FK, not a required link.
  MailPoller currently find-or-creates `hotel_properties` then writes
  `hotel_stays`, not `trips`/`trip_segments`, for parsed hotel
  confirmations (see README gap list).
- Value objects (`HotelProperty`, `HotelStay`, `Trip`, `TripSegment`,
  `User`) use readonly properties with named-argument construction.
  `toArray()`/`fromRow()` use **snake_case** keys (DB column names);
  constructors use **camelCase** parameter names. Don't spread `toArray()`
  output directly into the constructor -- go through `fromRow()` instead
  (see `tests/HotelStayRepositoryTest.php::testUpdateStayRatingUpdatesOverall`).
- The sandbox this was built in has no PHP preinstalled and no root
  access; PHP 8.1 + extensions + PHPUnit were pulled via `apt-get download`
  (no `apt-get install`) and extracted with `dpkg-deb -x` into a scratch
  prefix to lint and test everything before delivery. That tooling doesn't
  ship with the project -- it was throwaway CI for this session only.

## Immediate next steps (suggested, not started)

1. Car/rideshare email parsers (Enterprise/Hertz/Uber airport).
2. Hotel-stay edit page (currently add/view/delete only).
3. Tighten Delta/United/Hilton parsers against more live fixtures (trip
   details / multi-leg United / Hilton cancel without original conf #).
4. Decide whether the pending-approval UI ("we found a trip, confirm?")
   is worth building vs. the current auto-create-and-notify approach.
