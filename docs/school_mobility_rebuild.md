# Zippi School Mobility — Platform Spec (carbon copy of the Enterprise Ride module, retargeted to schools)

> **How to read this.** This document is the school-side twin of `enterprise_ride_rebuld.md`. Every part letter maps to the same part letter in the enterprise doc, so a developer who knows the enterprise module can diff the two and see exactly what changed. Where school reality forces a **different** rule (not just a renamed one), the section is marked **⚠ DIVERGES FROM ENTERPRISE** and explains why. Do not "fix" those back to the enterprise behavior — several of them are child-safety requirements, not preferences.

---

## ⚠ Critical Safety Invariants (read before writing any code)

These four rules outrank every other section of this document, including the L-series. If any change would violate one of them, the change is wrong.

1. **A child is never released without a verified receiver.** On the Drop direction, a child may only be handed over to (a) a guardian who presents a valid handover code, (b) a person on the child's authorized-pickup list identified by photo + code, or (c) self-release, and only when the school has recorded a signed self-release consent for that child. If none of these is satisfiable at the stop, the child **stays on the bus and is returned to school**. There is no "mark absent and drive on" at a drop stop. See PART A7.

2. **A trip cannot be completed while a child is unaccounted for.** `boarded_count` must equal `alighted_count + returned_to_school_count` before `/trip-complete` succeeds. Mismatch → 422 with the specific child ids. See PART L2.

3. **The bus is physically swept before the trip closes.** The attendant must confirm an end-of-trip empty-vehicle sweep — a timestamped, geo-stamped, photo-backed confirmation taken at the last stop. This is the control against a child being left asleep in a parked vehicle. It cannot be skipped, pre-tapped, or defaulted. See PART L3.

4. **A stuck ride is an alerting condition, not a silent state.** Every state a trip can get stuck in has (a) a Control Tower alert, (b) an admin override that resolves it, and (c) an append-only audit row recording who resolved it and why. See PART J.

## ⚠ Critical Regression Fix Carried Forward

The enterprise module carried a back-button regression: pressing back inside the driver's in-ride screen landed on "Start Ride" and let the driver create a duplicate ride for the same shift. The same three defenses apply here, with the vehicle-role split added:

1. **Back-stack cleanup (client)** — when the driver/attendant app POSTs `/start-trip` successfully, the schedule screen and any intermediate list screen are `finish()`ed, and the trip-flow activity launches with `FLAG_ACTIVITY_NEW_TASK | FLAG_ACTIVITY_CLEAR_TASK`.
2. **Back-press confirmation (client)** — `SchoolTripFlowActivity.onBackPressed` never calls `super` unconditionally. It branches: handover code entry open → close entry only; child selected → deselect; nothing selected → "End trip?" dialog. **Additional school rule**: if the sweep confirmation (invariant #3) has not been completed, the exit dialog is replaced with a hard block — "You cannot leave this screen until the vehicle sweep is confirmed."
3. **Server-side guard** — `/start-trip` rejects when a `school_trips` row with `status='started'` already exists for this (vehicle, service_date, direction, trip_leg). Returns 409 with a status-aware payload the app branches on (see PART L12).

All three must be implemented and tested before rollout. PART I and verification tests #46–56 enforce this.

---

## Context

The chain this platform connects is five parties deep, not three:

```
School  →  Transport operator  →  Driver / Attendant  →  Parent  →  Child
```

Zippi is the layer that connects all five.

```
                         School
                            │
                            ▼
                  ┌──────────────────────┐
                  │  Zippi Control Tower │
                  └──────────────────────┘
                            │
            ┌───────────────┼───────────────┐
            ▼               ▼               ▼
        Buses          Drivers /        Routes &
                       Attendants        Stops
                            │
                            ▼
                        Children
                            │
                            ▼
                        Parents
```

**The structural differences from the enterprise module** — these drive most of the design decisions below:

| # | Enterprise | School | Consequence |
|---|---|---|---|
| 1 | The rider is the app user | The rider (child) is **not** the app user — the parent is | Every "rider-facing" API is actually guardian-facing. A child has 0 logins and N guardians. |
| 2 | One vehicle actor (driver) | Two vehicle actors (**driver** + **attendant/ayah**) | Role split: driver drives and navigates; attendant does all child marking. Two app modes, one codebase. |
| 3 | Employee boards with an OTP they hold | Child cannot hold a credential | OTP moves from Pickup to **Drop**, and belongs to the **guardian**, not the rider. |
| 4 | Employee not at pickup → mark no-show, drive on | Child not at drop → **cannot** drive on | The drop-side terminal state is an escalation ladder ending in "returned to school", never "no-show". |
| 5 | One trip per driver per direction per day | One bus runs 2–3 **tiered** trips per direction (senior 7:10, middle 7:50, primary 8:20) | `trip_leg` is a first-class scheduling dimension; PART M is mandatory, not an add-on. |
| 6 | Nearest-neighbor route order | **Fixed, published stop sequence** parents plan their morning around | PART G inverts: sequence is authored and frozen, not solved per trip. |
| 7 | Holiday calendar deferred | School calendar (holidays, exams, half-days, PTMs) is the operating rhythm | PART C3 — the school calendar is a required table, not "out of scope". |
| 8 | Attendance is HR's problem | Boarding **is** the attendance record the school relies on | PART D — the child-level journey record is the product, not a report. |
| 9 | 1 employee = 1 company | 1 parent = N children, possibly on N buses at N bell times | The parent app is a **family** dashboard, not a rider dashboard. |
| 10 | Compliance = data retention | Compliance = CMVR school-bus rules, speed governor, female attendant, police verification, CCTV | PART K expands into a regulatory checklist with document expiry tracking. |

**Questions this plan answers** (the school analogs of the enterprise question list):

1. How does a parent mark their child absent for a day (or a week)?
2. What if they mark absent after the bus has already started?
3. What if the child isn't at the stop in the morning?
4. What if nobody is at the stop in the afternoon?
5. How does the school see every bus, every child, in real time?
6. How do tiered bell times / split sessions work?
7. Should the child journey record be one big table or many?
8. What if a child rides the bus in the morning but is picked up by a parent in the afternoon (asymmetric)?
9. What happens on a field trip / exam day / early dismissal?
10. Who is allowed to receive the child, and how do we prove it?

---

## The four product layers

Everything below belongs to exactly one of these. When adding a feature, name its layer first.

**1. Zippi Parent** — child journey + notifications.
Family dashboard (N children), live bus map, boarding/alighting confirmations, handover code, absence marking, stop-approach alerts, journey history, SOS acknowledgement.

**2. Zippi School** — transport administration.
Students, routes, stops, bus assignment, guardian/authorized-pickup management, daily roster, school calendar, transport fee ledger, attendance export, incident register, parent communications.

**3. Zippi Fleet** — bus / driver / attendant operations.
Vehicle master, document expiry (fitness, permit, insurance, PUC, speed governor cert), driver + attendant onboarding and police verification, duty roster, trip execution app, maintenance and fuel/charge logs.

**4. Zippi Control Tower** — central monitoring, safety and analytics.
Live multi-school map, exception queue (deviation, overspeed, delay, unaccounted child, SOS), admin overrides, audit log, on-time performance, utilization, ESG/sustainability reporting.

---

## Phase plan

### Phase 1 — Phoenix Greens (pilot school #1)

Deliberately scoped. **5–10 buses / 200–500 children.** Do not try to solve everything.

Ship exactly this:

| # | Capability | Part |
|---|---|---|
| 🚌 | Live bus tracking | B |
| 👧 | Child boarding confirmation | F2 |
| 🏫 | School arrival confirmation | F4 |
| 🏠 | Drop-off confirmation (with guardian handover) | A7, F5 |
| 📱 | Parent notifications | F |
| 🗺️ | Route & stop management | E, G |
| 👨‍✈️ | Driver / attendant app | H1, H2 |
| 🚨 | SOS / emergency | Q |
| 📊 | School transport dashboard | B, D |
| ⚠️ | Route deviation / unexpected-stop alerts | R |

The **child-level journey record** (PART D) is the killer feature. Everything else is table stakes; that is the thing a school cannot get anywhere else and cannot stop paying for once they have it.

Phoenix Greens is **Pilot School #1**, not the end customer. Nothing hard-codes its name, its bell times, its grade structure, or its route labels. Every such value lives in `schools`, `school_calendars`, `school_bell_times`, or `routes`.

### Phase 2 — Make it school-agnostic

Once Phoenix Greens runs clean for a full term, strip anything specific to it. A new school must be able to self-serve:

```
Create school → upload students → create routes & stops → add buses
   → assign drivers & attendants → invite parents → go live
```

That onboarding funnel is the SaaS product. Target: a mid-size school live in under one working day, with the student CSV being the only data-entry effort. See PART S1 for the onboarding wizard spec.

### Phase 3 — Two revenue models

Do not restrict Zippi to schools that own buses.

| Model | Zippi provides | School provides | Billing |
|---|---|---|---|
| **Software-only** | Technology + tracking + parent app + Control Tower | Buses, drivers, attendants, fuel, maintenance, compliance | Per child per month, or per bus per month |
| **Managed mobility** | Software + buses + drivers + attendants + operations + compliance | Nothing but the student list and the bell schedule | Per child per term, or per route per month |

This is powerful precisely because **Zippi already has the EV fleet and the employee-transport operating capability**. The managed-mobility pitch is not aspirational — it is the existing business pointed at a new customer:

> "We don't just track your buses. We can operate your entire student transportation system."

### The EV angle

The existing EV fleet is the long-term differentiation. Position as:

**Safe + Smart + Sustainable School Transportation**

What the school gets:

- EV school transport (quiet, zero tailpipe emission at the school gate — a real air-quality argument at a place where 800 children stand every morning)
- Lower operating cost per km, passed through or retained by model
- Centralized fleet management
- Parent visibility
- Child-level safety
- Driver / attendant management with compliance tracking
- Route optimization
- Transport analytics
- **ESG / sustainability reporting** — CO₂e avoided per term, per route, per child; a board-level artifact the principal can publish (PART S3)

---

## Current State — what carries over from the enterprise module

Reusable as-is or with a rename. Do not rebuild these.

- **Firebase Realtime DB live location** — `ID/{driver_id}`, 5-second writes. Same plumbing, same JS SDK subscription on the admin side, same `ValueEventListener` on the parent app.
- **Sanctum auth + OTP login** — `otps` table with `attempts` / `locked_until` (PART P3/P4 of the enterprise doc). Parent login reuses it verbatim on mobile number.
- **FCM notification classes + LocalBroadcast bridge** — `MyFirebaseMessagingService` → LocalBroadcast → activity receiver. Every school notification type slots into the same pipeline.
- **`DriveTimeEstimator`** (enterprise M15) — Google Directions, traffic-aware, 15-min-bucket cached. Reused unchanged for stop ETAs and the "leave depot now" solve.
- **Schedule solver skeleton** (enterprise M7) — the backward/forward per-stop time solve. Retargeted in PART M7: schools solve **backward from the bell**, and the stop *order* is fixed rather than nearest-neighbor.
- **Append-only audit log pattern** (enterprise J4/K1) — same INSERT-only grant, same model-level `save()` guard.
- **Idempotency middleware + throttle buckets** (enterprise K2/K5).
- **Geo-fence with request-body lat/lng** (enterprise L3) — the fix where the action endpoint accepts `latitude`/`longitude` and persists them before the haversine check. Carry it forward; the same stale-GPS symptom will otherwise reappear.
- **Bottom-sheet + map unified dashboard** (enterprise L29) — the parent app's live-trip screen is the same pattern: full-bleed map, sheet peeking with the status pill, pinned vehicle card.

Explicitly **not** carried over: nearest-neighbor stop ordering (PART G), pickup-side OTP (PART A7), and "mark no-show and drive on" at a drop stop (invariant #1).

---

# PART A — Absence, Leave & Non-Boarding Flow

The enterprise "Apply Leave" flow, retargeted. Parent acts on behalf of a child; a child has two independent legs per school day per trip leg.

### A1. "Mark Absent" replaces "Apply Leave"; defaults to tomorrow

The parent app's primary action on a child card is **"Mark absent / not travelling"**, defaulting to the **next school day** (not "tomorrow" — the calendar in PART C3 resolves the next actual school day, skipping holidays and weekends).

Wording matters here because parents read it under stress at 6:50 AM. Copy:

- Button: **"Not travelling today?"** when today's morning trip hasn't started; **"Mark absent"** otherwise.
- Dialog title: **"Which trips is [Child] skipping?"**
- Checkboxes: **☐ Morning — home to school**  /  **☐ Afternoon — school to home**
- Helper: "Tick only the afternoon if someone is collecting [Child] from school today."

**Files**: parent app `ChildCard`, `dialog_mark_absent.xml`, `FamilyDashboardActivity.kt`. No backend change for A1 itself.

### A2. Block when the trip is ongoing OR past the per-direction cutoff

For each requested (service_date, direction, trip_leg), apply both rules:

**Rule A — Ongoing block.** If a `school_trips` row with `status='started'` contains this child in `child_ids`, matching `service_date` + `direction` + `trip_leg` → reject. (Exception: see A2a — the drop-side "someone is collecting them" case, which is allowed mid-trip.)

**Rule B — Cutoff**, different per direction:

- **Morning (home → school)**: time-based cutoff = **the child's stop `scheduled_at` − 20 minutes**. Not the bell time — the *stop* time. A parent on the last stop of a 45-minute route has a legitimately later cutoff than the first stop, and telling both "cancel before 6 AM" is wrong. If `now() ≥ stop.scheduled_at − 20 min` → reject with a message naming the actual deadline: *"Absences for the morning trip close at 7:12 AM (20 minutes before [Child]'s stop)."*

  Rationale for 20 min rather than the enterprise's 120: the driver is not building a bespoke route around each home. The stop is fixed and the bus passes it regardless. 20 minutes is only enough lead to stop the attendant from waiting and to keep the head count honest.

- **Afternoon (school → home)**: **state-based** cutoff, exactly like the enterprise Drop. Allowed until ANY of these becomes true for this (child, service_date, direction, trip_leg):
  1. The attendant has marked the child **boarded** at school.
  2. The child has already been **alighted** or **returned to school**.
  3. The trip is complete for this child.

  First action wins. The parent can mark absent any time before the attendant acts. If the attendant marks the child boarded first, the absence button disappears from the parent's app (push-driven) and the parent sees:

  > "[Child] has already boarded the afternoon bus. If plans changed, call the school office on [number] — the bus cannot be re-routed from the app."

  ⚠ **DIVERGES FROM ENTERPRISE**: there is no attendant-initiated "no-show" that closes the afternoon window. An unclaimed child is never a no-show (invariant #1). The window closes on **boarded**, not on a timer.

Each (date, direction, trip_leg) is checked independently. Morning can be allowed while afternoon is blocked, and vice versa.

**Files**: `SchoolTripController.php` (`markChildAbsent`), `config/school.php` (`morning_absence_cutoff_minutes => 20`, `stop_wait_seconds => 120`, `drop_escalation_*` — see A7).

### A2a. Mid-trip afternoon release ("I'm collecting them from school")

⚠ **DIVERGES FROM ENTERPRISE** — no analog exists there.

A parent frequently decides at 2 PM to collect the child themselves. Between the bell and the bus boarding, the child is in the school's custody, not Zippi's. The app must be able to tell the attendant *before* the child boards.

- Parent taps **"I'm collecting [Child] today"** on the afternoon leg.
- Backend writes an absence row with `reason='parent_collecting'` and pushes **both** the attendant (child removed from today's boarding list, row turns grey with "Parent collecting") **and** the school's front-office user (so the gate staff know to expect the parent).
- Allowed until the child is marked boarded. After boarding, the button is replaced with the "call the office" copy from A2.
- The school can require a **release approval**: config flag `require_office_approval_for_parent_collection`. When true, the absence row starts as `pending_approval` and the child stays on the boarding list until an office user approves. Phoenix Greens: off by default; turn on per school.

### A3. Date-range absence + asymmetric directions

Dialog has two direction checkboxes (at least one required) plus From/To date pickers. Backend accepts:

```json
{
  "child_id": 4411,
  "start_date": "2026-08-24",
  "end_date":   "2026-08-28",
  "directions": ["Morning", "Afternoon"],
  "reason":     "Family travel",
  "reason_code": "travel"
}
```

Backend expands to (school_day × direction) pairs — **skipping non-school days from `school_calendars`**, which is the school-specific twist. A Mon–Fri range that contains a holiday creates 4 rows, not 5, and the confirmation copy says so: *"Absence recorded for 4 school days (25 Aug is a holiday)."*

Rules A + B are applied to each pair independently, all rows inserted in one transaction. Range capped at **60 days** (schools legitimately have month-long absences; the enterprise 30-day cap is too tight). If any pair fails, reject the whole request and list which pairs failed and why.

`reason_code` is an enum the school configures — `sick | travel | exam | activity | parent_collecting | other`. It feeds the attendance export in PART D4, which is the artifact the school actually wants.

**Asymmetric example**: parent ticks Morning only for 24 Aug → one row. The 24 Aug afternoon trip still runs for that child — they came to school by car and go home by bus. Extremely common; must not require two separate actions.

**Files**: `SchoolTripController.php`, parent app `dialog_mark_absent.xml`, `FamilyDashboardActivity.kt`, `ApiInterface.kt`.

### A4. Undo absence (per direction) + parent-visible state banners

Undo accepts the same `directions` array, is subject to the same Rules A + B, and only works when `marked_by='parent'`.

The parent app shows a different banner per `marked_by`:

| `marked_by` | Banner | Text | Undo? |
|---|---|---|---|
| `parent` | Yellow | "Absent: Morning + Afternoon, 24–28 Aug" | Yes (subject to A + B) |
| `parent_collecting` | Blue | "You're collecting [Child] from school today." | Yes, until boarding |
| `school` | Grey | "Marked absent by school: [reason]" | No — school must reverse |
| `attendant_not_at_stop` | Amber | "[Child] was not at the stop this morning. The bus waited 2 minutes." | No |
| `admin` | Grey | "Cancelled by Zippi ops: [reason]" | No |
| `holiday` | Green | "No school on 25 Aug." | N/A — auto-generated, not a row the parent created |

⚠ Note there is **no red "no-show" banner on the afternoon leg**. That state cannot exist (invariant #1).

**Files**: `SchoolTripController.php` (`undoAbsence`), `SchoolController.php` (`getFamilyDashboard` returns `upcoming_absences[]` grouped by consecutive runs), `app/Notifications/SchoolChildAbsenceUndoneNotification.php`, parent app models + dashboard.

### A5. Auto-prompt trip end when every child is cleared

After absences/marks, if every child on the trip is absent/alighted/returned with none pending → attendant app prompts **"All children accounted for. End this trip?"** → calls `/trip-complete`. Attendant app only.

⚠ The prompt is gated on the sweep confirmation (invariant #3). "All cleared" never bypasses the sweep.

### A6. Morning — child not at the stop

At the child's stop on a morning trip:

1. Attendant taps **"At [Stop name]"** → geo-fence check (150 m) → arrival timestamp set → the stop's children list expands with a per-child ✓ / ✗.
2. A **server-anchored `stop_wait_seconds` countdown** (default **120 s**, per-school configurable) starts, anchored to `stop_arrivals.arrived_at`.
3. Parents of children at this stop who are not yet marked boarded get a push at T-60s: *"The bus is at [Stop] and leaves in about a minute."*
4. After the countdown, the ✗ **"Not at stop"** action unlocks per child.
5. Backend `markNotAtStop` enforces the minimum wait server-side (client timers are advisory only), writes an absence row with `marked_by='attendant_not_at_stop'`, and pushes the parent.

⚠ **DIVERGES FROM ENTERPRISE**: 120 seconds, not 6 minutes. A school bus with 40 children on board cannot wait 6 minutes per stop. The wait window is a per-school config because rural routes and gated-community routes have genuinely different norms.

**Escalation**: if a child is marked not-at-stop **twice in a rolling 5 school days**, the Control Tower raises a Low alert and the school gets a digest line. Repeated non-boarding usually means the family quietly switched to private transport and is still being billed — a revenue-recovery signal, not just an ops signal.

### A7. Afternoon — the guardian handover ladder ⚠ THE CORE SCHOOL FEATURE

This replaces the enterprise Drop no-show entirely. **There is no path through this section that ends with a child left at a stop.**

**At the drop stop, the attendant must resolve each child into exactly one of four terminal states:**

| State | How it's reached | Recorded |
|---|---|---|
| `alighted_to_guardian` | Guardian presents a valid **handover code**, or attendant selects the guardian's face from the authorized-pickup list and confirms | `receiver_guardian_id`, `verification_method`, timestamp, geo, optional photo |
| `alighted_self_release` | Child's `self_release_consent = true` and grade ≥ school's `self_release_min_grade` | timestamp, geo |
| `returned_to_school` | Escalation ladder exhausted (below) | full escalation trail |
| `absent` | Never boarded (marked earlier, in A2/A3) | absence row |

**The handover code.** Each child has a rotating 4-digit code visible in the parent app to every linked guardian, rotating daily at 00:05 and on demand. The guardian at the stop shows it; the attendant types it. Verified server-side against `children.handover_code_hash` with the same 5-attempt lockout as the enterprise OTP (PART K3).

Offline resilience: the attendant app caches the day's code hashes for its own manifest at trip start, so a code verifies inside a network dead zone. The verification event syncs when connectivity returns, flagged `verified_offline=true`.

**The authorized-pickup list.** Each child has N authorized receivers, each with name, relationship, phone, and photo, maintained by the school (parents can *request* additions; only school staff approve). At the stop the attendant can select a face instead of typing a code — used when the regular grandmother collects every day and typing a code four times a week is friction that gets worked around.

**The escalation ladder** — runs when nobody at the stop can be verified. Timings in `config/school.php`:

```
T+0:00   Attendant taps "No one here for [Child]"
         → push to ALL linked guardians: "The bus is at [Stop]. Nobody is here
            for [Child]. The bus waits until HH:MM."
T+0:00   Attendant app starts drop_wait_seconds countdown (default 180 s)
T+1:30   Auto-call guardian 1 (in-app dialer prompt on the attendant device;
         the number is masked via the call proxy — attendants never see it)
T+2:30   Auto-call guardian 2
T+3:00   Countdown expires → "Return to school" becomes the ONLY remaining
         action for this child. There is no "leave child" action, at any
         point, for any role, in any build.
         → Control Tower High alert raised
         → School front-office user pushed + called
         → All guardians pushed: "[Child] is returning to school with the
            bus. Please collect from the school office. Contact: [number]."
On trip end at school:
         → Attendant hands child to the front office; office user confirms
            receipt in the school app, which closes the incident.
         → Incident row written to school_incidents with the full trail.
```

The bus does **not** hold the entire route hostage for one stop: after `drop_wait_seconds`, it proceeds with the child on board. The child rides the remainder of the route and returns to school — which is why the "returned_to_school" state must reconcile against the head count at trip end (invariant #2), not at the stop.

**Files**:
- `app/Http/Controllers/Api/SchoolTripController.php` — `verifyHandoverCode`, `alightToAuthorizedPerson`, `alightSelfRelease`, `beginDropEscalation`, `returnChildToSchool`.
- `app/Notifications/` — `SchoolGuardianAtStopNotification`, `SchoolNoGuardianAtStopNotification`, `SchoolChildReturningToSchoolNotification`, `SchoolChildHandedToOfficeNotification`.
- `app/Models/SchoolChildHandover.php`, `SchoolIncident.php`.
- `database/migrations/…_create_school_child_handovers.php`, `…_create_school_incidents.php`.
- Attendant app: `SchoolTripFlowActivity.kt` (drop mode), `ChildStopAdapter.kt`, `dialog_handover_code.xml`, `dialog_authorized_person_picker.xml`, `view_escalation_banner.xml`.

### A8. Mixed-stop reality

One stop typically has 3–8 children from 2–5 families. The attendant screen groups by **stop**, then lists children, then shows per-child state. Never a flat child list — the attendant's mental unit is the stop, and a flat list causes mis-taps between siblings with the same surname.

Sibling disambiguation: when two children at the same stop share a surname, the row shows **grade + section + photo**, not just the name. Cheap change, prevents the highest-frequency attendant error observed in this category of product.

---

# PART B — Control Tower & School Live Tracking

### B1. School-first selection

- **Page 1** `/admin/school/live` — list of schools (cards with "N buses running · M children on board" badges). Click → page 2.
- **Page 2** `/admin/school/live/{school_id}` — live board scoped to that school.
- **School-facing view** `/school/live` — the same page 2, scoped by the logged-in school user's `school_id`, with admin override buttons hidden. One controller, two authorization gates. Do not fork the view.

### B2. Live board (page 2)

- Header: school name + back + direction toggle (All / Morning / Afternoon) + **trip-leg toggle** (All / Senior / Middle / Primary — populated from the school's configured legs).
- Left/top: Google Map with bus markers, route polylines, and stop pins. Bus marker label = route code (`RT-03`), colored by status (on time / late / deviated / SOS).
- Right/bottom: scrollable trip cards — route code, bus number, driver + attendant names, direction, started_at, **"18 of 22 on board"**, next stop + ETA, delay vs schedule.
- Click card → trip detail drawer: ordered stop list with actual vs scheduled times, per-child status chips, handover records, and the admin override buttons (PART J).

**The exception queue is the primary UI, not the map.** Above the cards sits a persistent, sorted **Needs attention** strip. An ops person watching 40 buses cannot watch a map; they work a queue. Feeds from PART J3 + PART R.

### B3. Realtime strategy

- Bus locations → Firebase JS SDK, 5-second cadence (same as enterprise `LocationService`).
- Trip and child state → poll `/admin/api/school/{id}/live-trips` every **8 s**, diff-render.
- New boarding / alighting / escalation / deviation / SOS events since last poll → corner toast, and for High severity an audible alert the ops user must acknowledge.
- SOS bypasses the poll: a dedicated Firebase node `SOS/{school_id}` is written by the driver app and subscribed by the board, so an SOS surfaces in under a second rather than up to eight (PART Q).

### B4. JSON shape (live trips for a school)

```json
{
  "trips": [
    {
      "trip_id": 9142,
      "route": {"id": 12, "code": "RT-03", "name": "Kondapur – Gachibowli"},
      "direction": "Morning",
      "trip_leg": "Primary",
      "service_date": "2026-08-24",
      "started_at": "2026-08-24T07:04:00+05:30",
      "bus": {"id": 31, "reg_no": "TS09UB1234", "capacity": 42, "is_ev": true,
              "latitude": "17.4512", "longitude": "78.3812", "speed_kmph": 34,
              "last_ping_at": "2026-08-24T07:31:52+05:30"},
      "driver":    {"id": 7,  "name": "Suresh",  "phone_masked": "•••••4321"},
      "attendant": {"id": 19, "name": "Lakshmi", "phone_masked": "•••••8890"},
      "progress": {"on_board": 18, "expected": 22, "boarded": 18,
                   "absent": 3, "not_at_stop": 1, "alighted": 0,
                   "returned_to_school": 0},
      "schedule": {"next_stop_id": 88, "next_stop_name": "Silver Oak Gate",
                   "next_stop_eta": "2026-08-24T07:36:00+05:30",
                   "scheduled_at": "2026-08-24T07:34:00+05:30",
                   "delay_minutes": 2, "bell_time": "08:15",
                   "projected_arrival": "2026-08-24T08:09:00+05:30",
                   "will_be_late": false},
      "stops": [
        {"stop_id": 86, "name": "Phoenix Gate 2", "seq": 1,
         "scheduled_at": "07:12", "arrived_at": "07:11", "departed_at": "07:13",
         "children": [
           {"child_id": 4411, "name": "Aarav S.", "grade": "3-B",
            "status": "boarded", "boarded_at": "07:12:20"},
           {"child_id": 4498, "name": "Diya M.",  "grade": "5-A",
            "status": "absent", "marked_by": "parent"}
         ]},
        {"stop_id": 88, "name": "Silver Oak Gate", "seq": 2,
         "scheduled_at": "07:34", "arrived_at": null, "departed_at": null,
         "children": [ … ]}
      ],
      "flags": {"deviation": false, "overspeed": false, "sos": false,
                "unaccounted_child": false, "sweep_pending": false}
    }
  ]
}
```

**Privacy note**: guardian phone numbers are **masked** in this payload. Ops reaches a guardian through the call proxy (PART K9), never by reading a number off a dashboard. The unmasked number exists only in the school's own student record view, behind its own permission.

### B5. Files (PART B)

- `app/Http/Controllers/Admin/SchoolLiveTrackingController.php` — `schoolPicker()`, `livePage($schoolId)`, `liveTripsJson($schoolId)`, `tripDetailJson($id)`.
- `resources/views/admin/school/live-picker.blade.php`, `live.blade.php`.
- `resources/views/school/live.blade.php` — thin wrapper, same partials, school-scoped.
- `components/admin/sidebar.blade.php` + `components/school/sidebar.blade.php`.
- `routes/admin.php`, `routes/school.php`.
- `public/assets/admin/js/school-live.js`, `school-live.css`.

---

# PART C — Service Date, Bell Times & the School Calendar

### C1. Concept: `service_date` + `trip_leg`

The enterprise `shift_date` becomes `service_date`, and gains a second dimension.

- **`service_date`** — the school day a trip belongs to. Morning and afternoon of the same school day share it. There is no night-shift roll-over case (a school day never crosses midnight), which makes the school model *simpler* than enterprise here. Keep the column name and the discipline anyway — half-day and exam-day variants still key off it.
- **`trip_leg`** — which tiered run this is. A single bus commonly does three morning runs: `Senior` (bell 07:40), `Middle` (bell 08:15), `Primary` (bell 08:45). Each is an independent `school_trips` row with its own children, its own stop times, its own completion.

Every absence row, trip row, and journey record is keyed by **(child, service_date, direction, trip_leg)**. All four. Getting this wrong is the school analog of the enterprise `shift_date` bug in L1 — it will present as "I marked her absent and the bus still waited."

### C2. Schema

```
schools
  id, name, code, address, latitude, longitude, timezone,
  gate_geofence_radius_m (default 200),
  self_release_min_grade (nullable int),
  require_office_approval_for_parent_collection (bool, default false),
  stop_wait_seconds (default 120), drop_wait_seconds (default 180),
  contact_phone, logo_path, status

school_bell_times
  id, school_id, trip_leg, grade_from, grade_to,
  start_time TIME, end_time TIME,
  effective_from DATE, effective_to DATE NULL
  -- grade ranges let one row cover "Grades 1–5, primary leg"

school_calendars
  id, school_id, date DATE, day_type ENUM('school','holiday','half_day',
      'exam','event','vacation'),
  label, morning_trips_run BOOL, afternoon_trips_run BOOL,
  override_end_time TIME NULL,     -- half-day dismissal
  UNIQUE(school_id, date)
```

- `date` and `service_date` columns are **raw `Y-m-d` strings on the model — no Eloquent `date` cast**. This is the enterprise L1 rule carried forward verbatim; re-adding the cast reintroduces the IST→UTC off-by-one-day bug where an absence marked for 24 Aug is stored as 23 Aug and the undo predicate then matches nothing. Datetime columns (`started_at`, `arrived_at`, `boarded_at`) keep their `datetime` casts. Only DATE columns are bare strings.
- Both models carry an explicit comment warning against re-adding the cast.

**Migrations**: `…_create_schools_table.php`, `…_create_school_bell_times_table.php`, `…_create_school_calendars_table.php`.

### C3. The calendar is load-bearing ⚠ DIVERGES FROM ENTERPRISE

The enterprise doc lists "Holiday calendar auto-leave" under Out of Scope. **For schools it is in scope for Phase 1**, because a school's operating rhythm is the calendar. Consequences:

- The nightly schedule generator (PART M2) reads `school_calendars` and generates **nothing** for `holiday` and `vacation` days.
- `half_day` generates the morning trips normally and the afternoon trips against `override_end_time`, with all stop times re-solved backward from the earlier dismissal.
- `exam` days often run senior legs only — modelled as `morning_trips_run` / `afternoon_trips_run` per row plus a leg filter.
- The parent app's "next school day" resolver, the absence date picker, and the range expander (A3) all consult the calendar. A parent cannot mark a child absent on a holiday, and a range that spans one silently skips it with visible confirmation copy.
- Calendar upload: CSV (`date, day_type, label, morning_trips_run, afternoon_trips_run, override_end_time`) plus a month-grid editor. Most schools have the whole year in a PDF in July; the CSV importer is what turns that into the system of record in ten minutes.

**Late-breaking closures** (rain, strike, bandh, air quality) use the **bulk closure** admin action from PART J2 #7, which writes calendar rows *and* cancels already-generated trips *and* fans out a parent notification, in one transaction.

### C4. Backend logic

- `startTrip` computes `service_date = today` (school timezone) and takes `trip_leg` from the trip row.
- `markChildAbsent` accepts `start_service_date` + `end_service_date` + `directions[]` and expands against the calendar (C3).
- `getRouteForVehicle` and `startTrip` filter absences by `(service_date, direction, trip_leg)`.
- Rule A queries `school_trips` by all four keys.
- The morning cutoff (A2 Rule B) reads the child's own `stop.scheduled_at` from the trip's `route_schedule`, falling back to `bell_time − default_route_minutes` when no solved schedule exists yet.

**Cutoff matrix — applies to every trip:**

| Direction | Day type | Cutoff type | Example | When absence-marking closes |
|---|---|---|---|---|
| Morning | School | Time: `stop.scheduled_at − 20 min` | stop at 07:34 | **07:14** |
| Afternoon | School | State: until attendant marks boarded | bell 15:30, boarding 15:35–15:50 | whenever the attendant ticks the child |
| Morning | Half day | Same as school day | unchanged — dismissal moves, not arrival | **07:14** |
| Afternoon | Half day | State-based, but boarding starts earlier | dismissal 12:00 | earlier, follows actual boarding |
| Either | Holiday / vacation | N/A | — | no trip exists |

The time anchor is the **stop's solved `scheduled_at`**, not the bell time and not the bus's depot departure. A parent must be able to read their own deadline off their own child's card.

### C5. Attendant / driver app

- Schedule screen lists **today's trips for this vehicle**, in order, each with direction + leg + bell + child count. Tiered legs are the normal case, so the list is the primary UI, not a date picker.
- All app→API calls send `service_date` and `trip_leg` explicitly. No inference from `now()` on the client.

### C6. Control Tower

Trip cards group by `service_date`, then by `trip_leg` within direction. The leg name is displayed prominently — "RT-03 · Morning · Primary" — because "RT-03 is late" is meaningless when RT-03 runs three times before 9 AM.

### C7. Files (PART C)

- Migrations for `schools`, `school_bell_times`, `school_calendars`.
- `app/Models/School.php`, `SchoolBellTime.php`, `SchoolCalendar.php` (⚠ no date casts).
- `app/Services/SchoolCalendarService.php` — `isSchoolDay`, `nextSchoolDay`, `schoolDaysBetween`, `dayTypeFor`, `effectiveEndTime`.
- `app/Http/Controllers/Api/SchoolTripController.php`, `SchoolController.php`.
- `app/Http/Controllers/Admin/SchoolCalendarController.php` + `resources/views/admin/school/calendar/index.blade.php` (month grid + CSV upload).
- Parent app + attendant app: `service_date` / `trip_leg` params throughout.

---

# PART D — The Child Journey Record (the killer feature)

### D1. Decision: no new "history" tables — derive from operational tables

Same call as the enterprise module, same reasoning. `school_trips`, `school_trip_children`, `school_child_absences`, `school_stop_arrivals`, and `school_child_handovers` already hold everything and join cleanly by school / route / bus / child / service_date. A parallel history table would duplicate and drift.

Revisit when: 10M+ rows (years out at pilot scale), a statutory audit requirement lands, or pre-computed term aggregates are needed for slow reports.

### D2. What "child-level journey record" means concretely

For any child, on any school day, the system can answer — with timestamps, coordinates, and the identity of the person who acted:

```
Aarav S.  ·  Grade 3-B  ·  Mon 24 Aug 2026  ·  RT-03 · Primary

MORNING
  07:04:12  Bus TS09UB1234 started trip (driver Suresh, attendant Lakshmi)
  07:11:40  Bus arrived at Phoenix Gate 2                17.4499, 78.3781
  07:12:20  BOARDED — confirmed by attendant Lakshmi     17.4499, 78.3781
  07:12:20  Parent notified (delivered 07:12:23)
  08:06:55  Bus arrived at school gate                   17.4620, 78.3390
  08:07:40  ARRIVED AT SCHOOL — attendant confirmed disembark
  08:09:10  Vehicle sweep confirmed by Lakshmi (photo)   17.4620, 78.3390

AFTERNOON
  15:38:05  BOARDED at school                            17.4620, 78.3390
  16:22:30  Bus arrived at Phoenix Gate 2
  16:23:11  HANDED OVER to Meera S. (mother)
            method: handover_code · code verified on 1st attempt
            photo captured · 17.4499, 78.3781
  16:23:14  Parent notified (delivered 16:23:16)

Total on-bus time: 106 min   ·   Deviation events: 0   ·   Max speed: 46 km/h
```

That record is why a school signs. It is the answer to "where was my child at 4:15", to an insurance query, to a police query, and to a parent complaint — produced in seconds instead of by phoning a driver.

**Requirements it imposes on the write path:**
- Every state transition writes actor identity (`acted_by_type`, `acted_by_id`), coordinates, and server time. Client time is stored separately as `client_reported_at` and never trusted for ordering.
- Nothing in this record is mutable. Corrections are new rows with `supersedes_id`, plus an audit entry. The timeline renders both, with the superseded row struck through and the reason shown.
- Retention: **7 years** by default (schools are asked to state a policy; most have none and accept the default). Configurable per school; enforced by a scheduled purge job that anonymizes rather than deletes, preserving audit integrity (PART K13).

### D3. Indexes

**Migration**: `…_add_journey_indexes.php`

```sql
CREATE INDEX idx_trips_school_service   ON school_trips(school_id, service_date, trip_leg);
CREATE INDEX idx_trips_route_service    ON school_trips(route_id, service_date);
CREATE INDEX idx_trips_bus_service      ON school_trips(bus_id, service_date);
CREATE INDEX idx_trip_children_child    ON school_trip_children(child_id, created_at);
CREATE INDEX idx_trip_children_trip     ON school_trip_children(trip_id, child_id);
CREATE INDEX idx_absences_child_date    ON school_child_absences(child_id, service_date);
CREATE INDEX idx_absences_child_dir     ON school_child_absences(child_id, direction, service_date);
CREATE INDEX idx_handovers_child        ON school_child_handovers(child_id, created_at);
CREATE INDEX idx_stop_arrivals_trip     ON school_stop_arrivals(trip_id, sequence);
```

### D4. Admin / school pages

- `/school/trips` — paginated. Filters: route, bus, driver, attendant, direction, leg, status, service_date range.
- `/school/trips/{id}` — full detail: ordered stops with scheduled vs actual, per-child chips, handover records with method and receiver, deviation events, speed profile, sweep confirmation with photo, audit entries inline.
- `/school/children/{id}/journey` — **the parent-facing record above, in staff form.** Date picker, day-by-day timeline, export to PDF.
- `/school/routes/{id}/performance` — on-time rate, average delay per stop, boarding rate.
- `/school/drivers/{id}/record` and `/school/attendants/{id}/record` — trips run, incidents, overspeed events, document expiry status, parent ratings.
- `/school/reports/attendance` — **the export the school actually wants**: per-class, per-day boarding matrix with absence reason codes, downloadable as CSV/XLSX, filterable by term. This is the artifact that gets the transport office out of a spreadsheet.

### D5. Parent-facing journey history

The parent app's per-child **Journey** tab shows the last 90 days, one card per school day, expandable to the full timeline above. Add:
- A **"Share"** action producing a read-only, time-limited link for a single day's record (useful when a parent needs to show a grandparent or forward to the school).
- A monthly summary: days travelled, days absent, on-time rate, average arrival time.

### D6. Files (PART D)

- `…_add_journey_indexes.php`.
- `app/Http/Controllers/School/SchoolTripHistoryController.php` — `index`, `show`, `childJourney`, `routePerformance`, `staffRecord`, `attendanceReport`, `exportAttendance`.
- `app/Http/Controllers/Api/SchoolChildJourneyController.php` — parent-facing `journey(child_id, from, to)`, `shareDay(child_id, date)`.
- `resources/views/school/trips/{index,show}.blade.php`, `children/journey.blade.php`, `routes/performance.blade.php`, `reports/attendance.blade.php`.
- Parent app: `ChildJourneyActivity.kt`, `JourneyDayAdapter.kt`, `item_journey_event.xml`.

---

# PART E — Admin Panel Clarity (School / Stop / Home semantics)

### E1. The data model, stated once

- `schools.latitude/longitude` = the **school gate** (the drop-off/pick-up point inside or at the campus, not the postal centroid of the campus). Destination of morning trips, origin of afternoon trips.
- `route_stops.latitude/longitude` = a **published stop**. Children are assigned to a stop, not to their house. This is the single biggest model difference from enterprise, where each employee had their own home coordinate.
- `children.home_address` = record only. Used for the school's records, the fee zone calculation, and nothing in the routing path.
- `child_stop_assignments` = `(child_id, route_id, stop_id, direction, effective_from, effective_to)` — because a child can legitimately have a different morning stop and afternoon stop (dropped at the grandmother's in the evening), and can change stops mid-term.

**No employee-style per-rider coordinate.** If a school insists on door-to-door for a few children (common for pre-primary), model it as a **stop with one child**, not as a per-child coordinate. That keeps one routing path.

### E2. Vocabulary — use these words everywhere

| Term | Definition |
|---|---|
| **School gate** | The school's pickup/drop point. Morning destination, afternoon origin. |
| **Stop** | A published point on a route where children board and alight. Has a name parents recognise ("Silver Oak Gate", not "Lat 17.44"). |
| **Route** | An ordered sequence of stops, with a code (`RT-03`) and a name. |
| **Trip** | One execution of a route on a given service_date, in one direction, for one leg. |
| **Trip leg** | Which tiered run — Senior / Middle / Primary, per the school's bell times. |
| **Service date** | The school day a trip belongs to. |
| **Guardian** | An adult linked to a child with app access. |
| **Authorized receiver** | Someone permitted to collect the child at a stop. Every guardian is one; not every authorized receiver is a guardian (the driver's usual grandmother may have no app). |
| **Handover** | The verified release of a child to an authorized receiver at a stop. |
| **Sweep** | The end-of-trip empty-vehicle check. |

Use them in the admin panel, the school panel, both apps, every export, and every notification body. Inconsistent vocabulary between the school panel and the parent app generates support calls at a rate that dwarfs its apparent triviality.

### E3. Admin form changes

**School form** — rename the lat/lng group to **"School Gate Location"**, building icon, helper: *"This is where buses pick up and drop children on campus. Drop the pin at the actual gate, not the centre of the campus — arrival confirmation uses a [radius] m geo-fence around this point."* Add the gate radius field beside it.

**Route form** — the stop builder is the main event:
- Ordered, drag-reorderable stop list with map preview and the solved cumulative time per stop.
- Each stop: name, landmark, lat/lng (Google Places autocomplete, `componentRestrictions: {country: "in"}`, same pattern as the enterprise employee form), dwell seconds override.
- Direction: routes may be **shared** (afternoon = morning reversed, the default) or **independent** (checkbox reveals a second stop list). Independent afternoon routes are common where one-way streets or school dismissal traffic force a different path.

**Student form**:
- **"Stop assignment"** group — route + morning stop + afternoon stop (with "same as morning" checked by default), effective dates.
- **"Guardians"** repeater — name, relationship, phone, email, app access toggle, "primary contact" radio, notification preferences.
- **"Authorized receivers"** repeater — name, relationship, phone, **photo upload** (required), active toggle.
- **"Self-release consent"** — checkbox + the grade gate, with helper: *"Only available for Grade [self_release_min_grade] and above. When enabled, [Child] may leave the bus at their stop without an adult present. The school must hold a signed consent form."* Disabled and explained when the child's grade is below the gate.
- **"Medical & emergency"** — allergies, conditions, emergency contact, blood group. Visible to the attendant on the trip screen behind a deliberate tap (not on the default list — it is sensitive and must not be shoulder-surfable).

**Bus form** — reg no, capacity, `is_ev`, model, **document expiry set** (fitness, permit, insurance, PUC, speed governor certificate), GPS device id, camera present, first-aid and extinguisher check dates. Expiry dates feed the compliance dashboard (PART K15).

**Driver / attendant form** — licence number and expiry, **years of heavy-vehicle experience**, police verification status + date + document, medical fitness date, training completion, photo. The attendant record additionally carries the gender field where a state mandates a female attendant (PART K15).

### E4. "How school trips work" reference page

A static help page at `/school/help/how-it-works`, linked by a `?` icon from every relevant form, covering:

```
  Morning (home → school):
  🏠 Stop 1  →  🏠 Stop 2  →  🏠 Stop 3  →  🏫 SCHOOL
  (fixed published sequence — same every day)

  Afternoon (school → home):
  🏫 SCHOOL  →  🏠 Stop 3  →  🏠 Stop 2  →  🏠 Stop 1
  (reverse of morning by default; can be authored independently)
```

Plus: the tiered-leg diagram, the absence cutoff rules, the handover ladder (A7) as a flowchart, the sweep requirement, what parents see and when, and the PART I navigation matrix as a QA checklist. This becomes the single document the school's transport coordinator and Zippi's support team both work from.

### E5. Files (PART E)

- `resources/views/admin/school/{schools,routes,children,buses,staff}/_form.blade.php`.
- `resources/views/school/help/how-it-works.blade.php`.
- `public/assets/admin/img/school-trip-diagram.svg`.
- `components/admin/sidebar.blade.php`, `components/school/sidebar.blade.php`, `routes/admin.php`, `routes/school.php`.

No backend or DB change for E3/E4 beyond the fields listed in E3.

---

# PART F — Parent Notifications

The enterprise "Driver on the way" mechanism, retargeted. The unit of notification is **(child, event)**, fanned out to **all linked guardians** with app access whose preferences allow that event type.

### F1. The notification set

| # | Event | Trigger | Direction | Default |
|---|---|---|---|---|
| 1 | Trip started | Attendant taps Begin Trip | Both | On |
| 2 | **Bus approaching your stop** | Bus enters `approach_radius_m` (default 1200 m) of the stop **or** live ETA ≤ `approach_eta_minutes` (default 5), whichever first | Both | On |
| 3 | Bus at your stop | Attendant taps "At [Stop]" (geo-fenced) | Both | On |
| 4 | **Child boarded** | Attendant marks boarded | Both | On, non-mutable |
| 5 | Child not at stop | Attendant marks not-at-stop after the wait | Morning | On, non-mutable |
| 6 | **Arrived at school** | Attendant confirms disembark at gate | Morning | On, non-mutable |
| 7 | Left school | Trip started + all boarded | Afternoon | On |
| 8 | **Child handed over** | Handover verified | Afternoon | On, non-mutable |
| 9 | Nobody at stop | Escalation begins | Afternoon | On, non-mutable |
| 10 | Returning to school | Escalation exhausted | Afternoon | On, non-mutable |
| 11 | Bus delayed | Projected arrival slips > `delay_alert_minutes` (default 10) past bell | Both | On |
| 12 | Route deviation | PART R | Both | Off for parents (Control Tower + school only) |
| 13 | SOS | PART Q | Both | On, non-mutable |
| 14 | Absence recorded / undone | Parent's own action, or school's | — | On |
| 15 | Trip cancelled (closure) | PART J #7 | Both | On, non-mutable |

**Non-mutable** means the parent cannot switch it off. Safety events and custody events are not preferences.

⚠ **DIVERGES FROM ENTERPRISE F2/F3**: there is no "one rider at a time" commit tap and no per-rider en-route push, because a school bus does not route around individuals — it runs a fixed sequence. The enterprise's "driver committed to you" concept is replaced by **stop proximity** (#2), which is computed server-side and fires for every child at the upcoming stop simultaneously. This is both simpler and more correct for the school case.

### F2. Boarding confirmation (the Phase-1 headline)

Morning, at a stop:
1. Attendant taps the stop → geo-fence check (150 m) → stop opens with its children.
2. Attendant taps each child as they board. Row goes green, count increments in the header.
3. Backend `markBoarded` writes `school_trip_children` (`status='boarded'`, `boarded_at`, `boarded_lat/lng`, `acted_by_attendant_id`) and fires `SchoolChildBoardedNotification` to that child's guardians only.
4. Push copy: **"Aarav boarded the bus at Phoenix Gate 2 at 7:12 AM."** Tapping opens the child's live map.
5. Undo window: 90 seconds, attendant-side, for mis-taps. After that, correction requires the school (PART J #5) so the record stays trustworthy.

**Mis-tap defence.** The single most common attendant error is tapping the adjacent row. Mitigations: 56 dp minimum row height, photo on every row, sibling disambiguation (A8), a 90-second undo, and a **head-count reconciliation prompt** — after the last stop, the app asks "You marked 18 children boarded. Count the children on the bus." and the attendant enters a number. Mismatch → the app lists the boarded children for a visual recheck before allowing the trip to proceed. Cheap, and it catches the error class that invariant #2 exists to prevent.

### F3. Approach alerts

Computed server-side in the live-trip poll, not by the client:

- **Distance trigger**: haversine bus → next stop ≤ `approach_radius_m`.
- **ETA trigger**: `DriveTimeEstimator` (Google Directions, traffic-aware, cached — enterprise M15) bus → next stop ≤ `approach_eta_minutes`.
- Fires **once per (trip, stop)**, guarded by `school_stop_arrivals.approach_notified_at`. Repeated crossings from traffic circling do not re-fire.
- Push copy: **"The bus is about 4 minutes from Silver Oak Gate."** Plus the live ETA in the parent app card.

Parents plan their morning around this. Getting it wrong in the pessimistic direction (parent waits 10 minutes in the sun with a five-year-old) is the fastest way to lose a school. Use `best_guess` traffic model, never `pessimistic` — the enterprise M15 gotcha applies identically, and after changing `SCHOOL_TRAFFIC_MODEL` you must `php artisan cache:clear` to drop stale cached legs.

### F4. Arrival at school

When the bus enters the school gate geo-fence and the attendant confirms disembark:
- `school_trips.arrived_at_school_at` stamped.
- Every boarded child transitions to `arrived_at_school`.
- Fan-out `SchoolChildArrivedAtSchoolNotification`: **"Aarav reached school at 8:07 AM."**
- The school's front-office dashboard tile increments — this is the school's own attendance signal, delivered before the class register is taken.

### F5. Drop-off confirmation

Fires on handover (A7), per child, with the receiver named:
**"Aarav was handed over to Meera (mother) at Phoenix Gate 2, 4:23 PM."**

Self-release variant: **"Aarav got off at Phoenix Gate 2 at 4:23 PM."**
Return-to-school variant: see A7 ladder copy.

### F6. Delivery guarantees

- Push is **best-effort**. The parent app polls `/family-dashboard` every 10 s while foregrounded and **fetches immediately in `onResume`** — the enterprise L14 rule, carried forward verbatim, for the same reason (a push arriving while the app is backgrounded is dropped by `LocalBroadcastManager` with no buffering, and scheduling the first poll 10 s out means the UI is stale exactly when the parent opens it).
- For events 4, 6, 8, 9, 10, 13 (the safety and custody set), if FCM reports non-delivery and the guardian has `sms_fallback=true`, send an SMS after 60 s. Schools ask for this and it is worth the cost on this subset only.
- PII rule: push **titles** are generic-safe, bodies carry first name + stop name + time, never the handover code, never an address, never a phone number (PART K9).

### F7. Files (PART F)

- `app/Notifications/School*` — one class per event, all extending a shared `SchoolParentNotification` base that handles the guardian fan-out, preference filtering, and the SMS fallback decision.
- `app/Services/SchoolNotificationDispatcher.php` — resolves child → guardians → tokens → preferences → dispatch. Single fan-out point; no controller sends to a guardian directly.
- `app/Http/Controllers/Api/SchoolTripController.php` — `markBoarded`, `markNotAtStop`, `arriveAtStop`, `confirmSchoolArrival`, handover methods (A7).
- Parent app: `MyFirebaseMessagingService.kt` (one handler per `notification_type`), `FamilyDashboardActivity.kt`, `ChildLiveActivity.kt`, banner layouts.

---

# PART G — Fixed Stop Sequence (replaces nearest-neighbor)

### G1. ⚠ DIVERGES FROM ENTERPRISE — and this one is important

The enterprise module computes nearest-neighbor order from the driver's start position on every ride. **Schools must not do this.** The stop sequence is a published contract: parents stand at a stop at a time they were told, term after term. A route whose order changes because the bus started from a different corner is unusable.

**Rule**: `route_stops.sequence` is authored by the school in the route builder, stored, and frozen. Trip execution renders stops in that order. The solver (M7) computes *times*, never *order*.

Where optimization belongs:
- **Route design time.** The route builder offers a "Suggest an order" button that runs the optimizer and proposes a re-sequence, showing the time saved. A human accepts or rejects it, and accepting it re-publishes the route with new stop times and notifies affected parents (PART G3).
- **Never at trip time.**

### G2. Deviation from sequence is an exception, not a feature

If the bus visits stops out of the authored order, that is a PART R deviation event, surfaced to the Control Tower. Legitimate causes exist (a road closed) and the driver can log a reason from a short list; the event is still recorded.

### G3. Re-sequencing a live route

Changing a published route's stop order or times mid-term:
1. Admin edits in the route builder → preview shows old vs new time per stop, and which children are affected.
2. Requires an **effective-from date** (minimum next school day, default 3 school days out).
3. On save: new `route_versions` row; trips generated for dates ≥ effective_from use the new version; already-generated trips before it are untouched.
4. Fan-out `SchoolRouteChangedNotification` to affected guardians: **"From Mon 1 Sep, the bus will reach Silver Oak Gate at 7:29 AM instead of 7:34 AM."**

Versioning the route (rather than mutating it) is what keeps the journey record in PART D historically accurate. A record from March must render against March's stop times.

### G4. "NEXT STOP" indicator

The attendant app shows a **NEXT** pill on the first unvisited stop, and the map centres on it. Same visual affordance as the enterprise NEAREST pill, different semantics — it follows the authored sequence, not distance.

### G5. Files (PART G)

- `…_create_routes_table.php`, `…_create_route_stops_table.php`, `…_create_route_versions_table.php`.
- `app/Http/Controllers/Admin/SchoolRouteController.php` — CRUD, `suggestOrder`, `publishVersion`.
- `app/Services/RouteOptimizer.php` — design-time only, explicitly not called from any trip-execution path (enforced by a test).
- `resources/views/admin/school/routes/builder.blade.php` + `public/assets/admin/js/route-builder.js`.
- Attendant app: `StopAdapter.kt` (NEXT pill), `SchoolTripFlowActivity.kt`.

---

# PART H — Screen Flow Diagrams

### H1. Attendant app — morning trip, end to end

```
[Splash] → [Login (mobile OTP)] → [Duty Dashboard]
                                        │
                                        ▼
                              [Today's Trips]
                               RT-03 · Morning · Primary · 07:04 · 22 children
                               RT-03 · Morning · Middle  · 07:50 · 18 children
                               RT-03 · Afternoon · Primary · 15:35
                                        │
                        Swipe-to-confirm "Begin trip"
                                        │  POST /start-trip → trip_id
                                        ▼
                              [Pre-trip checklist]
                               ☐ Vehicle clean & seats intact
                               ☐ First aid box present
                               ☐ Fire extinguisher present
                               ☐ Emergency exit clear
                               (blocking — cannot proceed until all ticked;
                                each tick timestamped into the trip record)
                                        │
                                        ▼
                              [Trip Flow — stop list]  ◄───────────────┐
                              ┌────────────────────────────────────┐   │
                              │ Header: "18 of 22 on board"         │   │
                              │ NEXT pill on first unvisited stop   │   │
                              │ Stops in authored sequence          │   │
                              │ Nav icon → Google Maps to next stop │   │
                              │ SOS button always visible (PART Q)  │   │
                              └────────────────────────────────────┘   │
                                        │                                │
              Tap next stop ────────────┤                                │
                                        ▼                                │
                              [At Stop — geo-fenced]                     │
                               POST /stop-arrive (150 m fence)           │
                               → parents at this stop pushed             │
                               → stop_wait countdown starts (120 s)      │
                                        │                                │
              Tap each child as they board                               │
                               POST /child-board (per child)             │
                               → that child's guardians pushed           │
                                        │                                │
              After countdown: ✗ "Not at stop" unlocks per child         │
                               POST /child-not-at-stop                   │
                                        │                                │
              Tap "Depart stop" ────────┤ POST /stop-depart              │
                                        ▼                                │
                              [Next stop] ────────────────────────────► │
                                                                         │
              After last stop → drive to school                          │
                                        ▼                                │
                              [Head-count reconciliation]                │
                               "You marked 18 boarded. Count the bus."   │
                               mismatch → list for visual recheck        │
                                        ▼                                │
                              [At School — geo-fenced to gate]           │
                               POST /school-arrive                       │
                               → all boarded children → arrived_at_school│
                               → all guardians pushed                    │
                                        ▼                                │
                              [⚠ VEHICLE SWEEP — blocking]               │
                               "Walk to the back of the bus.             │
                                Confirm every seat is empty."            │
                               Photo capture + geo + timestamp           │
                               Cannot be skipped. Cannot be pre-tapped.  │
                                        ▼                                │
                              [Trip Complete]                            │
                               POST /trip-complete                       │
                               (422 if boarded ≠ alighted + returned)    │
                                        ▼                                │
                              [Today's Trips — next leg]                 │
```

### H2. Attendant app — afternoon trip (differences)

```
... Today's Trips → Begin trip → Pre-trip checklist ...
                                        │
                                        ▼
                              [Boarding at school]
                              ┌────────────────────────────────────┐
                              │ 🏫 BOARDING AT SCHOOL               │
                              │ Children grouped by class           │
                              │ ☐ NOT BOARDED / ✓ BOARDED per child │
                              │ Absent children greyed with reason  │
                              │ "Parent collecting" rows in blue    │
                              │ Countdown to scheduled departure    │
                              │ [Lock in & depart] (red)            │
                              └────────────────────────────────────┘
                                        │
              Lock-in enabled when EITHER every child is boarded/absent
              OR the departure time is reached.
              Un-boarded children are marked absent (marked_by='attendant_
              not_boarded_at_school') and the SCHOOL OFFICE is notified —
              because an expected child who did not board is the school's
              problem to resolve before the bus leaves.
                                        │
                                        ▼
                              [Per-stop drop flow]
                              ┌──────────────────────────────────────────┐
                              │ Stops in authored sequence (reverse)      │
                              │ Tap stop → geo-fence → children list      │
                              │                                            │
                              │ Per child, ONE of:                         │
                              │  • [Verify handover code]  → keypad        │
                              │  • [Pick authorized person] → photo grid   │
                              │  • [Self-release]  (only if consented)     │
                              │  • [No one here]   → escalation ladder A7  │
                              │                                            │
                              │ There is NO "skip child" action.           │
                              └──────────────────────────────────────────┘
                                        │
              Escalation exhausted for a child →
              child stays on bus, status = returning_to_school,
              High alert to Control Tower + school office
                                        │
                                        ▼
                              [Return leg to school] (only if any child returning)
                                        ▼
                              [Hand to office] — office user confirms receipt
                                        ▼
                              [⚠ VEHICLE SWEEP — blocking]
                                        ▼
                              [Trip Complete]
```

### H3. Driver app — the same trip, driver role

```
[Login] → [Duty Dashboard] → [Today's Trips]
                                   │
              Swipe "Begin trip" (either role can begin; the trip is
              shared state, both devices see the same trip)
                                   ▼
                        [Navigation-first screen]
                        ┌────────────────────────────────────┐
                        │ Big map, next stop, turn-by-turn    │
                        │ handoff to Google Maps              │
                        │ "18 on board · next: Silver Oak"    │
                        │ Speed readout — turns red over the  │
                        │   school-bus limit (PART R3)         │
                        │ SOS button                          │
                        │ NO child marking controls           │
                        └────────────────────────────────────┘
```

⚠ **Role split**: the driver device shows navigation, head count, and SOS. It **cannot** mark children. Child marking exists only on the attendant device. Rationale: a driver marking children is a driver looking at a phone with children boarding around the vehicle. Where a school runs without an attendant (some smaller schools do, where legally permitted), the school must explicitly enable `allow_driver_child_marking` per bus, which surfaces a banner in the Control Tower and is reported in the compliance dashboard.

### H4. Parent app — end to end

```
[Login (mobile OTP)] → [Family Dashboard]
                              │
                    ┌─────────┴─────────┐
                    ▼                   ▼
            [Child card: Aarav]  [Child card: Ishaan]
             RT-03 · Primary       RT-07 · Senior
             Status pill           Status pill
                    │
              Tap child
                    ▼
            [Child Live]
            ┌────────────────────────────────────────┐
            │ Full-bleed map: bus marker + my stop   │
            │ Bottom sheet:                           │
            │  • Status pill ("Boarded 7:12 · on bus")│
            │  • ETA to my stop / to school           │
            │  • HANDOVER CODE (afternoon only,       │
            │    large, tappable to enlarge)          │
            │  • Attendant + driver row, masked call  │
            │  • Today's timeline (collapsible)       │
            │  • [Mark absent] [Call school]          │
            └────────────────────────────────────────┘
                    │
            Tap Journey tab
                    ▼
            [Journey history — 90 days, per-day timeline, share a day]
```

The live map is visible **only while a trip containing this child is active and the child has not reached a terminal state for that leg** — same `showLiveMap` discipline as enterprise L29:

```kotlin
val terminalForMe = me_status in listOf("absent", "not_at_stop",
                                        "alighted", "returned_to_school")
val showLiveMap   = has_active_trip && !terminalForMe &&
                    (isMorning || me_status != "alighted")
```

Morning: the map stays until the trip reaches school (the child is on board and the parent wants to see them arrive). Afternoon: the map closes for this family the moment their child is handed over, independent of the rest of the route.

### H5. Control Tower flow

```
[Ops login] → [Control Tower]
                    │
                    ▼
          [Needs attention queue]  ← the default landing view
           SOS · unaccounted child · deviation · overspeed ·
           delayed past bell · sweep pending · stuck state
                    │
          Click item → [Trip detail drawer]
                    │         ├─ stop timeline (scheduled vs actual)
                    │         ├─ child chips + handover records
                    │         ├─ speed / deviation trace
                    │         └─ override actions (PART J)
                    ▼
          [Live map]  (secondary tab, all schools or one)
```

Save these diagrams into `/school/help/how-it-works` so support and the school's transport coordinator work from the same picture.

---

# PART J — Admin Overrides & Emergency Controls

Real situations the flows cannot resolve alone. Every one needs a control, and every control is logged.

### J1. Edge cases to cover

| # | Situation | Without a control | With the override |
|---|---|---|---|
| 1 | Attendant phone dies mid-trip | Trip stuck in `started` forever | Admin force-completes with reason; guardians and school notified. **Head-count reconciliation is recorded as "unverified"** and an incident row is raised — a force-complete never silently satisfies invariant #2 |
| 2 | Attendant absent entirely | Bus can't legally run | Admin assigns a relief attendant; if none, trip cancelled and all guardians notified before the bus is due |
| 3 | Handover code won't verify (parent's phone dead) | Child can't be released | Admin verifies on behalf after a voice call — records `verification_method='admin_voice'` + the ops user's identity |
| 4 | Push didn't reach a guardian | Guardian doesn't know | Admin resends; SMS fallback forced |
| 5 | GPS stale, geo-fence blocks stop arrival | Attendant stuck | Admin grants a 10-minute geo-fence override, heavily logged |
| 6 | Unlisted holiday / sudden closure | Buses dispatched into an empty school | **Bulk closure** — writes calendar rows, cancels trips, fans out to every guardian, in one transaction |
| 7 | Driver or attendant calls in sick | Route stranded | Admin reassigns driver / attendant for the day (PART O one-day override) |
| 8 | Bus breakdown mid-route | Children stranded on a road | **Bus swap** — admin assigns a relief bus; the trip's remaining stops transfer; guardians pushed with the new ETA; the old bus's trip closes as `transferred` with the child list intact |
| 9 | Parent disputes a handover ("nobody collected her") | No record | Journey record shows method, receiver, code attempt count, photo, coordinates |
| 10 | Accident / medical emergency | No safe stop mechanism | Emergency stop (PART Q) — urgent push to driver, attendant, all guardians on board, school, ops |
| 11 | Child on the wrong bus | Nobody notices until a parent calls | Boarding a child not on this trip's manifest is **blocked** at the tap with "Aarav is on RT-07 today" — and if the attendant overrides with a reason, a High alert fires immediately |
| 12 | Trip stuck with sweep pending | Attendant went home | Admin force-completes; sweep recorded as **not performed**; incident raised; attendant's record flagged |
| 13 | Absence marked for wrong dates, past cutoff | Parent can't undo | Admin force-deletes the absence row |
| 14 | Child needs a one-day different stop | No mechanism | Admin (or school) sets a one-day stop override for (child, date, direction) — see PART O |

### J2. The override actions

Each is a button on the appropriate page (Control Tower drawer, trip detail, child record). All require an authenticated admin/school user with the right permission, all write an audit row, and the high-impact ones require non-empty `notes` (422 otherwise).

1. **Force-complete trip** — reason required. Sets `status='completed'`, `completed_by_admin_id`, records that reconciliation and/or sweep were not verified, raises an incident if either is unverified. Notifies driver, attendant, school.
2. **Cancel trip** — every pending child marked `cancelled_by='admin'`, all guardians pushed.
3. **Verify handover on behalf** — modal asks the ops user to confirm they spoke to the guardian and what they verified. Records `verification_method='admin_voice'`, the ops user id, and the notes. Pushes the attendant device so its UI clears (the enterprise L11 rule — every override that changes per-rider state must push the vehicle device immediately, not wait for a poll).
4. **Resend / rotate handover code** — resend pushes the existing code; rotate issues a new one and invalidates the old (used when a parent believes the code was seen by someone else).
5. **Mark absent (school/admin)** — inserts an absence row with `marked_by='school'|'admin'`.
6. **One-day stop override** — (child, service_date, direction) → different stop on the same route, or a different route entirely. Re-solves the affected trips. Pushes the attendants of both trips.
7. **Bulk closure** — pick school + date(s) + which directions. Writes `school_calendars` rows, cancels generated trips, fans out to all guardians and staff. One transaction.
8. **Override geo-fence** — 10 minutes or one successful call, whichever first. Auto-revokes. Heavily logged.
9. **Reassign driver / attendant** — for today, or as a new default (PART O).
10. **Swap bus mid-trip** — transfers remaining stops and the on-board child list to a relief bus's new trip, links via `parent_trip_id`, notifies everyone. The original trip closes `status='transferred'` and its journey rows stay intact.
11. **Emergency stop** — PART Q.
12. **Clear stuck state** — clears a wedged `current_stop_id` / boarding lock after 5 minutes of API silence.
13. **Force-delete absence row** — no validation, fully logged.
14. **Close an incident** — an ops or school user records the resolution narrative; the incident stays in the register forever.

### J3. The exception queue (PART B2's "Needs attention")

| Condition | Severity |
|---|---|
| SOS raised | **Critical** |
| Child unaccounted at trip completion attempt | **Critical** |
| Drop escalation reached "returning to school" | **Critical** |
| Bus stationary > 10 min mid-route with children on board | High |
| GPS stale > 5 min during an active trip | High |
| Route deviation > `deviation_threshold_m` sustained > 90 s | High |
| Overspeed above the school-bus limit sustained > 30 s | High |
| Projected school arrival > 10 min past bell | High |
| Trip completed with sweep unverified | High |
| Child boarded who is not on this trip's manifest | High |
| Stop skipped (departed without arriving) | Medium |
| Child in `arrived at stop` > 10 min with no resolution | Medium |
| Attendant head-count mismatch unresolved | Medium |
| Document expiring within 15 days (bus / driver / attendant) | Medium |
| Child not-at-stop ≥ 2 times in 5 school days | Low |
| Boarding rate < 70% on a route for a week | Low (commercial signal) |

Each row links to the trip drawer where the matching override lives. **Critical rows require explicit acknowledgement by a named ops user**, recorded with a timestamp — an alert nobody acknowledged is the failure mode this whole section exists to prevent.

### J4. Audit log (append-only)

**Migration**: `…_create_school_admin_audit_log.php`

```
id              BIGINT PK
actor_type      VARCHAR(24)   -- admin | school_user | system
actor_id        BIGINT
action          VARCHAR(64)   -- force_complete_trip, override_handover, …
school_id       FK
trip_id         FK nullable
child_id        FK nullable
staff_id        FK nullable
payload         JSON          -- old/new state, geo values, code attempt counts
notes           TEXT          -- required for high-impact actions
ip, user_agent
created_at      TIMESTAMP
```

Append-only, three ways (enterprise K1 carried forward):
- DB grant: INSERT only on this table for the app role.
- No admin UI to edit or delete rows.
- Model override on `save()` rejecting updates with `LogicException` when `!wasRecentlyCreated`.

Audit entries render inline in the trip timeline and the child journey record, so a school reading a record sees *"Ops (R. Nair) verified handover on behalf at 16:31 — 'Spoke to Meera S., confirmed she was at the stop, phone battery dead'"* in sequence with everything else.

### J5. Files (PART J)

- `app/Http/Controllers/Admin/SchoolOverrideController.php`.
- `app/Notifications/School{ForceComplete,TripCancelled,BusSwapped,EmergencyStop,ClosureAnnounced}Notification.php`.
- `…_create_school_admin_audit_log.php`, `…_create_school_incidents.php`, `…_add_override_columns_to_school_trips.php` (`completed_by_admin_id`, `geofence_override_until`, `parent_trip_id`, `sweep_verified`, `headcount_verified`).
- `app/Models/SchoolAdminAuditLog.php` (append-only guard), `SchoolIncident.php`.
- `resources/views/admin/school/live.blade.php` (drawer actions), `closures.blade.php`, `incidents/{index,show}.blade.php`.
- Attendant + driver app: handle `School_Force_Completed`, `School_Trip_Cancelled`, `School_Bus_Swapped`, `School_Emergency_Stop` — finish the flow activity, show an explanatory dialog, stop location streaming.
- Parent app: same push types → friendly dialog + banner.

### J6. Authorization

Three roles, three scopes:
- **Zippi admin** — all schools, all actions.
- **School user** — own school only; may mark absent, close days, view records, request overrides. Cannot force-complete a trip or override a geo-fence (those affect Zippi's operational record).
- **Transport operator user** (managed-mobility and third-party-operator cases) — own buses and staff, no child records beyond the manifest for the current trip.

High-impact actions require `notes`. Optional 2FA on emergency stop and bulk closure — deferred past Phase 1 but reserve the hook.

---

# PART K — Security, Child Safety & Compliance

Honest review of what remains risky, each with the mitigation that ships.

### K1. Audit log must be append-only
As enterprise K1: DB grant, no edit UI, model-level guard. A school incident inquiry is worthless if the trail is editable by the person being asked about.

### K2. Rate limiting
- `/handover-verify`: 5 attempts per (child, trip) per 10 min; then the child's handover **locks** and requires an admin override (J2 #3). A locked handover is never a reason to leave a child — the escalation ladder (A7) still applies.
- `/child-absent`: 40 per guardian per hour (a parent with four children legitimately fires several).
- `/child-board`, `/stop-arrive`: 300 per attendant per hour — generous, because a 40-stop route with 60 children is a lot of taps and a throttled attendant is a safety problem. The enterprise BF3 lesson applies: **do not throttle an action the operator must be able to repeat under pressure**; rely on the idempotency middleware for duplicate-suppression instead.
- 429 responses always carry `retry-after` and a human-readable message the app renders verbatim.

### K3. Handover-code brute force
4 digits = 10,000 combinations. After 5 wrong attempts the handover locks (K2) and a **Medium alert** fires to the Control Tower. Every wrong attempt is audit-logged with the attendant id — repeated lockouts by one attendant across different children is a pattern worth a conversation.

Codes rotate daily and on demand. A code is scoped to (child, service_date) — yesterday's code never verifies.

### K4. Time zone handling
- `APP_TIMEZONE` pinned; per-school `timezone` column for future multi-region.
- All date math via `Carbon::today($school->timezone)`, never `date('Y-m-d')`.
- Verification test: at 23:40 IST, mark a child absent for "the next school day" and confirm the stored `service_date` is the IST next school day, not the UTC rollover date.
- DATE columns stay bare strings (C2 / enterprise L1).

### K5. Idempotency
Every action endpoint accepts an `Idempotency-Key` header; `(endpoint, actor, key)` → cached response for 10 minutes, Redis-backed where available. Covers the double-tap and the retry-on-flaky-network case that a bus in a basement parking level generates constantly.

### K6. Offline operation ⚠ DIVERGES FROM ENTERPRISE
Schools routinely have routes through areas with no usable data. The attendant app must function offline for the duration of a trip:
- At trip start, the app downloads the manifest, the stop list, the handover code **hashes**, and the authorized-receiver photos.
- Boarding, alighting, handover verification, and the sweep all work offline, queued locally with monotonic sequence numbers and client timestamps.
- On reconnect, the queue replays; the server stores `client_reported_at` alongside its own `created_at` and flags the rows `synced_offline=true`.
- Parent notifications for offline-marked events fire on sync, with the body naming the actual event time: *"Aarav boarded at 7:12 AM (received late — the bus was out of network)."* Silently pushing "boarded" at 7:41 for a 7:12 event destroys parent trust in the timestamp.
- The Control Tower shows a bus with a stale ping as `offline` with the last-known position and time, never as "stopped".

### K7. Location integrity
- Reasonableness check on location writes: reject a position more than `MAX_TELEPORT_KM` (10) from the previous position within 30 s.
- Log anomalies (implausible speed, position jumps) into `school_location_anomalies` for periodic review.
- Reserve `client_attestation` for Play Integrity later.

### K8. Backward compatibility
Every new response field optional on the client. `min_app_version` checked at login; below it, a blocking "Please update" screen. For the **attendant app specifically**, an out-of-date version is a safety issue (it may lack the sweep step), so the gate is hard, not advisory.

### K9. PII in notifications and dashboards
- Push bodies: child first name, stop name, time. Never the handover code, never an address, never a phone number.
- The handover code is delivered **in-app only** — never in a push body, never over SMS. A code on a lock screen is a code anyone holding the phone can read.
- Guardian phone numbers are masked in ops-facing payloads (B4). Calls between staff and guardians go through a **number-masking proxy**, so an attendant never holds a parent's real number and a parent never holds an attendant's. This is a standard and expected control in this category and schools ask for it by name.
- Child photos are served through signed, short-TTL URLs, never public paths.

### K10. Session integrity
- One active session per driver / attendant device. New login revokes prior tokens.
- Parent accounts allow **multiple concurrent sessions** (mother's and father's phones are both legitimate) — this deliberately differs from the enterprise one-session rule, which would produce constant logouts in a two-parent household.
- Every guardian's session is scoped to their linked children only; the family dashboard is built server-side from the link table, never from a client-supplied child list.

### K11. Missed pushes
Poll every 10 s while foregrounded, immediate fetch in `onResume` (enterprise L14), pull-to-refresh everywhere. The push is a trigger; `/family-dashboard` is the source of truth.

### K12. Data minimisation for the operator role
A third-party transport operator sees, for the current trip only: child first name, grade, stop, boarding status, and any medical flag marked "share with vehicle staff". They never see home address, guardian phone, full name history, or journey records for other days. Enforced in the serializer, tested.

### K13. Retention & erasure
- Journey records: 7 years default, per-school configurable.
- Location traces: 90 days at full fidelity, then downsampled to one point per stop event.
- Photos (handover, sweep): 1 year default.
- Right-to-erasure: an anonymisation job replaces name/phone/email/address/photo with `[REDACTED]` across all related rows, preserving row structure and audit integrity. Children who leave the school are anonymised on the school's stated schedule, not deleted.
- The parent-facing "share a day" links (D5) expire in 7 days and are revocable.

### K14. Backups & DR
Daily database backups plus point-in-time recovery, with a **restore drill before go-live** on the pilot school. Journey records and the audit log are the assets; losing them is not recoverable by re-running anything.

### K15. Regulatory compliance dashboard ⚠ DIVERGES FROM ENTERPRISE

Indian school-bus operation carries specific statutory obligations (CMVR and state rules, plus Supreme Court directions that most states have adopted). Zippi should track them rather than assume the school does. Per-bus and per-staff checklist, with expiry-driven alerts at 30 / 15 / 7 / 0 days:

**Per bus**: registration, fitness certificate, permit, insurance, PUC, **speed governor** certificate, first-aid box (check date), fire extinguisher (check date), emergency exit functional, "SCHOOL BUS" lettering and yellow livery, school name + phone displayed, seating capacity vs children assigned, GPS device health, camera present/health, EV-specific — battery health and charging log.

**Per driver**: licence class + expiry, minimum years of heavy-vehicle experience, no serious-offence record, **police verification** (document + date), medical fitness + expiry, uniform, training completion date.

**Per attendant**: police verification, medical fitness, training completion, gender where a state mandates a female attendant on buses carrying young children.

**Per trip**: children on board ≤ rated capacity — enforced at boarding, not just reported. The 19th boarding tap on an 18-seat manifest is blocked with a clear message.

The dashboard is a **school-facing** page as well as ops-facing, because the school is the legally accountable party and being handed a green/amber/red compliance board is, on its own, a reason to choose Zippi.

### K16. Safeguarding the staff-child interaction surface
- Attendants and drivers cannot message parents in-app. All communication is templated notifications plus proxied voice. This is deliberate: an open messaging channel between vehicle staff and families is a safeguarding risk and a support burden, and every school that has tried it has turned it off.
- The school office can broadcast to a route or a class; parents reply to the office, not to the vehicle.

---

# PART L — Shipped-Behavior Rules (carried forward + school-specific)

> **Precedence rule**: PARTS A–K are the design spec. PART L records shipped behavior and safety-critical implementation rules. **When PART L conflicts with A–K, PART L is authoritative** — with the single exception of the four Critical Safety Invariants at the top of this document, which nothing overrides.

### L1. No Eloquent `date` casts on `service_date` / `date` columns
Carried forward verbatim from enterprise L1, same symptom, same cause, same rule. `service_date` and `school_calendars.date` are raw `Y-m-d` strings on the model. Compare with `where('service_date', $str)`; render with `Carbon::parse($v)->format('d M Y')`. Datetime columns keep their casts. Model files carry the warning comment. Adding the cast reintroduces the IST→UTC off-by-one that makes absence-undo delete zero rows.

### L2. Head-count reconciliation gates trip completion
`POST /trip-complete` computes, for the trip:

```
boarded_count == alighted_count + returned_to_school_count      (afternoon)
boarded_count == arrived_at_school_count                        (morning)
```

Mismatch → **422** with `unaccounted_child_ids[]` and a message naming them. The attendant app renders the list and the only way forward is to resolve each child. An admin force-complete (J2 #1) is the sole bypass and it records `headcount_verified=false` plus an incident.

### L3. The sweep is blocking and evidenced
`school_trips.sweep_verified_at`, `sweep_photo_path`, `sweep_lat`, `sweep_lng`, `sweep_by_staff_id`.

- The sweep step appears only **after** the last stop / school arrival — it cannot be pre-tapped at the depot.
- Photo capture is required (rear-of-vehicle interior). Camera-denied → the trip cannot complete on that device; the attendant must grant permission or ops force-completes and an incident is raised.
- The geo-stamp must be within `sweep_geofence_m` of the final stop or the school gate.
- `/trip-complete` returns 422 when `sweep_verified_at` is null.
- The Control Tower alerts on any trip completed with `sweep_verified=false`.

This is the single highest-consequence control in the product. It is deliberately annoying.

### L4. Every state change writes actor + geo + server time
No exceptions, including offline-queued events (K6). The journey record's value is entirely a function of this discipline.

### L5. Per-child notifications, never bulk
Enterprise L5's lesson: a bulk "trip completed" push is the wrong message at the wrong time. Every child event notifies that child's guardians at that child's moment. The only bulk notifications are trip-level facts that genuinely apply to everyone (closure, SOS, bus swap).

### L6. Defensive `Schema::hasColumn` on staged migrations
Carried forward. Code paths touching newly-added columns gate on `Schema::hasColumn(...)` and fall back, so the same controller runs against pre- and post-migration databases during a staged rollout.

### L7. Explicit facade imports + real exception text
Carried forward. Every controller using `DB`, `Schema`, `Log`, `Notification` declares the `use Illuminate\Support\Facades\...` import (the bare-`DB::` namespace-resolution bug is real and cost a day). High-impact endpoints wrap in try/catch, `Log::error` with trace, and return the actual message in the JSON `message` field so a staging failure surfaces in the app instead of vanishing into "server error".

### L8. Deploy runbook — storage and cache permissions
Carried forward:
```
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
sudo systemctl reload apache2
```
Rolling back the throttle/idempotency middleware to "fix" a post-deploy 500 masks a permissions bug and defeats K2 + K5.

### L9. Empty route returns the specific reason
`getRouteForVehicle` returns a structured `empty_reason` — `no_assignment | holiday | vacation | half_day_no_afternoon | not_a_school_day | all_absent | no_children_assigned | trip_already_completed` — plus a human message the app shows verbatim. "No schedule found" tells an attendant standing in a depot at 6:40 AM nothing.

### L10. FCM token uniqueness per guardian device
Enterprise L13, adapted. A token belongs to at most one `guardians` row. Before writing a token, strip it from every other guardian:

```php
Guardian::where('fcm_token', $token)->where('id', '!=', $guardian->id)
        ->update(['fcm_token' => null]);
```

⚠ School-specific amplifier: two parents sharing one phone, or a parent handing a phone to a grandparent, is common. Without this, one family's boarding notification lands under the wrong guardian's identity and the journey record's "notified" trail becomes wrong.

### L11. Every admin override pushes the vehicle device
Enterprise L11 carried forward and generalised: an override that changes per-child state writes the DB change **and** pushes the attendant device so the stale UI clears immediately. The audit log is for later; the push is for now. Applies to override-handover, mark-absent, bus-swap, force-complete, geo-fence grant.

### L12. `/start-trip` 409 branching
When a trip for this (vehicle, service_date, direction, trip_leg) already exists, the response's `trip.status` decides app behavior:

| status | App behavior |
|---|---|
| `started` | Resume into that trip with the returned `trip_id` and `child_statuses` map. No second start. |
| `completed` | Dialog: "This trip is already complete." No navigation. |
| `cancelled` | Dialog with the cancellation reason. |
| `transferred` | Dialog: "This trip moved to bus [reg_no]." |
| (other) | Toast with the server `message`. |

### L13. Resume an in-progress trip after back-press or restart
Enterprise M17 carried forward — and more important here, because a mid-route restart with 30 children on board must not reset anyone to `pending`.

`GET /ongoing-trip` returns a `child_statuses` map `{child_id: "pending|boarded|not_at_stop|absent|alighted|returned_to_school"}` and a `stop_statuses` map, both derived from authoritative rows. The app restores the exact stop and the exact per-child state. Auto-navigation into the active trip fires **once per app launch** so a deliberate back-press isn't immediately undone.

### L14. Immediate fetch on resume
Enterprise L14 carried forward to both the parent app and the attendant app: fetch synchronously in `onResume` before scheduling the periodic loop. A push that arrives while the activity is paused is dropped without buffering; waiting a full 10 s poll after resume shows the parent stale state exactly when they look.

### L15. `me_status` / `me_done` gate the parent's per-trip UI
Enterprise L15 carried forward. `/family-dashboard` returns, per child, `me_status` and `me_done` (`true` once status ∈ {alighted, returned_to_school, absent, not_at_stop, arrived_at_school-on-morning}). The child card keeps showing the bus and staff info (so the parent can still call the school) but drops the live map, ETA, and handover code.

### L16. Location streaming only during an active trip
Enterprise L16 carried forward. The driver/attendant device streams GPS from Begin Trip to Trip Complete, and not otherwise. A school bus idle between the 8:45 run and the 15:35 run should not be uploading a position every 5 seconds for six hours. Defensive `stopService` on the duty dashboard in case a previous trip leaked the service.

⚠ Exception: buses under **managed mobility** with a hardware GPS unit stream continuously from the device, independent of the app — that is fleet telematics, a different pipeline, and it is what powers the depot/charging view in PART S.

### L17. Capacity is enforced at the tap
K15's capacity rule, restated as shipped behavior: `markBoarded` returns 422 when `on_board_count >= bus.capacity`. The attendant app shows "Bus is full — call the school office" rather than a generic error.

### L18. Wrong-bus boarding is blocked, not warned
`markBoarded` for a child not in this trip's manifest returns 422 with the child's actual trip today. The attendant may override with a reason from a fixed list (`sibling swap approved by school`, `school instructed`, `other`); the override raises a **High** Control Tower alert immediately and notifies both the child's guardians and the school office.

### L19. Notification bodies name the real event time
K6's offline rule as shipped copy. Any notification dispatched more than 120 s after its event time appends the parenthetical *"(received late — the bus was out of network)"* and shows the true event time. Never present a sync time as an event time.

### L20. The parent app is a family dashboard
One login, N children, each with its own route, leg, bell time, and status. The dashboard sorts children by "next relevant event" — the child whose bus is 4 minutes away outranks the child already at school. Do not build a child-switcher that hides one child behind a dropdown; a parent with two children on two buses at 7 AM needs both statuses on one screen.

---

# PART M — Multi-Trip Per Bus Per Day (tiered bell times)

⚠ In the enterprise module this was a later addition. **For schools it is Phase 1**, because a school bus almost never runs one trip per direction.

### M1. Data model

```
school_trips
  id, school_id, route_id, route_version_id, bus_id,
  driver_id, attendant_id,
  service_date DATE (raw string, no cast),
  direction ENUM('Morning','Afternoon'),
  trip_leg VARCHAR(32),            -- Senior | Middle | Primary | custom
  sequence TINYINT,                -- order of this trip within the bus's day
  status VARCHAR(24),              -- scheduled|started|completed|cancelled
                                   -- |transferred|emergency_stopped
  child_ids JSON,
  scheduled_start_at, scheduled_end_at DATETIME,
  bell_time TIME,
  school_arrival_deadline DATETIME,   -- morning
  school_depart_at DATETIME,          -- afternoon
  route_schedule JSON,                -- ordered stops w/ solved times
  started_at, arrived_at_school_at, completed_at DATETIME,
  sweep_verified_at, sweep_photo_path, sweep_lat, sweep_lng, sweep_by_staff_id,
  headcount_reported INT, headcount_verified BOOL,
  auto_generated, admin_edited BOOL,
  source VARCHAR(16),              -- auto | admin | adhoc | legacy
  parent_trip_id BIGINT NULL,      -- bus swap / split lineage
  distance_m INT, duration_min INT

INDEX idx_trips_bus_date_seq (bus_id, service_date, sequence)
INDEX idx_trips_school_date_leg (school_id, service_date, trip_leg)
```

Only **one trip per bus** may be `started` at a time. The rest queue in `sequence` order on the duty dashboard.

### M2. Nightly generation

Artisan `school:generate-trips {date?} {--school=} {--dry-run} {--force}`, scheduled at **02:30 school-local** for the coming school day.

1. Consult `school_calendars` — a holiday or vacation generates nothing; a half-day uses `override_end_time`.
2. For each active route × direction × leg, resolve the assigned bus, driver, attendant (via the PART O resolver, honoring one-day overrides).
3. Resolve the child list from `child_stop_assignments` effective on that date, minus absences already recorded for that (date, direction, leg).
4. Skip when an existing row matches and `admin_edited=true`; skip auto rows unless `--force`.
5. Run `SchoolTripSolver::solve($trip)` (M7).
6. Write `sequence` from the solved start times.

Idempotent. One `school_trip_generation_runs` row per invocation with counts and errors. Manual trigger from the admin Schedule page.

### M3. Vehicle-app contract

`GET /api/school/today-trips` returns every trip for the logged-in staff member's vehicle today:

```json
{
  "status": true,
  "has_active_trip": false,
  "trips": [
    {
      "id": 9142, "direction": "Morning", "trip_leg": "Primary",
      "status": "scheduled", "sequence": 2, "can_start": true,
      "route": {"code": "RT-03", "name": "Kondapur – Gachibowli"},
      "child_count": 22, "stop_count": 9,
      "bell_time": "08:15",
      "scheduled_start_at": "2026-08-24T07:04:00+05:30",
      "scheduled_end_at":   "2026-08-24T08:09:00+05:30",
      "route_schedule": [ {seq, stop_id, name, scheduled_at, lat, lng,
                           leg_distance_m, leg_minutes, child_ids} ],
      "next_action": {"kind":"leave_in","minutes_until":18,
                      "label":"Leave in 18 min"}
    }
  ]
}
```

`can_start` is true only when this trip is `scheduled` and no other trip on this bus is `started` — enforced server-side too, as defence in depth. `next_action.kind` flips to `leave_now` at `scheduled_start_at − 2 min` and the card turns coral.

### M4. Student bulk import

CSV columns — required: `admission_no, name, grade, section, school_id`. Optional: `route_code, morning_stop, afternoon_stop, home_address, guardian1_name, guardian1_phone, guardian1_email, guardian2_name, guardian2_phone, self_release_consent, medical_notes, transport_fee_zone`.

Behavior: dedupe on `(school_id, admission_no)`; unknown `route_code` / stop names are collected as warnings rather than failing the row; guardians are created and linked, with an invite SMS/email queued but **not sent** until the school clicks "Invite parents" (so a mis-import doesn't spam 500 families).

Preview-then-commit, unlike the enterprise straight-insert: a student import is high-stakes and schools send messy files. The preview shows created / updated / skipped / warned counts and a downloadable row-level report.

### M5. Interaction with the single-active-trip invariant

`startTrip` rejects only on "another trip on this bus is currently `started`". Two completed morning legs on the same bus and date coexist normally. `startTrip` **promotes** a matching `scheduled` row to `started` rather than inserting a duplicate (enterprise M18's fix) — otherwise the duty dashboard shows a phantom "Now driving (no time)" card beside the real "Up next".

### M6. Backward compatibility

Not applicable at pilot — this is a new module. Keep `source='legacy'` in the enum anyway for imports from a school's prior system.

### M7. The solver — backward from the bell

`app/Services/SchoolTripSolver.php`.

**Morning (backward solve):**
```
school_arrival_deadline = bell_time − arrival_buffer_minutes     (default 10)
ordered = route_stops in AUTHORED sequence          ← not optimized
school.scheduled_at = school_arrival_deadline
for k in N..1:
    stop_k.scheduled_at = next.scheduled_at
                        − leg_minutes(stop_k → next)      ← DriveTimeEstimator
                        − dwell_seconds(stop_k)           ← per-stop, from child count
depot_departure = stop_1.scheduled_at − depot_leg − safety_buffer_minutes
```

**Afternoon (forward solve):**
```
school_depart_at = dismissal_time + boarding_minutes(child_count)
ordered = afternoon stop sequence (reverse of morning by default)
for k in 1..N:
    stop_k.scheduled_at = previous.scheduled_at + leg_minutes + dwell_seconds
```

- `dwell_seconds(stop)` = `base_dwell` (20 s) + `per_child_dwell` (8 s morning, 12 s afternoon — handover takes longer than boarding) × children at that stop. Overridable per stop.
- Leg times come from `DriveTimeEstimator` (Google Directions, traffic-aware, `best_guess`, 15-minute-bucket cache — enterprise M15). Fallback: haversine × `road_circuity_factor` (1.5) ÷ `fallback_speed_m_per_min` (300), deliberately conservative so the bus leaves early rather than late.
- **Departure-time realism**: solve the morning legs with `departure_time` set to the actual planned departure, not `now`. A 7 AM leg and an 8:20 AM leg have materially different traffic.
- When the backward solve lands the depot departure before a sane floor, clamp and emit `warning='deadline_too_tight'` — surfaced in the admin Schedule page as an amber chip, because it means the route physically cannot make the bell and someone must split it.

**Config** (`config/school.php`):
```
arrival_buffer_minutes            10
safety_buffer_minutes              5
base_dwell_seconds                20
per_child_dwell_seconds_morning    8
per_child_dwell_seconds_afternoon 12
boarding_minutes_at_school         base 5 + 0.25/child
stop_wait_seconds                120
drop_wait_seconds                180
approach_radius_m               1200
approach_eta_minutes               5
stop_geofence_m                  150
school_geofence_m                200
sweep_geofence_m                 300
delay_alert_minutes               10
deviation_threshold_m            500
deviation_sustain_seconds         90
schedule_drift_amber_minutes       5
use_google_eta                  true
traffic_model             best_guess
eta_cache_seconds                900
road_circuity_factor             1.5
fallback_speed_m_per_min         300
```

**Admin per-stop override**: the Schedule page lets an admin edit any stop's `scheduled_at`; touched entries get `manually_set: true` and the trip gets `admin_edited=true`, which future generator runs respect. "Recompute from bell" clears the manual flags and re-solves.

**Start-time hydration**: when the attendant taps Begin Trip, the server re-solves against the current child list (after dropping fresh absences) so the times on screen reflect today, not last night's snapshot.

### M8. Duty dashboard — read-only trip stack

The vehicle app's trip list is informational. Trips start only from the trip screen via swipe-to-confirm (M9) — never by tapping a dashboard card. Prevents a pocket-tap from promoting a scheduled trip to started.

| status | Alpha | Pill |
|---|---|---|
| `started` | 1.0 | **Now running** (teal) |
| `scheduled` | 0.65 | Up next |
| `completed` | 0.45 | Done |
| `cancelled` / `transferred` | 0.45 | Cancelled / Moved |

Active trip pinned to the top.

### M9. Swipe-to-begin

Begin Trip is a swipe widget, not a button — released past 85% travel fires the call, anything less springs back. Same rationale as enterprise M9, higher stakes: an accidental trip start pushes "the bus has left" to 22 families.

### M10. On-time targeting

The deadline is `bell_time − arrival_buffer_minutes`, targeting the child **in** the school before the bell, not at the gate as it rings. The enterprise M10 lesson applies: earlier is the safe direction to be wrong.

### M11. JIT generation

`today-trips` self-heals: if nothing exists for today, generate for this vehicle inline (mirroring M2's logic, calendar-aware) so a missed 02:30 cron doesn't strand a route. ~50–200 ms once per vehicle per day.

### M12. Ad-hoc trips — field trips, exams, activities

A dedicated form at `/school/trips/one-off`: date, route or free stop list, bus, driver, attendant, direction, anchor time, and a child multi-select (or a whole class). Common cases:
- **Field trip** — non-route stops, one-off child set, often a different bell anchor. Requires guardian consent capture per child, which the school can send from the same form.
- **Exam day** — subset of children, earlier dismissal.
- **Late bus** — an extra afternoon leg at 17:30 for after-school activities. This is a recurring ad-hoc: model it as a schedulable extra leg, not a one-off.

Ad-hoc children are marked `is_adhoc_trip_member` on the trip only; they are **not** cloned into a separate roster (the enterprise `is_adhoc` rider pattern doesn't apply — a school child is always a real, enrolled child).

### M13. Deleting a scheduled trip

`DELETE /school/trips/{trip}` hard-deletes a `scheduled` trip and its child rows in a transaction, after snapshotting the row into the audit log. Refuses when `status='started'`. Double-confirm in the UI. Guardians of affected children are notified.

### M14. Reconciliation on every load

`today-trips` runs two passes (enterprise M18):
- **`reconcileScheduledTrips`** — re-sync each non-admin-edited scheduled trip's child list against current stop assignments and today's absences; re-solve when it changed. Fixes "I added a child and the times are still the old ones."
- **`sweepOrphanTrips`** — remove auto-generated trips whose route assignment no longer exists.

Changed trips re-solve synchronously so the corrected times show on this load; newly created ones solve `afterResponse` so the first load stays fast.

---

# PART N — One-off riders & guest children

Narrower than the enterprise PART N, because a school roster is closed.

### N1. Cases that actually occur
- A child who normally doesn't use transport needs the bus for a week (parent travelling).
- A visiting/exchange student.
- A sibling riding a different route for one day.

### N2. Mechanism
Not a new employee-like record. Instead a **temporary stop assignment**: `child_stop_assignments` row with `effective_from`/`effective_to` and `is_temporary=true`. The generator picks it up automatically; it expires on its own; the child's journey record is continuous.

### N3. Billing hook
A temporary assignment creates a pro-rated transport-fee line in the ledger (PART S2) rather than a full term charge. Schools care about this more than about the routing.

### N4. Files (PART N)
`app/Http/Controllers/School/TemporaryAssignmentController.php`, `resources/views/school/children/temporary-assignment.blade.php`, plus the generator already honoring `effective_from/to`.

---

# PART O — Daily Roster, route-based staff assignment & one-day overrides

> **One minute, plain English.** Children belong to a **route** (`RT-03`), not to a driver. A driver and an attendant are assigned to a **route + leg**, and that applies to every child on it. A **Daily Roster** page shows, per date, who rides, on which bus, with which driver and attendant, grouped by route. There are two tiers: a **default** assignment (every day) and a **one-day stand-in** for a single date + direction + leg, which auto-reverts. Morning and afternoon staff are independent.

### O1. Why
Ops think in routes. They need a daily "who's on which bus today" view and the ability to swap a driver or attendant for one leg of one day without permanently changing the route.

### O2. Schema
- `route_staff_assignments` — `(route_id, direction, trip_leg, driver_id, attendant_id, bus_id, effective_from, effective_to)`. The default.
- `route_staff_overrides` — `(service_date, route_id, direction, trip_leg, driver_id NULL, attendant_id NULL, bus_id NULL)`, unique on the first four. Any of the three may be set independently — swapping only the attendant is the common case. ⚠ Dates **not** cast (L1).
- `child_stop_assignments` — `(child_id, route_id, direction, stop_id, effective_from, effective_to, is_temporary)`.
- `child_stop_overrides` — `(child_id, service_date, direction, stop_id NULL, route_id NULL)` for "today she's getting off at her grandmother's stop", unique on `(child_id, service_date, direction)`.

### O3. The shared resolver
`app/Services/SchoolRouteStaffResolver.php` — one source of truth used by both the roster page and the trip pipeline so they cannot drift.

- `forDate($dateStr)` bulk-preloads that date's overrides (no N+1).
- `effectiveStaff($route, $direction, $leg)` = `override(date, route, direction, leg)` field-by-field ?? `default assignment` field.
- `effectiveStopFor($child, $direction, $dateStr)` = `child_stop_overrides` ?? `child_stop_assignments` effective on that date.
- `tripsForStaff($staffId, $dateStr)` — every trip where this person is the effective driver or attendant, pulling in override-targeted routes and dropping override-away ones.

The `??` fallback chain lives **only** here.

### O4. Pipeline integration
- `getRouteForVehicle` and the duty dashboard load via `tripsForStaff`, never via a raw `where('driver_id', X)`.
- `reconcileScheduledTrips` loops direction-outermost and uses the resolver for membership.
- `sweepOrphanTrips` uses effective membership, so a one-day override cleanly moves a trip to the stand-in and reverts the next day.
- Ad-hoc and admin-created trips are never touched by the sweep.

### O5. Admin — Daily Roster page
`app/Http/Controllers/Admin/SchoolDailyRosterController.php`

- `index()` — date (default today) + optional school and route filters. Lists only children with an active stop assignment, who attend that weekday, and who aren't absent that day (direction-aware: a morning-only absence blanks only the morning column). Grouped by (school, route). Columns: Date, Route, Leg, Child, Grade, Stop, Guardian phone (masked), Week offs, Morning bell + Morning driver + Morning attendant, Afternoon bell + Afternoon driver + Afternoon attendant.
- `saveRoute()` — one Save per route card: sets default morning/afternoon driver, attendant, and bus, and sets/clears the day's overrides (blank = revert to default). Lazy — the next generator pass or the next duty-dashboard poll materializes it.
- `export()` — CSV honoring the filters. This is the sheet the transport coordinator prints and pins in the office; make it good.
- View: one card per route showing "Default (every day)" and "Today only · stand-in" selects for driver, attendant, and bus, morning and afternoon, with a single Save.

### O6. Assignment page changes
- **Route code** on assignment create/edit, fixed `RT-` prefix, zero-padded (`3` → `RT-03`).
- **Multi-select children** — assign a stop to many children at once, with a confirm list showing each child's grade and existing stop, and an amber warning when the selected children span more than one leg (a common data-entry error that produces a bus arriving at the wrong bell).
- Index grouped by route, one card per route.

### O7. Files (PART O)
- New: `SchoolRouteStaffResolver.php`, `RouteStaffOverride.php`, `ChildStopOverride.php`, `SchoolDailyRosterController.php`, `resources/views/admin/school/roster/index.blade.php`, four migrations.
- Modified: `SchoolController::getRouteForVehicle`, `SchoolTripController::{reconcileScheduledTrips,sweepOrphanTrips}`, assignment controllers and views, sidebars, routes.

---

# PART P — Auth, OTP, SMS & Email Hardening

### P1. Random OTPs everywhere
Parent login (mobile), driver login (mobile), attendant login (mobile), school-user login (email) → `random_int(1000, 9999)`. Handover codes → `random_int(1000, 9999)`, stored hashed, rotated daily. **No static `1234` anywhere, in any environment that is reachable from the internet**, including staging.

### P2. SMS delivery
Carry forward both enterprise fixes verbatim, they are real and were expensive:
- **Template id** — always pass a configured DLT template id with a hard-coded fallback, never an empty string (an empty templateId is accepted by the gateway and silently never delivered).
- **Number format** — do **not** write `'91' . ltrim($mobile, '91')`. `ltrim` treats `'91'` as a character *set* and strips leading 9s and 1s, producing a malformed number the gateway accepts and never delivers. Send the raw number.

School-specific DLT templates needed (register these before go-live; approval takes days and will otherwise block launch): OTP, child boarded, child handed over, nobody at stop, returning to school, trip cancelled/closure, SOS, absence confirmation.

### P3. OTP verify rate limit
5 wrong attempts → 1-minute lockout, using `otps.attempts` / `otps.locked_until`. Wrong code → `400 {status:false, message:"Invalid OTP", attempts_remaining:N}`. Locked → `429`. Success or a new OTP resets.

### P4. OTP send throttle
3 requests per 2 minutes per mobile/email via `RateLimiter`. 4th → `429` with the seconds remaining.

### P5. Email delivery
School-user OTPs and reports go by email, dispatched `afterResponse` with failures logged, never blocking.

⚠ **Infra gotcha carried forward**: the current droplet has **outbound SMTP (25/465/587) blocked** by the provider, so Gmail/Titan SMTP times out. Email requires either the provider opening 587 or an **HTTPS email API** (Resend / Mailgun / Brevo) over 443. Choose the HTTPS API — it is the same effort and removes the dependency. SMS over an HTTPS gateway is unaffected.

### P6. Multi-role identity
One phone number can legitimately be a parent at School A, a parent at School B, and an attendant. Model identity as a `users` row with N `role_links`, and let login present a role/school picker when more than one link exists. Do **not** block registration on cross-role phone collisions — the enterprise P6 fix (removing that cross-check) applies with more force here.

### P7. API contract the mobile apps must handle

Every non-2xx carries a `message` the app **renders verbatim**. Specifically:
- OTP verify success → `200 {status, message, user:{...}, roles:[...], token}`.
- Wrong OTP → `400 {status:false, message, attempts_remaining}`.
- Locked → `429 {status:false, message}`.
- Send throttled → `429 {status:false, message}`.
- Handover verify wrong → `400 {status:false, message, attempts_remaining}`; locked → `423 {status:false, message, escalation_available:true}`.
- Trip complete blocked → `422 {status:false, message, unaccounted_child_ids:[...], sweep_required:true|false}`.

An app that clears the input and shows nothing on a non-2xx is a defect, not a cosmetic issue — it is how an attendant ends up guessing at a locked handover.

### P8. Files (PART P)
`app/Http/Controllers/Api/SchoolAuthController.php`, `SchoolTripController.php` (handover codes), `app/Services/OtpService.php`, `…_add_attempts_and_locked_until_to_otps_table.php` (guarded with `hasColumn`), `config/services.php`, `.env`.

---

# PART Q — SOS & Emergency

⚠ No enterprise analog. This is a Phase-1 requirement.

### Q1. Who can raise it
- **Driver** — persistent button on the navigation screen; 2-second long-press to avoid accidental fire, with haptic confirmation.
- **Attendant** — persistent button on the trip screen, same interaction.
- **Parent** — from the child's live screen, raises a **concern**, not a vehicle SOS: routes to the Control Tower and the school office, not to the vehicle.
- **Control Tower / school** — can raise on behalf.

### Q2. What happens, in order

```
T+0s   SOS written to Firebase SOS/{school_id} (sub-second Control Tower surface)
       + POST /sos with type, lat/lng, trip_id, actor
T+0s   Control Tower: Critical alert, audible, requires named acknowledgement
T+0s   School office: push + proxied call
T+0s   Trip locked: no further child state changes accepted until resolved
       or explicitly released by ops (prevents a panicked mis-tap sequence)
T+5s   Guardians of every child ON BOARD pushed:
       "There is an emergency on [Child]'s bus. The school and Zippi
        control room have been alerted and are responding. We will
        update you shortly. Do not call the driver."
       (deliberate copy: 200 simultaneous calls to a driver in an
        emergency is itself a hazard)
T+..   Ops works the incident from the trip drawer: live position, child
       manifest with medical flags, staff contacts, nearest hospital,
       one-tap conference with driver + attendant + school
Close  Ops records the resolution narrative → school_incidents row →
       guardians receive a closing update, which is REQUIRED before
       the incident can be marked closed
```

### Q3. Types
`accident | breakdown | medical | security | fire | other`. Type drives the response playbook shown to the ops user and the notification copy. `breakdown` triggers the bus-swap flow (J2 #10) automatically as a suggested next action.

### Q4. Silent alarm
A separate, unlabelled long-press gesture raises a **security** SOS without any on-screen change on the vehicle device. For the hijack/threat case. Surfaces as Critical in the Control Tower, marked `silent=true`, and suppresses the guardian fan-out until ops decides.

### Q5. Drills
The school can run a **drill** (`is_drill=true`) which exercises the entire path including guardian notifications, clearly labelled "THIS IS A DRILL". Run one before go-live and once a term; the drill result is a compliance artifact.

### Q6. Files
`app/Http/Controllers/Api/SchoolSosController.php`, `app/Services/SosDispatcher.php`, `app/Models/SchoolIncident.php`, `app/Notifications/SchoolSosNotification.php` (+ guardian, staff, ops variants), Firebase `SOS/{school_id}` node, Control Tower alert component, both apps' SOS widgets.

---

# PART R — Route Deviation, Unexpected Stops & Driving Behaviour

⚠ No enterprise analog. Phase-1 requirement (it is on the pilot list).

### R1. Corridor deviation
Each published route version stores an encoded polyline (from the Directions response used by the solver). During an active trip, each location ping is tested against the corridor:

- Distance from the polyline > `deviation_threshold_m` (500) sustained for > `deviation_sustain_seconds` (90) → **deviation event**, High alert.
- The event records entry point, max distance, duration, and the exit point, and renders as a red segment on the Control Tower trace.
- The driver app shows "Off route — tap to log a reason" with a short list (`road closed`, `traffic diversion`, `police direction`, `breakdown`, `other`). A logged reason does not suppress the alert; it annotates it.
- Parents are **not** notified of deviations by default. A deviation is usually a diversion, and pushing "your child's bus is off route" to 22 families over a closed road generates panic and phone calls that make the situation worse. Configurable per school; default off.

### R2. Unexpected stop
Stationary > `unexpected_stop_seconds` (default 300) outside a known stop geo-fence, the school geo-fence, and the depot, with children on board → **Medium** alert escalating to **High** at 10 minutes. Combined with the deviation signal, this is the pattern that matters.

Suppressed when the position matches a known traffic-signal cluster or the average speed of nearby traffic is also near zero (cheap heuristic: other Zippi vehicles in the same 200 m cell are also stopped).

### R3. Speed
- School buses are legally speed-limited in most Indian states (commonly 40 km/h) and are required to carry a speed governor. Configure `max_speed_kmph` per school (default 40) and per road class where available.
- Sustained > limit for `overspeed_sustain_seconds` (30) → High alert, recorded against the driver's record.
- The driver app shows a live speed readout that turns amber at 90% of the limit and red above it. Visible feedback changes behaviour far more than a report the driver never sees.
- Harsh braking / harsh acceleration / sharp cornering from device accelerometer where available; recorded, aggregated into a weekly driver score, never surfaced as a real-time alert (too noisy).

### R4. Idling (EV-relevant)
For ICE buses, idling minutes are logged and reported (fuel + emissions). For EVs it is largely moot, which is itself a line in the sustainability report (PART S3).

### R5. What the school sees
A weekly **Route & Driving** digest per route: on-time rate, average delay by stop, deviation count, overspeed count, harsh-event count, idling minutes, distance. This is the artifact that justifies the software-only subscription in year two.

### R6. Files
`app/Services/RouteCorridorMonitor.php`, `SpeedMonitor.php`, `app/Models/SchoolTripEvent.php`, `…_create_school_trip_events_table.php`, Control Tower trace renderer, driver app speed widget, `resources/views/school/reports/driving.blade.php`.

---

# PART S — SaaS, Managed Mobility, Fees & ESG

### S1. School onboarding wizard (Phase 2's product)

Seven steps, resumable, each independently savable:

```
1. School profile      name, code, gate location + radius, timezone, logo, contacts
2. Bell schedule       legs, grade ranges, start/end times, effective dates
3. Calendar            CSV upload or month grid; holidays, exams, half-days
4. Students            CSV import with preview (M4); guardians created, not invited
5. Routes & stops      route builder, stops via Places autocomplete, sequence,
                       child→stop assignment (bulk by area, or from the CSV)
6. Fleet & staff       buses with documents, drivers, attendants, assignments (PART O)
7. Go live             dry-run generation for tomorrow, review the solved times,
                       run one drill, then "Invite parents" fans out the invites
```

Target: a 600-child school live in one working day. The long pole is always steps 4 and 5; invest the engineering there (good CSV error reporting, bulk stop assignment by drawing a polygon on a map).

### S2. Transport fee ledger

Schools bill transport separately from tuition and almost universally do it in a spreadsheet.

- **Fee zones** — distance bands from the school gate, or named zones. A stop belongs to a zone; a child inherits it from their stop.
- **Plans** — per term / per month / per annum, one-way vs two-way (a child who only takes the morning bus pays less — the asymmetric case from A3 has a billing consequence).
- **Ledger** — per child: charges, discounts (sibling, staff, scholarship), payments, balance. Pro-rated on mid-term joins, temporary assignments (N3), and stop changes.
- **Outputs** — a defaulters list, a term invoice PDF, a collection summary. Payment gateway integration deferred; schools mostly collect through their existing ERP and want the *numbers*, not the rails.
- **The commercial hook**: cross-referencing the ledger against actual boarding data (PART D) surfaces children being billed who never board and children boarding who aren't billed. In the pilot, expect this to find real money. It is the single most persuasive number in a renewal conversation.

### S3. Sustainability / ESG reporting

For the EV positioning, this must be a real artifact, not a marketing slide.

- Per trip: distance, energy consumed (EV telematics) or fuel (ICE), computed CO₂e using a published grid-intensity factor for the region, cited in the report.
- Per term, per route, per school: total km, CO₂e emitted, **CO₂e avoided vs the counterfactual** — the counterfactual being private-car trips replaced, computed as `children_boarded × trips × average_car_trip_emissions`, with the assumptions stated on the page. State the methodology openly; an ESG number without a stated method is worthless to a board and worse than none.
- Per child: "Aarav's bus travel avoided X kg CO₂ this term compared to a car" — in the parent app. Small, and parents genuinely like it.
- Export: a branded PDF the principal can put in the annual report, plus CSV for whoever compiles the school's disclosures.

### S4. Managed-mobility operations module

Only needed for the second revenue model, but design the schema for it now:
- Depot and charging management, charging session logs, state-of-charge visibility per bus, range-vs-route feasibility check (an EV assigned to a 180 km/day duty cycle must fail validation at assignment time, not at 4 PM on a Tuesday).
- Staff duty rosters, leave, relief pool.
- Maintenance schedule, breakdown history, spares.
- Per-route cost model: energy, driver, attendant, maintenance, insurance, depreciation → cost per km and cost per child, which is what makes the managed-mobility quote defensible.

### S5. Multi-school scale

- Everything is scoped by `school_id` at the query layer, enforced by a global scope plus a test that fails if any model lacks it.
- The Control Tower defaults to all schools for Zippi ops, one school for a school user, and a configurable set for a multi-school group (chains are the best expansion path — one relationship, ten campuses).
- Feature flags per school so a pilot feature can ship to one school without a branch.

---

# PART I — Navigation & Back-Button Safety

The enterprise regression checklist, with the school-specific blocks added.

### I1. Vehicle app — once a trip is active, back must not lead to "Begin Trip"
- After `/start-trip` succeeds, the schedule/list screens are finished and the trip flow launches with `FLAG_ACTIVITY_NEW_TASK | FLAG_ACTIVITY_CLEAR_TASK`.
- `SchoolTripFlowActivity.onBackPressed` branches: handover keypad open → close keypad; stop open with children listed → collapse stop; child selected → deselect; nothing selected → "End trip?" dialog.
- ⚠ **Hard block**: if the trip is at the sweep step and the sweep is unconfirmed, back shows "You cannot leave until the vehicle sweep is confirmed" and does not exit. No dialog with an escape.
- ⚠ **Hard block**: if any child is `on_board` and the trip is not complete, back cannot end the trip. The dialog reads "3 children are still on the bus" and lists them.
- Both hardware back and the toolbar arrow route through the same handler.

### I2. Vehicle app — afternoon-specific
- At school boarding, before lock-in: back → "Pause boarding? The trip stays active — resume from Today's Trips." State persists in DB.
- Mid-escalation for a child (A7): back is disabled entirely. The escalation must reach a terminal state.

### I3. Parent app — a completed trip cannot show stale tracking
- When `/family-dashboard` reports the child's terminal state, the live map hides via `applyMapMode` (enterprise L29 pattern). No blocking dialog — the push is the user-visible signal (enterprise L27).
- Absence dialog dismissed → stays on the family dashboard, no back-stack pollution.
- Deep link from a notification lands on the right child's live screen with the family dashboard beneath it, so back goes to the dashboard, not out of the app.

### I4. Control Tower
- Back from `/admin/school/live/{id}` returns to the school picker.
- The trip drawer closes on X/ESC and leaves the board intact.
- An unacknowledged Critical alert survives navigation — it follows the ops user until acknowledged.

### I5. Universal regression matrix

| Screen / state | Back behavior | Note |
|---|---|---|
| Mark-absent dialog | Dismiss, stay on dashboard | Standard |
| Absence success | Dialog closes, banner appears | No nav change |
| Absence banner + Undo | Updates in place | No nav change |
| Vehicle schedule screen | Back to duty dashboard | Standard |
| Trip flow, nothing selected | "End trip?" dialog | Dialog |
| Trip flow, stop open | Collapse stop | UI only |
| Trip flow, handover keypad open | Close keypad, child stays pending | UI only |
| Trip flow, escalation running | **Disabled** | Hard block |
| Trip flow, children on board | **Blocked with child list** | Hard block |
| Trip flow, sweep pending | **Blocked** | Hard block |
| Afternoon boarding, pre-lock-in | "Pause boarding?" dialog | State in DB |
| Parent live screen | Back to family dashboard | Standard |
| Parent journey screen | Back to child card | Standard |
| Control Tower live board | Back to school picker | Standard |
| Trip drawer | Close → board | Drawer dismiss |
| Unacknowledged Critical alert | Persists across navigation | By design |

This table goes into `/school/help/how-it-works` as the QA checklist.

---

# Verification

Plain-English tests. Every one should be runnable by a non-engineer against staging.

### Safety invariants (run these first, and after every release)
S1. Attempt to complete a morning trip with one child marked boarded and not marked arrived → **422**, child named, cannot proceed.
S2. Attempt to complete any trip without the sweep → **422**.
S3. Attempt the sweep at the depot before the last stop → the step is not available.
S4. Deny the camera permission and attempt the sweep → trip cannot complete on that device; ops force-complete path raises an incident.
S5. In the afternoon flow, search every screen and every menu for any action that removes a child from the bus without a handover, self-release, or return-to-school. **There must be none.**
S6. Run the drop escalation to exhaustion → child status becomes `returning_to_school`, Critical alert fires, school office notified, all guardians notified, and the child appears in the office hand-off list on trip end.

### PART A — Absence
1. Open Mark Absent → defaults to the next **school day**, skipping a weekend and a holiday correctly.
2. Apply a 5-school-day absence, both directions → 10 rows.
3. Morning only, 5 days → 5 rows; the afternoon trips still include the child.
4. Afternoon only, 1 day → the child rides in, is collected at school.
5. Range spanning a holiday → the holiday is skipped and the confirmation says so.
6. 70-day range → rejected (cap 60).
7. Trip already started → morning marking blocked with a clear message.
8. Morning cutoff: child's stop at 07:34 → marking at 07:13 allowed, 07:15 blocked, and the message names 07:14.
9. Afternoon has no time cutoff: at any point before boarding, marking succeeds. After the attendant ticks the child, the button is gone and the "call the office" copy shows.
10. Undo removes exactly the rows the parent created — no more, no fewer.
11. Parent-collecting flow: attendant sees the child greyed as "Parent collecting" **and** the front office is notified.
12. Morning not-at-stop: attendant taps At Stop, waits the configured 120 s, ✗ unlocks, marks → parent pushed, absence row `marked_by='attendant_not_at_stop'`.
13. Server enforces the wait: call `markNotAtStop` 20 s after arrival via API → rejected regardless of what the client shows.

### PART A7 — Handover
14. Correct code → child alights, receiver recorded, parent pushed within seconds.
15. Wrong code 5 times → handover locks, Medium alert, escalation still available.
16. Authorized-person path: attendant selects the grandmother's photo, confirms → recorded with `verification_method='authorized_person'`.
17. Self-release: enabled child alights without a code; a below-grade child shows the action disabled with the reason.
18. Escalation timings match config; guardian 1 and 2 auto-call prompts fire; return-to-school becomes the only action at expiry.
19. Offline: airplane mode at the stop → code verifies against the cached hash, child alights, event syncs on reconnect flagged `verified_offline`, and the parent's late notification carries the true time and the "received late" note.

### PART B — Control Tower
20. `/admin/school/live` lists schools with running-bus counts.
21. Click a school → board scoped to it; the school user's own `/school/live` shows the same data without override buttons.
22. Bus moves → marker moves within 5 s.
23. Direction and leg toggles filter correctly.
24. Boarding events surface as toasts and the on-board count increments.
25. Guardian phone numbers in the live JSON are masked.

### PART C — Calendar & legs
26. A holiday generates no trips; the parent app shows "No school on [date]".
27. A half-day generates morning normally and afternoon re-solved from `override_end_time`.
28. Three morning legs on one bus generate three independent trips with different bells and different children.
29. Marking absent for the Primary leg does not affect the child's sibling on the Senior leg.
30. At 23:40 IST, mark absent for the next school day → stored `service_date` is the IST date.

### PART D — Journey record
31. Open a child's journey for a completed day → every event listed with actor, time, and coordinates.
32. Trip detail shows scheduled vs actual per stop, handover method and receiver, sweep photo.
33. Attendance export for a class over a month matches the boarding data row for row.
34. `EXPLAIN ANALYZE` on a 500k-row test set uses the new indexes.
35. A correction creates a superseding row; the original remains visible, struck through, with the reason.

### PART F — Notifications
36. Boarding push reaches every linked guardian with app access, and no one else.
37. Approach alert fires once per stop, at roughly the configured distance/ETA, and does not re-fire when the bus circles.
38. Arrival-at-school push fires on gate confirmation.
39. Handover push names the receiver.
40. A guardian who disabled "bus delayed" still receives "child boarded" — the non-mutable set cannot be switched off.
41. Lock the phone and trigger a handover → the notification body does **not** contain the code.

### PART G — Sequence
42. Stops render in authored sequence regardless of where the bus starts.
43. Visiting stops out of order raises a deviation event.
44. Re-sequencing requires an effective date, versions the route, leaves prior trips intact, and notifies affected guardians with old and new times.

### PART J — Overrides
45. Force-complete a stuck trip → completed, `headcount_verified=false`, incident raised, audit row written, staff and school notified.
46. Override a handover on behalf → child alights, attendant device UI clears immediately via push (not on the next poll), audit row records the ops user and notes.
47. Bulk closure for tomorrow → calendar rows written, generated trips cancelled, every guardian notified, one transaction.
48. Bus swap mid-trip → remaining stops and on-board children move to the relief trip, guardians pushed with the new ETA, original trip closes `transferred` with journey rows intact.
49. Force-complete with empty notes → 422.
50. A school user attempting a Zippi-only action → 403.
51. Attempt to UPDATE an audit row via SQL or tinker → denied at the DB and by the model guard.

### PART K — Security & compliance
52. 5 wrong handover attempts → locked; escalation still works.
53. Idempotency: fire `/child-board` twice with the same key → one boarding row, one push.
54. Location teleport: post a Bangalore→Delhi jump in 30 s → rejected and logged.
55. Operator-role user fetches a trip payload → no home addresses, no guardian phone numbers, no other-day journey data.
56. Capacity: attempt the 43rd boarding on a 42-seat bus → 422 with a clear message.
57. Wrong-bus boarding → blocked with the child's actual trip named; override raises a High alert and notifies guardians and the school.
58. Document expiry at 15 days → Medium alert on the compliance dashboard for the right bus/staff member.

### PART I — Navigation regressions (CRITICAL)
59. **The original bug**: begin a trip → land in trip flow → hardware back → "End trip?" → Stay → still in flow; Leave → duty dashboard, never the begin screen. Confirm the begin screen is not in the back stack.
60. **Server defence in depth**: force the app to the begin screen during an active trip and tap Begin → 409 with a status-aware payload, and the app resumes rather than duplicating.
61. Back with children on board → blocked, children listed.
62. Back with the sweep pending → blocked.
63. Back during escalation → disabled.
64. Kill the app mid-trip with 18 children boarded and one child `arrived at stop` → reopen → exact same state restored from the server, countdown resumes from the server anchor.
65. Parent app: deep-link from a boarding push → child live screen; back → family dashboard, not out of the app.

### PART Q — SOS
66. Driver long-press 2 s → Critical alert in the Control Tower in under a second; unacknowledged alert follows the ops user across navigation.
67. Guardians of on-board children receive the calm-copy notification; guardians of absent children do not.
68. Silent security SOS produces no on-screen change on the vehicle device and suppresses the guardian fan-out.
69. Drill mode exercises the full path with "THIS IS A DRILL" in every message.
70. An incident cannot be closed without a resolution narrative and a closing update to guardians.

### PART R — Deviation & speed
71. Drive 600 m off corridor for 2 minutes → deviation event with entry, max distance, duration, exit.
72. Stationary 6 minutes off-stop with children on board → Medium escalating to High at 10.
73. Sustained 48 km/h against a 40 limit → High alert, driver record updated, in-app readout red.
74. Parents receive no deviation notification with the default config.

---

# Files — consolidated

**Backend**

Migrations:
`schools`, `school_bell_times`, `school_calendars`, `routes`, `route_stops`, `route_versions`, `buses`, `school_staff` (drivers + attendants), `children`, `guardians`, `child_guardian_links`, `authorized_receivers`, `child_stop_assignments`, `child_stop_overrides`, `route_staff_assignments`, `route_staff_overrides`, `school_trips`, `school_trip_children`, `school_stop_arrivals`, `school_child_absences`, `school_child_handovers`, `school_trip_events`, `school_incidents`, `school_admin_audit_log`, `school_trip_generation_runs`, `school_location_anomalies`, `transport_fee_zones`, `transport_fee_plans`, `transport_fee_ledger`, journey indexes.

Models: one per table. ⚠ **No `date` casts on `service_date` / `date` columns** (`SchoolTrip`, `SchoolChildAbsence`, `SchoolCalendar`, `RouteStaffOverride`, `ChildStopOverride`) — each carries the warning comment. `SchoolAdminAuditLog` overrides `save()` to reject updates.

Services:
- `SchoolTripSolver.php` — backward/forward per-stop solve (M7).
- `DriveTimeEstimator.php` — carried over from enterprise; Google Directions, traffic-aware, cached.
- `SchoolCalendarService.php` — school-day resolution (C).
- `SchoolRouteStaffResolver.php` — effective staff/stop resolution (O3).
- `SchoolNotificationDispatcher.php` — child → guardians fan-out (F7).
- `RouteCorridorMonitor.php`, `SpeedMonitor.php` (R).
- `SosDispatcher.php` (Q).
- `RouteOptimizer.php` — design-time only (G).
- `HandoverCodeService.php` — rotation, hashing, verification, lockout (A7/K3).

API controllers (`app/Http/Controllers/Api/`):
- `SchoolAuthController.php` — OTP login, multi-role picker (P).
- `SchoolController.php` — `getFamilyDashboard`, `getRouteForVehicle`, `getTodayTrips`, `getOngoingTrip`, `todayOverview`.
- `SchoolTripController.php` — `startTrip`, `arriveAtStop`, `markBoarded`, `markNotAtStop`, `departStop`, `confirmSchoolArrival`, `verifyHandoverCode`, `alightToAuthorizedPerson`, `alightSelfRelease`, `beginDropEscalation`, `returnChildToSchool`, `confirmSweep`, `completeTrip`, `markChildAbsent`, `undoAbsence`, `rateTrip`.
- `SchoolChildJourneyController.php` — parent journey + share link (D5).
- `SchoolSosController.php` (Q).

Admin/school controllers (`app/Http/Controllers/{Admin,School}/`):
`SchoolLiveTrackingController`, `SchoolTripHistoryController`, `SchoolRouteController`, `SchoolCalendarController`, `SchoolDailyRosterController`, `SchoolOverrideController`, `SchoolChildController`, `SchoolBusController`, `SchoolStaffController`, `SchoolComplianceController`, `SchoolFeeController`, `SchoolReportController`, `SchoolOnboardingController`, `TemporaryAssignmentController`.

Console: `GenerateSchoolTrips.php` (02:30 nightly), `RefreshImminentTrips.php` (every minute), `PurgeExpiredJourneyData.php` (daily), `DocumentExpiryAlerts.php` (daily).

Notifications (`app/Notifications/`): `SchoolParentNotification` base + `SchoolTripStarted`, `SchoolBusApproachingStop`, `SchoolBusAtStop`, `SchoolChildBoarded`, `SchoolChildNotAtStop`, `SchoolChildArrivedAtSchool`, `SchoolChildHandedOver`, `SchoolNoGuardianAtStop`, `SchoolChildReturningToSchool`, `SchoolChildHandedToOffice`, `SchoolBusDelayed`, `SchoolTripCancelled`, `SchoolClosureAnnounced`, `SchoolRouteChanged`, `SchoolBusSwapped`, `SchoolSos`, `SchoolAbsenceRecorded`, `SchoolAbsenceUndone`, `SchoolAdminOverrideStaff`.

Config: `config/school.php` (all M7 tunables + geofences + escalation timings + limits).

Routes: `routes/api.php` (parent + vehicle), `routes/admin.php`, `routes/school.php`.

Views: `resources/views/admin/school/**`, `resources/views/school/**`, `resources/views/school/help/how-it-works.blade.php`.

Assets: `public/assets/admin/js/{school-live,route-builder,calendar-grid,control-tower}.js` + CSS, `public/assets/admin/img/school-trip-diagram.svg`.

**Parent app** (`Zippi-Parent-Android`)
`FamilyDashboardActivity.kt`, `ChildLiveActivity.kt` (map + bottom sheet, enterprise L29 pattern), `ChildJourneyActivity.kt`, `MarkAbsentDialog.kt`, `HandoverCodeView.kt`, `MyFirebaseMessagingService.kt` (one handler per type), `models/FamilyDashboardResponse.kt`, `retrofit/ApiInterface.kt`, layouts and drawables.

**Vehicle app** (`Zippi-Vehicle-Android` — driver + attendant modes, one binary, role-gated)
`DutyDashboardActivity.kt`, `TodayTripsActivity.kt` (swipe-to-begin), `PreTripChecklistActivity.kt`, `SchoolTripFlowActivity.kt` (morning + afternoon modes, escalation banner, hard back blocks), `StopAdapter.kt`, `ChildStopAdapter.kt`, `HandoverKeypadDialog.kt`, `AuthorizedPersonPickerDialog.kt`, `SweepConfirmActivity.kt` (camera + geo), `SosWidget.kt`, `NavigationActivity.kt` (driver mode, speed readout), `OfflineQueue.kt` (K6), `models/`, `retrofit/ApiInterface.kt`, `firebase/MyFirebaseMessagingService.kt`.

---

# Out of Scope (Phase 1)

- Payment gateway integration for transport fees (ledger + invoices only — S2).
- In-app messaging between staff and parents (deliberately excluded — K16).
- Facial recognition or RFID/NFC boarding. **Consider RFID in Phase 2** — it removes the attendant mis-tap class entirely and schools ask for it; it needs hardware procurement and a fallback path for a lost card, so it is not a Phase-1 scope.
- CCTV video streaming to parents (recording + retention only; live parent video is a safeguarding minefield).
- Automatic route optimization at trip time (design-time only — G1).
- Cross-school child transfers.
- Parent-initiated stop change without school approval.
- Multi-language beyond English + one regional language at the pilot.
- Web push to the ops browser (in-page alerts only).
- Native iOS at Phase 1 (Android first — verify the pilot school's parent device mix before committing; if iOS share exceeds ~20%, this moves into Phase 1).
- Public API for school ERP integration (Phase 3; expect every school to ask, and expect their ERP to be the real integration cost).

---

# Open decisions (need a call before build starts)

1. **Attendant mandatory?** Some smaller schools run without one. `allow_driver_child_marking` exists as an escape hatch, but confirm Phoenix Greens has attendants on every bus before designing around the two-device model.
2. **Handover strictness at Phoenix Greens.** Full code verification on every child every day is correct and is also 22 code entries per stop-heavy route. Confirm the school wants strict mode from day one, or start with the authorized-person photo path as the default and code as the fallback.
3. **Self-release grade threshold.** Needs the school's policy and signed consents on file before the flag is enabled for anyone.
4. **Who owns the parent relationship** in the managed-mobility model — Zippi or the school? Determines the app's branding, the support number in every notification, and who a parent calls at 7:15 AM. Decide before the first notification template is written.
5. **Device provisioning.** School-owned tablets on the bus, or staff phones? Affects the offline cache, the camera requirement for the sweep, and the MDM story.
6. **Retention default.** 7 years is proposed. Confirm against the school's own policy and any state requirement.
