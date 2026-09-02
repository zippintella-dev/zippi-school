# Zippi Fleet

**Layer 3** of the school transport platform — the vehicle app, for attendants
and drivers. Flutter, built from the Claude Design canvas
`Zippi Fleet.dc.html` (project *Zippi Parent App Redesign*), against the brief
in [`design/zippi-fleet-design-brief.md`](../../design/zippi-fleet-design-brief.md).

It is the sibling of [`mobile/zippi_parent`](../zippi_parent) and shares its
design system deliberately: a family and a bus crew use both apps at the same
kerb at the same moment, and they have to look like one product.

```bash
# 1 · the server
cd ~/zippi-school && php artisan serve        # http://localhost:8000

# 2 · the app
cd ~/zippi-school/mobile/zippi_fleet
flutter pub get
flutter run                                   # 59 tests: flutter test

# a real handset on your Wi-Fi cannot reach 10.0.2.2
flutter run --dart-define=ZIPPI_API_BASE=http://192.168.0.246:8000

# no backend at all — the fifteen screens on in-memory data
flutter run --dart-define=ZIPPI_DEMO=true
```

## The links

This app is the **write** side of the platform. Until it existed, everything
the Parent app displayed was put there by hand or by the seeder, because
nothing marked a child boarded.

```
  Zippi Fleet  ──POST /api/fleet──▶  school_trips · school_trip_children
  (this app)                          school_stop_arrivals · school_child_handovers
                                      school_child_absences · school_trip_events
                                      school_incidents · buses
                                                │
                            ┌───────────────────┴───────────────────┐
                            ▼                                       ▼
             Layer 2 · the dashboard                  Layer 1 · Zippi Parent
             live board, Control Tower                 card, timeline, live map,
             (LiveBoardController)                     handover code
                                                       (FamilyCardBuilder)
```

⚠ **There is no sync step, and that is the whole design.** All three layers read
and write the *same rows*. Nothing polls a queue and nothing copies a record
between stores, because a second copy of a child's state re-opens the question
"which one is right about where this child is", and there is no acceptable
answer to it. An attendant's tap is on the ops live board and on the family's
card at their next poll.

`tests/Feature/FleetLinkTest.php` asserts the absence of that seam — a boarding
reaching the family card, a ping moving their map, an afternoon handover closing
*that* family's map and nobody else's, and the handover code the parent is shown
being the one the attendant's endpoint accepts.

### The API

`routes/api.php` · `app/Http/Controllers/Api/FleetApiController.php` ·
`app/Services/FleetTripService.php`

| Screen | Endpoint |
|---|---|
| 1 · sign in (OTP, PART P1–P4) | `POST /api/fleet/otp`, `/otp/verify` |
| 2 · role & vehicle | role links come back from `/otp/verify` |
| 3 · duty board | `GET /api/fleet/duties` |
| 4 · checklist, swipe to begin | `POST /trips/{trip}/checklist`, `/start` |
| 5 · stops | `POST /trips/{trip}/stops/{stop}/reach`, `/depart` |
| 6 · board · undo · not-at-stop | `POST`/`DELETE /trips/{trip}/children/{child}/…` |
| 7 · head count | `POST /trips/{trip}/head-count` |
| 8 · disembark at school | `POST /trips/{trip}/disembark` |
| 9 · afternoon lock-in | `POST /trips/{trip}/lock-in` |
| 10 · handover | `POST /trips/{trip}/children/{child}/handover` |
| 11 · escalation | `POST /…/escalate`, `/return-to-school` |
| 12 · sweep (multipart, geo-stamped) | `POST /trips/{trip}/sweep` |
| 13 · complete | `POST /trips/{trip}/complete` |
| 15 · SOS | `POST /trips/{trip}/sos` |
| the parents' live map | `POST /trips/{trip}/ping` |

⚠ **A token identifies a ROLE, not a person** (PART P6). `school_staff` is
UNIQUE(school_id, phone), so an attendant at one school and a driver at another
are two rows keyed by one number — and `/otp/verify` mints one token per row.
Picking a card on screen 2 is literally picking which token gets sent, which is
why `can_mark_children` cannot be spoofed: there is no role field in any request
for a client to claim.

⚠ **`token.holder` pins each API group to one kind of token.** Sanctum
authenticates whoever a bearer token belongs to and does not care which model
that is — so the moment this app started minting staff tokens, `/api/parent`
needed an explicit check, or an attendant's token would have authenticated
against endpoints that resolve a guardian's children. Both directions are
tested.

⚠ **`bell_tier` ships alongside the spec's `trip_leg`** at the boundary, and the
app reads `bell_tier`. Do not reintroduce the old name inside either app.

## The four invariants, and where they live

⚠ **THE SERVER IS THE AUTHORITY.** `app/Services/FleetTripService.php` re-decides
every rule from the database on every write, and its refusal is what the crew
sees. The same rules exist in `FleetStore` so a crew member is told *before* they
tap rather than after — but a phone can be old, offline, rooted, or running a
build from March, and the local copy is never a substitute.

Both raise the same `SafetyViolation`, whose `message` is rendered verbatim
(PART P7), so a refusal looks identical whichever decided it.

1. **A child is never released without a verified receiver.** Three ways out at
   a drop stop — handover code, authorized face, consented self-release — and no
   fourth. `recordHandover()` refuses self-release without consent;
   `departStop()` refuses while a child is unresolved; `markNotAtStop()` refuses
   on an afternoon trip entirely. If nobody can be verified the only remaining
   action is `returnToSchool()`, and that unlocks only when the escalation
   window has run out.
2. **A trip cannot complete while a child is unaccounted for.**
   `completeTrip()` throws and **names them** — "Cannot complete: Aarav Mehta is
   still on board" — and the screen offers a button straight to that child.
3. **The bus is physically swept before the trip closes.** `confirmSweep()`
   requires the school geo-fence and a camera photo; `completeTrip()` refuses
   while the sweep is pending.
4. **An emergency locks child records.** After `fireSos()` no boarding or
   handover is accepted until ops release it. A drill behaves identically.

Three test files cover this, and all three have to pass:

| File | Asserts |
|---|---|
| `test/safety_invariants_test.dart` | the app's local rules |
| `tests/Feature/FleetApiTest.php` | the **server** refuses, with the request shaped the way a tampered client would shape it |
| `test/fleet_api_test.dart` | the app parses the server's payloads and maps 422/423 to a refusal and 5xx to a transport failure — which are not the same event |

If a change makes one of those fail, the change is wrong.

## Screens

Fifteen, numbered as in the brief. `test/screen_smoke_test.dart` pumps each one
at 428 × 908 including its blocked, expired and refused states — the ones a
manual walkthrough never reaches.

| # | Screen | File |
|---|---|---|
| 1 · 1b | Login · OTP | `screens/login_screen.dart`, `verify_screen.dart` |
| 2 | Role & vehicle | `screens/role_screen.dart` |
| 3 | Duty dashboard (+ empty) | `screens/duty_screen.dart` |
| 4 | Pre-trip checklist, swipe to begin, already-running | `screens/checklist_screen.dart` |
| 5 | Trip flow — stop list | `screens/stop_list_screen.dart` |
| 6 | At stop — the child list | `screens/at_stop_screen.dart` |
| 7 | Head-count reconciliation | `screens/head_count_screen.dart` |
| 8 | At school — arrival | `screens/arrival_screen.dart` |
| 9 | Boarding at school (afternoon) | `screens/pm_boarding_screen.dart` |
| 10 | Drop stop — verified handover | `screens/drop_stop_screen.dart` |
| 11 | Escalation ladder | `screens/escalation_screen.dart` |
| 12 | Vehicle sweep | `screens/sweep_screen.dart` |
| 13 | Trip complete, and the refusal | `screens/complete_screen.dart` |
| 14 | Driver — navigation only | `screens/driver_screen.dart` |
| 15 | SOS, and the drill | `screens/sos_screen.dart` |

Plus the offline banner, empty and error states, and the
already-running-elsewhere state the brief also asks for.

## Design system

`lib/theme.dart` is a near-copy of the Parent app's, with two Fleet-only
additions: the red/danger ramp (SOS, blocked actions) and `Z.strip`, the dark
bar behind the driver's head count. Change a colour or a radius **on the canvas
first** so the two do not drift.

Sizing rules that are not negotiable, and are asserted or enforced in code:

- **56dp** minimum for anything that changes a child's state (`Z.tapSafety`).
- **44dp** minimum for everything else (`Z.tapMin`).
- **16px** minimum in any text input.
- Status is conveyed by **colour AND words**, never colour alone.

## Deliberate divergences from the canvas

- **Class groups on screen 9 sort youngest-first** rather than in roster order.
  UKG is dismissed first and is least able to find its own bus, so it belongs at
  the top of the screen rather than past the fold.
- **The head count is not pre-filled** with the app's own number. Confirming a
  number the app already knows does not catch a wrong-row mis-tap; counting
  heads does.
- **The escalation copy names the child** instead of using a pronoun.
- **No child photographs are bundled.** `TripChild.photoUrl` is null throughout
  the demo roster and the avatar falls back to a tinted initial. A repository is
  the wrong place for 22 children's faces — and the fallback is a real
  production path too, for a school that has not uploaded photos yet.

## Location

⚠ **The crew's device is the bus tracker** until a hardware unit exists, and
three separate safety behaviours read from it: the Parent app's live map, the
stop geo-fences, and Invariant #3's "you are not at the school gate" refusal on
the sweep.

`lib/services/location_service.dart` is deliberately willing to return **null**.
If there is no fix the app sends no coordinates and the server refuses the
sweep, which is correct — a sweep that cannot be placed is not evidence.
Substituting the school's own published coordinates, which are sitting right
there in `session.stops`, would make every geo-fence pass from anywhere and
quietly delete the check.

Permission is requested when a trip **starts**, not at the sweep: a crew member
who first meets the dialog while standing at the gate with a bus full of
children is a crew member who taps "deny" to make it go away.

## Not built

- **Google Maps.** The driver's map is a schematic plot of the authored stop
  sequence, as in the Parent app. Turn-by-turn hands off to Google Maps by URL,
  which is deliberate and survives the real map landing.
- **Push.** The 15 PART F1 notification types need FCM credentials. `/device-token`
  accepts and stores an FCM token; nothing sends to it yet.
- **SMS.** There is no gateway, so a live OTP is written to
  `storage/logs/laravel.log`. Read PART P2 before wiring one — both
  carried-forward bugs are silent.
- **An offline write queue.** Failed writes are counted and shown in the banner,
  not replayed. The app tells the truth about what has not reached the server;
  it does not yet get it there by itself.

## Debug affordances

Chips and menu items gated on `kDebugMode` let every state in the brief be
reached without driving a bus: the empty duty board, already-running-elsewhere,
the school-gate geo-fence, the SOS drill and ops release, the escalation clock,
and an offline simulation (crew menu, top right of the duty board). None of them
ship in a release build.
