# CLAUDE.md -- NexWAYPOINT project notes

This file is for whichever Claude session picks this project up next.
Keep it current: update the Status and Decisions sections whenever scope
changes, and don't let it drift from what's actually in the repo.

## Who this is for

David Mcferrin, broadcast engineer at NewsNation, travels ~60% of the
time, based in Huntsville, AL. Builds his own tools: Python/PHP/MySQL or
SQLite, self-hosted, reliability and low-maintenance-while-traveling over
feature breadth. Prefers direct technical pushback over agreement.

## Status as of this build (2026-07-18)

v1 scaffold is complete and passes lint + tests: hotel stay tracker (full
CRUD + blacklist + criteria suggestions + photo upload), mail ingestion
(DreamHost IMAP working end-to-end, Gmail/M365 interfaces defined but
throw `NotImplementedException`), a generic hotel-confirmation parser,
FlightAware AeroAPI client with rate limiting + caching, trip status
engine, alert evaluator + notifications, and the visibility/sharing
engine covering all five directions with override precedence. Basic
server-rendered PHP UI exists for login, hotel list/add/view, dashboard,
and sharing settings. VPS deployment is bootstrapped by an idempotent
`setup.sh` that installs/verifies dependencies, creates `.env`, initializes
MySQL or SQLite, creates the first local user, and configures eligible cron
jobs. Additional users can be created with `scripts/create_user.php`.

**Not started:** flight/train/car email parsers (only hotel), Azure AD
SSO, map view, PWA/offline, push notifications, hotel-stay edit page,
approval UI for auto-imported stays (currently auto-creates + notifies
instead of a pending-confirmation flow).

24 PHPUnit tests / 56 assertions passing against an in-memory SQLite DB.
All PHP files pass `php -l`.

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
- **Visibility defaults resolved a real contradiction in the brief.** See
  the "A note on the visibility defaults" section in README.md --
  TOP_DOWN (manager viewing subordinate) defaults to city+date only,
  BOTTOM_UP (subordinate viewing manager) defaults to full visibility.
  This follows the two structured spec blocks the user pasted, not the
  one looser free-text sentence that seemed to say the opposite. If this
  is wrong, it's a one-line fix in `VisibilityEngine::getVisibleFields()`.
- **Auto-import creates the hotel stay directly + notifies**, rather than
  a pending-approval queue the user has to click through. The original
  "We found a trip... Confirm?" flow from the pasted spec is more UI than
  this pass covers. Documented as a gap, not silently dropped.
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
- **Production host layout keeps the DreamHost domain folder alone.** Code
  lives at `/home/dh_w9tij7/NexWAYPOINT`; the public site is
  `https://nexwaypoint.area51consulting.com`. Set the domain's Web Directory
  in the DreamHost panel to `/home/dh_w9tij7/NexWAYPOINT/public`. Do not
  symlink or replace `/home/dh_w9tij7/nexwaypoint.area51consulting.com`.
  Storage and secrets stay under the clone, never under a web-facing path.

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
  MailPoller currently only writes `hotel_stays`, not `trips`/
  `trip_segments`, for parsed hotel confirmations (see README gap list).
- Value objects (`HotelStay`, `Trip`, `TripSegment`, `User`) use readonly
  properties with named-argument construction. `toArray()`/`fromRow()` use
  **snake_case** keys (DB column names); constructors use **camelCase**
  parameter names. Don't spread `toArray()` output directly into the
  constructor -- go through `fromRow()` instead (a test caught this exact
  mistake -- see the comment in `tests/HotelStayRepositoryTest.php::testUpdateChangesFields`).
- The sandbox this was built in has no PHP preinstalled and no root
  access; PHP 8.1 + extensions + PHPUnit were pulled via `apt-get download`
  (no `apt-get install`) and extracted with `dpkg-deb -x` into a scratch
  prefix to lint and test everything before delivery. That tooling doesn't
  ship with the project -- it was throwaway CI for this session only.

## Immediate next steps (suggested, not started)

1. Flight confirmation parsers (Delta/United minimum) -- highest-value
   next addition since it unlocks real dashboard auto-population.
2. Hotel-stay edit page (currently add/view/delete only).
3. Wire `trip_segments` creation into `MailPoller` for hotel confirmations
   so a parsed stay also shows up on the travel dashboard, not just the
   hotel tracker.
4. Decide whether the pending-approval UI ("we found a trip, confirm?")
   is worth building vs. the current auto-create-and-notify approach.
