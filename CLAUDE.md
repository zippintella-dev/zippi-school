# Zippi School Mobility — project context

> Auto-loaded by Claude Code. Read this before touching anything.

## What this is

The **Zippi School** dashboard — Layer 2 of a four-layer school transport
platform, built from the spec at [`docs/school_mobility_rebuild.md`](docs/school_mobility_rebuild.md).

| Layer | What it is | Status |
|---|---|---|
| 1. Zippi Parent | Mobile web app — child journey + absence | ✅ built *inside* Layer 2 (`/parent`) |
| 2. **Zippi School** | **Dashboard — transport administration** | **✅ this repo** |
| 3. Zippi Fleet | Mobile app — driver/attendant operations | ✅ Flutter app + `/api/fleet` — **linked to Layers 1 & 2** |
| 4. Zippi Control Tower | Central monitoring, safety, analytics | ✅ built *inside* Layer 2 |

Layer 4 lives inside Layer 2 deliberately: the "Live board" page **is** the
Control Tower. One controller, two authorization gates — a Zippi user sees every
school plus override buttons; a school user sees only their own school with
those controls hidden. Forking them would mean maintaining two live boards.

The dashboard was built **first on purpose**: the two mobile apps have nothing
to render until schools, routes, stops, children, buses and assignments exist in
a database. This is where all of that is created.

## Origin

This is a deliberate **carbon copy of an existing enterprise ride module**
(`docs/enterprise_ride_rebuld.md`, 4,307 lines — employee office transport),
retargeted to schools. Part letters map 1:1 (PART A, B, C…) so the two specs can
be diffed. Where school reality forces a *different* rule, the spec marks it
**⚠ DIVERGES FROM ENTERPRISE** — those are not to be "fixed" back.

The ten structural differences are tabulated in the spec's Context section. The
big ones:

- The rider (child) is **not** the app user — the parent is. A child has 0 logins and N guardians.
- Two vehicle actors: **driver** (drives, navigates) + **attendant** (marks children).
- OTP moves from pickup to **drop**, and belongs to the **guardian**, not the rider.
- A child not at a drop stop **cannot** be marked no-show and left.
- One bus runs 2–3 **tiered** trips per direction (Senior/Middle/Primary bell times).
- Stop order is **authored and frozen**, not nearest-neighbour.

## ⚠ Four Critical Safety Invariants

These outrank every other section of the spec, including its own later parts.
If a change would violate one, the change is wrong. All four are tested in
`tests/Feature/SafetyInvariantsTest.php`.

1. **A child is never released without a verified receiver.** Handover code,
   authorized-receiver photo, or consented self-release. If none can be
   satisfied at the stop, the child **returns to school**. There is no
   "mark absent and drive on" at a drop stop.
   → `Child::canSelfRelease()` requires *both* signed consent *and* the school's minimum grade.
2. **A trip cannot complete while a child is unaccounted for.**
   → `SchoolTrip::unaccountedChildIds()` is the 422 gate.
3. **The bus is physically swept before the trip closes** — timestamped,
   geo-stamped, photo-backed. Cannot be skipped or pre-tapped.
   → `SchoolTrip::sweepPending()`.
4. **A stuck ride alerts, and every override is audited.**
   → `SchoolAdminAuditLog` throws `LogicException` on update *and* delete.

## ⚠ PART L1 — the date rule (most likely thing to break)

`service_date`, `school_calendars.date`, and every `effective_from` /
`effective_to` column are **raw `Y-m-d` strings on the model**.

**Never add a `'date'` cast to them.** Carbon reads the value as midnight in
`APP_TIMEZONE`, re-serialises as UTC, and shifts every IST date back one
calendar day. That is exactly what made undo-leave delete zero rows in the
enterprise module. Datetime columns (`started_at`, `boarded_at`, …) keep their
casts — only DATE columns are bare strings.

Affected models carry warning comments: `SchoolTrip`, `SchoolChildAbsence`,
`SchoolCalendar`, `RouteStaffOverride`, `ChildStopOverride`,
`ChildStopAssignment`, `SchoolBellTime`.

## ⚠ Keep this project out of iCloud

`~/Desktop` and `~/Documents` are iCloud-synced on this machine. With ~10,000
files under `vendor/`, iCloud stalls Composer's class loader and PHPUnit at
startup. The failure is **not a clean error** — it hangs, then dies with
`Maximum execution time exceeded at vendor/composer/ClassLoader.php`.

Measured, identical code and PHP: test suite **hangs indefinitely** under
`~/Desktop`, **3.9 seconds** at `~/zippi-school`. Details in
[`docs/WHERE-IS-THE-APP.md`](docs/WHERE-IS-THE-APP.md).

## Stack & running it

Laravel 13 · PHP 8.5 · SQLite locally (schema is MySQL-compatible — switch
`DB_CONNECTION` for production). **CSS is hand-written** in
`public/css/zippi.css` — no Vite, no Tailwind, no npm. `php artisan serve` is
the only command needed.

```bash
cd ~/zippi-school
php artisan migrate:fresh --seed
php artisan school:refresh-demo    # rolls seeded dates to today; idempotent
php artisan serve                  # http://localhost:8000
php artisan school:generate-trips  # PART M2 — solve today's trips
php artisan test                   # 158 tests, ~55s
```

| Role | Email | Password |
|---|---|---|
| Zippi admin | `ops@zippi.in` | `password` |
| Ops lead | `lead@zippi.in` | `password` |
| School user | `transport@phoenixgreens.edu.in` | `password` |

**No Tailwind.** Laravel's default pagination views are Tailwind-classed, so
their `w-5 h-5` SVG chevrons rendered page-sized. Fixed with a custom view at
`resources/views/vendor/pagination/zippi.blade.php`, registered in
`AppServiceProvider`. If you add a package that ships Tailwind markup, expect
the same class of bug — there's a defensive `svg { max-width: 100% }` rule.

## Codebase shape

20 controllers · 25 models · 11 migrations · 31 Blade views · 158 tests ·
27 domain tables · 74 web routes + 33 API routes

```
app/Http/Controllers/   Dashboard, LiveBoard, Trip, Child, ChildImport, Route,
                        Roster, Calendar, Bus, Staff, Compliance,
                        SchoolSettings, Auth  (+ base Controller)
app/Http/Controllers/Api/  ParentApi, ParentAuthApi   (Layer 1)
                           FleetApi,  FleetAuthApi    (Layer 3)
app/Models/             School, Child, Guardian, SchoolTrip, SchoolTripChild,
                        RouteStop, SchoolStaff, Bus, SchoolAdminAuditLog, …
app/Services/           SchoolCalendarService  (school-day resolution, PART C3)
                        FleetTripService       (Layer 3 — the four invariants)
                        FamilyCardBuilder      (Layer 1 — the live-map rule)
                        OtpService             (PART P1–P4 — login codes)
                        SchoolTripGenerator    (PART M2 — nightly generation)
                        SchoolTripSolver       (PART M7 — solve from the bell)
                        RouteCrewResolver      (PART O3 — override ?? default)
app/Console/Commands/   RefreshDemoDay         (school:refresh-demo)
                        GenerateTrips          (school:generate-trips)
config/school.php       PART M7 constants — buffers, dwell, fallback speed
mobile/zippi_parent/   the Flutter app (Layer 1) — see its own section below
mobile/zippi_fleet/    the Flutter app (Layer 3) — see its own section below
design/                design briefs handed to Claude Design
dist/                  build outputs & share bundles (APKs, code bundles)
resources/views/parent/ Zippi Parent (Layer 1) + layouts/parent.blade.php
public/css/parent.css   the parent app's mobile design system
public/parent-sw.js     PWA shell — caches assets, NEVER trip data
resources/views/        dashboard live roster trips children routes calendar
                        buses staff compliance settings + vendor/pagination
public/css/zippi.css    the whole design system (~560 lines)
docs/                   the specs — school + the enterprise source material
```

`app/Http/Controllers/Controller.php` is a real base class: `activeSchool()`,
`calendar()`, `today()` (school timezone, never the server's), and a `view()`
helper that injects the sidebar alert badges.

## Zippi Parent (Layer 1) — `/parent`

The family app, served as a **mobile web app / PWA from this same install** —
Blade + hand-written `public/css/parent.css`, still no npm. Four screens per
PART H4: phone → OTP → Family Dashboard → Child Live → Journey (90 days).

```
/parent               login (phone)      /parent/child/{id}          live
/parent/verify        OTP                /parent/child/{id}/data     10s poll (JSON)
/parent/home          family dashboard   /parent/child/{id}/journey  history
```

**Two identities, deliberately separate.** A guardian authenticates on the
`guardian` guard (own table, OTP, no password); staff use `web`. Ops routes are
pinned to `auth:web` and parent routes to `auth:guardian` — **never bare
`auth`**, which resolves the ambient default guard and let a Guardian object
reach `Controller::activeSchool()` → `isZippi()` and 500. Same reason
`activeSchool()` calls `auth('web')` explicitly.

⚠ **`ParentController::myChild()` is the authorization gate.** Every child
lookup goes through it and resolves via the signed-in guardian's own links —
never `Child::findOrFail()`. It 404s rather than 403s so the response can't be
used to probe which children exist. Getting this wrong hands any parent the live
GPS of any child in the school.

⚠ **The live map obeys the server (enterprise L29).** Morning: open until the
child is inside the school. Afternoon: closes for *that family* the moment their
child is handed over, while the bus keeps running for everyone else. When it's
closed the bus lat/lng is **omitted from the payload**, not just hidden in the UI.

⚠ **Handover code (PART A7) — AFTERNOON ONLY, and that is deliberate.**

The code is a **release** check, not a boarding check. Afternoon custody goes
school → *individual*, at a kerb, to whoever turns up — the one moment a
stranger could take a child, and exactly what Invariant #1 protects. Morning
custody goes child → *institution*: the child is delivered to the school, which
does not need to prove its identity to receive a pupil.

The morning risk is different — wrong child boards, or boards the wrong bus —
and is covered by the attendant's roster (photo per row, 56dp targets, sibling
disambiguation, PART F2) plus the boarding confirmation the parent receives.

⚠ Asked for and re-decided once already ("shouldn't there be a code when the
parent hands the child over in the morning?"). Moving the code to the morning
would leave the afternoon drop with **no receiver verification anywhere**. If a
morning boarding code is ever genuinely wanted, ADD one — never relocate this.

The DB stores only a hash; the plaintext lives in the cache for the day so the
parent can read it out at the kerb. Rotates daily. Never goes in an ops payload
or a push body (K9).

### ⚠ Morning boarding code (PART A7 extended) — the ADD, not the move

Built 2026-09-02. The attendant asks the guardian for a boarding code as each
child gets on in the morning. The afternoon handover code above is **unchanged
and still where it was**.

**It is a second, separate value.** `Child::todaysBoardingCode()` has its own
hash, own column (`boarding_code_hash`/`boarding_code_date`), own daily rotation
and own cache key. Merging the two would be less schema and a worse system: the
morning code is read aloud at a public kerb every day in front of the other
families, so a shared value would broadcast that afternoon's *release* code to
everyone within earshot. `FleetApiTest::test_the_boarding_code_is_not_the_handover_code`
holds the line.

**The two keypads lock out separately.** The attempt counter was keyed by day
alone; it now takes a `$scope`. Sharing it meant five wrong codes at a 07:15
kerb silently locked that child's 15:30 *release*.

⚠ **The morning check never blocks boarding, and that asymmetry is the design.**
Refusing is safe in the afternoon — the child stays on the bus and the ladder
runs. In the morning the child is on the pavement and the bus is leaving, so a
check that could refuse absolutely would strand a child over a flat phone
battery. `FleetTripService::verifyBoarding()` therefore always leaves the
PART F2 photo-roster route open, `school_trip_children.boarding_verification`
records which was used, and a `boarding_code_bypassed` event fires when a code
was tried, failed, and the child boarded anyway. If a school wants a hard block,
gate **only the fallback branch** on a per-school flag — do not delete it.
`test_a_locked_boarding_keypad_still_lets_the_child_board` is the one that must
never stop passing.

Parent side: `show_boarding_code` on the family card, mutually exclusive with
`show_handover_code`. It disappears the moment the child boards — the morning map
stays open until they reach school, so nothing else would clear it.

**OTP** (`OtpService`, PART P1–P4): `random_int(1000,9999)`, hashed, 10-min TTL,
5 wrong tries → 1-min lockout, 3 sends per 2 min, and issuing a new code
consumes the old one. **No static 1234 anywhere** — in local the code is logged
and flashed to the screen, gated on `app()->environment('local')`, never a
config flag. An unknown phone gets the *same* response as a known one; telling a
stranger which numbers are parents at a school is a child-safety leak.

**Not wired yet:** no SMS gateway, so codes go to `storage/logs/laravel.log`.
Read PART P2 before wiring one — both carried-forward bugs (empty DLT template
id; `ltrim($mobile,'91')` eating leading 9s and 1s) are silent, and the gateway
reports success either way. Push (PART F1's 15 notification types) is also not
built; the app polls every 10s and re-fetches on resume (F6/L14) instead.

## Zippi Fleet (Layer 3) — `mobile/zippi_fleet`

The vehicle app, in Flutter, built from the Claude Design canvas
`Zippi Fleet.dc.html` and the brief in `design/zippi-fleet-design-brief.md`.
15 screens · 47 tests. Its own README lists
the screen-to-file map and the API endpoints it is written against.

**One app, two role modes.** The attendant marks children, verifies who
collects them and sweeps the bus. The driver navigates. `FleetRole.driver`
renders **no child-marking control anywhere** — and `FleetStore._guardRole()`
refuses one even if a route reached it. That split is the reason both roles
share one app rather than two.

⚠ **THE THREE LAYERS SHARE ONE SET OF ROWS. There is no sync step.** Fleet is
the only surface that writes what actually happened on a bus, and it writes
`school_trips`, `school_trip_children`, `school_stop_arrivals`,
`school_child_handovers`, `school_child_absences`, `school_trip_events`,
`school_incidents` and `buses` — the same rows `LiveBoardController` (Layer 2)
and `FamilyCardBuilder` (Layer 1) already read. An attendant's tap is on the ops
live board and on the family's card at their next poll, with nothing in between.

That is deliberate and it is why the linking work was small. Anything that
introduces a separate fleet-side store of child state re-opens the question
"which copy is right about where this child is", and there is no acceptable
answer to it. `tests/Feature/FleetLinkTest.php` asserts the absence of that
seam and will fail if somebody adds one.

⚠ **`POST /api/fleet/trips/{trip}/ping` is what moves the parents' live map.**
The crew's device is the bus tracker until a hardware unit exists; it writes
`buses.latitude/longitude`, and `FamilyCardBuilder` then decides *per family*
whether that position may leave the server at all (enterprise L29). An app in
the background is a bus that appears to have stopped — a known limitation, and
why the parent side renders a stale fix as "may be out of date" rather than
silently drawing it as live.

⚠ **A token identifies a ROLE, not a person (PART P6).** `school_staff` is
UNIQUE(school_id, phone), so an attendant at one school and a driver at another
are already two rows keyed by one number — those rows ARE the spec's role links,
and `/otp/verify` mints one token each. Picking a card on the role screen is
literally picking which token is sent, which is why `canMarkChildren()` cannot
be spoofed: no request carries a role for a client to claim.

⚠ **`token.holder` middleware pins each API group to one kind of token.**
`routes/api.php` once said a staff token "could never satisfy these routes
because no staff token is ever minted" — Zippi Fleet mints them, so that stopped
being true. Sanctum authenticates whoever a bearer token belongs to and does not
care which model it is; without the middleware an attendant's token would
authenticate against `/api/parent`, where every route resolves children through
`$request->user()`. Both directions are tested. **Do not remove it.**

`Config.demoMode` (`--dart-define=ZIPPI_DEMO=true`) still runs the fifteen
screens with no backend, for a walkthrough on a laptop. Nothing it records
leaves the handset, and the login screen says so.

⚠ **THE SERVER IS THE AUTHORITY FOR ALL FOUR INVARIANTS.**
`app/Services/FleetTripService.php` re-decides every rule from the database on
every write, and its refusal is what the crew sees. `FleetStore` holds the same
rules so a crew member is told *before* they tap rather than after — but a phone
can be old, offline, rooted, or running a build from March, and the local copy
is never a substitute. Both raise the same `SafetyViolation`, whose message is
rendered verbatim (PART P7) and always names children rather than counting them.

Three test files, all of which must pass: `test/safety_invariants_test.dart`
(the app's local rules), `tests/Feature/FleetApiTest.php` (the server refuses a
tampered request), and `test/fleet_api_test.dart` (the app parses the server's
payloads, and maps 4xx to a refusal but 5xx to a transport failure — a crashed
server has not decided that a child may not be released).

⚠ **The design system is a near-copy of the Parent app's** (`lib/theme.dart`),
plus a red/danger ramp and `Z.strip`. Change a colour or radius **on the canvas
first**, or the two apps drift — and a family and a bus crew look at both at the
same kerb.

⚠ **Deliberate divergences from the canvas**, all in the README: class groups
sort youngest-first (UKG is dismissed first and is least able to find its own
bus); the head count is never pre-filled with the app's own number, because
confirming a number the app already knows does not catch a wrong-row mis-tap;
and no child photographs are bundled — a repo is the wrong place for 22
children's faces, so `photoUrl` is null and the avatar falls back to an initial.

`test/screen_smoke_test.dart` pumps every screen at 428×908 **including its
blocked, expired and refused states**. Those are the ones a manual walkthrough
never reaches, and a `RenderFlex overflowed` on the escalation screen is a bug
nobody finds until the worst afternoon of somebody's year.

## Domain model — the four keys

Every operational row is keyed by **(child|route, service_date, direction,
bell_tier)**. All four. Getting this wrong presents as *"I marked her absent and
the bus still waited."*

- **service_date** — the school day. Never crosses midnight (unlike enterprise night shifts).
- **direction** — `Morning` (home→school) or `Afternoon` (school→home).
- **bell_tier** — which staggered bell: `Senior` / `Middle` / `Primary`, from `school_bell_times`.

A **bell tier** is a *when* — one of the school's staggered bells (Senior 07:40,
Middle 08:15, Primary 08:45), each covering a grade band. It exists because one
bus cannot carry the whole school at once, so the same bus makes 2–3 runs per
direction. A **trip** is one scheduled run of one route, one direction, one date,
one tier (a *what actually runs*). One tier spans many trips on many buses
simultaneously. Don't conflate them.

⚠ **`bell_tier` was called `trip_leg`** and both specs still use that name.
Renamed because "leg" already means the hop between two stops — and the codebase
used it in *both* senses, in the same JSON payload. In this codebase `leg` now
means only the stop-to-stop hop (`leg_minutes`, `leg_distance_m` inside
`route_schedule`). When the mobile API is built, map `bell_tier` back to the
spec's `trip_leg` **at the boundary** so the PART M3 contract still matches;
don't reintroduce the old name inside the app.

Children are assigned to a published **stop**, never to a home coordinate.
Door-to-door is modelled as a stop with one child, so there's only one routing path.

## Seeded demo data

Phoenix Greens International School (Pilot School #1 — nothing hard-codes it):
**217 children · 390 guardians · 6 routes · 25 stops · 6 buses · 12 staff · 6 trips**,
with today's trips seeded mid-flight (3 completed, 2 running, 1 upcoming) plus
deliberate exception rows so the Control Tower queue isn't empty. One trip is
force-completed **without a sweep** on purpose — that's Invariant #3 surfacing.

Bell times: Senior 07:40 (grades 9–12) · Middle 08:15 (6–8) · Primary 08:45 (1–5).

## What works

**Read:** Dashboard, Live board (queue above map — an ops person watching 40
buses works a list, not a map), Daily roster, Trips & history, **Child journey
record** (the killer feature — every event with actor, coordinates, server
time), Students, Routes & stops, Calendar, Buses, Staff, Compliance.

**Trip generation** — `school:generate-trips {date?} {--school=} {--tomorrow}
{--dry-run} {--force}` (PART M2), scheduled 02:30 IST in `routes/console.php`.
Generates one trip per **(route × direction × bell tier)** and solves its stop times
with `SchoolTripSolver` (PART M7): morning backward from `bell − 10min`,
afternoon forward from dismissal. Idempotent — a re-run skips; `--force`
re-solves auto rows but **never** an `admin_edited` or already-started trip.
Every invocation writes a `school_trip_generation_runs` row with counts and
warnings.

⚠ **The tier comes from the children, not from `routes.bell_tier`.** That column is
a labelling hint. If it were authoritative a route could serve only one tier,
which contradicts the whole tiered model (one bus, 2–3 trips per direction).

⚠ **Solved times currently run long.** `DriveTimeEstimator` isn't built, so
`legMinutes()` uses the spec's haversine fallback — straight line × 1.5 circuity
÷ 300 m/min (18 km/h). That is deliberately conservative (PART M10: earlier is
the safe direction to be wrong), but it means a 20 km route solves to a ~108-min
run and a 06:16 departure. Real ETAs will tighten this substantially. The
`deadline_too_tight` warning fires when a route can't reach its bell at all.

**Write** — the PART S1 onboarding funnel, end to end:
`Settings & bell times → Calendar → Students (CSV or manual) → Routes & stops → Fleet → Crew`

- **Student CSV import**: preview-then-commit with per-row warnings. Matches on
  `admission_no`, resolves routes by code and stops by name. Guardians created
  but **not invited** — a mis-import must never SMS 500 families.
- **Calendar**: day/range setting; a holiday forces both trip flags off and can
  cancel already-generated trips in one transaction. Year-long CSV import.
- **Routes & stops**: add/edit/reorder/delete. Codes normalise (`3` → `RT-03`).
- **Roster**: everyday **default** crew vs one-day **stand-in** that auto-reverts.

Enforced guardrails (not suggestions): the last guardian cannot be unlinked (no
guardian = no notifications at all), a route with riders cannot be deleted, and
a school user gets **403** on Zippi-only overrides — server-side, not just
hidden UI.

⚠ **ONE NUMBER, ONE PERSON (guardians).** A guardian's phone decides who gets a
child's boarding alerts and daily handover code, so two people sharing a number
means the wrong adult is told where a child is — and can collect them.

`linkGuardian()` used to be a bare `firstOrCreate(['phone' => …])`, which
silently reused the existing row and **threw the new name away**: entering a
second, different parent on a number already in use quietly linked the *first*
parent to the child. Now a clash on a **different name** is rejected and the
conflict named; the same person guarding two siblings still reuses the record
(`samePerson()` is forgiving on case, spacing and punctuation only). Editing a
guardian enforces the same rule, so it can't be used as a side door. The CSV
importer reports clashes rather than aborting the batch — one bad cell must not
fail 400 rows — and skips the link, which the summary states loudly.

Phones are stored **canonical** (`PhoneNumber::canonical`) on write, so
`+91 98765 43210` and `9876543210` collide instead of becoming two rows.
⚠ Pre-existing rows were **not** back-filled; comparison is canonical-to-canonical
so they still match correctly, but a stored 11-digit number stays 11 digits.

**Guardians are editable** from the student record (name, phone, email,
relationship, primary, app access). Name/phone/email belong to the *person* and
change for every child they guard — the form warns when that is the case;
relationship and primary are per-child pivot values.

**Removing a student — two actions, deliberately distinct.** Row buttons on the
Students list, plus a danger zone on the record page.

- **Remove from transport** — reversible. Sets `status=inactive`, and
  `SchoolTripGenerator` skips inactive children from the next run. The journey
  record is kept (PART K13). They **drop off the Students list** and appear
  under **Removed** (sidebar, with a count badge), where *Restore* puts them
  back. The two lists share one query in `rosterPayload()` so they cannot
  disagree; Students is the working roster, and a retired child mixed into it
  is a child someone assigns a stop to by mistake.
- **Delete permanently** — offered **only** when the child has no trip row, no
  handover, no absence and no incident: a row typed in error during onboarding,
  with no journey record to protect. `ChildController::transportHistory()` is
  the single definition, used by both the view (to show the button) and the
  controller (to enforce it), so the two cannot drift apart. Guardians left with
  no children are removed too, freeing their phone number's UNIQUE slot.

⚠ The deletion audit entry carries the child's identity in its **payload**, not
only the `child_id` column — that column is a `nullOnDelete` FK, so an entry
recording a deletion would otherwise fail to record *what* was deleted.

## Not built yet — in dependency order

1. **Route versioning on re-sequence** (PART G3) — the ↑↓ arrows reorder
   immediately. Production requires an effective-from date, a new route version,
   and guardian notification, because a March journey record must render against
   March's stop times. **Reordering a live route today retroactively shifts
   historical records.** Known gap.
2. **Real Google Directions ETAs** — `DriveTimeEstimator` carries over from the
   enterprise module unchanged (traffic-aware, `best_guess`, 15-min cache).
   The solver has the seam (`SchoolTripSolver::legMinutes()`) and currently uses
   the documented haversine fallback, so solved times run long. See below.
3. **JIT generation + reconciliation** (PART M11 / M14) — `today-trips` should
   self-heal when the 02:30 cron is missed, and re-sync a scheduled trip's child
   list on load. Today a missed cron means no trips until someone runs the command.
4. **Trip generator UI** — generation is CLI-only; PART M2 wants a manual trigger
   on an admin Schedule page, with per-stop time overrides (M7).
5. **Parent notifications** (PART F1) — all 15 event types. Needs FCM
   credentials and an SMS gateway; the parent app polls instead for now.
6. **An offline write queue in the Fleet app.** Failed writes are counted and
   shown in the banner; they are not replayed. The app tells the truth about
   what has not reached the server, but does not yet get it there by itself —
   so a boarding marked in a long dead zone has to be re-tapped. The route
   passes through dead zones, so this matters.
7. **PART P6 proper** (`users` + `role_links`). One token per `school_staff` row
   is the shape that cannot be spoofed today, but it does not model "one person"
   — a crew member who is also a parent has two unrelated logins, and nothing
   joins them.
8. Live map is a schematic plot; production uses Google Maps + Firebase RTDB on
   `ID/{driver_id}`, same plumbing as enterprise.

## Bugs found and fixed here (don't reintroduce)

- **Holiday wrote contradictory data.** Reading checkboxes with a `true` default
  meant `day_type=holiday` stored alongside `morning_trips_run=1`, and the
  closure cancelled zero trips — buses dispatched into a closed school. Fixed by
  deriving the flags from `day_type`. Regression test posts the flags "on" and
  asserts they're forced off.
- **Calendar shift collided with `UNIQUE(school_id, date)`** in
  `school:refresh-demo` — rows must move in the direction of travel (highest
  date first when shifting forward).
- **Pagination SVGs rendered page-sized** — see the No-Tailwind note above.
- **`bell_tier` filter existed in the controller with no UI control** — only reachable
  by hand-editing the URL. Dropdown added.
- **A MORNING absence switched off the AFTERNOON handover code.**
  `FamilyCardBuilder` loaded the day's absences for *all* directions and tested
  that one collection in both `show_handover_code` and `show_boarding_code`. So
  "dropped at school by a parent, riding the bus home" — an ordinary school day
  — lost its receiver verification at the drop stop (Invariant #1): the
  attendant asks for a code the parent's app refuses to show, and the escalation
  ladder ends with a child returned to school. The check is now scoped to the
  trip's own direction (`$legAbsence`). The same day-wide thinking also pinned
  the card to a morning trip the child was not on, so the afternoon leg could
  never become current — trip selection now prefers legs the child is riding.
  `test_a_morning_absence_leaves_the_afternoon_handover_code_alone` holds both.
- **The morning boarding code was unreachable in the Flutter parent app.**
  `PickupScreen` rendered it and `FamilyCard` carried it, but `home_screen.dart`
  only ever offered a way in for `show_handover_code` — and that screen is not a
  tab, so in the morning nothing led to it. Worse, `PickupScreen` popped itself
  when `!showHandoverCode`, which is false all morning, so even reaching it
  bounced you out on the next 10-second poll. The entry point now switches on
  the direction, the pop tests both flags, and the header no longer says
  "Afternoon pickup" over a morning code.
- **The Fleet role picker showed DEMO assignments in a live session.** Both
  `FleetStore.signIn()` and `RoleScreen` read
  `roleLinks.isEmpty ? DemoData.assignments : roleLinks` unconditionally, and
  `main.dart`'s restore passed no links (the keychain held only name/phone/token).
  So every relaunch offered the crew "Route 12 / Route 7 · Silver Oak School" —
  a school and buses that exist only in `demo_data.dart`. Picking one set
  `assignment.role`, which gates whether the device renders any child-marking
  control, while the token in hand belonged to a different staff row. Links are
  now persisted and restored; the demo fallback is gated on `!isLive`; an empty
  live picker says "sign in again". ⚠ **A real role link carries no route, bus
  or bell** — those belong to today's trips. A picker card showing a route is
  fabricated.
- **The Fleet duty board said "No trips today · this vehicle has nothing
  scheduled" when it had simply failed to reach the server.** An empty
  `duties` list was treated as an answer; it is also what an unread board looks
  like. The board now carries `dutiesCheckedAt`/`dutiesError` and separates
  unreachable from empty. Same fix removed two smaller lies: the empty state's
  "Refresh · checked HH:MM" button was a bare `setState` that fetched nothing
  and printed the current clock, and a failed *read* was counted as a queued
  *write*, so the banner promised to sync an update that did not exist.

## Six open decisions (block later phases)

Listed at the end of the spec. The two that matter most:

1. **Does every Phoenix Greens bus have an attendant?** The whole two-device
   role split rests on it.
2. **Who owns the parent relationship under managed mobility — Zippi or the
   school?** Determines the support number in every notification template.
   Decide before writing the first template.

## Conventions

- Comments explain *why*, and cite the spec part (`PART A7`, `Invariant #2`) so
  a reader can find the reasoning. Don't strip them.
- Every state change records actor identity, coordinates and **server** time.
  Client time is stored separately as `client_reported_at` and never trusted for ordering.
- Ops-facing payloads mask phone numbers (`maskedPhone()`). A test asserts no raw
  `+9198…` appears in `/live/data`.
- Never throttle an action an operator must repeat under pressure (enterprise
  BF3 — a throttled attendant is a safety problem). Use idempotency instead.
- **Everything lives under `~/zippi-school`.** Never write generated files to
  `~/Downloads` or `~/Desktop` — build outputs and anything handed to the user
  go in `dist/`. Quote **absolute** paths, never `~`; it doesn't expand in every
  app and the files become impossible to find.
- This is **not a git repository yet.**
