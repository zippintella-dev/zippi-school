# Zippi School — Dashboard (Layer 2 of 4)

The **Zippi School** layer from [`docs/school_mobility_rebuild.md`](docs/school_mobility_rebuild.md):
transport administration and the Control Tower board, built on Laravel so it can
merge into the existing enterprise codebase rather than becoming a second thing
to maintain.

The spec and its source material live in [`docs/`](docs/):

| File | What it is |
|---|---|
| `school_mobility_rebuild.md` | The school-mobility spec this app implements |
| `enterprise_ride_rebuld.md` | The original enterprise module it was derived from |

Built first, deliberately: the parent app and the driver/attendant app have
nothing to show until schools, routes, stops, children, buses and assignments
exist in a database. This is where all of that is created.

## ⚠ Keep this out of iCloud-synced folders

This lives at `~/zippi-school`, **not** under `~/Desktop`, and needs to stay
somewhere iCloud does not sync.

`~/Desktop` and `~/Documents` are iCloud-synced on this machine. A Laravel
project has ~10,000 files under `vendor/`, and iCloud stalls the rapid
small-file reads that Composer's class loader and PHPUnit perform at startup.
The symptom is not a clean error — it is an indefinite hang, ending in
`Maximum execution time exceeded at vendor/composer/ClassLoader.php`.

Measured on this machine, same code and same PHP: the test suite **hangs
indefinitely** under `~/Desktop`, and completes in **3.9 seconds** at
`~/zippi-school`.

## Run it

```bash
cd ~/zippi-school
composer install          # already done
php artisan migrate:fresh --seed
php artisan school:refresh-demo   # rolls demo data to today (see below)
php artisan serve
```

Then open <http://localhost:8000>.

| Role | Email | Password |
|---|---|---|
| Zippi admin | `ops@zippi.in` | `password` |
| Ops lead | `lead@zippi.in` | `password` |
| School user | `transport@phoenixgreens.edu.in` | `password` |

Sign in as the **school user** to see the same pages with Zippi-only override
controls hidden — one controller, two authorization gates (PART J6).

### `school:refresh-demo`

Seeded trips are dated the day they were seeded. A demo left running overnight
would show an empty dashboard the next morning. This command shifts every date
column by the same offset so relative relationships (bell times, stop arrivals,
calendar entries) stay intact:

```bash
php artisan school:refresh-demo            # roll to today
php artisan school:refresh-demo --to=2026-09-01
```

It is idempotent — running it twice on the same day is a no-op.

## What's here

| Page | Spec part | What it demonstrates |
|---|---|---|
| Dashboard | B, D, J3 | Today's trips, boarding counts, exception queue, absences with reason codes |
| Live board | B2, T4 | The **queue above the map** — an ops person watching 40 buses works a list, not a map |
| Daily roster | O5 | Per-route rider list with default vs one-day stand-in staffing |
| Trips & history | D1, D4 | Filterable trip log derived from operational tables — no parallel history tables |
| Trip detail | A7, G1, L2, L3 | Stops in authored sequence, children grouped by stop, handovers, sweep + head-count state, append-only audit trail |
| **Child journey record** | **D2** | The killer feature — every event with actor, coordinates and server time |
| Students | E1, E3 | Stop assignment (not home coordinates), guardians, authorized receivers |
| Routes & stops | G1, G3 | Authored sequence, crew assignment, re-sequencing rules |
| Calendar | C3 | Month grid with holidays, half-days, exams driving generation |
| Buses / Staff | E3, H3 | Fleet with EV flags, the driver/attendant role split |
| Compliance | K15 | CMVR document expiry board, school-facing as well as ops-facing |

## The rules this code enforces

The spec's four Critical Safety Invariants outrank everything else, so they are
tested in `tests/Feature/SafetyInvariantsTest.php`:

1. **A child is never released without a verified receiver.** `Child::canSelfRelease()`
   requires *both* a signed consent and the school's minimum grade.
2. **A trip cannot complete while a child is unaccounted for.**
   `SchoolTrip::unaccountedChildIds()` is the 422 gate.
3. **The bus is swept before the trip closes.** `SchoolTrip::sweepPending()`;
   the seeder deliberately leaves one force-completed trip without a sweep so
   the exception queue has a real row.
4. **A stuck ride alerts, and every override is audited.**
   `SchoolAdminAuditLog` throws `LogicException` on update *or* delete.

### ⚠ PART L1 — the date rule

`service_date`, `school_calendars.date` and every `effective_from/to` column are
**raw `Y-m-d` strings on the model**. Do not add a `'date'` cast. Carbon would
read the value as midnight in `APP_TIMEZONE`, re-serialize it as UTC, and shift
every IST date back one calendar day — which is exactly what made undo-leave
delete zero rows in the enterprise module. Datetime columns keep their casts;
only DATE columns are bare strings. The models carry warning comments and
`SafetyInvariantsTest` asserts the types.

### PART K9 — masked numbers

`/live/data` and every ops-facing view render `maskedPhone()`. A test asserts no
raw `+9198…` number appears in that payload. Staff and guardians reach each
other through a call proxy in production.

## Write paths

The onboarding funnel from PART S1 works end to end:

```
School settings & bell times → Calendar → Students (CSV or one by one)
   → Routes & stops → Buses & staff → Crew assignment
```

| Surface | Notes |
|---|---|
| **Student CSV import** | Preview-then-commit. Reports per-row warnings, matches on `admission_no`, resolves routes by code and stops by name. Guardians are created but **not invited** — a mis-import never SMSes 500 families. |
| **Calendar** | Set a day or range; a holiday forces both trip flags off and can cancel already-generated trips in the same transaction (PART J2 #7). CSV import for the whole year. |
| **Routes & stops** | Add, edit, reorder (↑↓) and delete stops. Route codes normalise — typing `3` yields `RT-03`. Deleting a route with riders is blocked. |
| **Students** | Full CRUD plus guardians, authorized receivers, stop assignment and absence marking. Deleting *retires* — the journey record survives (PART K13). |
| **Roster** | Per route and direction: set the everyday **default** crew, or a **stand-in** for one date that reverts the next day (PART O). |
| **Fleet** | Buses and staff with document expiry dates feeding the compliance board. A bus or staff member with trip history is retired, not deleted. |

Guardrails that are enforced, not just suggested: the last guardian cannot be
unlinked (no guardian means no notifications at all), a route with assigned
students cannot be deleted, and a school user gets a 403 on Zippi-only overrides.

## Not built yet

Deliberately out of this increment, in dependency order:

- **Trip generator** — `school:generate-trips` (M2) and `SchoolTripSolver` (M7).
  Trips are currently seeded rather than solved backward from the bell.
- **Route versioning on re-sequence** — the arrows reorder immediately; the
  effective-from-date + new-version + notify-guardians wrapper (PART G3) lands
  with the generator.
- **Zippi Parent** (mobile) and **Zippi Fleet** (driver/attendant mobile).
- Real Google Directions ETAs — `DriveTimeEstimator` carries over from the
  enterprise module unchanged.
- Live map is a schematic plot; production uses Google Maps + Firebase RTDB on
  `ID/{driver_id}`, the same plumbing as the enterprise module.

## Stack

Laravel 13 · PHP 8.5 · SQLite for local dev (schema is MySQL-compatible; switch
`DB_CONNECTION` in `.env` to point at the production MySQL). CSS is hand-written
in `public/css/zippi.css` — no build step, so `php artisan serve` is the only
thing you need to run.
