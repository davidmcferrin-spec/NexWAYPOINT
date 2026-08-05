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
clusters → face markers. Map pins = current location only. Table/cards show
Current + Next (city + dates when a later trip exists). Avatar/row/card click
opens a 21-day travel look-ahead modal.

**Complex itinerary (2026-07-20):** `TripStatusEngine` phases are pre-flight /
en_route / post-flight (45m windows), layover (gap ≤3h), itinerary remote
(gap >3h at arrived city), then hotel / override / Home. Segment times stay
naive local wall-clock; depart is interpreted in the **origin** airport
IANA TZ and arrive in the **destination** airport TZ via `airports` lookup
(`data/airports_us.php` seed + `AirportRepository`). Seed covers ~190 US
codes (city/state + timezone) plus nearby hubs; UI/status use
`labelFor()` e.g. `Washington, DC (DCA)`. Unknown codes / trains
fall back to `APP_TIMEZONE`. Trip create/edit uses spreadsheet builder
(`/trips/builder.php` + `replaceTripLegs` / `replaceTripHotels`); Mode is
Flight or Train; hotels attach via `hotel_stay_id` (existing stay or create
inline; multiple stays per trip OK). Long transit gaps use `at_hotel` when
a linked hotel covers `now`. `/flights/add.php` and `/trains/add.php`
redirect to the builder.

**Calendar feeds (2026-08-04):** Settings → Calendar feeds issues per-user
secret ICS URLs (`/feeds/calendar.php?t=…`) for (1) personal travel
(flights/trains timed via airport TZ + trip all-day blocks) and (2) team
whereabouts (visibility-filtered; optional member picker; timed legs only
when flight/carrier fields are visible). Outlook/M365/iOS/Android subscribe
via HTTPS or `webcal://`. Rotate invalidates the old token. Requires
`calendar_feeds` (`php scripts/migrate.php` on existing DBs).

**Expense receipts (2026-08-04):** Per-user receipt bin at `/receipts/`
(date / location / brand / trip + download). Successful mail confirm/change
imports archive a PDF under `storage/receipts/` — vendor MIME PDF attachment
when present, else a generated itinerary/stay summary (`SimplePdf`, no
Composer). Manual upload (PDF/JPG/PNG) and generate-from-trip/stay also
supported. Retention `RECEIPT_RETENTION_DAYS` (default 90); purge runs at
end of mail poll. Requires `expense_receipts` (`php scripts/migrate.php`).

**Not started:** Azure AD SSO, PWA/offline, push notifications.
Mail auto-import stays on auto-create + notify (no pending-approval queue).
System-admin Mail review: Settings → Mail review (`is_system` only).

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
  Users and Site catalogs. **Mail review** (raw .eml download + import
  history with links to created trips/stays) is **`is_system` only**, not
  `is_admin`. Settings also manage `hotel_brands` and `office_venues`
  (named offices with addresses for the walk-to combobox and hotel map
  squares). Mail ownership is correlated via `user_emails` (many addresses
  per user), not a single `users.email`. Travel dates come from confirmation
  body/subject/JSON-LD only — never IMAP Date or forward `Date:`/`Sent:`
  lines (`ForwardedMailNormalizer::stripDateSentHeaderLines`). Raw .eml
  files live under `storage/mail_raw/` for `MAIL_RAW_RETENTION_DAYS`
  (default 7) then purge; review list uses `MAIL_REVIEW_DAYS` (default 14).
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
  `public/hotels/edit-stay.php` (includes merge-duplicate). Edit property via
  `public/hotels/edit-property.php`. Email import upserts by confirmation
  code, else soft-matches same property + check-in so manual stays absorb
  the confirmation instead of duplicating.
- **Carriers own IATA.** Per-user `carriers` table (name + iata_code);
  `trip_segments.carrier_id` links flights. Flight form asks for flight
  number only; enrichment builds FlightAware ident as IATA+number.
  Manage under Settings → Site catalogs (`/settings/site.php`); shared site-wide catalog.
- **Mail parsers must handle direct vendor mail AND teammate forwards, plus
  confirm / change / cancel.** Ownership prefers outer `From:` (matched via
  `user_emails`). When `From` is a known vendor (Hilton/AA/Amtrak/…) and no
  user matches, `MailOwnerResolver` falls back to IMAP `Delivered-To` /
  `X-Original-To` / `To` / `Cc`, then body “delivered to” / “sent to”
  recipient hints — so Proton auto-forwards that keep the vendor `From`
  still attribute correctly. `ForwardedMailNormalizer` strips Fw:/Fwd:
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
- **FlightAware enrichment is date-scoped.** `GET /flights/{ident}` uses
  `start`/`end` around the segment's origin-TZ `depart_dt`, then picks the
  closest origin/destination match. After the first hit, refreshes stick to
  `fa_flight_id`. The enrich cron only sweeps segments with `depart_dt` in
  roughly the last 18h through the next 48h.
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

- `TripStatusEngine` itinerary `remote` (gap >3h) sets `detail.from_itinerary`
  so `TeamLocationResolver::isAtBaseStatus` does not treat it like a manual
  remote override (upcoming trips never relocate an at-base pin).
- **Airport timezones for status math.** Segment `depart_dt` / `arrive_dt`
  remain naive local wall-clock (builder + mail parsers unchanged). At compare
  time, depart uses origin IATA TZ and arrive uses destination IATA TZ from
  `airports` (seeded from `data/airports_us.php`). Avoids storing UTC while
  fixing HSV→DEN style multi-zone days. Hotels stay on `APP_TIMEZONE`.
- **Expense receipts are a durable file archive, not parse_log.** Short-lived
  `mail_raw` stays for system debug (7 days). User-facing PDFs live in
  `expense_receipts` + `storage/receipts/` (~90 days), preferring vendor MIME
  attachments over generated itinerary summaries. Generated PDFs are
  confirmation helpers, not vendor folios (amounts only when already on the
  stay).

## Immediate next steps (suggested, not started)

1. Tighten Delta/United/Hilton parsers against more live fixtures (trip
   details / Hilton cancel without original conf #).
2. Expand non-US hubs / admin UI for airports if travel patterns need it.
3. Optionally use `users.timezone` for "now" when home ≠ `APP_TIMEZONE`.
