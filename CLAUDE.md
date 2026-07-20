# CLAUDE.md -- NexWAYPOINT project notes

This file is for whichever Claude session picks this project up next.
Keep it current: update the Status and Decisions sections whenever scope
changes, and don't let it drift from what's actually in the repo.

## Who this is for

David Mcferrin, broadcast engineer at NewsNation, travels ~60% of the
time, based in Huntsville, AL. Builds his own tools: Python/PHP/MySQL or
SQLite, self-hosted, reliability and low-maintenance-while-traveling over
feature breadth. Prefers direct technical pushback over agreement.

## Status as of this build (2026-07-20)

v1 scaffold is complete and passes lint + tests: hotel tracker split into
site-wide `hotel_properties` (identity, amenities including EV/restaurant/
off-site gym/walk-to-office, public `overall_rating`) and per-user
`hotel_stays` (dates, room/bed/bath, stay rating, price/privacy); personal
blacklist in `user_hotel_blacklist`; add form reuses the global directory;
mail ingestion (DreamHost IMAP working end-to-end, Gmail/M365 interfaces
defined but throw `NotImplementedException`), airline/hotel/train parsers
(AA/Delta/United/Breeze, Hilton/Marriott/generic hotel, Amtrak) with
PNR/confirmation upsert + cancel, FlightAware AeroAPI client with rate
limiting + caching, trip status engine, alert evaluator + notifications,
and the visibility/sharing engine covering all five directions with
override precedence. Basic server-rendered PHP UI exists for login, hotel
list/add/view, dashboard, and sharing settings. VPS deployment is
bootstrapped by an idempotent `setup.sh`. Additional users via
`scripts/create_user.php` or `/settings/users.php` (org chart = reports-to
+ dotted line; `is_admin` for site admin). Appearance (map basemap, pin
colors, default theme) is under Settings → Appearance. Install auto-seeds
`admin` with a random password; `setup.sh reset-password` regenerates.
Existing DBs need `php scripts/migrate.php` after pull (includes
global-properties migration).

**Team board UX (2026-07-20):** profile photo upload with face-center crop
(Settings → My profile), home city for map pins, nav-centered
`You are: <Status>` override (remote requires city/state), dashboard
Table / Cards / Map views (`localStorage` preference) with Leaflet city
clusters → face markers.

**Not started:** car/rideshare email parsers, Azure AD SSO,
PWA/offline, push notifications, approval UI for auto-imported stays
(currently auto-creates + notifies instead of a pending-confirmation flow).

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
  enterprise app registration in the loop. Org structure is who reports to
  whom (`manager_id` solid line + `user_dotted_managers` dotted line), not
  a coarse role dropdown. The seeded `admin` account is `is_system` —
  isolated from the org chart. Site-admin (`is_admin`) gates Settings →
  Users and Site catalogs. Settings also manage `hotel_brands` and
  `office_venues` (named offices with addresses for the walk-to combobox
  and hotel map squares). Mail ownership is
  correlated via `user_emails` (many addresses per user), not a single
  `users.email`.
- **Visibility defaults:** TOP_DOWN (manager viewing report, solid or
  dotted) defaults to full visibility; BOTTOM_UP (report viewing manager)
  defaults to city+date only. Per-hotel / per-trip `is_private` and
  `visibility_blocks` can hide an item from everyone or from selected users.
- **Hotels are properties vs stays.** `hotel_properties` is a site-wide
  directory (identity, location, amenities, phone; `created_by_user_id` is
  audit only). Dedup key: case-insensitive name + city + state. Stays are
  per-user (`user_id` + visit fields + `stay_rating` + `is_private`).
  `overall_rating` is the public `AVG(stay_rating)` across all users'
  stays on that property (each stay is 0–5 stars). Blacklist is per-user in
  `user_hotel_blacklist`
  (teammates can see matching adverse prefs). Any auth user can edit
  amenities; hard-delete property is site-admin only. Add-stay UI filters
  by **City, State** then property; Add New is a modal. Rate/edit a stay via
  `public/hotels/edit-stay.php`. Edit property via
  `public/hotels/edit-property.php`.
- **Carriers own IATA.** Per-user `carriers` table (name + iata_code);
  `trip_segments.carrier_id` links flights. Flight form asks for flight
  number only; enrichment builds FlightAware ident as IATA+number.
  Manage under Settings → Site catalogs (`/settings/site.php`); shared site-wide catalog.
- **Mail parsers must handle direct vendor mail AND teammate forwards, plus
  confirm / change / cancel.** Ownership always uses the outer `From:`
  (matched via `user_emails`). `ForwardedMailNormalizer` strips Fw:/Fwd:
  wrappers (Gmail, Outlook, Proton, Yahoo, Apple) before detect/parse so
  parsers see the underlying confirmation. Brand parsers (AA, Hilton,
  Marriott, …) must tolerate quoted bodies, soft line-breaks, zero-width
  characters, and template drift — prefer multiple date/code/property
  patterns over one brittle regex. Upserts by confirmation/PNR already
  absorb updates; cancels and schedule changes are first-class events.
  When a live `.eml` fails, add a fixture test and widen the parser, do
  not special-case one mailbox.
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
