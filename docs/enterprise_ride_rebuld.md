# Enterprise Module — Cancellation, Night Shifts, Admin Tracking, History

## ⚠ Critical Regression Fix Carried Forward

A previously fixed bug **must not return**: pressing the back button inside the driver's in-ride screen used to land on the "Start Ride" screen and let the driver create a duplicate ride for the same shift. Three independent defenses block this:

1. **Back stack cleanup (client)**: when `EnterpriseUserList` successfully POSTs `/start-ride`, it finishes itself AND finishes `EnterpriceRideSchdule` before launching `EnterpriseRideFlowActivity`. The intent uses `FLAG_ACTIVITY_NEW_TASK | FLAG_ACTIVITY_CLEAR_TASK` so no schedule/list screen is reachable via back.
2. **Back-press confirmation (client)**: `EnterpriseRideFlowActivity.onBackPressed` does NOT call `super.onBackPressed()` unconditionally. It branches: if OTP/employee is selected → revert state, stay; if nothing selected → show "End shift?" dialog. Only an explicit confirm exits.
3. **Server-side guard (defense in depth)**: even if a driver somehow reaches `EnterpriseUserList` and taps Start Ride again, `Rule A` (Part A2) rejects the request because an `EnterpriseRide` with `status='started'` already exists for this (driver, shift_date, direction). The duplicate-ride attempt returns a 4xx with a clear message.

All three must be implemented and tested before this rollout ships. Part I and verification tests #46–52 enforce this.

## Context

User asked:
1. How does an employee cancel for a day (leave)?
2. What if they cancel after the driver starts the ride?
3. How does Uber / industry handle this?
4. What if employee doesn't show up at pickup?
5. How should admin track every ride in realtime?
6. How do night shifts work?
7. Should ride history use one big table or many?
8. What if employee wants to be picked up from home but NOT dropped back (they're going elsewhere)?

This plan covers four parts:
- **A**: Cancellation flow (asymmetric direction support, range, undo, no-show)
- **B**: Admin live-tracking dashboard (company-first)
- **C**: Night-shift support
- **D**: Ride history / records (no new tables — admin views on existing data)

**User constraints:**
- Employee CANNOT cancel an ongoing (started) ride.
- Employee CANNOT cancel any ride within 30 min of shift start.
- Employees CAN apply leave for a range of days.
- Employees CAN cancel one direction (Pickup OR Drop) independently — not forced to skip both.
- Admin tracks all rides — filtered by company first.
- Night shifts must work end-to-end.

---

## Current State (already built — no changes needed)

- **Cancel endpoint** `EnterpriseRideController.employeeCancelRide` — accepts employee_id, direction, date, reason. Blocks only after driver-arrived.
- **Tables**: `enterprise_rides`, `enterprise_employee_rides`, `enterprise_employee_cancel_rides`. All persist after ride completion. Cancellations already keyed by (employee, date, **direction**) — asymmetric leave is naturally supported by the schema.
- **Employee table** has `shift_start`, `shift_end` (TIME). When `shift_end < shift_start`, it's a night shift.
- **Driver schedule screen** allows selecting yesterday for Drop shifts (night-shift workers).
- **Firebase realtime DB**: every driver's coords at `ID/{driver_id}`, 5-sec writes.

---

## PART A — Cancellation Flow

### A1. Reword "Cancel Ride" → "Apply Leave"; default to tomorrow
**Files**: `Zippi-User-Android-main/.../activity_car_dashboard.xml`, `dialog_cancel_ride.xml`, `CarDashboardActivity.kt`. No backend.

### A2. Block when ongoing OR past the per-direction cutoff
For each requested (date, direction), apply both rules:
- **Rule A — Ongoing block**: If an `EnterpriseRide` with `status='started'`, this employee in `employee_ids`, matching shift_date AND direction → reject.

- **Rule B — Cutoff (today only)**, different formula per direction:

  - **Pickup**: time-based cutoff = `shift_start − 120 min` (2 hours). Strict: driver routes around each home individually, so a late cancellation wastes the planned trip and re-route time. If `now() ≥ shift_start − 120 min` → reject.

  - **Drop**: **state-based cutoff** — not a fixed time. Allowed until ANY of these become true for this (employee, shift_date, Drop):
    1. Driver has marked this employee as **boarded** at the office (they're in the vehicle).
    2. Driver has marked this employee as **no-show** at office (only available from `shift_end + 12 min` onwards — see Part A6).
    3. The ride is already completed for this employee.
    
    First action wins — the employee can cancel any time before the driver acts, even past `shift_end + 12 min`. The `+12 min` mark only enables the driver's no-show button; it does NOT close the cancel option for the employee. If the driver marks no-show first, the cancel button disappears from the employee's app (push-driven UI update) and they see a prompt:
    
    > "Your evening Drop for [date] has been marked as no-show because you did not board the vehicle."

Plain English:
- "If office is at 8 AM, you can only cancel the morning **Pickup** before 6 AM (2 hours before shift_start)."
- "For the evening **Drop**: you can cancel any time until the driver either marks you boarded OR marks you no-show. The driver gets the no-show option starting 12 min after shift end."

Each (date, direction) pair is checked independently — Pickup can be allowed while Drop is blocked, and vice versa.

**Files**: `EnterpriseRideController.php` (`employeeCancelRide`), `config/enterprise.php` (`pickup_cutoff_minutes => 120`, `drop_noshow_buffer_minutes => 12`).

### A3. Range leave + asymmetric directions
Dialog has two checkboxes: ☐ Pickup ☐ Drop (at least one required) + From/To date pickers. Backend accepts:
```json
{
  "employee_id": 11,
  "start_date": "2026-05-21",
  "end_date":   "2026-05-25",
  "directions": ["Pickup", "Drop"],   // 1 or 2 entries
  "reason":     "Vacation"
}
```
Backend expands to (date × direction) pairs, applies Rules A + B to each independently, inserts one row per pair in a DB transaction. Cap range at 30 days. If any pair fails, reject the entire request with a list of which (date, direction) pairs failed.

**Asymmetric example**: Employee picks "Pickup" only for May 21 → backend creates one row (shift_date=May 21, direction=Pickup). The May 21 Drop still runs normally — they can use the company shuttle home but skip the morning pickup, OR be picked up from home but find their own way back.

**Files**:
- `EnterpriseRideController.php` (`employeeCancelRide` signature change).
- `Zippi-User-Android-main/.../dialog_cancel_ride.xml` — two date pickers + two direction checkboxes + summary text.
- `Zippi-User-Android-main/.../CarDashboardActivity.kt` — checkbox handlers, helper text "Applying leave for N day(s) × M direction(s)".
- `Zippi-User-Android-main/.../ApiInterface.kt` — updated signature with `directions: List<String>`.

### A4. Undo cancellation (per direction) + driver-action UI states
Undo accepts the same `directions` array. Subject to same Rules A + B (and only undoable if `cancelled_by='employee'`).

The user app shows a different banner depending on `cancelled_by`:

| `cancelled_by` value | Banner style | Banner text | Cancel button | Undo button |
|----------------------|--------------|-------------|---------------|-------------|
| `employee` | Yellow | "Leave applied: Pickup + Drop, May 21–25" | Hidden for affected dates | Visible (subject to Rules A + B) |
| `driver_no_show` | Red | "Your [Pickup/Drop] for [date] has been marked as no-show. You did not [board the vehicle / be available at pickup]." | Hidden for that (date, direction) | NOT visible (admin must reverse) |
| `admin` | Grey | "Cancelled by admin: [reason]" | Hidden | NOT visible |

Banner examples:
- "Leave applied: Pickup + Drop, May 21–25" (employee, both directions, range)
- "Leave applied: Pickup only, May 21" (employee, asymmetric)
- "Your evening Drop for May 21 was marked as no-show" (driver_no_show)

**Files**:
- `EnterpriseRideController.php` — new `undoCancelRide(start_date, end_date, directions[])`.
- `EnterpriseController.php` — `getDriverFullDetails` returns `upcoming_leaves: [{start_date, end_date, directions, count}]` grouped by consecutive runs of same (employee, directions).
- `app/Notifications/EnterpriseEmployeeUncancelledNotification.php` — new.
- `routes/api.php` — 1 new route.
- `Zippi-User-Android-main/.../DriverFullDetailsResponse.kt`, `CarDashboardActivity.kt`, `activity_car_dashboard.xml`, `ApiInterface.kt`.
- `Zippi-Driver-Android-main/.../MyFirebaseMessagingService.java`, `EnterpriseRideFlowActivity.kt`.

### A5. Auto-prompt end shift when all cleared
After cancel/no-show, if every employee is cancelled/no_show with none completed → "All cleared. End this shift?" → calls existing `completeRide`. Driver-app only.

**File**: `EnterpriseRideFlowActivity.kt`.

### A6. Driver "Mark No-Show" — direction-aware

The no-show button uses different timing rules per direction, matching the cutoffs in Rule B:

**Pickup no-show** — at employee's home:
- Driver taps "Arrived" at the employee's home → OTP pushed → server-anchored 6-min countdown starts (anchor = `enterprise_employee_rides.created_at`).
- After 6 min elapses, red "Mark No-Show" button appears next to Verify.
- Backend `markNoShow` enforces the 6-min minimum, deletes pending row, inserts cancel row with `cancelled_by='driver_no_show'`.

**Drop no-show** — at office:
- For Drop rides, when the driver opens `EnterpriseRideFlowActivity` at the office, each expected employee shows up with a "Boarded" checkbox (visible only before route starts).
- Driver checks each as they enter the vehicle.
- A server-anchored countdown shows time-since-`shift_end`. Before `shift_end + 12 min`, employees can still cancel or board.
- At `shift_end + 12 min`, a red "Lock In & Start Drop" button appears. Tap → every employee NOT checked as boarded gets marked no-show in one batch → driver begins the route.
- Backend `markNoShowBatch` (Drop only) accepts a list of employee IDs to mark no-show; validates ride direction = Drop and `now() ≥ shift_end + 12 min`.

**Both flows** record `cancelled_by='driver_no_show'` so HR reports treat them uniformly, and notify the affected employees via push.

**Files**:
- `EnterpriseRideController.php` — `markNoShow` (Pickup, per-employee), `markNoShowBatch` (Drop, multi-employee at office). Both share the cancel-row insert logic.
- `app/Notifications/EnterpriseEmployeeNoShowNotification.php` — new (used by both).
- `routes/api.php` — 2 new routes.
- `Zippi-Driver-Android-main/.../ApiInterface.kt` — `markNoShow`, `markNoShowBatch`.
- `Zippi-Driver-Android-main/.../EnterpriseRideFlowActivity.kt` — branches on `shift==Drop`: shows "Boarded" checkboxes + Lock-In button at office; Pickup keeps existing per-employee 6-min flow.
- `Zippi-Driver-Android-main/.../EnterpriseEmployeeRideAdapter.kt` — `"no_show"` and `"boarded"` states.
- `Zippi-Driver-Android-main/.../activity_enterprise_ride_flow.xml` — Boarded checkbox per row, "Lock In & Start Drop" button, countdown TextView.

---

## PART B — Admin Live Tracking

### B1. Company-first selection
- **Page 1** `/admin/enterprise/live-rides` — list of companies (cards with "N active rides" badge). Click → page 2.
- **Page 2** `/admin/enterprise/live-rides/{company_id}` — live dashboard scoped to that company.

### B2. Live dashboard (page 2)
- Header: company name + back + direction toggle (All / Pickup / Drop).
- Top half: Google Maps with driver markers (Firebase subscriptions on `ID/{driver_id}`).
- Bottom half: scrollable ride cards (driver name, vehicle, direction, started_at, "X of Y picked", current employee status). Click card → ride detail modal.

### B3. Realtime strategy
- Driver locations → Firebase JS SDK (5-sec cadence, matches LocationService).
- Ride state → poll `/admin/api/enterprise/companies/{id}/live-rides` every 8 sec, diff render.
- New cancel/no-show/complete events since last poll → corner toast.

### B4. JSON shape (live rides for a company)
```json
{
  "rides": [
    {
      "ride_id": 42,
      "driver": {"id": 7, "name": "...", "vehicle_no": "...", "latitude": "...", "longitude": "..."},
      "direction": "Pickup",
      "shift_date": "2026-05-21",
      "started_at": "2026-05-21T08:05:00Z",
      "stops": [
        {"employee_id": 11, "name": "Rahul", "lat": "...", "lng": "...", "status": "picked", "arrived_at": "...", "verified_at": "..."},
        {"employee_id": 12, "name": "Priya", "lat": "...", "lng": "...", "status": "arrived", "arrived_at": "..."},
        {"employee_id": 13, "name": "Sam",   "lat": "...", "lng": "...", "status": "pending"}
      ],
      "progress": {"picked": 1, "total_active": 3, "cancelled": 0, "no_show": 0}
    }
  ]
}
```

### B5. Files (Part B)
- `app/Http/Controllers/Admin/EnterpriseRideController.php` — `companyPicker()`, `liveRidesPage($companyId)`, `liveRidesJson($companyId)`, `rideDetailJson($id)`.
- `resources/views/admin/enterprise/live-rides-picker.blade.php`, `live-rides.blade.php` — new pages.
- `resources/views/admin/layouts/sidebar.blade.php` — sidebar entry.
- `routes/web.php` — admin routes under existing admin middleware.
- `public/assets/admin/js/enterprise-live-rides.js`, `enterprise-live-rides.css` — new.

---

## PART C — Night Shift Support

### C1. Concept: shift_date
Every ride and every cancellation row has a `shift_date` — the calendar date the **shift** belongs to, regardless of when each direction actually runs.

- Day-shift employee (shift_end > shift_start): Pickup runs `shift_date` morning, Drop runs `shift_date` evening. Both anchored to shift_date.
- Night-shift employee (shift_end < shift_start): Pickup runs `shift_date` evening, Drop runs `shift_date + 1` morning. Both still anchored to `shift_date`.

The shift_date is what employees and admins think of as "the day I worked." Each direction is still independent — the asymmetric leave from Part A3 works the same way for night-shift employees.

### C2. Schema changes
- `enterprise_rides` — add `shift_date DATE NULL`. Migration backfills `shift_date = started_at::date` for Pickup rides, `started_at::date - 1` for Drop rides where the driver is assigned to a night-shift employee (best-effort backfill; for active rides going forward, set explicitly).
- `enterprise_employee_cancel_rides` — add `shift_date DATE NULL`. Migration backfills `shift_date = date` (rename intent only; existing `date` becomes derived).
- Keep `date` column for backward compatibility, but `shift_date` is the source of truth going forward.

**Migration**: `staging/database/migrations/2026_05_XX_add_shift_date_to_enterprise_tables.php`.

### C3. Backend logic
- `startRide` computes `shift_date`:
  - Pickup → today.
  - Drop, day-shift → today.
  - Drop, night-shift → today - 1 (the shift this drop completes).
- `employeeCancelRide` accepts `start_shift_date` + `end_shift_date` (clearer than `start_date`). One row per (shift_date × direction).
- `getDriverRoute` and `startRide` filter cancellations by `shift_date` (not `date`).
- Rule A queries `EnterpriseRide` by `shift_date = $request->shift_date` AND `direction = $direction` — Pickup/Drop checked independently.
- **Pickup** is a fixed time-based cutoff: `shift_start − 120 min`. **Drop** is state-based — employee can cancel until the driver takes a terminal action (boarded / no-show / completed). The driver's no-show option opens at `shift_end + 12 min`.

**Cutoff matrix — applies to every ride:**

| Direction | Shift type | Cutoff type | Example | When cancel closes |
|-----------|------------|--------------|---------|---------------------|
| Pickup    | Day        | Time-based: `(shift_date + shift_start) − 120 min` | shift_date=Wed, shift_start=08:00 | **Wed 06:00** — cancel before 6 AM |
| Drop      | Day        | State-based: until driver acts (boarded / no-show / completed) | shift_date=Wed, shift_end=18:00, driver gets no-show option from **Wed 18:12** | Whenever driver acts — could be 18:13, 18:30, or whenever they tap the lock-in |
| Pickup    | Night      | Time-based: `(shift_date + shift_start) − 120 min` | shift_date=Wed, shift_start=21:00 | **Wed 19:00** — cancel before 7 PM |
| Drop      | Night      | State-based: until driver acts | shift_date=Wed, shift_end=06:00 next day, driver no-show option from **Thu 06:12** | Whenever driver acts (next morning) |

Shift type detection: if `shift_end < shift_start`, it's a night shift; the Drop runs on `shift_date + 1`. Pickup is always anchored to `shift_date`.

The time anchor is `shift_start` / `shift_end` (employee's work time, stored in `enterprise_employees`), NOT `driver_pickup_at` / `driver_drop_at` (driver's per-employee times in `enterprise_assigned_drivers`).

**Why Drop is state-based (vs Pickup's fixed-time cutoff):**
- Pickup: driver builds a route. Late cancellations force re-routing or wasted detours. Strict fixed cutoff.
- Drop: driver MUST go to the office regardless (other employees board there). Late "I'll find my own way home" doesn't disrupt anything. The race between employee-cancel and driver-no-show resolves on first action: whoever moves first wins, the loser sees the result in real-time via push.

**Race-condition example (Drop, 18:00 shift_end):**
- 17:55: Employee X opens app, cancel button visible.
- 18:13: Driver gets no-show option (12 min past shift_end). Hasn't tapped yet.
- 18:14: Employee X taps Cancel → backend confirms driver hasn't acted → cancel row inserted with `cancelled_by='employee'` → push to driver → driver's no-show button for X disappears.
- (Alternative)  18:14: Driver taps "Mark No-Show" for X first → backend writes cancel row with `cancelled_by='driver_no_show'` → push to employee X → X's cancel button disappears, X sees: "Your evening Drop for [date] has been marked as no-show."

**Real-time propagation (already wired):**
1. `employeeCancelRide` (or `markNoShowBatch`) writes the cancel row in DB.
2. Same request sends `EnterpriseEmployeeCancelledNotification` or `EnterpriseEmployeeNoShowNotification` FCM push to the affected party.
3. Receiving app translates into a LocalBroadcast → activity updates UI immediately.

No new push infrastructure — just ensuring both directions of the race trigger the same broadcast pipeline.

Each (date × direction) cancellation runs this calculation independently — within a single range-leave request, every pair is evaluated on its own.
- `getDriverFullDetails.upcoming_leaves` groups by (start_shift_date, end_shift_date, directions[]).

### C4. User app — night-shift helper text
When dialog opens, detect if employee is night-shift (compare `shift_start` & `shift_end`). If yes, show italic note under direction checkboxes:
> "You're on a night shift. Pickup runs the evening of the selected date; Drop runs the next morning."

This avoids confusing the employee about which calendar date their drop happens on.

### C5. Driver app
- Schedule screen: replace the "allow yesterday for Drop" hack with proper `shift_date` selection (radio: Today's shift / Yesterday's shift if it's morning and a Drop is pending). Driver picks shift_date, app shows the right ride.
- All driver-app API calls send `shift_date` explicitly.

### C6. Admin live tracking
Dashboard cards group by `shift_date`. A night-shift Drop running 6 AM Thursday displays under "Wednesday's shift." Current actual time shows on the card; shift_date shows in the section header.

### C7. Files (Part C)
- `staging/database/migrations/2026_05_XX_add_shift_date.php` — new.
- `staging/app/Http/Controllers/Api/EnterpriseRideController.php` — `startRide`, `employeeCancelRide`, `markNoShow`, `completeRide`.
- `staging/app/Http/Controllers/Api/EnterpriseController.php` — `getDriverRoute`, `getDriverFullDetails`.
- `staging/app/Models/EnterpriseRide.php`, `EnterpriseEmployeeCancelRide.php` — `shift_date` cast.
- `Zippi-Driver-Android-main/.../EnterpriceRideSchdule.kt` — shift_date selector.
- `Zippi-Driver-Android-main/.../retrofit/ApiInterface.kt` — `shift_date` param on relevant calls.
- `Zippi-User-Android-main/.../CarDashboardActivity.kt` — night-shift helper text in dialog.

---

## PART D — Ride History / Records

### D1. Decision: no new tables
Existing tables already store everything. `enterprise_rides`, `enterprise_employee_rides`, `enterprise_employee_cancel_rides` persist forever and join cleanly by driver / company / employee / shift_date. New tables would duplicate data and create sync risk.

When to revisit: 10M+ rows (years away), compliance audit log requirement, or pre-computed monthly aggregates for slow reports.

### D2. Add indexes
**Migration**: `2026_05_XX_add_history_indexes.php`
```sql
CREATE INDEX idx_rides_company_shift  ON enterprise_rides(company_id, shift_date);
CREATE INDEX idx_rides_driver_shift   ON enterprise_rides(driver_id, shift_date);
CREATE INDEX idx_emp_rides_employee   ON enterprise_employee_rides(employee_id, created_at);
CREATE INDEX idx_emp_rides_ride       ON enterprise_employee_rides(ride_id, employee_id);
CREATE INDEX idx_cancels_employee     ON enterprise_employee_cancel_rides(employee_id, shift_date);
CREATE INDEX idx_cancels_employee_dir ON enterprise_employee_cancel_rides(employee_id, direction, shift_date);
```

### D3. Admin history pages
All read from existing tables:
- `/admin/enterprise/rides` — paginated table. Filters: company, driver, employee, direction, status, shift_date range.
- `/admin/enterprise/rides/{id}` — full detail: stops with timestamps, OTP verification times, cancellations, route replay.
- `/admin/enterprise/employees/{id}/history` — employee's ride history + leaves + no-shows (HR-friendly).
- `/admin/enterprise/drivers/{id}/history` — driver's shifts, total rides, total km, no-show events.
- `/admin/enterprise/companies/{id}/history` — company aggregates: rides per month, attendance rate.

### D4. Reports
Aggregated monthly dashboards deferred — add later if needed.

### D5. Files (Part D)
- `staging/database/migrations/2026_05_XX_add_history_indexes.php` — new.
- `staging/app/Http/Controllers/Admin/EnterpriseRideController.php` — `ridesIndex`, `rideDetail`, `employeeHistory`, `driverHistory`, `companyHistory`.
- `staging/resources/views/admin/enterprise/rides/index.blade.php`, `show.blade.php` — new.
- `staging/resources/views/admin/enterprise/employees/history.blade.php` — new.
- `staging/resources/views/admin/enterprise/drivers/history.blade.php` — new.
- `staging/resources/views/admin/enterprise/companies/history.blade.php` — new.
- `staging/routes/web.php` — admin routes.
- `staging/resources/views/admin/layouts/sidebar.blade.php` — "Ride History" entry.

---

## PART E — Admin Panel Clarity (Pickup / Drop Semantics)

### E1. Confirmation: data model is correct
- `enterprise_companies.latitude` + `longitude` = office address. One pair per company. Used as: **destination** of Pickup rides, **origin** of Drop rides.
- `enterprise_employees.latitude` + `longitude` = employee's home address. One pair per employee. Used as: **origin** of Pickup rides, **destination** of Drop rides.
- `enterprise_employees.pickup_drop_latitude` / `pickup_drop_longitude` (already exists) = optional override if the employee wants to be picked up/dropped at a different point than their home address (e.g., the colony gate instead of the door). Backend already prefers this when present (see `EnterpriseRideController.startRide` line ~304).

**No schema changes.** The directionality is fully captured by the existing tables. The issue is that admin form labels currently say things like "Pickup Address" and "Drop Address" without clarifying which side of the trip they belong to.

### E2. Consistent terminology across admin forms

Adopt this vocabulary throughout the admin panel, sidebar, and any export/report:

| Term | Definition |
|------|------------|
| **Office** | The company location. Pickup destination, Drop origin. |
| **Home** | The employee location. Pickup origin, Drop destination. |
| **Pickup ride** | Direction = Pickup. Driver visits each employee's home in nearest-neighbor order, ends at office. Runs morning (day shift) or evening (night shift). |
| **Drop ride** | Direction = Drop. Driver starts at office with all boarded employees, visits each home in order. Runs evening (day shift) or next morning (night shift). |
| **Shift date** | The calendar date the shift starts. For night shifts, the Drop runs the following calendar day but is still anchored to this shift_date. |

### E3. Admin form changes

**Company form** (`/admin/enterprise/companies/create` and edit):
- Rename "Address / Latitude / Longitude" field group to **"Office Location"** (highlighted with a building icon if available).
- Helper text below the field: "This is the office address. All employees of this company are picked up here in the morning and dropped here in the evening."
- The map picker should drop the pin at the office's main gate.

**Employee form** (`/admin/enterprise/employees/create` and edit):
- Rename the primary lat/lng group to **"Home Location"** (with a house icon).
- Helper text: "This is the employee's home — where the driver picks them up in the morning and drops them in the evening."
- Rename `pickup_drop_address/lat/lng` to **"Alternate Pickup/Drop Point (optional)"**.
- Helper text on the alternate point: "Use this if the employee wants the driver to wait at a nearby landmark (e.g., the apartment gate or community entrance) instead of the home address. Leave blank to use the home address."
- Show a "Shift Hours" field group with `shift_start` and `shift_end`. Helper text: "If the end time is earlier than the start time (e.g., 21:00 to 06:00), this is a night shift — Pickup runs the evening of the shift date and Drop runs the next morning."

**Assign Driver form** (`/admin/enterprise/drivers/{id}/assign-employee` or wherever):
- `driver_pickup_at` field — relabel to **"Driver arrives at employee's home"**. Helper text: "The time the driver should arrive at this specific employee's home to start the morning pickup. Should be slightly before the employee's shift_start, allowing for travel time to the office."
- `driver_drop_at` field — relabel to **"Driver leaves office with this employee"**. Helper text: "The time the driver picks up this employee from office for the evening drop. Usually just after the employee's shift_end."
- Add a small visual diagram at the top of the form (described in E4).

### E4. "How Pickup/Drop Works" visual reference

Add a static SVG (or PNG) at the top of the relevant admin forms showing the two flows. Two-row diagram:

```
  Pickup (Morning):
  🏠 Home A  →  🏠 Home B  →  🏠 Home C  →  🏢 OFFICE
  (nearest-neighbor from driver's start position)

  Drop (Evening):
  🏢 OFFICE  →  🏠 Home C  →  🏠 Home B  →  🏠 Home A
  (nearest-neighbor from office)
```

Also link to a brief help page `/admin/help/enterprise-flow` that covers:
- Pickup vs Drop semantics with the diagram.
- Day vs night shift behavior (shift_date, when Drop runs).
- Asymmetric leave (employee can skip just Pickup or just Drop).
- Cancellation cutoffs (2 hours before shift_start for Pickup; 12 min after shift_end for Drop).
- No-show flows for both directions.

This becomes the single reference document for the support team. Linked from every relevant admin form via a small "?" icon next to the page title.

### E5. Files (Part E)
- `staging/resources/views/admin/enterprise/companies/_form.blade.php` — relabels + helper text + icon.
- `staging/resources/views/admin/enterprise/employees/_form.blade.php` — relabels + helper text + alternate point clarifier + shift hours note.
- `staging/resources/views/admin/enterprise/assigned-drivers/_form.blade.php` (or wherever assignment form lives) — driver_pickup_at / driver_drop_at relabels + diagram.
- `staging/resources/views/admin/help/enterprise-flow.blade.php` — new help page with the full reference.
- `staging/public/assets/admin/img/pickup-drop-diagram.svg` — new diagram asset.
- `staging/resources/views/admin/layouts/sidebar.blade.php` — link to help page under Enterprise section.
- `staging/routes/web.php` — route for the help page.

No backend or DB changes.

---

## PART F — "Driver On The Way" Notification (both directions)

### F1. Per-employee push, fired by an explicit commit tap

Applies to BOTH Pickup and Drop. The dispatcher "Start Ride" tap in `EnterpriseUserList` only creates the ride (`/start-ride` → `ride_id`). Per-employee "on the way" pushes happen inside `EnterpriseRideFlowActivity` when the driver picks a specific person to service next.

**Pickup direction state machine** (OTP generated at Start Ride, NOT at Arrived):
```
pending → tap card → SELECTED → tap "Start Ride" → EN_ROUTE
                                  POST /notify-en-route (Pickup variant)
                                  Backend: generate 4-digit OTP,
                                           create enterprise_employee_rides row
                                           with pin_verified=0
                                  Push to employee: "Driver started ride
                                                     to your home. Your
                                                     boarding OTP is 1234.
                                                     Show it to the driver
                                                     when they arrive."
                              → tap "Arrived at [Name]" → ARRIVED
                                  POST /ride-arrived
                                  Backend: geo-fence check (1km), update
                                           arrived_at timestamp, start
                                           server-anchored 6-min no-show
                                           countdown.
                                  NO push to employee (they already have OTP)
                              → driver enters OTP shown by employee → VERIFY
                                  POST /ride-otp-verify → PICKED
```

Why OTP at Start Ride and not at Arrived:
- Employee gets the OTP 5–10 min before driver pulls up — time to find/screenshot/remember it.
- Driver doesn't wait for an OTP push to arrive (FCM lag) before knocking on the door.
- The 6-min no-show timer still anchors to Arrived (not Start Ride) — driver is now at the location and waiting for the employee to come out.

**Drop direction state machine** (no OTP — geo-fence only):
```
boarded → tap card → SELECTED → tap "Start Drop" → EN_ROUTE
                                  POST /notify-en-route (Drop variant)
                                  Push to employee: "Driver has started
                                                     the drop ride to your
                                                     home. Please be ready."
                              → tap "Mark Dropped" → DROPPED
                                  POST /ride-drop-mark + geo-fence (500m)
                                  Push: "You've been dropped at home"
```

Three taps per employee for Pickup. Two taps per employee for Drop.

Same one-at-a-time rule applies to both directions — see F2.

If the driver changes mind, they tap back to revert. The pre-generated OTP for the previous employee is invalidated (delete the `enterprise_employee_rides` row); next time they Start Ride, a fresh OTP is generated. The employee's banner with old OTP auto-expires after 30 min — even if they tried it, the backend would reject it (row doesn't exist).

### F2. One employee at a time — no multi-select
The driver can only be servicing ONE employee at any moment. While any employee is in `en_route` or `arrived` state, the other pending employee cards become **non-tappable** (greyed). This prevents the driver from accidentally firing "on the way" pushes to multiple employees in parallel — a single driver physically can only be heading to one place.

Behavior per state:
- All pending, none en_route → all pending cards tappable.
- Driver taps Employee A → A becomes `selected`. Other pending cards still tappable (free to change mind before committing).
- Driver taps "Start Ride" → A becomes `en_route` (push fires). **Other pending cards now non-tappable.** Only A's "Arrived" button is active.
- Driver taps "Arrived" → A becomes `arrived`. Other pending cards still non-tappable. Driver must verify OTP or wait 6 min for no-show.
- A is picked / no-show / cancelled → cards re-enable for the rest.

If the driver wants to switch from A back to a pending state (changed their mind, A wasn't ready), they tap the back arrow on the header → confirm dialog "Switch from A? They will go back to pending and the 'on the way' notification will be invalidated." → A reverts to `pending`, no DB change needed, other cards re-enable. (No retraction push is sent to A — the green banner just expires after 30 min.)

### F3. Two distinct user-side signals — keep them separate

There are TWO things an employee can see, triggered at different points:

**(1) Live map tracking — enabled for ALL selected employees as soon as the overall ride starts** (i.e., the first "Start Ride" tap by the driver in `EnterpriseUserList`):
- The backend marks the ride `status='started'`.
- The user-app `getDriverFullDetails` poll now returns `has_active_ride=true` for every employee in that ride.
- `EnterpriseRideStatusActivity` becomes accessible — they can see driver's live position on the map (Firebase subscription on `ID/{driver_id}`, already wired).
- The "Stop X of Y" card appears on `CarDashboardActivity`.
- **No green banner yet.** No push yet for any employee.

**(2) Green "Driver on the way" banner + push — fires ONLY for the one employee the driver just committed to** (i.e., the per-employee "Start Ride" / "Start Drop" tap inside `EnterpriseRideFlowActivity`):
- Backend `/notify-en-route` sends push only to that one employee.
- That employee's app shows the green banner.
- Other assigned employees: silent. They can still see the driver moving on the map (signal #1 is still active for them), but they don't get the targeted "you're next" notification.

Plain English: "All assigned employees can track the driver on the map once the ride starts. Only the employee the driver explicitly picked next gets the 'on the way' notification."

When the driver finishes with that employee (picked / dropped / no-show) and selects the next one, the next employee gets their push. The previous employee's banner auto-expires after 30 min.

### F3a. Show ETA ONLY to the currently-serviced employee

ETA is calculated as driver-location → employee's home. If the driver is heading to Employee A but Employee B's app shows "Driver arrives in 4 min", B will think their pickup is imminent — wrong, the driver is going to A.

**Rule**: only the employee currently being serviced (`en_route` or `arrived`) sees the ETA card. Everyone else sees a different state.

**Schema**:
- `enterprise_rides.current_servicing_employee_id` — new nullable column. Set by `/notify-en-route` to the selected employee's id. Cleared (set NULL) when that employee transitions to `picked` / `dropped` / `no_show` / `cancelled`, OR when driver switches to a different employee (which updates it to the new id). Reset on `completeRide` / ride end.
- Migration: `2026_05_XX_add_current_servicing_employee_to_enterprise_rides.php`.

**Backend**:
- `/notify-en-route` writes `current_servicing_employee_id = $request->employee_id` on `EnterpriseRide`.
- `verifyEmployeePin` (Pickup OTP verify), `markEmployeeDropped` (Drop confirm), `markNoShow`, `employeeCancelRide` — all clear `current_servicing_employee_id` if it matches this employee.
- `getDriverFullDetails` returns ONLY a boolean — no name leak:
  ```
  currently_servicing: {
    "is_me": true | false,
    "someone_else_active": true | false   // true if a different employee is being serviced
  }
  ```
  When `is_me=false` and someone else IS being serviced, set `someone_else_active=true`. The user app uses these two booleans to decide what banner to show, without ever learning the other employee's identity.

**User app**:
- `DriverFullDetailsResponse.kt` — add `currently_servicing` field (boolean shape above).
- `EnterpriseRideStatusActivity.kt` — branch on `currently_servicing`:
  - `is_me=true` → show green/amber banner + OTP card + ETA + map (driver heading to me).
  - `is_me=false`, `someone_else_active=true` → show neutral grey banner: **"Driver is currently picking up another employee. You're stop [X] of [Y]."** + map. NO ETA card (it would be wrong). NO name.
  - both false → "Driver has not started servicing anyone yet" / hide tracking-specific UI.
- Same logic on `CarDashboardActivity`.

**Privacy**: employee names never leak across the API to other employees. Each employee only ever knows: (a) they themselves are being serviced, or (b) someone else (anonymous) is being serviced, or (c) no one is. This is stricter than needed for enterprise co-workers but is the safer default and avoids any HR/policy concerns.

### F4. Backend — notify endpoint + endpoint split for Pickup OTP

**`POST /api/enterprise/rides/notify-en-route`** (the per-employee commit tap):
- Validates: driver owns ride, employee in `employee_ids`, employee not in a terminal state.
- Reads `ride.direction`:
  - **Pickup**: generates 4-digit OTP, creates `enterprise_employee_rides` row with `pin_verified=0`, status=`en_route`, sends `EnterpriseDriverEnRouteNotification` with the OTP embedded in the body and FCM data: `notification_type=Enterprise_Driver_EnRoute`, `ride_id`, `employee_id`, `direction=Pickup`, `driver_name`, `otp`.
  - **Drop**: no OTP generated. Sends the same notification class but with the Drop body text and `otp=null`. No DB row needed.
- Also updates `enterprise_rides.current_servicing_employee_id` to this employee (see F3a).
- If driver switches commit to a different employee mid-route, this endpoint is called again — the previous `enterprise_employee_rides` row (Pickup only) is invalidated/deleted before creating the new one.

**`POST /api/enterprise/rides/ride-arrived`** (Pickup only, NEW — replaces the OTP-send portion of `/ride-otp-send`):
- Validates: driver owns ride, employee in `en_route` state for this ride.
- Performs 1km geo-fence check (server-stored driver coords).
- Updates `enterprise_employee_rides.arrived_at = now()` — anchor for the 6-min no-show countdown.
- Sends `EnterpriseDriverArrivedNotification` to ONLY this employee:
  - Title: "Driver has arrived"
  - Body: "Your driver [Name] is at your location. Please show your OTP to board."
  - FCM data: `notification_type=Enterprise_Driver_Arrived`, `ride_id`, `employee_id`.
- Does NOT include OTP (employee already received it at Start Ride; we don't want to send it twice for security).
- Other employees in the ride do NOT receive this push.

**`POST /api/enterprise/rides/ride-otp-verify`** — unchanged. Verifies the OTP the driver typed.

**`POST /api/enterprise/rides/ride-mark-no-show`** — anchor changes to `arrived_at` (not `created_at`).

Net change to routes:
- Add `/notify-en-route` (new, both directions).
- Add `/ride-arrived` (new, Pickup only).
- Keep `/ride-otp-send` for backward compatibility OR remove if no other callers (verify before deleting).
- Existing `/ride-otp-verify`, `/ride-drop-mark`, `/ride-mark-no-show`, `/ride-complete` stay.

**Files**: `EnterpriseRideController.php` (new `notifyEnRoute`, `rideArrived` methods; updated `markNoShow` to anchor off `arrived_at`), `app/Notifications/EnterpriseDriverEnRouteNotification.php` (new, direction-branched, OTP for Pickup), `app/Notifications/EnterpriseDriverArrivedNotification.php` (new, Pickup only — fires from `/ride-arrived`), `routes/api.php` (2 new routes).

### F5. Driver app
- `EnterpriseRideFlowActivity.refreshUI` — branches by direction + selected employee's state:
  - Pickup + `pending` → button "Start Ride".
  - Pickup + `en_route` → button "Arrived at [Name]'s Location".
  - Drop + `boarded` → button "Start Drop".
  - Drop + `en_route` → button "Mark [Name] as Dropped".
- On "Start Ride" or "Start Drop" tap: fire `/notify-en-route` (silent, fire-and-forget), flip local state to `en_route`, button text changes.

**Files**: `Zippi-Driver-Android-main/.../retrofit/ApiInterface.kt`, `EnterpriseRideFlowActivity.kt`.

### F6. User app
- `MyFirebaseMessagingService.kt` — handle three new notification types:
  - `Enterprise_Driver_EnRoute` → broadcast `Enterprise_Driver_EnRoute` LocalIntent with `direction`, `otp` (Pickup only).
  - `Enterprise_Driver_Arrived` → broadcast `Enterprise_Driver_Arrived` LocalIntent.
  - `Enterprise_Driver_Dropped` (existing/new — pushed by `/ride-drop-mark`) → broadcast `Enterprise_Driver_Dropped`.
- `CarDashboardActivity.kt` and `EnterpriseRideStatusActivity.kt` — register receivers. UI transitions:
  - On `EnRoute` (Pickup): green banner "🚗 Driver is on the way to your home — get ready" + OTP card with the received OTP.
  - On `EnRoute` (Drop): green banner "🚗 Driver has started the drop ride — be ready to disembark."
  - On `Arrived` (Pickup): banner updates to "📍 Driver has arrived at your location. Show your OTP to board." (OTP card stays visible). Background color shifts from green to bright orange/amber to signal urgency.
  - On `Dropped`: "✓ You've been dropped at home. Have a great day!" (5-sec snackbar, then ride completion flow takes over).
- Banner auto-hides after 30 min OR when next state-change push arrives.

**Files**: `Zippi-User-Android-main/.../firebase/MyFirebaseMessagingService.kt`, `CarDashboardActivity.kt`, `EnterpriseRideStatusActivity.kt`, layouts for the green/orange banner card in each activity.

### F7. Nearest-neighbor in Drop too
The backend already returns Drop stops in nearest-neighbor order (computed in `startRide` from office location). The driver app simply renders them in that order. The "🎯 NEAREST" pill from Part G applies to Drop too — shows on the first pending-or-boarded employee that hasn't been dropped yet.

---

## PART G — Nearest-Neighbor Sorting in Driver Screen

### G1. Already in backend
`startRide` and `getDriverRoute` already compute nearest-neighbor order from the driver's start position. The `stops` and `employees` arrays come back in optimal-route order. Driver app just needs to use this order (it already does for stops; verify the ride flow list also respects it).

### G2. Visual "NEAREST" indicator
On the first pending employee card in `EnterpriseRideFlowActivity`, show a small green pill: "🎯 NEAREST". This guides the driver to pick the closest one without manually checking distances.

The "first pending" calculation: iterate `employees` in order, find the first with status=`pending`. That one gets the badge. As employees are picked, the badge moves to the next.

### G3. Optional: dynamic re-sort on driver movement
On every LocationService update (every 5 sec), recompute distance from driver's current location to each pending employee. If a different employee becomes nearest (e.g., driver took a detour), re-order the list. Initial implementation: skip this — keep static order from API. Add later if drivers report the order feels stale.

**Files**: `Zippi-Driver-Android-main/.../adapters/EnterpriseEmployeeRideAdapter.kt` — add the NEAREST pill rendering for the first pending employee.

---

## PART H — Screen Flow Diagrams

### H1. Driver app — Pickup ride end-to-end

```
[Splash] → [Login (mobile OTP)] → [Dashboard]
                                       │
                                       ▼
                                 [Schedule Rides]
                                  - Pick company
                                  - Pick shift (Pickup/Drop)
                                  - Pick date (defaults today)
                                       │
                                       ▼
                                 [Route Summary]
                                  - N-stop route
                                  - "View Employees & Start Ride"
                                       │
                                       ▼
                                 [Employee List]
                                  - Select which employees
                                  - "Start Ride"
                                       │  (POST /start-ride → ride_id)
                                       ▼
                              [Enterprise Ride Flow]  ◄─────────────────┐
                              ┌─────────────────────────────────┐       │
                              │ Header: "Picking up N employees" │       │
                              │ Employee cards w/ statuses:      │       │
                              │   PENDING / SELECTED / EN_ROUTE / │       │
                              │   ARRIVED / PICKED / NO-SHOW /    │       │
                              │   CANCELLED                       │       │
                              │ NEAREST pill on first pending     │       │
                              │ Nav icon → Google Maps            │       │
                              │ (only ONE employee active at a    │       │
                              │  time — others greyed when one is │       │
                              │  EN_ROUTE or ARRIVED)             │       │
                              └─────────────────────────────────┘       │
                                       │                                  │
              Tap pending employee ────┤                                  │
                                       ▼                                  │
                              [SELECTED → "Start Ride" button shown]      │
                                       │                                  │
              Tap "Start Ride" ────────┤  (POST /notify-en-route)         │
                                       ▼  Backend generates OTP + row     │
                                          Push to ONLY this employee:     │
                                          "Driver on the way. OTP: 1234"  │
                              [EN_ROUTE → "Arrived at [Name]" button.     │
                               Other pending cards now NON-TAPPABLE]      │
                                       │                                  │
              Tap "Arrived" ───────────┤  (POST /ride-arrived, geo-fence) │
                                       ▼  No push (employee has OTP)      │
                                          arrived_at timestamp set        │
                              [ARRIVED → OTP entry + 6-min wait timer     │
                               anchored to arrived_at]                    │
                                       │                                  │
              Verify OTP ──────────────┤  (POST /ride-otp-verify)         │
                                       ▼                                  │
                              [PICKED ✓] ──────────────────────────────► │
                              Snackbar "✓ [Name] picked up"               │
                              Other cards become tappable again            │
                                                                          │
              After 6 min wait → red "Mark No-Show" appears               │
              Tap → (POST /ride-mark-no-show)                             │
              [Employee marked NO-SHOW] ─────────────────────────────────►│
                                                                          │
              When all picked/no-show/cancelled →                         │
                                       ▼                                  │
                              [Complete Shift button]                     │
                                       │ (POST /ride-complete, geo-fence) │
                                       ▼                                  │
                              [Day Shift Completed dialog]                │
                                       │                                  │
                                       ▼                                  │
                                 [Dashboard]                              │
```

### H2. Driver app — Drop ride end-to-end (differences from Pickup)

```
... Schedule → Route Summary → Employee List → Start Ride ...
                                       │
                                       ▼
                              [Drop Ride Flow at office]
                              ┌─────────────────────────────────┐
                              │ "Pick up employees at office"    │
                              │ Boarded checkboxes per employee  │
                              │ Countdown until shift_end + 12   │
                              └─────────────────────────────────┘
                                       │
              Driver checks each as they board                          
                                       │
              At shift_end + 12 min → "Lock In & Start Drop" button
                                       │
              Tap → unchecked employees auto-no-show
                                       ▼
                              [Per-employee drop flow]
                              ┌─────────────────────────────────────────┐
                              │ Cards sorted nearest-neighbor from office│
                              │ "🎯 NEAREST" pill on next boarded one     │
                              │                                           │
                              │ Tap pending boarded → SELECTED            │
                              │ Tap "Start Drop" → EN_ROUTE               │
                              │   POST /notify-en-route (Drop variant)    │
                              │   Push to this employee:                  │
                              │     "Driver has started drop to home"     │
                              │   Other boarded cards become non-tappable │
                              │                                           │
                              │ Drive to home                              │
                              │ Tap "Mark [Name] as Dropped" → DROPPED    │
                              │   POST /ride-drop-mark + geo-fence        │
                              │   Push to employee: "You've been dropped" │
                              │   Other cards re-enable                    │
                              └─────────────────────────────────────────┘
                                       │
                          Repeat for each boarded employee
                                       │
                                       ▼
                              [Complete Shift]
                              (POST /ride-complete — checks all dropped)
```

### H3. User app — Employee end-to-end

```
[Login (email OTP via company)] → [Dashboard]
                                       │
                                       ▼
                                 [Car Dashboard]
                              ┌──────────────────────────────────────┐
                              │ Driver photo + name + vehicle        │
                              │ Pickup (office) / Drop (home)        │
                              │ Stop position card (multi-emp)       │
                              │ Active leave banner (yellow)         │
                              │ No-show banner (red, if applicable)  │
                              │ "On the way" banner (green) +        │
                              │   OTP card — BOTH appear together    │
                              │   when driver taps Start Ride for me │
                              │   (NOT when driver arrives)          │
                              │                                       │
                              │ Buttons:                              │
                              │  • Track Driver Location              │
                              │  • Apply Leave                        │
                              │  • Call Support                       │
                              └──────────────────────────────────────┘
                                       │
              "Apply Leave" ──────────┤
                                       ▼
                              [Apply Leave Dialog]
                              - From / To dates
                              - ☐ Pickup ☐ Drop checkboxes
                              - Reason (optional)
                              - Submit → POST /employee-cancel-ride
                                       │
                                       ▼
                              Banner appears on Car Dashboard
                                       │
              Undo ──────────────────►│ DELETE /employee-cancel-ride-undo
                                       │
              When driver dispatches → no per-employee push yet,
              but tracking screen becomes accessible
                                       ▼
                              "Tracking available" → tap "Track"
                                       │
                                       ▼
                              [Enterprise Ride Status]
                              - Live map: driver location (Firebase)
                              - If I'm currently being serviced:
                                  green banner + OTP card + ETA
                                  (OTP arrived via push from /notify-en-route)
                              - If someone else is being serviced:
                                  neutral banner "Driver is picking up
                                   another employee" (no name shown)
                                  no ETA card (driver isn't coming to me)
                              - Recenter button
                              - Cancel button (Drop direction, before
                                driver acts)
                                       │
              When driver taps Arrived → I get a push:
              "Driver has arrived at your location. Show your OTP to board."
              Banner color shifts from green to amber for urgency.
              OTP card stays visible (same OTP I got at Start Ride).
              Other employees: no push, no banner change.
                                       ▼
              I show OTP to driver in person → driver enters → verify
              → I'm marked PICKED → push received
                                       ▼
              Ride completes (all picked / dropped) → push received
                                       ▼
                              "Ride Completed" dialog → back to Car Dashboard
```

### H4. Admin live tracking flow

```
[Admin login] → [Admin Dashboard]
                       │
                       ▼
              [Enterprise → Live Rides]
                       │
                       ▼
              [Company Picker]
              List of companies with "N active rides" badges
                       │
              Click company
                       ▼
              [Live Dashboard for Company X]
              - Map with driver markers (Firebase live)
              - Ride cards: driver, vehicle, progress
              - Direction toggle (All / Pickup / Drop)
              - Real-time updates (poll + Firebase)
              - Click card → ride detail modal w/ timeline
```

Save these diagrams as `staging/resources/views/admin/help/enterprise-flow.blade.php` (linked to from sidebar) so support team can reference them.

---

## PART J — Admin Overrides & Emergency Controls

There are real-world situations the per-direction flows can't resolve on their own: a driver's phone dies mid-ride, an OTP push never delivers, a holiday isn't on the calendar, an accident forces an emergency stop. Admin must be able to step in for every one of them.

### J1. Edge cases to cover

| # | Situation | Today (without controls) | With admin override |
|---|-----------|--------------------------|---------------------|
| 1 | Driver phone dead mid-ride | Ride stuck in `started` forever | Admin force-completes the ride; remaining employees notified |
| 2 | Driver no-show entirely | Employees wait, no recourse | Admin cancels the ride; flags driver for follow-up |
| 3 | Stuck in `arrived` state (OTP unverified, no no-show) | Stays forever | Admin force-resolves: verify on driver's behalf OR mark no-show OR cancel |
| 4 | OTP push didn't deliver to employee | Driver can't board them | Admin resends OTP push OR verbally provides OTP via support call + admin marks verified |
| 5 | Employee phone dead | Same as #4 | Same as #4 |
| 6 | Driver's GPS stale, geo-fence blocks completion | Driver stuck | Admin overrides geo-fence with a logged reason |
| 7 | Holiday / company closure not in system | Drivers still dispatched | Admin cancels ALL rides for a (company, shift_date, directions) |
| 8 | Driver quits / sick day | Assigned employees stranded | Admin reassigns the ride to another driver |
| 9 | Employee dispute ("I wasn't picked but record shows picked") | No audit trail visible | Admin reviews timeline, reverses pickup, adds note |
| 10 | Emergency (accident / illness) | No way to stop ride safely | Admin emergency-stops the ride; sends emergency notifications to all parties |
| 11 | Wrong assignment (admin error earlier) | Employee assigned to wrong driver | Admin edits assignment mid-day; new driver picks up subsequent shifts |
| 12 | `current_servicing_employee_id` stuck after driver app crash | Server thinks driver is en-route to someone forever | Admin clears the servicing flag |
| 13 | Range-leave applied for wrong dates | Employee can't undo after cutoff | Admin force-deletes leave row |

### J2. Admin actions provided

Each action is a button on the appropriate admin page (live tracking modal, ride detail page, employee history, driver history). All actions are logged.

1. **Force-complete ride** — Live ride modal → "Force Complete" button. Confirms with reason input. Backend: sets `EnterpriseRide.status='completed'`, sets `completed_by_admin_id`, records reason. Notifies driver + remaining employees. Skips geo-fence check on completion.
2. **Force-cancel ride** — Same UI. Cancels the ride entirely. Each remaining employee is marked `cancelled_by='admin'`. Push notifications go out.
3. **Override OTP verify** — Ride detail → employee row → "Verify on behalf" button → confirm modal asks "Spoke to employee on phone — they confirmed boarding? (yes/no)". On yes: marks `pin_verified=1`, status=`completed` for that employee row. Notifies employee.
4. **Resend OTP push** — Ride detail → employee row → "Resend OTP" button. Re-pushes the existing OTP (no re-generation). Useful when push delivery failed.
5. **Mark no-show (admin)** — Ride detail → employee row → "Mark No-Show". Inserts cancel row with `cancelled_by='admin_no_show'`. Same effect as driver no-show, but admin-triggered.
6. **Cancel for employee (any time)** — Ride detail OR employee history → "Cancel for [Date] [Direction]" button. Inserts cancel row with `cancelled_by='admin'`. Rules A + B bypassed — admin can cancel even after cutoff or during active rides. Logged + notifies all parties.
7. **Cancel ALL rides for a date** — Calendar page → "Mark Closure Day" → pick (company, date(s), directions). Bulk-inserts cancel rows for every employee × selected directions. Useful for holidays declared late.
8. **Override geo-fence** — Ride detail → "Override Geo-Fence" toggle. When ON, the next `/ride-arrived` or `/ride-complete` or `/ride-drop-mark` from this driver bypasses the geo-fence check. Auto-revokes after 10 minutes or after one successful API call (whichever first). Heavily logged.
9. **Reassign ride to different driver** — Ride detail → "Reassign" → driver picker → confirm. Backend ends the current ride for the old driver (status=`reassigned`), starts a new ride for the new driver with the remaining (not-yet-picked / not-yet-dropped) employees. Pushes notifications.
10. **Emergency stop** — Live tracking → "Emergency Stop" red button at top of ride detail. Confirmation modal with emergency reason input. Backend: sets ride `status='emergency_stopped'`, pushes URGENT notification to driver ("STOP immediately. Admin will contact you.") + all employees in the ride. Triggers a support workflow.
11. **Clear stuck servicing** — Ride detail → if `current_servicing_employee_id` non-null but no ongoing API activity in last 5 min → "Clear stuck servicing" button visible. Sets the field to NULL.
12. **Edit assignment mid-day** — Employee detail → "Reassign to driver" — only for non-active days going forward; for today, requires reassign-ride (#9).
13. **Force-delete leave row** — Employee history → leave row → "Remove leave" (admin only). Subject to no validation rules.

### J3. Stuck-state alerts (admin dashboard)

Add an "Alerts" widget on the admin Enterprise home page that lists rides needing attention:

| Condition | Severity |
|----|----|
| Ride in `started` > expected duration + 60 min (e.g., a 1-hour Pickup running 2+ hours) | High |
| Ride in `started` with driver location stale > 5 min (no LocationService update) | High |
| Employee in `arrived` state > 15 min (no OTP verify, no no-show) | Medium |
| Driver hasn't moved > 500m in 20 min during active ride | Medium |
| > 30% no-show rate for a (company, day) | Low / report |

Each alert links to the ride detail page where admin can intervene.

### J4. Audit log (one new small table)

Every admin override is recorded. Existing tables don't capture the *who/why/when* of administrative actions. One new table is the right call here.

**Migration**: `2026_05_XX_create_enterprise_admin_audit_log.php`
```
id BIGINT PK
admin_user_id FK → users.id   (the admin who performed the action)
action VARCHAR(64)            (e.g., 'force_complete_ride', 'override_otp_verify')
ride_id FK → enterprise_rides (nullable)
employee_id FK → enterprise_employees (nullable)
driver_id FK → enterprise_drivers (nullable)
payload JSON                  (action-specific details — old/new state, geo-fence vals, etc.)
notes TEXT                    (admin's stated reason — required for high-impact actions)
created_at TIMESTAMP
```

Every override endpoint writes one row here. The ride history pages (Part D) include audit entries in the timeline so support can see "Admin Anita force-completed at 18:42, reason: 'Driver phone dead'."

### J5. Files (Part J)

**Backend**:
- `app/Http/Controllers/Admin/EnterpriseRideController.php` — extends earlier (Part B/D) controller with the override methods listed in J2.
- `app/Notifications/` — new: `EnterpriseAdminForceCompleteNotification`, `EnterpriseEmergencyStopNotification`.
- `database/migrations/2026_05_XX_create_enterprise_admin_audit_log.php` — new table.
- `database/migrations/2026_05_XX_add_admin_override_columns.php` — adds `completed_by_admin_id`, `geofence_override_until`, `reassigned_to_ride_id` columns to `enterprise_rides`.
- `app/Models/EnterpriseAdminAuditLog.php` — new model.
- `routes/web.php` — admin routes for each override action.

**Admin views** (`resources/views/admin/enterprise/`):
- `rides/show.blade.php` — add the action buttons (Force Complete, Cancel, Reassign, etc.).
- `rides/_employee-row.blade.php` — partial with per-employee overrides (Verify on behalf, Resend OTP, Mark No-Show).
- `closures.blade.php` — bulk-closure tool (action #7).
- `alerts.blade.php` — stuck-state alerts dashboard widget.

**Driver app**: handle two new push types `Enterprise_Force_Completed`, `Enterprise_Emergency_Stop` — the activity finishes itself and returns to Dashboard with a dialog explaining what happened.

**User app**: same — `MyFirebaseMessagingService` handles these two push types and routes to a friendly dialog.

### J6. Authorization

All admin actions require:
- Authenticated admin user.
- Existing admin RBAC (role checks already in place).
- High-impact actions (force-complete, reassign, emergency-stop, geo-fence override) require a `notes` field — empty notes → 422.
- Optional: SMS/email 2FA confirmation for emergency-stop (out of scope for first version; can be added later).

---

## PART K — Security & Reliability Gaps (review + mitigations)

Honest review of what's still risky. Each item has a mitigation that gets added to the implementation.

### K1. Audit log must be append-only
**Risk**: A corrupt or compromised admin could edit `enterprise_admin_audit_log` to cover their tracks, defeating the whole point of the audit trail.
**Mitigation**:
- DB-level: GRANT only INSERT (not UPDATE/DELETE) to the admin role on this table.
- App-level: no admin-panel UI for editing/deleting audit rows.
- Code-level: the model overrides `save()` to reject updates (`throw new \LogicException` if `wasRecentlyCreated` is false).

### K2. Rate limiting on sensitive endpoints
**Risk**: Flooding `/employee-cancel-ride`, `/notify-en-route`, `/ride-otp-verify` could DoS the API or brute-force OTPs.
**Mitigation**:
- Laravel's `throttle` middleware on per-user buckets:
  - `/ride-otp-verify`: 5 attempts per ride per 10 min. After 5 wrong OTPs, the row locks; admin must reset.
  - `/employee-cancel-ride`: 30 per user per hour.
  - `/notify-en-route`: 60 per driver per hour.
- Returns 429 with `retry-after`.

### K3. OTP brute-force protection (explicit)
**Risk**: 4-digit OTP = 10,000 combos. Even with rate limiting, after 5 wrong attempts a malicious driver could try again on the next ride.
**Mitigation**:
- After 5 wrong OTP attempts for a single `enterprise_employee_rides` row, set `status='locked'`. Driver must call admin to unlock (admin override #3 in Part J).
- Log every wrong OTP attempt in audit log with `driver_id` — repeated lockouts on the same driver flag them for review.

### K4. Time zone handling
**Risk**: Cutoff math uses `shift_start` / `shift_end` (stored as TIME in DB). If server is UTC but employees and admins think in IST, comparisons silently drift.
**Mitigation**:
- Store all timestamps in DB as UTC (`now()` in Laravel respects `APP_TIMEZONE`).
- Pin `APP_TIMEZONE=Asia/Kolkata` in `.env` (already done — verify).
- For shift-date math, always use `Carbon::today()` (timezone-aware) not `date('Y-m-d')` (server tz only).
- Display layer in admin panel + apps uses the user's local tz with explicit "IST" labels.
- Verification: write a test that runs at 23:00 IST = 17:30 UTC, applies leave for "tomorrow" (= IST tomorrow), confirms the cancel row's `shift_date` is IST tomorrow, not UTC's next-rollover day.

### K5. Idempotency on action endpoints
**Risk**: Network retry, double-tap → API fires twice → duplicate OTP rows / double-completions / etc.
**Mitigation**:
- Action endpoints (`/notify-en-route`, `/ride-arrived`, `/ride-otp-verify`, `/ride-drop-mark`, `/ride-complete`, `/ride-mark-no-show`, all admin overrides) accept an `idempotency_key` header (UUID generated by client per action).
- Backend stores `(endpoint, user_id, idempotency_key) → response_cache` for 10 min. Repeat with same key returns cached response, no side effect.
- Lightweight implementation: a `request_cache` table or Redis. If Redis is available (probably), prefer it.

### K6. Switch-OTP confusion
**Risk**: Driver Start-Rides A → employee A receives OTP → driver switches to C → A's row deleted, OTP invalidated. If A meanwhile came out and tried to board another vehicle showing the same OTP, that's irrelevant — but A's user app still shows the OTP card with the now-invalid number. Confusing.
**Mitigation**:
- When the backend invalidates an employee's pending OTP (due to driver switching away), it sends a `Enterprise_OTP_Invalidated` push to that employee.
- User app on receive: green/amber banner disappears, OTP card disappears, neutral banner restored ("Driver is picking up another employee.").

### K7. Driver location spoofing at the source
**Risk**: We trust `enterprise_drivers.latitude/longitude` for geo-fence. But that column is populated by the driver app's `/update-user-location` call, which sends client-side coords. A modded driver app could send fake coords.
**Mitigation** (best-effort, not bulletproof):
- Add "reasonableness checks" on `/update-user-location`: reject if the new position is more than `MAX_TELEPORT_KM` (config, default 10 km) from the previous position within 30 seconds — unrealistic.
- Log suspicious patterns (location jumps, consistent speed > 200 km/h) into a `driver_location_anomalies` table. Admin reviews periodically.
- Future: integrate Google's Play Integrity API on the driver-app side to detect rooted/modded devices. Out of scope here, but reserve a `client_attestation` column for it.

### K8. Backward compatibility for older app versions
**Risk**: User installs the app, doesn't update, hits an endpoint expecting old response shape (no `currently_servicing`, no `upcoming_leaves`). Crashes or silent break.
**Mitigation**:
- Every new response field is optional (Kotlin `?` types).
- Backend's response JSON always includes the new fields; missing on client side simply doesn't render.
- Add a `min_app_version` check at login. If the user's app is too old, show a "Please update" screen and lock further use until they update.

### K9. PII in push notification bodies
**Risk**: OTP, driver name, employee names appear in push bodies. On a lost phone, anyone seeing the lock-screen sees these.
**Mitigation**:
- For OTP: title is generic ("Enterprise ride update"), body says "Open the app to see your OTP." The OTP itself comes via FCM data payload (background-handled by app, not shown on lock screen) and is rendered only when user opens app.
- For ride-state pushes: keep first names but no addresses, no phone numbers.

### K10. Two-driver-on-same-phone / session hijacking
**Risk**: Driver logs in on two phones; the app on each has different state.
**Mitigation**:
- On successful login, invalidate all prior Sanctum tokens for that driver (one active session at a time).
- User app: same policy.
- Trade-off: legit re-login (lost phone, new device) requires re-authentication — acceptable.

### K11. Stale push delivery
**Risk**: Push notifications are best-effort (FCM). A missed push = stuck state.
**Mitigation**:
- User app already polls `getDriverFullDetails` every 10s — picks up state regardless of push.
- Driver app should similarly poll `getOngoingRide` (or equivalent) every 10s while in `EnterpriseRideFlowActivity`. Verify this is in place.
- Pull-to-refresh on every screen as a manual recovery option.

### K12. Holiday / DST edge cases (low priority)
**Risk**: Date math around DST transitions or end-of-month for night shifts.
**Mitigation**:
- India doesn't observe DST — N/A here.
- Carbon handles month rollovers correctly (Mar 31 + 1 day = Apr 1) — verified with unit test.

### K13. Data retention / right-to-deletion
**Risk**: Compliance: ex-employee requests their data be deleted.
**Mitigation**:
- Soft-delete on `enterprise_employees` (add `deleted_at` if not present).
- For an actual "right to forget" request, admin can run an erasure job that anonymizes (not deletes — audit log integrity) the employee's name/phone/email/address in all related rows, replacing with `[REDACTED]`.
- Out of scope to fully implement now, but design the schema to support it.

### K14. Backups & DR (operational)
**Out of scope** for this plan but flagged: daily DB backups should be in place (probably already are for `staging` → ensure the same for production). Without backups, all the audit logs and history are at single-point-of-failure risk.

---

## PART L — Integration Fixes Carried Forward

Behaviors surfaced during staging integration that must remain in place. These are non-obvious from the design alone and would silently regress if rewritten.

> **Precedence rule**: PART A–K above is the **original design spec**. PART L is the **shipped behavior** — fixes, deviations, and refinements made during build. **When PART L conflicts with PART A–K, PART L is authoritative.** Notable supersedes:
>
> - The standalone `EnterpriseRideStatusActivity` (PART F3 / F5 / I3 / H3) is **dormant** — kept for rollback but no longer launched. The unified dashboard (L29) absorbed its map + Firebase tracking + OTP + cancel UI into `CarDashboardActivity`. Any reference in PART A–K to "the tracking screen" should be read as "the active-mode bottom-sheet on `CarDashboardActivity`".
> - The standalone "OTP card" (PART F6) is gone — OTP now renders inline inside the status pill (L25).
> - The standalone "stop position card" (PART F3a) is gone — implicit on the map (L29).
> - Standalone "Today's completed shifts" green banners (L18) are now inline "✓ Completed" pills on the daily-commute card (L29).
> - The "Track Driver Location" button is removed — the map is always inline when a ride is active (L29).
> - The "Ride Completed" in-app dialog is removed — push-only (L27).
>

### L1. No Eloquent `date` casts on `shift_date` / `date` columns

**Symptom**: undo-leave deletes 0 rows; leave applied for May 24 in IST gets stored as `2026-05-23T18:30:00.000000Z` (off by one calendar day at the IST→UTC boundary).

**Cause**: adding `'shift_date' => 'date'` / `'date' => 'date'` model casts on `EnterpriseRide` and `EnterpriseEmployeeCancelRide` forces Carbon to interpret plain MySQL `DATE` values as midnight in `APP_TIMEZONE` and re-serialize them as UTC datetimes. This shifts every IST date back by one calendar day in API responses, and the cancel-row delete predicate then fails to match by `whereDate(...)`.

**Rule**: `shift_date` and `date` columns stay as **raw `Y-m-d` strings on the model**. Compare them with `where('shift_date', $shiftDateStr)` in queries, render with `\Carbon\Carbon::parse($r->shift_date)->format('d M Y')` in Blade. Datetime columns (`started_at`, `current_servicing_set_at`, `arrived_at`, `otp_cooldown_until`) keep their `datetime` casts — only DATE columns are bare strings.

**Files protecting this rule**:
- `app/Models/EnterpriseRide.php` — comment explicitly warns against re-adding the cast.
- `app/Models/EnterpriseEmployeeCancelRide.php` — same.
- `resources/views/admin/enterprise/history/{index,show,employee}.blade.php` — all use `Carbon::parse(...)->format()` on shift_date, never `?->format()`.

### L2. `EnterpriseDriver` and `EnterpriseEmployee` must extend `Authenticatable`

**Symptom**: 500 on any endpoint protected by `throttle:auth` → `Call to undefined method App\Models\EnterpriseDriver::getAuthIdentifier()`.

**Cause**: Laravel's ThrottleRequests middleware calls `$request->user()->getAuthIdentifier()` to derive the per-user bucket key. Plain `Model` doesn't implement the `Authenticatable` contract.

**Rule**: both models extend `Illuminate\Foundation\Auth\User as Authenticatable`, not `Illuminate\Database\Eloquent\Model`. They are Sanctum-authenticated and rate-limited; the contract is mandatory.

### L3. Geo-fence freshness: accept lat/lng in the action request itself

**Symptom**: "Your GPS location is stale. Please enable location services and retry." even when the driver's GPS is on and reporting.

**Cause**: the driver app's `LocationService` writes to the `users` table (legacy shero plumbing), not to `enterprise_drivers`. The geo-fence freshness check reads `enterprise_drivers.updated_at`, which can be hours stale.

**Rule**: every action endpoint that geo-fences (`/ride-arrived`, `/ride-drop-mark`, `/ride-complete`) accepts optional `latitude` / `longitude` in the request body. If present, the controller persists them to the `enterprise_drivers` row and `refresh()`es before the haversine check. This bumps `updated_at` and feeds the haversine fresh coords in one step.

Driver app calls send `my_lat` / `my_long` from SharedPrefs on each of those endpoints.

**Files**:
- `app/Http/Controllers/Api/EnterpriseRideController.php` — `rideArrived`, `markEmployeeDropped`, `completeRide` all do the lat/lng update + refresh.
- `Zippi-Driver-Android-main/.../retrofit/ApiInterface.kt` — `latitude` and `longitude` are optional `@Field` params on those three endpoints.

### L4. Pending OTP query accepts both `en_route` and `pending`

**Symptom**: user app shows no OTP card after driver taps Start Ride, even though the push delivered.

**Cause**: `notifyEnRoute` creates `enterprise_employee_rides` rows with `status='en_route'` (matches the Pickup state machine), but `getDriverFullDetails` originally only matched `status='pending'`.

**Rule**: `getDriverFullDetails` selects the active OTP row with `whereIn('status', ['en_route', 'pending'])`. Both are valid pre-arrival states.

### L5. Per-employee Drop notification, not bulk completion

**Symptom**: employees on a Drop ride only get a push when the driver clicks "Complete all rides" — long after they've been let off — and the title says "Ride completed for the entire ride" rather than "your ride."

**Cause**: `markEmployeeDropped` was calling `new EnterpriseEmployeePinVerifiedNotification($ride, $driver)`. That class:
1. Has a constructor signature `EnterpriseEmployeeRide $employeeRide` (single arg, wrong type) → silent `TypeError` swallowed by try/catch.
2. Is the Pickup "Pickup Started" notification — wrong message even if it had worked.

So drop confirmations were silently failing, and the only push the employee ever got was the bulk `EnterpriseRideCompletedNotification` later in `completeRide`.

**Rule**:
- `markEmployeeDropped` fires `EnterpriseEmployeeDroppedNotification` to that one employee with title "Your ride has been completed", `notification_type=Enterprise_Employee_Dropped`.
- `completeRide`'s bulk fan-out of `EnterpriseRideCompletedNotification` runs **only for Pickup** (`if ($ride->direction === 'Pickup')`). For Drop, each employee was already personally notified at their stop; sending a second "ride completed" would be redundant and confusing (the employee doesn't care about the driver finishing the rest of the route after they're home).

**Files**:
- `app/Notifications/EnterpriseEmployeeDroppedNotification.php` — new.
- `app/Http/Controllers/Api/EnterpriseRideController.php` — `markEmployeeDropped` uses the new notification; `completeRide` guards the bulk push with `direction === 'Pickup'`.

### L6. Defensive Schema checks for staged migrations

**Symptom**: 500 on apply-leave / driver-route fetch with `Unknown column 'shift_date'` or `current_servicing_employee_id` on a database that hasn't run the new migrations yet.

**Rule**: code paths that touch newly added columns gate on `\Illuminate\Support\Facades\Schema::hasColumn(...)` and fall back to the legacy column (`date` instead of `shift_date`, skipping the servicing-flag clear, etc.). This lets the same controller code run against both pre- and post-migration databases during staged rollout.

**Files**:
- `app/Http/Controllers/Api/EnterpriseController.php` — `buildUpcomingLeaves` Schema check on `shift_date`.
- `app/Http/Controllers/Api/EnterpriseRideController.php` — Schema check on `current_servicing_employee_id` before writing/clearing it.

### L7. Facade imports + try/catch surfaces real exception text

**Symptom**: generic "Server Error 500" with no detail; logs show `Class "App\Http\Controllers\Api\DB" not found` — controller used a bare `DB::` reference that namespace-resolved to the controller's own namespace instead of the global facade.

**Rule**:
- Every controller using `DB`, `Schema`, `Log`, `Notification` declares the explicit `use Illuminate\Support\Facades\...` import.
- High-impact endpoints (`notifyEnRoute`, `employeeCancelRide`, `undoCancelRide`, `getDriverRoute`, `getDriverFullDetails`, `markEmployeeDropped`, `completeRide`, `rideArrived`) wrap their body in try/catch and return the actual `$e->getMessage()` in the JSON `message` field (plus a `Log::error` with stack trace). This means staging exceptions surface in the user-app banner instead of vanishing into "server error".

### L8. Storage / cache permissions on Phase-4 deploy

**Symptom**: login + session-related endpoints 500 after Phase-4 push, with `file_put_contents(...): Failed to open stream: Permission denied` in `storage/framework/cache/`.

**Cause**: new throttle + idempotency middleware writes to Laravel's file cache. Web user (`www-data`) lacked write permission after a deploy that rebuilt the cache directory under a different owner.

**Rule (ops checklist on every deploy)**:
```
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
sudo systemctl reload apache2
```

This is a deploy-time runbook item, not code, but rolling back the throttle/idempotency middleware to "fix" the login 500 would mask a permissions bug and defeat Phase K2 + K5.

### L9. emptyRoute returns the specific reason

**Symptom**: driver-app Schedule Rides shows generic "No schedule found", driver doesn't know if it's a holiday, working_days mismatch, or all-cancelled.

**Rule**: `getDriverRoute` returns a structured `empty_reason` ("no_assignment", "not_working_day", "all_cancelled", "no_active_employees", etc.) plus a human-readable `message`. Driver app shows the message verbatim.

### L10. Admin can delete ride history (per-ride + full wipe)

**Why it exists**: pre-go-live cleanup, test data removal, GDPR-style erasure requests.

**Rule**:
- Per-row delete button on `/admin/enterprise/ride-history` and on the detail page. Confirmation modal captures an optional Reason.
- Full-wipe button (red, top of index page) deletes every ride and every employee stop row. The admin must type `DELETE ALL HISTORY` exactly — anything else returns a validation error.
- Both operations run inside a `DB::transaction` and delete `enterprise_employee_rides` rows first, then the `enterprise_rides` rows. **Cancel rows (leave records) and the audit log are NEVER touched.**
- Every delete writes an `enterprise_admin_audit_log` entry (`history_delete_ride` or `history_wipe_all`) with `admin_user_id`, the ride payload, and the typed notes. Because the audit log is append-only, the admin who deleted cannot erase the record of the deletion itself.

**Files**:
- `app/Http/Controllers/Admin/EnterpriseRideHistoryController.php` — `destroy`, `destroyAll`.
- `routes/admin.php` — `DELETE /admin/enterprise/ride-history/ride/{ride}` and `DELETE /admin/enterprise/ride-history/wipe`.
- `resources/views/admin/enterprise/history/index.blade.php` — delete column + wipe button + confirmation modals + flash messages.
- `resources/views/admin/enterprise/history/show.blade.php` — delete-this-ride modal.

### L11. Admin override OTP verify must push the driver

**Symptom**: admin clicks "Verify OTP on behalf" → admin panel updates to picked, but driver app still shows the OTP-entry screen. Driver presses back and gets stuck on "you already have an active pickup ride".

**Cause**: `overrideOtpVerify` only updated `enterprise_employee_rides` + the audit log — never told the driver app about the change. The driver app's local state stayed at `arrived`, OTP UI visible.

**Rule**: every admin override that changes per-employee ride state pushes the driver immediately so the app dismisses the stale UI without waiting for a poll or pull-to-refresh.

**Files**:
- `app/Notifications/EnterpriseAdminOtpVerifiedDriverNotification.php` — new, `notification_type=Enterprise_Admin_OTP_Verified`; data includes `ride_id`, `employee_id`, `employee_name`.
- `app/Http/Controllers/Admin/EnterpriseLiveTrackingController.php` `overrideOtpVerify` — sends the push after writing the audit log.
- `Zippi-Driver-Android-main/.../firebase/MyFirebaseMessagingService.java` — `handleEnterpriseAdminOtpVerified` broadcasts `Enterprise_Admin_OTP_Verified` LocalIntent + posts a system tray notification.
- `Zippi-Driver-Android-main/.../activities/EnterpriseRideFlowActivity.kt` — `adminOtpVerifiedReceiver` calls `markEmployeePickedByAdmin(id)` which flips the employee row to `completed`, hides OTP entry, stops the no-show countdown, refreshes UI.

**Same pattern applies to all admin overrides going forward** — force-complete, mark-no-show, geo-fence override — each writes its DB change AND pushes the driver. The audit log is for the future; the push is for the present.

### L12. "Begin Shift" replaces the employee-selection screen

**Old flow**: Schedule → "View Employees & Start Ride" → `EnterpriseUserList` (driver picks subset, taps Start Ride) → `EnterpriseRideFlowActivity`.

**New flow**: Schedule → "Begin Shift" tap → `/start-ride` is called immediately with ALL route employees → driver navigates straight to `EnterpriseRideFlowActivity` with `FLAG_ACTIVITY_NEW_TASK | FLAG_ACTIVITY_CLEAR_TASK`. The intermediate user-list activity is removed from the navigation path entirely.

**Why**: the per-employee Start Ride commit happens INSIDE `EnterpriseRideFlowActivity` (Part F2 one-at-a-time rule), so the upstream "select which employees" screen was redundant. Removing it also collapses the back-stack regression class: there is no "Start Ride" button reachable via back, so the duplicate-ride 409 case can no longer happen from normal navigation.

**Backend `/start-ride` 409 branching on the driver app** — when the server says a ride for this driver+company+direction already exists today, the response's `ride.status` field decides what happens:

| existing `status` | Driver-app behavior |
|----|----|
| `started` | Resume into that ride — navigate to `EnterpriseRideFlowActivity` with the existing `ride_id`. No second start. |
| `completed` | AlertDialog "$Direction shift completed — Your $Direction shift for today is already done. Come back tomorrow." No navigation. |
| `emergency_stopped` | AlertDialog "$Direction shift halted" with the admin's reason. |
| `reassigned` | AlertDialog "$Direction shift reassigned" with the admin's reason. |
| (other) | Toast with the server's `message`. |

The "Driver has started their shift" push fires from `/start-ride` for every active employee on the route — this is the single fan-out point, no other endpoint sends it.

**Files**:
- `staging/app/Notifications/EnterpriseRideStartedNotification.php` — body text "Driver {name} has started their shift. You'll be notified when they are on the way to you." (no per-employee OTP at this stage).
- `Zippi-Driver-Android-main/.../res/layout/activity_enterprice_ride_schdule.xml` — button text "Begin Shift".
- `Zippi-Driver-Android-main/.../activities/EnterpriceRideSchdule.kt` — `beginShift()` helper + status-aware 409 handling + `goToRideFlow()` with CLEAR_TASK.
- `Zippi-Driver-Android-main/.../activities/EnterpriseUserList.kt` — still exists but is no longer launched from any flow. Safe to delete in a follow-up cleanup.

### L13. FCM token must be unique per employee row

**Symptom**: per-employee Start-Ride push for Madhavan also arrived on Adithya's app showing Madhavan's OTP.

**Cause**: the user app's `getDriverFullDetails` polling auto-syncs `enterprise_employees.fcm_token` from `user_details.fcm_token`. When the same physical device logs in sequentially as different employees, both `enterprise_employees` rows end up storing the same FCM token. A push targeted at one employee physically reaches the device, and the app silently shows it (broadcast filter passes whenever the device's currently-logged-in employee matches `employee_id` — but the token table is wrong either way).

**Rule**: a given FCM token may belong to **at most one** `enterprise_employees` row at any moment. Before writing a new token to an employee, the controller MUST strip that token from any other employee row:

```php
EnterpriseEmployee::where('fcm_token', $token)
    ->where('id', '!=', $employee->id)
    ->update(['fcm_token' => null]);
```

**Files**:
- `app/Http/Controllers/Api/EnterpriseController.php` `getDriverFullDetails` (auto-sync from user_details) — strips other rows first.
- `app/Http/Controllers/Api/EnterpriseController.php` `updateFcmTokenEnterprise` (explicit update) — same.

**One-time DB cleanup after deploy** (in case duplicates already exist):
```sql
UPDATE enterprise_employees SET fcm_token = NULL
WHERE fcm_token IS NOT NULL
  AND id NOT IN (
    SELECT id FROM (
      SELECT MAX(id) AS id FROM enterprise_employees
      WHERE fcm_token IS NOT NULL
      GROUP BY fcm_token
    ) t
  );
```

### L14. User app must fetch immediately on resume

**Symptom**: OTP push arrives while user-app is backgrounded → user opens app → OTP card stays hidden until "manually back out and re-open." Going back-and-forward "worked" only because activity recreation re-fetched.

**Cause**: `CarDashboardActivity.onResume` scheduled the next `getDriverFullDetails` poll 10 seconds in the future, so the first refresh after resume waited a full polling cycle. Meanwhile the `LocalBroadcastManager` push that fired while the activity was paused had no receiver and was discarded — there's no buffered delivery.

**Rule**: every activity that depends on push-driven UI updates **must call its fetch synchronously in `onResume`**, before scheduling the periodic loop. The push handles "while open"; the immediate fetch handles "just came back to foreground". Polling at 10s remains the long-tail backstop for FCM failures.

```kotlin
override fun onResume() {
    super.onResume()
    refreshHandler.removeCallbacks(refreshRunnable)
    refreshHandler.post(refreshRunnable)   // fire now, then continue periodic
    registerEnterpriseReceivers()
}
```

**Files**:
- `Zippi-User-Android-main/.../activities/CarDashboardActivity.kt` — `onResume` posts immediately.
- `Zippi-User-Android-main/.../activities/EnterpriseRideStatusActivity.kt` — already called `loadOngoingRide(silent=false)` on resume; no change needed.

### L15. `me_status` / `me_done` suppress per-stop UI after this employee is serviced

**Symptom**: driver picks Adithya (stop 2 of 2) → Adithya's user app keeps showing "Your pickup stop — Stop 2 of 2 — Driver will pick up 1 other employee before you" because the parent ride is still `started` while the driver heads to the office.

**Cause**: the user app gated the stop-position card and OTP card on `has_active_ride && total_stops > 1`. That stays true for the whole ride duration — even after this specific employee is done.

**Rule**: `getDriverFullDetails` returns this employee's own state inside the ride. The user app uses it to gate per-stop UI elements.

Backend additions to the response:

| Field | Type | Meaning |
|----|----|----|
| `me_status` | string | `pending` / `en_route` / `arrived` / `picked` / `cancelled` / `no_show` — derived from the employee's row in `enterprise_employee_rides` and any matching cancel row (direction + shift_date). |
| `me_done` | boolean | `true` once `me_status ∈ {picked, cancelled, no_show}` — used as the single gate for hiding the OTP card and the Stop X-of-Y card. |

The user app keeps showing the driver/vehicle info and the daily-commute card after `me_done=true` (so the employee can still call the driver if needed), but the per-stop banners go away.

**Files**:
- `app/Http/Controllers/Api/EnterpriseController.php` `getDriverFullDetails` — computes `meStatus` from `enterprise_employee_rides.pin_verified` + cancel-row lookup, returns both new fields.
- `Zippi-User-Android-main/.../EnterpriseModel/DriverFullDetailsResponse.kt` — `me_status: String?`, `me_done: Boolean?`.
- `Zippi-User-Android-main/.../activities/CarDashboardActivity.kt` — `me_done` now feeds `applyMapMode` (L29), which hides the entire active-mode chrome including OTP, status pill, and the dropped per-stop card. The `otpCard` and `stopPositionCard` views named here are obsolete after L29 — the inline OTP is in the status pill and the per-stop card was removed entirely (the map makes it redundant). The `me_done` field and its gating role are unchanged.

### L16. Enterprise drivers stream GPS only during an active shift

**Symptom**: previous behavior had `LocationService` start in `Dashboard.onCreate` and run forever — drivers' phones were uploading GPS every 5s for 8+ idle hours per day (≈5,700 unnecessary writes per driver), burning battery and data.

**Rule**: for enterprise drivers, `LocationService` runs **only** from Begin Shift through ride completion. Regular (non-enterprise) shero drivers keep the always-on behavior because their work model is "go online and accept any ride."

**GPS lifecycle for enterprise drivers**:

| Event | LocationService state |
|---|---|
| Dashboard opens (idle) | **off** — defensively `stopService` in case of stale state |
| Tap **Begin Shift** | **on** — started by `EnterpriceRideSchdule.beginShift()` and `EnterpriseRideFlowActivity.ensureLocationServiceRunning()` |
| During ride (driving to homes / office) | on — 5s cadence as before |
| Tap **Complete Shift** → server returns 200 | **off** — `stopLocationServiceIfRunning()` called before showing "Day Shift Completed" dialog |
| Admin force-completes / cancels / reassigns / emergency-stops | **off** — same helper called in `rideForceCompletedReceiver` before its dialog |

The single gate is `sessionManager.isEnterpriseDriverPref == 1` in `Dashboard.kt`. Non-enterprise drivers reach `initializeService()` as before.

**Files**:
- `Zippi-Driver-Android-main/.../activities/Dashboard.kt` — gates `initializeService()`; on Dashboard entry for an enterprise driver, defensively `stopService(service)` in case a previous shift's service leaked.
- `Zippi-Driver-Android-main/.../activities/EnterpriseRideFlowActivity.kt` — new `stopLocationServiceIfRunning()` helper; called on `/ride-complete` success AND on `Enterprise_Ride_Force_Completed` broadcast.

**Doesn't affect Phase F realtime tracking guarantees**: the admin live dashboard and the user app's "track driver" feature only need GPS while a ride is active — which is exactly when this rule keeps it on. The only thing that goes dark is the wasteful idle-time streaming.

### L17. Drop office-boarding stage implemented (closes PART A6)

PART A6 was specified but unimplemented — Drop rides went straight into the per-employee selection screen with no way to mark who actually boarded the vehicle at the office, and no batch no-show recovery. This entry documents the now-completed implementation.

**Behavior**:

1. Driver taps **Begin Shift** for a Drop ride → lands in `EnterpriseRideFlowActivity` in **boarding phase**.
2. Each employee row's status badge becomes a boarding indicator: `☐ NOT BOARDED` (neutral) or `✓ BOARDED` (green card, white pill).
3. A sticky banner pinned to the bottom of the screen shows:
   - `🚍 BOARDING AT OFFICE` header.
   - Server-anchored countdown text (`Wait window: M:SS remaining (until shift_end + 12 min)`). Anchored to the **latest** `shift_end` among assigned employees — handles teams with mixed end times.
   - Red `Lock In & Start Drop` button.
4. Driver taps a row → toggles boarded ✓ ⇄ ☐. Card greens up when checked.
5. **Lock-In enablement (lenient rule)** — the button becomes active whenever EITHER:
   - Every assigned employee is boarded OR already cancelled today, OR
   - The shift_end + 12 min timer reaches zero.
6. Tap **Lock In & Start Drop** → confirmation dialog ("X boarded, Y will be marked NO-SHOW") → confirm:
   - `POST /api/enterprise/ride-mark-no-show-batch` with the boarded list.
   - Backend inserts one `enterprise_employee_cancel_rides` row per un-boarded employee (`cancelled_by='driver_no_show'`, reason `'Did not board the vehicle at office'`), in a single DB transaction.
   - Each no-show employee receives `EnterpriseEmployeeNoShowNotification` (red banner appears on their app).
   - Audit log entry `action='drop_lock_in'` with `boarded_count`, `no_show_count`, and the lists of IDs.
7. Driver app exits boarding phase → banner disappears → adapter returns to normal status display → per-employee Drop flow proceeds as before (Start Drop → Mark Dropped per employee).
8. Back-press while in boarding phase shows a confirm dialog ("Leave boarding? The ride stays active — you can resume from the Schedule screen.").

**Endpoint contract** (`POST /api/enterprise/ride-mark-no-show-batch`):

```
ride_id                : int
boarded_employee_ids[] : int[]   (employees confirmed in the vehicle)
```

Returns 200 with:
```
{ status, message, boarded_count, no_show_count }
```

Server validates: direction = Drop, status = started, driver owns ride. Excludes any employee_id already in `enterprise_employee_cancel_rides` for today (idempotent against earlier employee-initiated cancels). Audit-logged.

**Files**:
- `staging/app/Http/Controllers/Api/EnterpriseRideController.php` — new `markNoShowBatch` method.
- `staging/routes/api.php` — new route `POST /enterprise/ride-mark-no-show-batch` (throttle `10,60`, `idempotent`).
- `Zippi-Driver-Android-main/.../retrofit/ApiInterface.kt` — `markNoShowBatch(token, ride_id, boarded_employee_ids[])`.
- `Zippi-Driver-Android-main/.../adapters/EnterpriseEmployeeRideAdapter.kt` — `boardingMode: Boolean`, `boardedIds: Set<Int>`, new `onBoardingToggle` callback, `setBoardingMode()` + `updateBoardedIds()` APIs. In boarding mode the row's status badge is repurposed as boarded indicator and row tap toggles instead of selecting.
- `Zippi-Driver-Android-main/.../activities/EnterpriseRideFlowActivity.kt` — `inBoardingPhase` state, programmatic sticky Lock-In banner (created at runtime — no layout XML change required), `latestShiftEndEpochMs()` math (handles night-shift roll-over), `boardingTick` 1-second countdown, `refreshLockInButtonState()`, `performLockIn()`, `exitBoardingPhase()`, back-press guard.

**No layout XML change**: the Lock-In banner is constructed in Kotlin and attached to the root via reflection-style layout-param probing (`FrameLayout`, `ConstraintLayout`, `RelativeLayout`) so the same code drops into any future layout container without editing XML.

**Doesn't break the Pickup path**: `enterBoardingPhase()` is only called when `isDropShift()`. Pickup still does the per-employee `pending → en_route → arrived → completed` state machine with the 6-min no-show button as before.

### L18. "Today's completed shifts" visible in both apps

**Why**: previously the user app had no passive indicator of whether the morning Pickup ride had run — they had to remember. Driver app likewise had no banner; the only signal that today's shift was already done was the 409 dialog on tap.

**Backend additions to `getDriverFullDetails`**:
```
today_completed_shifts: [
  { direction: "Pickup", shift_date: "2026-05-22", completed_at: "2026-05-22T08:42:00+05:30", duration_min: 47 },
  { direction: "Drop",   ... }
]
```
Built by `buildTodayCompletedShifts(employeeId)` — queries `enterprise_rides` for completed rides containing this employee, with `shift_date IN (today, yesterday)` or `started_at::date = today` (yesterday is included for night-shift Drops). Deduplicates by direction (most recent kept).

**Backend additions to `getDriverRoute`** — each route in the response now includes:
- `shift_completed_today: bool`
- `shift_completed_at: ISO8601`
- `shift_duration_min: int`

**User app**:
- `DriverFullDetailsResponse.kt` — new `today_completed_shifts: List<CompletedShift>?` + new `CompletedShift` model. (Still in use — consumed by L29's inline commute-card rendering.)
- ~~`CarDashboardActivity.kt` — `renderTodayCompletedShifts()` appends green banners~~ — **superseded by L29**. The standalone banner stack was removed; the completion indicator now renders inline as a green "✓ Completed" pill on the matching leg of the daily-commute card (handled in `renderDailyCommute`). `renderTodayCompletedShifts` was deleted entirely.

**Driver app**:
- `EnterpriseScheduleResponse.kt` — three new fields on `EnterpriseScheduleRoute`.
- `EnterpriceRideSchdule.kt` — when the displayed route has `shiftCompletedToday=true`, the route header flips to "✓ Pickup shift completed", direction badge to "DONE", Begin Shift button hidden, completion time + duration shown via toast. The 409 dialog from L12 remains the fallback for the rare case where a driver bypasses the schedule screen.

### L19. User-app dashboard banner is direction + state aware

**Symptom**: after an employee was picked up (Pickup), their dashboard banner still read "Driver has started their shift" even though they were already in the vehicle being driven to office. The pre-existing `currently_servicing` matrix never accounted for the "I'm in the car" state.

**Rule**: the dashboard banner is now a single function of `direction`, `me_status`, and `currently_servicing`. The backend returns `direction` ("Pickup" | "Drop") on the active-ride response so the user app doesn't have to guess.

**State matrix the dashboard renders** (Pickup unless noted):

| `me_status` / phase | `currently_servicing` | Banner shown |
|---|---|---|
| `pending`, ride active, no commit | both false | 🚐 "Driver has started their shift" |
| `pending` | `is_me=true` | 🚗 "Driver is on the way to your home" + OTP card |
| `arrived` | `is_me=true` | 📍 (FCM) "Driver has arrived" + OTP card |
| `pending` / `arrived` | `someone_else_active=true` | 🕒 "Driver is picking up another employee" |
| `picked` / `completed` (Pickup) | any | 🏢 "On the way to office" |
| `en_route` (Drop) | `is_me=true` | 🚗 "Driver is on the way to your home" |
| `pending` (Drop) | `someone_else_active=true` | 🕒 "Driver is dropping another employee" |
| `picked` / `completed` (Drop) | any | hidden — employee is home |
| `cancelled` / `no_show` | any | red leave banner (from `upcoming_leaves`) |

**Auto-redirect removed**: previously the `Enterprise_Ride_Started` push handler in `MyFirebaseMessagingService.java` force-opened `EnterpriseRideStatusActivity`. That hijacked whatever the user was doing. The auto-`startActivity` is gone — the system-tray notification is still posted, the dashboard banner still updates via LocalBroadcast. (Post-L29: the Track Driver button no longer exists; the map is inline on the dashboard. The system-tray push remains the only "go to live tracking" prompt.)

**Notes about the matrix table above (post-L29)**: the "OTP card" referenced in some rows is no longer a separate card view — it's rendered inline inside the status pill itself (L25). The banner pill now floats inside the active-mode bottom sheet over the live map (L29). The matrix copy + which-state-shows-what logic is unchanged.

**Files**:
- `app/Http/Controllers/Api/EnterpriseController.php` `getDriverFullDetails` — adds `direction` to the active-ride response.
- `Zippi-User-Android-main/.../EnterpriseModel/DriverFullDetailsResponse.kt` — adds `direction: String?`.
- `Zippi-User-Android-main/.../activities/CarDashboardActivity.kt` — `applyCurrentlyServicingFromPoll` rewritten with the matrix above. Terminal-ish (`picked`/`completed`) checks happen before the `currently_servicing` switch so the post-pickup banner is correct even when the driver has moved on to someone else.
- `Zippi-User-Android-main/.../firebase/MyFirebaseMessagingService.java` `handleenterpriseBookingRequest` — the `startActivity(...)` line for `EnterpriseRideStatusActivity` is removed; the system-tray notification stays.

### L20. Drop boarding-phase has its own banner

**Symptom**: between Begin Shift (Drop) and Lock-In (the office-boarding stage, see L17), employees waiting at the office saw the generic "Driver has started their shift" banner — which fits Pickup ("driver is leaving the depot, coming to you") but is wrong for Drop ("driver is at the office now, come board the vehicle").

**Rule**: when `direction === 'Drop'` AND no `enterprise_employee_rides` rows exist yet for the ride (i.e., driver hasn't `notifyEnRoute`-ed anyone), the backend flags `drop_boarding_phase=true`. The user app renders an **amber** banner: 🚍 *"Boarding at office now — Head to the vehicle. Driver is ticking off employees as they board."*

The flag flips to false the moment the driver taps "Start Drop" for the first employee (which creates an `enterprise_employee_rides` row), at which point the per-state matrix from L19 takes over.

**Why not derive it purely client-side**: the user app would have to infer "boarding phase" from absence-of-state, which is fragile. Server-side derivation guarantees the signal flips deterministically the moment the first Start-Drop call lands.

**Files**:
- `app/Http/Controllers/Api/EnterpriseController.php` `getDriverFullDetails` — computes `drop_boarding_phase` and includes it in the response.
- `Zippi-User-Android-main/.../EnterpriseModel/DriverFullDetailsResponse.kt` — new `drop_boarding_phase: Boolean?`.
- `Zippi-User-Android-main/.../activities/CarDashboardActivity.kt` — new branch in `applyCurrentlyServicingFromPoll` ahead of the generic "shift started" fallback.

### L21. ~~Tracking screen auto-closes when the ride completes~~ — superseded by L29

**Status: obsolete.** This rule lived inside `EnterpriseRideStatusActivity`, which is now dormant (kept in the codebase for rollback only — never launched). The unified dashboard introduced in **L29** does not need a separate "screen auto-close" step because the map is just a fragment that gets hidden via `applyMapMode` when the ride ends for this employee. The original symptom (stale map after Complete Shift) can no longer occur.

Historical note kept so future readers understand the dormant activity's design.

### L22. Admin can remove a no-show / cancel row (closes J13)

**Symptom**: admin marked an employee as `admin_no_show` for today's Pickup, then changed their mind / reassigned a driver. The cancel row remained — the red "Marked as no-show" banner kept showing on the employee's app, and the employee remained ineligible for that direction today.

**Rule**: admin can explicitly remove a single `enterprise_employee_cancel_rides` row from the per-employee history view. Deletion is audit-logged (`action='history_remove_cancel_row'`, with the full pre-delete row in payload). Because the audit log is append-only, the admin who deleted cannot erase the record of the deletion.

**Files**:
- `app/Http/Controllers/Admin/EnterpriseRideHistoryController.php` — new `destroyLeave(EnterpriseEmployeeCancelRide $leave)` method.
- `routes/admin.php` — `DELETE /admin/enterprise/ride-history/leave/{leave}`.
- `resources/views/admin/enterprise/history/employee.blade.php` — new "Action" column with a confirm-and-submit red ✕ button per row; success flash banner at top.

### L23. Today's completed shifts capped at last 12 hours

**Symptom**: a Drop ride completed at 2 AM (night-shift test) still pinged "✓ Evening Drop completed for today" on the user app at lunchtime, long after the situation was stale.

**Rule**: `buildTodayCompletedShifts` (the helper backing L18) now requires `updated_at >= now - 12 hours` OR `started_at >= now - 12 hours`. Genuinely recent completions (within half a day) still show; ancient ones drop off without polluting the UI.

**Files**:
- `app/Http/Controllers/Api/EnterpriseController.php` `buildTodayCompletedShifts` — additional `where` clause on `updated_at` / `started_at`.

### L24. Post-ride employee rating

Riders rate their per-employee ride immediately after Pickup completes (OTP verified) or Drop completes (Mark Dropped). Admin sees the rating in ride history alongside everything else.

**Data model** — four columns on `enterprise_employee_rides` (the existing per-employee-per-ride row, exactly one slot per rating):

| Column | Type | Meaning |
|---|---|---|
| `rating` | `TINYINT NULL` | 1–5 once submitted |
| `rating_comment` | `VARCHAR(500) NULL` | optional free text |
| `rating_submitted_at` | `TIMESTAMP NULL` | set on submit |
| `rating_dismissed_at` | `TIMESTAMP NULL` | set when user pressed Cancel/back |

Index `idx_emp_rides_pending_rating` on `(employee_id, rating_submitted_at, rating_dismissed_at)` powers the pending-rating lookup.

**Trigger model — poll-driven, no push needed.** `getDriverFullDetails` returns a `pending_rating` field. Combined with the immediate `onResume` fetch from L14, this covers both required cases:
- User on app when ride completes → next 10s poll surfaces `pending_rating` → dialog mounts.
- User opens app later → `onResume` immediate fetch surfaces it → dialog mounts at app open.

**Response shape** (active-ride and fallback branches both include it):
```json
"pending_rating": {
  "ride_id":      42,
  "direction":    "Drop",
  "driver_name":  "Suresh",
  "completed_at": "2026-05-22T18:42:00+05:30"
}
```

Non-null when an `enterprise_employee_rides` row for this employee has:
- `pin_verified = 1` (actually picked/dropped — cancelled/no-show employees never get a prompt)
- `rating_submitted_at IS NULL` AND `rating_dismissed_at IS NULL`
- `updated_at >= now() - 24 hours` (24h freshness window — past that, treated as silently "Not rated" forever)
- **For Pickup direction: the parent ride must additionally be `status='completed'`** (driver tapped Complete Shift). Asking right after OTP verify would mean the employee is still in the vehicle being driven to office — the ride isn't over yet.
- **For Drop direction: per-employee terminal** — prompt fires as soon as Mark Dropped is tapped for that employee. The parent ride may still be `started` for other employees.

Most recent row wins if multiple match — one dialog at a time.

**Endpoints**:

| Endpoint | Body | Action |
|---|---|---|
| `POST /api/enterprise/ride-rate` | `ride_id`, `rating` 1-5, `comment` ≤500 | Sets `rating`, `rating_comment`, `rating_submitted_at=now()`. Clears any prior `rating_dismissed_at` (submit wins over dismiss). |
| `POST /api/enterprise/ride-rate-dismiss` | `ride_id` | Sets `rating_dismissed_at=now()`. No-op if already submitted. |

Both: Sanctum auth → resolve `enterprise_employee_id` via `user_details`. Throttle `30,60`, `idempotent` middleware (same combo as `markEmployeeDropped`).

**Dismissal semantics**: Cancel button, back press, AND outside-tap all hit `/ride-rate-dismiss`. Once dismissed (or once 24h elapses), the prompt is gone forever for that ride. "Not rated" in admin UI covers all three terminal states (dismissed / 24h expired / never seen).

**Admin display**:
- **`resources/views/admin/enterprise/history/show.blade.php`** stops timeline — each `picked` stop renders `★★★★☆ (4) — "comment"` or `Not rated` pill.
- **`resources/views/admin/enterprise/history/employee.blade.php`** — new Rating column on the "Rides participated in" table.
- Controller `show()` enriches each stop with `rating` / `rating_comment` / `rating_submitted_at` / `rating_dismissed_at`. Controller `employee()` builds `$myRatings` keyed by ride_id.

**User-app dialog** (`dialog_rate_ride.xml`):
- 5-star RatingBar (`stepSize=1`)
- Multi-line EditText, max 500 chars
- Submit (disabled until rating ≥ 1) + Cancel
- Subtitle dynamically reads "Your Morning Pickup with Suresh just ended."
- Tracking activity does NOT mount the dialog itself — its `handlePotentialRideEnd` (L21) finishes the activity → `CarDashboardActivity.onResume` fires immediate fetch → dialog mounts there. Single mount point.

**Files**:
- `database/migrations/2026_05_22_120000_add_rating_to_enterprise_employee_rides.php` — new.
- `app/Models/EnterpriseEmployeeRide.php` — casts (`rating: integer`, two timestamps as `datetime`).
- `app/Http/Controllers/Api/EnterpriseRideController.php` — `rateRide`, `dismissRating`, `authedEmployee(Request)` helper.
- `app/Http/Controllers/Api/EnterpriseController.php` — `buildPendingRating(int $employeeId): ?array` + wired into both response branches of `getDriverFullDetails`.
- `routes/api.php` — two new POST routes.
- `app/Http/Controllers/Admin/EnterpriseRideHistoryController.php` — `show()` enrichment, `employee()` builds `myRatings`.
- `Zippi-User-Android-main/.../EnterpriseModel/DriverFullDetailsResponse.kt` — `pending_rating: PendingRating?` field + `PendingRating` model.
- `Zippi-User-Android-main/.../retrofit/ApiInterface.kt` — `rateRide`, `dismissRideRating`.
- `Zippi-User-Android-main/res/layout/dialog_rate_ride.xml` — new.
- `Zippi-User-Android-main/.../activities/CarDashboardActivity.kt` — `ratingDialogShownForRideId` guard + `maybeShowRatingPrompt(pending)` helper called from the `fetchDriverFullDetails` success branch.

### L25. OTP rendered inline in the status banner

**Symptom**: the user dashboard had a separate ~120dp tall green "OTP card" with `text-size 44sp`, sitting visually disconnected from the green status banner directly above it. Two large boxes saying related things. Wasted vertical space.

**Rule**: OTP renders **inside** the existing status banner (`serviceStatusCard`), to the right of the title/subtitle column. Same coloured chrome (the banner's green/amber/blue background is the OTP's background too), typography matches — `22sp` bold with `letterSpacing 0.15`, white pill on coloured banner. Hint label "Your boarding OTP" sits directly under the digits at `10sp`. Standalone OTP card is deleted entirely from both layouts.

Same treatment in `EnterpriseRideStatusActivity` — OTP inline in the location-status banner at the top of the tracking screen, slightly smaller (`18sp`) to fit alongside the location-hint text.

**Single rendering helper** in `CarDashboardActivity.kt`:
```kotlin
private fun setBannerOtp(otp: String?) {
    if (otp.isNullOrBlank()) { binding.otpInlineGroup.visibility = View.GONE }
    else { binding.otpInline.text = otp; binding.otpInlineGroup.visibility = View.VISIBLE }
}
```
Called from: `enRouteReceiver`, `otpInvalidatedReceiver` (with null), `noShowReceiver` (with null), `fetchDriverFullDetails` no-driver branch (null), and the main success branch (with `body.otp` when `driver_arriving && !meDone`).

**Files**:
- `Zippi-User-Android-main/res/layout/activity_car_dashboard.xml` — removed standalone `otpCard` block; added `otpInlineGroup` / `otpInline` / `otpHint` inside `serviceStatusInner`.
- `Zippi-User-Android-main/res/layout/activity_enterprise_ride_status.xml` — removed standalone `otpCard` block; added `otpInlineGroup` inside `layoutLocationStatus`; `tvLocationHint` flexes (`layout_weight=1`) to share row with OTP.
- `Zippi-User-Android-main/.../activities/CarDashboardActivity.kt` — every `binding.otpCard.*` / `binding.otpText.*` replaced with `setBannerOtp(...)`.
- `Zippi-User-Android-main/.../activities/EnterpriseRideStatusActivity.kt` — `binding.otpCard.isVisible` → `binding.otpInlineGroup.isVisible` (the `tvOtpCode` id is kept as the inline OTP TextView, no rename needed).

**Doesn't change push handlers**: the OTP value still arrives via FCM data payload on `Enterprise_Driver_EnRoute`. Only the rendering target changed.

### L26. ETA in the status banner when driver is heading to this employee

When the driver has explicitly committed to this employee (`currently_servicing.is_me = true`) and the employee isn't already serviced (`me_done = false`), the dashboard banner shows live ETA: *"Arrives in ~7 min."* prefixed to the existing subtitle copy.

**Calculation** — server-side haversine inside `getDriverFullDetails` active-ride branch:

- **From**: `enterprise_drivers.latitude` / `longitude` (driver's current GPS, written every 5s by `LocationService`).
- **To**: the employee's home — `pickup_drop_latitude` / `pickup_drop_longitude` if the alternate point is set, otherwise the regular `latitude` / `longitude`.
- **Speed assumption**: 500 m/min (~30 km/h) — **identical to the per-stop ETA formula in `EnterpriseRideController::getDriverRoute`** so the dashboard banner and the tracking-screen map ETA always show the same number for the employee currently being serviced.
- **Output**: `eta_minutes = max(1, round(distance_m / 500))`. Floors at 1 min so the banner never reads "0 min" while the driver is still moving.
- **Null when**: any of (driver lat/lng, home lat/lng) is missing, OR `is_me=false`, OR `me_done=true`. The user app's banner subtitle then renders without the ETA prefix.

**Why straight-line haversine and not Google Directions**:
- No per-call cost (Google charges per Directions request).
- Refreshes every 10s via the existing poll without rate-limit pressure.
- Same approach already used by `EnterpriseRideStatusActivity` until Google Maps ETA kicks in via Firebase tracking.

**Why both directions share the destination "your home"**:
- Pickup: driver is en-route to your home to pick you up → ETA = arrival at your door.
- Drop: driver is en-route to your home to drop you off → ETA = arrival at your door.

**Files**:
- `app/Http/Controllers/Api/EnterpriseController.php` `getDriverFullDetails` — adds `eta_minutes` field computed only when `is_me && !meDone` and all coords present. Reuses the existing `haversineMeters` helper.
- `Zippi-User-Android-main/.../EnterpriseModel/DriverFullDetailsResponse.kt` — new `eta_minutes: Int?` field.
- `Zippi-User-Android-main/.../activities/CarDashboardActivity.kt` `applyCurrentlyServicingFromPoll` `is_me=true` branch — reads `body.eta_minutes`, prefixes "Arrives in ~X min. " to the existing subtitle.

**Not shown when** — driver is picking up someone else, ride hasn't started, employee is already picked / dropped / cancelled, employee is on the "boarding at office" phase, or driver coords are stale (null lat/lng in DB).

### L27. "Ride Completed" in-app dialog removed — push notification only

**Symptom (user feedback)**: the blocking AlertDialog *"Your ride has been completed. Have a great day!"* fired in two places — once on the dashboard when the next poll saw `has_active_ride=false` after a completion, and again on the tracking screen when its `handlePotentialRideEnd` guard tripped. Users found it intrusive given that the system-tray FCM push already told them the same thing.

**Rule**: remove **all** in-app "Ride Completed" AlertDialogs in the user app. The system push notifications stay untouched:
- **Drop** — `EnterpriseEmployeeDroppedNotification` (per-employee, fires when driver taps Mark Dropped). System heads-up + tray.
- **Pickup whole-ride complete** — `EnterpriseRideCompletedNotification` (bulk fan-out from `completeRide`, Pickup-only as gated in L5). System heads-up + tray.

**What the user sees post-completion now**:
- System notification arrives via FCM (unchanged).
- Dashboard banner / OTP card / stop card / live tracking — all dismiss silently.
- If applicable, the rating prompt (L24) mounts on the dashboard a moment later (Drop: immediately after `EnterpriseEmployeeDroppedNotification`; Pickup: after the parent ride flips to `status='completed'` per L24's direction-specific timing).

**Files**:
- `Zippi-User-Android-main/.../activities/CarDashboardActivity.kt` — the `if (hadActiveRide)` branch in `fetchDriverFullDetails` no longer builds an AlertDialog or navigates. It just resets `hadActiveRide = false` and lets the UI settle; the rating prompt path picks up from there. **Still applies to the unified dashboard introduced in L29.**
- ~~`Zippi-User-Android-main/.../activities/EnterpriseRideStatusActivity.kt`~~ — obsolete after L29 (activity dormant). Historical note: the original fix also removed dialogs from this activity's `handlePotentialRideEnd` and its `showRideUI` `"completed"` branch.

**Rationale**: the FCM push is the canonical user-visible signal. An in-app blocking dialog on top of it was redundant and forced the user to tap OK before doing anything else.

### L28. Dashboard resets per-employee on Drop completion (not when whole ride ends)

**Symptom**: Driver tapped "Mark Employee A as Dropped" → Employee A's rating prompt appeared (good), but the dashboard UI still showed ride-in-progress chrome: Track Driver button enabled, banner showing, stop card visible. Only when the driver eventually tapped Complete Shift (after dropping all employees) did Employee A's screen finally settle.

**Cause**: for Drop direction, the parent `enterprise_rides.status` stays `'started'` until the driver completes ALL drops. So `has_active_ride=true` was still true from Employee A's perspective long after they were dropped. The dashboard's "ride active" gate was a single `body.has_active_ride == true` check, which gave a stale UI for any employee who'd already been dropped.

**Rule**: the dashboard treats a per-employee terminal state (`me_done = true`) as "ride ended for me", independent of the parent ride's status. Other employees still in the vehicle continue to see the active-ride UI; the dropped employee's UI resets cleanly.

**Implementation (current — post-L29):**
- Direction-aware gate `showLiveMap = has_active_ride && !terminalForMe && (isPickup || !droppedForMe)` — see L29 for the full formula. Pickup keeps the map until the parent ride completes (employee is in the car heading to office); Drop closes the map per-employee at Mark Dropped.
- `applyMapMode(body)` is the single switch driving sheet state, map visibility, banner clear, OTP clear, and Firebase listener start/stop. No more separate Track button to enable/disable.
- The earlier `rideActiveForMe` gate still exists (used for the `hadActiveRide` telemetry flag) but it no longer drives UI chrome directly; `showLiveMap` does.

**Immediate refresh via FCM broadcast** — to avoid waiting up to 10s for the next poll:
- `employeeDroppedReceiver` on `CarDashboardActivity` listens for the `Enterprise_Employee_Dropped` LocalIntent that `MyFirebaseMessagingService` already broadcasts.
- Filters by `employee_id` — only the dropped employee's dashboard reacts.
- Triggers `fetchDriverFullDetails(silent=true)`. The next poll cycle catches `me_done=true` and `applyMapMode` flips the dashboard to idle.

**End-to-end UI transition after Mark Dropped (Drop direction) — post-L29**:

1. Driver taps Mark Employee A as Dropped → backend marks `enterprise_employee_rides.pin_verified=1, status='completed'` for A.
2. `EnterpriseEmployeeDroppedNotification` push fires to A's device (system-tray + heads-up).
3. User-app Firebase service broadcasts `Enterprise_Employee_Dropped` LocalIntent.
4. **Dashboard** receives the broadcast → fires immediate `fetchDriverFullDetails`.
5. The fresh poll response carries `me_done=true`, `pending_rating=...`, parent ride still `has_active_ride=true`.
6. `applyMapMode` sees `showLiveMap=false` → map fragment hides, bottom sheet expands to full height (idle mode), Firebase tracking stops, all per-stop chrome clears. Rating prompt mounts.
7. Whole transition: ~1-2s after the driver's tap.
8. Other employees (still in vehicle, `me_done=false`) keep `showLiveMap=true` — they continue seeing the live driver marker on their map.

**Files**:
- `Zippi-User-Android-main/.../activities/CarDashboardActivity.kt`:
  - `employeeDroppedReceiver` registered on `Enterprise_Employee_Dropped` filter; unregistered defensively on stop. (Still applies.)
  - Per-stop chrome clearing is now handled by `applyMapMode` flipping the active group to GONE — see L29.

**Pickup direction unchanged**: Pickup's "ride active for me" lifecycle naturally tracks the parent ride — when an employee is picked, they're in the car heading to office (Pickup-specific banner "On the way to office" already kicks in via the L19 matrix). Only Drop has the per-employee-vs-parent-ride mismatch.

**Why FCM broadcast + immediate fetch, not pushing the full state in the push itself**: the push payload only carries `ride_id` and `employee_id` — it's a *trigger*, not the source of truth. The dashboard always re-pulls from `getDriverFullDetails` so it gets the authoritative `me_status` + `pending_rating` + everything else in one consistent snapshot.

### L29. Unified dashboard — Uber-style map + bottom sheet, tracking activity dormant

**Symptom**: the user dashboard surfaced 13 separate UI chunks (driver photo, name, phone, vehicle, service banner, OTP, leave banners, completion banners, stop position, daily commute, night-shift note, Track button, Cancel, Support) and a separate `EnterpriseRideStatusActivity` was needed to actually *track* the driver on a map. User said it was overloaded and asked to merge tracking into the dashboard.

**Rule**: one screen, two modes. `CarDashboardActivity` now hosts both the assignment / commute view AND the live-tracking view. Mode is computed each poll from the response:

```kotlin
val isPickup       = body.direction == "Pickup"
val terminalForMe  = body.me_status in listOf("cancelled", "no_show")
val droppedForMe   = body.me_status in listOf("picked", "completed")
val showLiveMap    = body.has_active_ride == true
                     && !terminalForMe
                     && (isPickup || !droppedForMe)
```

- **Pickup**: map shows from driver-starts-shift until parent ride is `completed` (driver reaches office). Stays visible while I'm in the car heading to office.
- **Drop**: map shows from driver-starts-shift until **I specifically** am marked dropped. Map closes for me independently of when other employees finish (per L28 per-employee terminal).
- **Cancelled / no-show**: map never appears.

**Active mode (`showLiveMap = true`)** — Uber pattern:
- Full-screen `SupportMapFragment` behind everything. Two markers: driver (live, Firebase RTDB on `ID/{driver_id}`) + employee's home.
- Translucent toolbar over the map (back / Driver Details / Logout).
- Recenter FAB anchored to the bottom sheet's top edge; hidden until the user pans/zooms the map.
- `BottomSheetBehavior`-backed `NestedScrollView` peeks at ~170dp showing: status pill (title + ETA + inline OTP from L19/L25/L26), compact driver row (40dp photo + name + "phone · vehicle" + call icon), cancel button. Drag up → expanded reveals everything; never fully hides.

**Idle mode (`showLiveMap = false`)**:
- Map fragment `visibility = GONE`. Sheet `peekHeight = 0` and `STATE_EXPANDED` — it acts as a normal full-height scroll container with the same compact driver row + leave banners + daily-commute card + Apply Leave / Call Support buttons.
- Cancel button hidden (no live ride to cancel).
- Recenter FAB hidden. `userInteractedWithMap` reset.

**Completion indicator merged into the commute card** (L25 visual cleanup completed): the standalone green "✓ Morning Pickup completed for today" banner stack is gone. Instead `renderDailyCommute` checks `body.today_completed_shifts` and, when today's matching shift is in the list, replaces the time hint on that leg ("≈ before 08:00") with a green "✓ Completed" pill (#2E7D32). Saves ~80dp vertical and a redundant card. `renderTodayCompletedShifts` is deleted.

**Ported from `EnterpriseRideStatusActivity`** (now dormant):
- `setupMapFromDetails(body)` — adapted to read coords from `DriverFullDetailsResponse` (driver lat/lng + employee lat/lng) instead of the route-stop list. Markers, camera bounds, REST-fallback driver position.
- `startFirebaseTracking(driverUserId)` — verbatim port. `FirebaseDatabase.getInstance(Constants.FIREBASE_REALTIME_DB_URL).getReference("ID").child(driverUserId)`, ValueEventListener updates `driverMarker` and animates camera bounds if `!userInteractedWithMap`.
- `stopFirebaseTracking()` — removes listener, clears refs. Called when `showLiveMap` transitions to false AND in `onDestroy` defensively.
- `recenterMap()` — wraps both markers' bounds or falls back to single-marker zoom.
- ETA: NOT ported as a separate Google Distance Matrix call. The dashboard already gets `eta_minutes` from `getDriverFullDetails` (L26, formula unified with the tracking screen) — the status pill reuses it as before.

**FCM intent retargeting**: every `startActivity(Intent(..., EnterpriseRideStatusActivity::class.java))` in the user app codebase now points at `CarDashboardActivity` instead. Affected:
- `MyFirebaseMessagingService.java` — `handleenterpriseBookingRequest` tap intent + a second handler near line 445 — both repointed.
- `DashboardActivity.kt` — the `getEnterpriseRideDetails` success path that opened the old activity.
- `CarDashboardActivity.kt` — the `binding.trackRideBtn` click listener that previously launched the old activity is **removed**. The `trackRideBtn` view ID survives in the layout (`width/height=0dp`, `visibility=gone`) purely for view-binding compatibility; no listener is wired.

**`EnterpriseRideStatusActivity` is kept in the codebase but unreferenced** (rollback choice). Grep for `EnterpriseRideStatusActivity::class.java` or `.class` returns zero matches; the class compiles but is never instantiated. If the merged dashboard regresses, restoring the old screen is a one-line edit.

**Files**:
- `Zippi-User-Android-main/app/src/main/res/layout/activity_car_dashboard.xml` — full rewrite. Root is `CoordinatorLayout`; contains `mapHost` FrameLayout holding `SupportMapFragment`, toolbar, recenter FAB anchored to the sheet, `NestedScrollView` bottom sheet with `activeStatusGroup` + always-visible driver row + `idleStatusGroup` (leaves container + commute card). Existing IDs (`serviceStatusCard`, `tvServiceTitle`, `otpInline`, `tvHomeAddressMorning`, etc.) preserved so all existing render code keeps working. Legacy IDs (`driverNumber`, `vehicleNumber`, `pickupLocation`, `dropLocation`, `stopPositionCard`, `trackRideBtn`) kept as 0dp hidden views purely for view-binding compat — no new code touches them.
- `Zippi-User-Android-main/.../activities/CarDashboardActivity.kt` — adds `OnMapReadyCallback`, the map/Firebase state fields, `applyMapMode(body)` central switch, `setupMapFromDetails`, `startFirebaseTracking`, `stopFirebaseTracking`, `recenterMap`, `resizeBitmap`, `dpToPx`. `renderTodayCompletedShifts` deleted. `renderDailyCommute` enriched to set "✓ Completed" + green color on the matching leg. `fetchDriverFullDetails` success path simplified: `applyMapMode(body)` replaces the old per-stop card + Track button branching. `onDestroy` calls `stopFirebaseTracking`.
- `Zippi-User-Android-main/.../firebase/MyFirebaseMessagingService.java` — both tap intents repointed to `CarDashboardActivity`. Old import deleted.
- `Zippi-User-Android-main/.../activities/DashboardActivity.kt` — `getEnterpriseRideDetails` success branch repointed to `CarDashboardActivity`.
- `Zippi-User-Android-main/app/src/main/res/drawable/circle_white_bg.xml` — new (small drawable for the call-icon background).

**Doesn't change**: any backend, the response model fields, FCM payloads, broadcast filters, the rating prompt path (L24), the L28 `employeeDroppedReceiver`, the L21 ride-end guard (which now lives only on the dormant activity).

### L30. Driver dashboard — "Today's Overview" card + polished Schedule route

**Symptom**: the enterprise driver's Dashboard was extremely plain — just a map fragment + a small bottom card with driver name + "Schedule Rides" button. No at-a-glance signal for "what's my day look like", whether morning Pickup was already done, or how many rides this month. Driver had to navigate into Schedule Rides → pick today's date → pick direction to see status.

**Rule**: the driver Dashboard now surfaces a compact **"Today's Overview"** card between the existing bottom name row and the Schedule Rides button. One backend call drives it.

**Card content**:

```
┌─────────────────────────────────────────────┐
│  TODAY · Wed 23 May          [Working day]  │
│                                             │
│  ┌─────────────────┐  ┌─────────────────┐  │
│  │ ☀ Morning Pickup │  │ 🌙 Evening Drop  │  │
│  │ ✓ Done                │  │ Pending             │  │
│  │ at 09:15 · 47 min ·   │  │                     │  │
│  │ 3 employees            │  │                     │  │
│  └─────────────────────┘  └─────────────────────┘  │
│  ─────────────────────────────────────────────────  │
│  ZIPPI · ABC123      Your Rating: ★ 4.7 · 482 km   │
└─────────────────────────────────────────────────────┘
```

Each shift chip renders one of four states (driven by today's ride row's `status`):

| `status` | Pill text | Color |
|---|---|---|
| `pending` (no row exists) | "Pending" | grey |
| `started` | "Running now" + `"N employee(s)"` | blue |
| `completed` | "✓ Done" + `"at HH:MM · X min · N employee(s)"` | green |
| `emergency_stopped` / `reassigned` | "Closed by admin" | red |

The **Working-day pill** (top-right of the card) flips to "Day off" when the driver has no active assignment (`enterprise_assigned_drivers.status=false` everywhere). Pre-emptive signal — driver knows they're not on duty before they tap anything.

The **footer line** shows the assignment (`company_name · vehicle_no`) on the left and the driver's **lifetime employee rating + month-to-date km** on the right:

- `Your Rating: ★ 4.7 · 482 km` — both present.
- `Your Rating: ★ 4.7` — no km tallied yet (fresh month, no completed rides).
- `482 km` — driver has km but no employee has rated them yet.
- Hidden (blank) when both null (fresh driver, no activity).

The star value is the **all-time** average employee rating computed from `enterprise_employee_rides.rating` for this driver. **No date filter** — rating is overall / lifetime, not monthly. Drivers see one stable headline number that improves slowly as more rides are rated.

Total km still comes from `SUM(enterprise_rides.distance)` (km units, populated by `startRide`'s nearest-neighbour haversine). Older rides with `distance=NULL` are skipped harmlessly. Whether km should also be lifetime vs month-to-date is a follow-up choice; today it's the month bucket (`whereBetween DATE(started_at) [monthStart, monthEnd]`).

**Backend endpoint** (new) — `GET /api/enterprise/driver/today-overview`:

```json
{
  "status": true,
  "today": {
    "date": "2026-05-23",
    "weekday": "Wednesday",
    "is_working_day": true,
    "pickup": { "status": "completed", "completed_at": "2026-05-23T09:15:00+05:30",
                "duration_min": 47, "employee_count": 3 },
    "drop":   { "status": "pending", "completed_at": null,
                "duration_min": null, "employee_count": null }
  },
  "month": {
    "total_rides": 42,
    "completed_rides": 38,
    "avg_rating": 4.7,
    "total_km": 482
  },
  "assignment": {
    "company_name": "ZIPPI",
    "driver_name":  "driverzippi",
    "vehicle_no":   "ABC123"
  }
}
```

Auth: driver (Sanctum). One DB query joins `enterprise_rides` filtered to today for the per-shift block; one aggregate query covers the month totals; one quick existence check on `enterprise_assigned_drivers` populates `is_working_day`. Returns in ~50ms even at scale because all keys are indexed.

**Schedule Rides screen polish** (the date-picked route summary card):
- **Coloured direction strip** down the card's left edge (4dp wide). Yellow `#F57F17` for Pickup, blue `#1565C0` for Drop. Same logic colours the direction badge in the header. Driver tells morning from evening at a glance.
- **New stats line** under the title — *"6.4 km · ~12 min driving"*. Derived from the existing `total_distance_meters` field on each route + the same 500 m/min ≈ 30 km/h formula used for ETA elsewhere (L26). No new API field.
- Header typography tightened so the title + badge no longer crowd each other.

**Files**:
- `staging/app/Http/Controllers/Api/EnterpriseController.php` — new `todayOverview(Request)` method. Reads from `enterprise_rides` + `enterprise_assigned_drivers` + `enterprise_companies`. No model changes.
- `staging/routes/api.php` — `GET /enterprise/driver/today-overview` under the existing `auth:sanctum` group.
- `Zippi-Driver-Android-main/.../models/DriverTodayOverviewResponse.kt` — new (4 nested data classes).
- `Zippi-Driver-Android-main/.../retrofit/ApiInterface.kt` — `getDriverTodayOverview(token)` Retrofit method.
- `Zippi-Driver-Android-main/.../res/layout/activity_dashboard.xml` — new `enterpriseOverviewCard` CardView inserted between the bottom name row and the existing `normallayout`/`enterpriselayoutbtn` button. Hidden by default.
- `Zippi-Driver-Android-main/.../activities/Dashboard.kt` — toggles `enterpriseOverviewCard.visibility=VISIBLE` for `isEnterpriseDriverPref==1`; calls new `fetchTodayOverview()` + `renderTodayOverview(body)` (the latter handles the four status states + Working-day pill + footer formatting).
- `Zippi-Driver-Android-main/.../res/layout/activity_enterprice_ride_schdule.xml` — added `routeDirectionStrip` 4dp View + `tvRouteStats` TextView inside the route card; restructured root to horizontal `LinearLayout` so the strip flows down the left edge.
- `Zippi-Driver-Android-main/.../activities/EnterpriceRideSchdule.kt` — colours the strip + badge by direction; populates `tvRouteStats` from `route.totalDistanceMeters`.

**No user-app changes.** Driver-side feature.

### L31. Dashboard refinements — overview refresh, Apply Leave below Support, no-driver chrome, admin rating column

A bundle of small refinements following user feedback on L29 + L30.

**a) Driver Dashboard overview card now refreshes on every `onResume`**

Symptom: driver tapped Complete Shift in `EnterpriseRideFlowActivity` → came back to the Dashboard → the "Today's Overview" card still showed Pickup as "Pending". Avg rating and km totals were also stale.

Cause: `fetchTodayOverview()` was only called once in `Dashboard.onCreate` when `isEnterpriseDriverPref == 1`. Coming back from another activity in the same task does not re-run `onCreate`.

Fix: `Dashboard.onResume()` now calls `fetchTodayOverview()` for enterprise drivers. Every return to the Dashboard re-pulls today's shift statuses + rolling-month rating + km.

**b) Apply Leave button reordered below Call Support**

User chose to keep the same button always-visible across modes (idle = yellow "Apply Leave", active = red "Cancel My Ride"), but wanted it physically positioned BELOW Call Support on the user-app dashboard's bottom sheet. Done by moving the `cancelButton` view in `activity_car_dashboard.xml` to sit after `supportButton` in the bottom-sheet content stack.

**c) `cancelButton` click listener hoisted to `onCreate`**

Symptom: when no driver was assigned, tapping Apply Leave did nothing.

Cause: the click listener was wired inside the `if (driver != null)` branch, which `return`s early in the no-driver path — so the listener never bound.

Fix: hoisted `binding.cancelButton.setOnClickListener { ... }` to `CarDashboardActivity.onCreate` where it's wired exactly once regardless of driver state. Falls back gracefully via `showSnack` if no employee_id is available.

**d) Defensive `driverInfoRow` visibility + no-driver call-button hide**

`binding.driverInfoRow.isVisible = true` is explicitly set in both the driver-assigned and no-driver branches of `fetchDriverFullDetails` success path — so any prior layout state can never accidentally hide the row.

`binding.callBtn.isVisible` is now flipped per branch: visible when a driver exists, gone when no driver (no number to dial).

**e) Admin drivers list — lifetime rating column**

`/admin/enterprise/drivers` table now shows a **Rating** column with each driver's lifetime average + count, formatted `★ 4.7 (32)`. Falls back to a grey "Not rated" pill when no ratings exist for that driver.

Server-side: `EnterpriseDriverController::index` adds one aggregate query over `enterprise_employee_rides`:

```php
$ratingStats = EnterpriseEmployeeRide::whereIn('driver_id', $driverIds)
    ->whereNotNull('rating')
    ->selectRaw('driver_id, ROUND(AVG(rating), 1) as avg_rating, COUNT(*) as rating_count')
    ->groupBy('driver_id')
    ->get()
    ->keyBy('driver_id');
```

Passed to the view as `$ratingStats` (keyed by driver_id so the Blade renders in O(1) per row).

**Files**:
- `Zippi-Driver-Android-main/.../activities/Dashboard.kt` — `onResume` calls `fetchTodayOverview()` for enterprise drivers.
- `Zippi-User-Android-main/.../res/layout/activity_car_dashboard.xml` — `cancelButton` moved AFTER `supportButton` in the sheet content. The early-position copy of `cancelButton` was removed.
- `Zippi-User-Android-main/.../activities/CarDashboardActivity.kt` — `cancelButton` click listener moved to `onCreate`; explicit `driverInfoRow.isVisible = true` + `callBtn.isVisible` toggling on driver presence; no-driver path no longer short-circuits the button wiring.
- `staging/app/Http/Controllers/Admin/EnterpriseDriverController.php` — `index()` joins ratings and passes `$ratingStats`.
- `staging/resources/views/admin/enterprise_drivers/index.blade.php` — new `Rating` column (★ avg + count, or "Not rated" pill).

**What was removed**:
- The earlier `cancelButton` block at its original top position in `activity_car_dashboard.xml` (replaced with a comment noting the move).
- The duplicate `cancelButton.setOnClickListener` inside the driver-non-null branch of `fetchDriverFullDetails` (replaced with a comment pointing at the `onCreate` listener).
- ~~No blocking "Driver Not Assigned" AlertDialog~~ — already removed earlier; mentioned again for completeness so future readers see why the no-driver path doesn't pop a dialog.

### L32. UI polish round 2 — sheet height cap, FAB, leaves below buttons, lifetime rating

A second round of UI polish on the user-app dashboard + driver-app overview, after seeing the L29/L30 rollout in practice.

**a) Bottom sheet expanded offset — fixes driver row hidden behind toolbar**

Symptom: in idle mode the driver assignment row at the top of the bottom-sheet content was invisible to the user; the screen jumped straight from toolbar to "YOUR DAILY COMMUTE".

Cause: the sheet was expanding to `y=0` (top of CoordinatorLayout) when `STATE_EXPANDED` + `peekHeight=0`. The translucent toolbar with `elevation=2dp` painted over the top ~56dp of the sheet content, hiding the drag handle + driver row.

Fix: `bottomSheet.expandedOffset = dpToPx(56)` so the sheet's top edge stops at the toolbar's bottom edge in idle mode. Driver row + drag handle render below the toolbar, visible.

**b) Sheet height capped at 70% of screen in active mode**

User complained the sheet went full-screen when dragged up, fully covering the map. Fix in `applyMapMode` active branch: `bottomSheet.expandedOffset = (screenH * 0.30).toInt()` — sheet stops 30% from the top, covers max 70%. Driver / status / OTP card all reachable; map ~30% always visible above.

Idle mode still uses the 56dp toolbar offset (no map, full content area below toolbar).

**c) Recenter FAB pulled above the drag handle**

Was anchored to `top|end` of the sheet which placed it AT the drag-handle edge. Added `android:layout_marginBottom="56dp"` on `btnRecenter` so it floats clearly above the sheet, on the map. Easier to spot and tap without grabbing the drag handle by accident.

**d) Leave / no-show banners moved BELOW the action buttons**

User asked: "bring leave card below cancel" and "from now on bring all new cards below". `upcomingLeavesContainer` moved out of `idleStatusGroup` and placed AFTER `cancelButton` in the bottom-sheet content order. Its visibility now toggles directly via `applyMapMode` (visible in idle, gone in active). Order from top to bottom in idle mode:

```
drag handle → driver row → daily commute → Call Support → Apply Leave → leave/no-show banners
```

Any future banner cards should follow this rule: append below the existing button stack, not above.

**e) Lifetime rating instead of monthly**

`todayOverview.month.avg_rating` query no longer date-filters by month. It's now the driver's **all-time** average employee rating — a stable headline number that improves slowly as ratings accumulate.

Driver-app footer text changed accordingly: `"Your Rating: ★ 4.7 · 482 km"` (no more "this month" suffix). Variations:
- Both present → `"Your Rating: ★ 4.7 · 482 km"`
- Only rating → `"Your Rating: ★ 4.7"`
- Only km → `"482 km"`
- Neither → blank

The admin panel (`/admin/enterprise/drivers` list) was already lifetime — no change needed there.

**f) `"emps"` → `"employee(s)"` in shift chips**

Pluralized: `"1 employee"` / `"3 employees"`. Applied in both the `completed` chip subtitle (`"at HH:MM · X min · N employees"`) and the `started` chip (`"N employees"`). Same rule everywhere employee count is shown.

**Files**:
- `Zippi-User-Android-main/.../res/layout/activity_car_dashboard.xml` — `btnRecenter` `marginBottom="56dp"`; `upcomingLeavesContainer` relocated after `cancelButton`.
- `Zippi-User-Android-main/.../activities/CarDashboardActivity.kt` — `bottomSheet.expandedOffset = dpToPx(56)` in `onCreate`; active branch sets `expandedOffset = screenH * 0.30`; idle branch resets to 56dp; `upcomingLeavesContainer.isVisible` toggled in both branches.
- `staging/app/Http/Controllers/Api/EnterpriseController.php` `todayOverview` — `avg_rating` query no longer has `whereBetween DATE(rating_submitted_at)` filter; now `WHERE rating IS NOT NULL` only.
- `Zippi-Driver-Android-main/.../activities/Dashboard.kt` — footer formatter changed to `"Your Rating: ★ X.X · Y km"`; `"emps"` replaced with pluralized `"employee(s)"` in both `completed` and `started` chip paths.

**What was changed/removed compared to earlier docs**:
- L30's footer copy `"★ 4.7 · 482 km this month"` is now `"Your Rating: ★ 4.7 · 482 km"` (no "this month" — rating is lifetime).
- L30's chip subtitle `"X min · N emps"` is now `"X min · N employees"`.
- L30's `avg_rating` description said "this calendar month" — it's now **all-time / lifetime**.

### L33. Driver row pinned outside the bottom sheet (topChrome)

L32's `expandedOffset = dpToPx(56)` placed the sheet's top edge just below the toolbar, but the driver row was still **inside** the bottom sheet's scrollable content. Even at full expansion (idle mode), the row drew at the very top of the sheet — and we still got recurring reports of "still can't see driver details" because:

- The drag-handle pill (8dp top margin + 4dp height + 12dp bottom margin = ~24dp) sat above it inside the sheet.
- Any sheet state below `STATE_EXPANDED` immediately hid the row by sliding it down.
- The status-banner card (when present) was rendered BEFORE the driver row in the sheet's content order.

Fix: **extract the driver row from the sheet entirely**. New top-level `topChrome` LinearLayout (vertical) lives directly inside the CoordinatorLayout, sibling to `bottomSheet`. It contains:

```
topChrome (elevation=4dp, white)
 ├─ toolbar (back / "Driver Details" / Logout)
 ├─ divider (1dp #EEEEEE)
 └─ driverInfoRow (avatar / name / meta / call)
```

The bottom sheet now floats below `topChrome`. `expandedOffset = topChrome.height` (measured async via `binding.topChrome.post { ... }`, fallback `dpToPx(130)` until layout completes). In active mode the offset is `max(topChrome.height, screenH * 0.30)` so the pinned row + ~30% map remain visible even when the sheet is dragged to full expansion.

Result: the driver row is **always visible** regardless of sheet state (collapsed, expanded, mid-drag, no-driver branch). The "No driver assigned" placeholder also stays visible at all times.

**Side effects / cleanups**:
- `binding.driverInfoRow.isVisible = true` calls in `fetchDriverFullDetails` (both no-driver and driver-assigned branches) are removed — visibility is structural now, not toggled.
- The duplicate `driverInfoRow` block inside `bottomSheetContent` was removed. Only `topChrome`'s copy exists.
- The drag-handle pill stays inside the sheet (it should — it sits ABOVE the active-status card / daily commute, signalling drag intent).
- `view-binding` IDs `driverInfoRow`, `profileImage`, `driverName`, `driverMeta`, `callBtn`, `vehicleNumber`, `driverNumber` are now resolved against `topChrome`. Existing references in `CarDashboardActivity` unchanged.

**Files**:
- `Zippi-User-Android-main/.../res/layout/activity_car_dashboard.xml` — new `topChrome` LinearLayout wrapping toolbar + divider + driverInfoRow; removed duplicate driverInfoRow from inside bottomSheetContent.
- `Zippi-User-Android-main/.../activities/CarDashboardActivity.kt`:
  - `onCreate`: `bottomSheet.expandedOffset = dpToPx(130)` fallback + `binding.topChrome.post` to update with measured height.
  - `applyMapMode`: `chromeH = binding.topChrome.height ?: dpToPx(130)`; active → `expandedOffset = max(chromeH, screenH * 0.30)`; idle → `expandedOffset = chromeH`.
  - Removed both `binding.driverInfoRow.isVisible = true` lines from `fetchDriverFullDetails` success path.

**What was changed/removed vs L32**:
- L32 placed `driverInfoRow` inside `bottomSheetContent` between the active status card and the cancel button. That layout is gone — only the `topChrome` copy remains.
- L32's idle-mode `expandedOffset = dpToPx(56)` (toolbar-only) is now `chromeH` (toolbar + driver row, ~130dp). Active-mode 30% cap is now `max(chromeH, 30%)`.

### L34. Driver card redesign + mode-aware placement (pinned in idle, in-sheet in active)

Two complaints the user raised against L33's pinned row:
1. "fix that driver details ui. it looks very static" — the flat `LinearLayout` row had no visual hierarchy.
2. "in next screen when driver begins ride: keep the driver details section, in that bottom container. layout: status card, driver details, call supp, apply leave." — the row should live INSIDE the sheet during a live ride, ordered between the status banner and the action buttons.

**a) Driver card visual redesign**

`driverInfoRow` is no longer a plain `LinearLayout`. It's now a `CardView` (cardCornerRadius=14dp, cardElevation=2dp, white bg, 12dp side margin) containing:
- 4dp vertical accent strip (primaryDark) on the left edge — the same hero-bar device used by the leave cards.
- 52dp `CircleImageView` avatar (up from 44dp) with a 1dp `#E0E0E0` border.
- Text column: small "YOUR DRIVER" label (10sp uppercase #9E9E9E), driver name (16sp bold #212121), meta (12sp #616161).
- 44dp circular call button — a 22dp-corner `CardView` (cardBackgroundColor=#E0F2F1 soft teal) wrapping the existing phone ImageView. Renders as a tappable FAB-style icon.

The card now has clear visual hierarchy and depth, no longer reads as a flat label.

**b) Two copies of the card — mode-aware placement**

`activeStatusGroup` (the bottom-sheet block that only shows while a ride is live) gets a SECOND copy of the same card with `Sheet`-suffixed IDs: `driverInfoRowSheet`, `profileImageSheet`, `driverNameSheet`, `driverMetaSheet`, `callBtnSheet`. The sheet content order in active mode becomes exactly what the user asked for:

```
serviceStatusCard → driverInfoRowSheet → Call Support → Apply Leave
```

`applyMapMode` toggles visibility:
- **Idle**: `driverInfoRow` (in topChrome) visible, `driverInfoRowSheet` gone. Sheet `expandedOffset = topChrome.height` (toolbar + pinned card).
- **Active**: `driverInfoRow` (in topChrome) gone, `driverInfoRowSheet` visible. Sheet `expandedOffset = max(toolbar.height, screenH * 0.30)` — the toolbar stays on top, the driver card rides the sheet.

`binding.toolbar.height` is used as the active-mode floor (instead of `topChrome.height`) since the pinned card is now hidden in active mode and `topChrome` shrinks to just the toolbar.

**c) `populateDriverCard(driver: DriverData?)` helper**

New helper in `CarDashboardActivity` writes name / meta / avatar / phone-listener to BOTH cards at once. Called from both branches (no driver / driver assigned) of `fetchDriverFullDetails`. Replaces the previous scattered `binding.driverName.text = ...`, `Glide.with(...).into(profileImage)`, and `binding.callBtn.setOnClickListener { ... }` blocks.

Keeps the two cards in sync regardless of which one is currently on-screen.

**Files**:
- `Zippi-User-Android-main/.../res/layout/activity_car_dashboard.xml`:
  - `driverInfoRow` rewritten as a `CardView` with accent strip + label + larger avatar + circular call button. Divider between toolbar and card removed (card's elevation+margin gives enough separation).
  - New `driverInfoRowSheet` block (same design, Sheet-suffixed IDs) appended inside `activeStatusGroup` after `serviceStatusCard`.
- `Zippi-User-Android-main/.../activities/CarDashboardActivity.kt`:
  - New `populateDriverCard(driver)` helper.
  - `fetchDriverFullDetails` calls it instead of writing to individual binding fields.
  - `applyMapMode` toggles `driverInfoRow` ↔ `driverInfoRowSheet` visibility; both branches finish by calling `syncSheetOffsetToChrome()` (see below).

**d) Dynamic sheet offset — no more overlap on any screen size**

L33's `binding.topChrome.post { expandedOffset = ... }` fired once on the first layout pass and was supposed to push the sheet down so it didn't render behind the pinned driver card. It didn't work in practice: `BottomSheetBehavior.setExpandedOffset()` does NOT trigger a re-settle when the sheet is already in `STATE_EXPANDED` — the stored value just sits in the behavior object until the next state change. Result: sheet stayed at y=0 with the Morning Pickup section of the daily commute card permanently hidden behind topChrome's elevation shadow.

**Fix — padding-based offset for idle mode** (per-screen-size dynamic):

For idle mode, leave `expandedOffset = 0` (sheet covers the full screen at y=0) and instead push the visible content down with `bottomSheetContent.paddingTop = topChrome.height + 2dp`. The pinned topChrome (elevation=4dp) draws over the sheet's top region; the padding ensures the visible content (drag handle, daily commute, buttons, leaves) starts BELOW topChrome. The 2dp slack lets the driver card's elevation shadow render without being clipped by the sheet's rounded top edge.

For active mode, `expandedOffset` works correctly because the transition is `STATE_EXPANDED → STATE_COLLAPSED → STATE_EXPANDED` (when user drags up) — the COLLAPSED step forces a re-settle. We keep `expandedOffset = max(toolbar.height, screenH * 0.30)` here so the map area is preserved above the sheet.

Continuous layout-change listeners on `topChrome` and `toolbar` keep the padding in sync with the current chrome height — rotation, font scale changes, screen size, and idle ↔ active visibility flips all just work. No more 130dp / 160dp fallback magic numbers — the live measured height is the source of truth.

Peek height in active mode also became responsive: `(screenH * 0.25).coerceIn(140dp, 220dp)` so on small phones the status pill is just visible and on tall phones it doesn't peek absurdly high.

**What was changed/removed vs L33**:
- L33's single pinned `driverInfoRow` is now mode-aware (idle only). A second in-sheet copy takes over in active.
- L33's `chromeH = topChrome.height` is split: idle still uses topChrome height, active uses toolbar height (smaller, since pinned card is hidden).
- L33's plain LinearLayout driver row is now a CardView with full hero-card design.
- L33's one-shot `post {}` measurement is now a continuous layout-change listener (handles screen size, rotation, font scale, dynamic visibility changes).

### L35. Auth-screen keyboard animation works on pre-Android-11 / OEM builds

Login, OTP, and Set-Up (registration) all had the same pattern:

```kotlin
ViewCompat.setWindowInsetsAnimationCallback(
    binding.root,
    object : WindowInsetsAnimationCompat.Callback(DISPATCH_MODE_STOP) {
        override fun onProgress(insets, ...): WindowInsetsCompat {
            val imeBottom = insets.getInsets(WindowInsetsCompat.Type.ime()).bottom
            ...
            binding.cardBg.translationY    = shift
            binding.formScroll.translationY = shift
            return insets
        }
    }
)
```

This callback's per-frame IME tracking only fires on **Android 11+ (API 30+)**. On older devices — and on a number of OEM-customised Android 10 builds — `onProgress` was never invoked, so the form stayed put when the keyboard opened and got hidden behind it.

**Fix** — promote the wiring to a `BaseActivity.wireKeyboardAnimation(rootView, vararg viewsToShift)` helper that keeps the modern callback AND adds a fallback `ViewTreeObserver.OnGlobalLayoutListener`:

- Modern callback (API 30+): unchanged behaviour, smooth per-frame translation synced to the IME animation.
- Fallback listener (all APIs): polls `getWindowVisibleDisplayFrame()` on each layout pass, computes `keypadHeight = rootView.height - visibleFrame.bottom`, and when crossing the >15% threshold, animates `translationY` via `.animate().setDuration(220)`.
- A `imeAnimatingViaCallback` flag (set in `onPrepare`, cleared in `onEnd`) makes the fallback skip its own `.animate()` whenever the modern callback is actively driving the shift — no double-animation on API 30+.

Three screens migrate from the inline callback to a one-line call:
- `Login.kt`: `wireKeyboardAnimation(binding.root, binding.cardBg, binding.formScroll)`
- `OtpActivity.kt`: `wireKeyboardAnimation(binding.root, binding.cardBg, binding.formScroll)`
- `SetUpActivity.kt`: `wireKeyboardAnimation(binding.root, binding.cardBg, binding.formContent)`

**Files**:
- `Zippi-User-Android-main/app/src/main/java/com/zippi/user/BaseActivity.kt` — added `wireKeyboardAnimation()` + `navBarHeightPx()`.
- `Zippi-User-Android-main/.../activities/Login.kt` — replaced 14-line inline callback with helper call; cleaned unused import.
- `Zippi-User-Android-main/.../activities/OtpActivity.kt` — same.
- `Zippi-User-Android-main/.../activities/SetUpActivity.kt` — same; also dropped unused `ViewCompat` import.

No manifest, layout, or theme changes — `windowSoftInputMode="adjustNothing"` is preserved on all three screens, the existing `fitsSystemWindows="true"` on the root layouts still handles the status-bar inset.

### L36. Track Driver gating + Variant B "Hero + Tiles" Driver Details redesign

Two related changes shipped together:

**a) Track Driver gating — no more auto-yank to the map**

Previously, the user app's `applyMapMode()` flipped to full-screen map mode the instant `body.has_active_ride == true`. Per user feedback, that's too aggressive — many employees just want to see "driver started" without surrendering the whole screen to a live map until they explicitly ask for it.

New behaviour: `applyMapMode()` now distinguishes between two flags:

```kotlin
val rideIsActive = body.has_active_ride == true && !terminalForMe && (isPickup || !droppedForMe)
if (!rideIsActive) userOptedIntoTracking = false  // reset when ride ends
val showLiveMap = rideIsActive && userOptedIntoTracking
```

- `rideIsActive` — the existing logic (driver has begun a shift relevant to me).
- `userOptedIntoTracking` — a new `CarDashboardActivity` field. False on activity start. Flipped to `true` when the user taps the new "Track Driver" button. Auto-resets to `false` when `rideIsActive` becomes `false` (ride ends), so the next ride starts in the gated state again.

Three visible states now:
1. **Idle / no ride** — daily commute card, action tiles, no status banner.
2. **Ride active, not tracking** — pinned driver card, status banner ("Driver started shift" / "Driver is on the way"), daily commute card, prominent **Track Driver** button above the tiles, plus tiles. **No map.**
3. **Map / tracking** — full-screen map + bottom sheet with status banner, in-sheet driver card, tiles. (Same as the old "active" mode.)

`activeStatusGroup` visibility is now gated on `rideIsActive` (so the status banner shows in state 2 even without the map). `upcomingLeavesContainer` hides whenever `rideIsActive` so a stale "Leave applied" banner doesn't sit next to a live status banner.

**b) Variant B "Hero header + tiles" design — Driver Details idle screen**

Implemented the design picked from the Claude Design handoff bundle (chat history: user picked "B · Hero header + tiles" from three options). Files imported from the bundle's `screens.jsx`. Key visual changes:

- **Teal gradient hero** at top (`#14C9B0 → #0FA896`) replaces the white toolbar + driver-card layout. Background drawable `zp_hero_gradient.xml`.
- **Toolbar**: back button is a 36dp translucent-white pill, "Driver Details" title and "Logout" both bold white.
- **Driver row inside the hero**: 56dp avatar in a translucent-white circle backdrop (`zp_circle_white_translucent.xml`); "YOUR DRIVER" 11sp tracked label; 19sp bold name; 12sp 85%-opacity meta; raised 46dp white circular phone button with `cardElevation=6dp` and teal phone icon.
- **Action tiles** (2-column grid, weight=1 each) replace the old stacked full-width buttons:
  - Apply Leave — cream `#FFF1DC` bg, white inner icon container, coral `#D87715` calendar icon, "Apply Leave" / "Skip a ride".
  - Call Support — teal-bg `#E4F9F4`, teal `#0FA896` phone icon, "Call Support" / "Mon–Sat · 24×7".
  - View IDs `cancelButton` and `supportButton` preserved on the CardView wrappers so existing click wiring keeps working.
- **Track Driver** button (state 2) sits ABOVE the tiles row, full-width primary `primaryDark` background, location-pin drawableStart, bold white text.

Skipped from Variant B to keep scope tight (call them out separately if needed):
- Segmented Morning/Evening toggle floating with `marginTop: -16dp` over the hero.
- Week-strip day pills + "View all" link (no per-day commute schedule data wired yet).
- Route card with FROM-circle + dotted line + TO-square + status pills — the existing two-row "Morning Pickup / Evening Drop" daily-commute card is preserved as-is for now.

**Drawables added**:
- `zp_hero_gradient.xml` — teal-to-tealDk vertical gradient
- `zp_circle_white_translucent.xml` — semi-transparent white circle (avatar backdrop)
- `zp_circle_white_shadow.xml` — solid white circle (raised phone button)
- `zp_route_dot.xml` / `zp_route_square.xml` / `zp_route_line_dashed.xml` — for the future route block
- `baseline_calendar_today_24.xml` — Material calendar icon vector

**Kotlin changes** (`CarDashboardActivity.kt`):
- New `userOptedIntoTracking` field; reset in `applyMapMode` when `rideIsActive=false`.
- `trackRideBtn.setOnClickListener` in `onCreate` — flips the gate and re-runs `applyMapMode` against the cached response.
- `applyMapMode` rewritten to compute `rideIsActive` and `showLiveMap` separately; visibility of `trackRideBtn`, `activeStatusGroup`, `upcomingLeavesContainer` now reflects all three states.
- Removed the dynamic `cancelButton.text` / `backgroundTintList` / `setTextColor` lines — the tile's text/colour are now baked into XML.

**Layout changes** (`activity_car_dashboard.xml`):
- `topChrome` background → `@drawable/zp_hero_gradient`, paddingBottom=26dp.
- `toolbar` children retinted white on teal; back-button pill is `#2EFFFFFF`.
- `driverInfoRow` flattened from a CardView with accent strip back into a transparent `LinearLayout` (the card visual is now the hero itself); avatar wrapped in a `FrameLayout` with translucent backdrop + CircleImageView.
- Phone button → 46dp white CardView with elevation 6dp.
- Action buttons section → 2-col `LinearLayout` of CardView tiles. `trackRideBtn` repositioned ABOVE the tile row with location-pin icon.

### L37. Map view redesign — LiveA "Refined sheet + ETA"

When the user taps Track Driver, the sheet flips into map mode. Picked **LiveA** out of the three Live tracking variants from the design bundle (LiveA / LiveB / LiveC) because it stays closest to the current bottom-sheet structure and doesn't need new data sources to fill its chrome.

Added inside `activeStatusGroup` (between the existing `serviceStatusCard` and the in-sheet driver row):

**a) 3-up stat row**

`statRow` LinearLayout with 1dp `#ECEFF2` divider dividers between cells:
- `tvStatMinAway` — populated from `body.eta_minutes`, formatted as `"~ N"`; falls back to `—` when missing.
- `tvStatKm` — placeholder `—` (no `distance_remaining_km` on the response yet; left as a hook).
- `tvStatEta` — derived: `now() + eta_minutes` formatted `HH:mm` (locale-aware via SimpleDateFormat). Teal color `#0FA896` for the value to distinguish from the two ink-colored stats. Falls back to `—`.

Backed by drawable `zp_stat_row_bg.xml` (`#FAFBFC` fill, 1dp `#ECEFF2` stroke, 14dp corners). Helper `populateStatRow(etaMinutes)` is called from the active branch of `applyMapMode`.

**b) In-sheet driver row restyle**

The L34 design (52dp avatar + thick accent strip + light-teal phone pill) was a leftover from when the row lived in `topChrome`. LiveA wants something more compact since the row now lives over a map. New:
- Container is a plain `LinearLayout` with `zp_driver_row_bg.xml` (white fill, 1dp `#ECEFF2` stroke, 14dp corners) — no CardView wrapper needed for the look.
- Accent strip removed.
- 44dp avatar with `zp_avatar_gradient.xml` (teal-to-tealDk linear gradient `#14C9B0 → #0FA896`) backdrop. CircleImageView sits over it so a real photo masks the gradient; the gradient shows through when only the placeholder `person` drawable is loaded.
- Phone button → 40dp solid teal `#14C9B0` CardView, white icon, `cardElevation=4dp` for the LiveA-spec lifted feel.

**c) Skipped from LiveA**

- Outline-grey message icon-button next to the phone button — no SMS endpoint wired yet.
- Pulse-dot animation on the status banner's icon circle (would require a custom drawable or animated vector; the static badge reads fine).
- Floating header overlay on the map (we already have the teal `topChrome` toolbar pinned above the map area; LiveA's translucent header overlay would replace teal with translucent-white, which loses the brand color in map mode).
- "View All" My Commutes schedule screen — separate activity, needs per-day commute history endpoint that the backend doesn't expose yet. Will rebuild when the schedule data lands.

**Files**:
- `Zippi-User-Android-main/.../res/layout/activity_car_dashboard.xml`:
  - New `statRow` block (with `tvStatMinAway` / `tvStatKm` / `tvStatEta`) inserted between `serviceStatusCard` and `driverInfoRowSheet`.
  - `driverInfoRowSheet` rewritten as a `LinearLayout` with `zp_driver_row_bg` background; 44dp gradient avatar; 40dp teal phone button.
- `Zippi-User-Android-main/.../activities/CarDashboardActivity.kt`:
  - New `populateStatRow(etaMinutes: Int?)` helper.
  - `applyMapMode` active branch calls it after toggling visibility.
- Drawables added:
  - `zp_avatar_gradient.xml` — teal linear-gradient oval (avatar backdrop)
  - `zp_stat_row_bg.xml` — light-grey rounded card with hairline border (stat strip bg)
  - `zp_driver_row_bg.xml` — white rounded card with hairline border (driver row bg)

### L38. Map view polish — pulse dot, toolbar trim, teal road polyline (gated on currently_servicing.is_me)

Four small but related changes layered onto the L37 LiveA map view:

**a) Pulse-dot animation restored on the status banner**

L37 deferred the LiveA pulse-dot because static reads fine; user asked for it back. The status banner's icon (`tvServiceIcon`) is now wrapped in a `FrameLayout` so two 10dp circle Views can sit at its top-end with `marginTop=-2dp / marginEnd=-2dp` (spilling slightly off the icon's corner):

- `pulseDotStatic` — drawable `zp_pulse_dot_static.xml` (teal `#14C9B0` solid + 2dp white stroke). Stays put as the "fixed pin".
- `pulseDotRing` — drawable `zp_pulse_dot_ring.xml` (plain teal solid). Animated by an `AnimatorSet` playing 3 ObjectAnimators together (scaleX 1→2.4, scaleY 1→2.4, alpha 0.7→0). Duration 1600 ms, RESTART repeat, INFINITE count, `AccelerateDecelerateInterpolator` — matches the JSX `@keyframes zPulse` ease-out spec.

Lifecycle:
- `startPulseAnimation()` is idempotent (cancels prior AnimatorSet) and is called from the active branch of `applyMapMode`.
- `stopPulseAnimation()` cancels + resets the ring view (scaleX/Y=1, alpha=0) so only the static dot remains; called from the idle branch + `onDestroy`.
- Pulse animator nulled in `onDestroy` so it can't outlive the activity.

**b) Toolbar trimmed to just the back button**

The teal-hero toolbar previously had a back arrow + "Driver Details" title + "Logout" text button. User: "remvoe that whole bar just keep back button." Result:

- Title `TextView` removed.
- `logoutBtn` kept in XML only as a hidden stub (width/height=0dp, visibility=gone) so the existing `binding.logoutBtn.setOnClickListener { ... }` binding lookup in `onCreate` doesn't crash. The logout flow itself is unwired in the UI — move it to `DashboardActivity` if/when needed.
- The teal hero + driver row below carry enough context that the screen title is redundant.

**c) Teal "Uber-style" route polyline on the map**

Wires the existing Google Directions API pattern (lifted from `ConfirmPickup.kt`) into `CarDashboardActivity`:

- New field `routePolyline: com.google.android.gms.maps.model.Polyline?`.
- `fetchAndDrawRoute(origin, dest)` — fires an OkHttp `GET` against `maps.googleapis.com/maps/api/directions/json` on a background thread, parses the response via existing `com.zippi.mapfullintegrationdemo.MapData` Gson model, decodes the route's encoded polyline into a `List<LatLng>` via local `decodePolyline()` helper, posts back to UI thread.
- `drawRoutePolyline(points)` — removes any prior polyline then adds a new one with color `#14C9B0`, width 14f, geodesic, `RoundCap` start+end, `JointType.ROUND`.
- Throttle: `maybeRefreshRoute(driverPos, employeePos)` skips when both `(now - lastRouteFetchMs) < 30_000 ms` AND `haversineMeters(lastOrigin, driverPos) <= 100`. So refetch only happens every 30 s OR when the driver moves >100 m, whichever comes first — keeps API spend low while staying responsive.
- Reuses `Constants.GOOGLEAPIKEY`. No new permissions, no new dependencies.

Call sites:
- `setupMapFromDetails` (REST snapshot when Firebase isn't yet streaming) — calls `maybeRefreshRoute` after both markers placed.
- Firebase `onDataChange` (every live driver-position delta) — calls `maybeRefreshRoute` after moving the driver marker.
- `stopFirebaseTracking` — calls `clearRoutePolyline()` and resets the gate flag (see d).
- `setupMapFromDetails` no-firebase branch where `map.clear()` runs — also nulls `routePolyline`, `lastRouteOrigin`, `lastRouteFetchMs`, so the next refresh draws fresh.

**d) Polyline gated on `currently_servicing.is_me`**

User: "only show that tracking line to employee A when driver starts ride to employee A or start drop to employee A." Without this, the line would show whenever the driver moved during a shift — including while they were picking up someone else, which is privacy-leaking and misleading.

New field `driverServicingMe: Boolean` set on every poll from `body.currently_servicing?.is_me == true`. This is the same flag the L29 status-banner logic already uses — the backend only sets it `true` when the driver has explicitly committed to THIS employee (Pickup en-route to my home, OR Drop actively dropping me).

Gating happens at THREE levels:
1. `setupMapFromDetails` — early at the top: if the flag transitioned `true → false` between polls, calls `clearRoutePolyline()` immediately so the line vanishes the moment the driver moves on.
2. Both call sites that invoke `maybeRefreshRoute` (REST branch + Firebase onDataChange) are wrapped: `if (driverServicingMe) maybeRefreshRoute(...)`.
3. `drawRoutePolyline()` itself early-returns when `!driverServicingMe`. Defensive belt-and-braces: if a stale async fetch lands AFTER the flag flipped off, it can't paint a phantom line.

`stopFirebaseTracking()` also resets `driverServicingMe = false` so the next ride starts in the gated state.

**Files**:
- `Zippi-User-Android-main/app/src/main/res/layout/activity_car_dashboard.xml`:
  - Toolbar simplified: title TextView removed, `logoutBtn` becomes hidden 0dp stub.
  - Status banner's `tvServiceIcon` wrapped in a `FrameLayout` with two new pulse-dot Views (`pulseDotStatic`, `pulseDotRing`).
- `Zippi-User-Android-main/app/src/main/res/drawable/`:
  - `zp_pulse_dot_static.xml` — teal-solid + white-stroke oval.
  - `zp_pulse_dot_ring.xml` — plain teal-solid oval.
- `Zippi-User-Android-main/app/src/main/java/com/zippi/user/activities/CarDashboardActivity.kt`:
  - New fields: `pulseAnimator: AnimatorSet?`, `routePolyline: Polyline?`, `lastRouteFetchMs: Long`, `lastRouteOrigin: LatLng?`, `driverServicingMe: Boolean`.
  - New helpers: `startPulseAnimation()`, `stopPulseAnimation()`, `maybeRefreshRoute()`, `fetchAndDrawRoute()`, `drawRoutePolyline()`, `clearRoutePolyline()`, `decodePolyline()`, `haversineMeters()`.
  - `applyMapMode` active branch calls `startPulseAnimation()`; idle branch calls `stopPulseAnimation()`.
  - `setupMapFromDetails` reads/transitions `driverServicingMe` at the top; gates `maybeRefreshRoute` on the flag.
  - Firebase `onDataChange` gates `maybeRefreshRoute` on the flag.
  - `stopFirebaseTracking` calls `clearRoutePolyline()` + resets `driverServicingMe`.
  - `onDestroy` cancels and nulls `pulseAnimator`.

**Reuses**:
- `Constants.GOOGLEAPIKEY` (already in use across the app).
- `com.zippi.mapfullintegrationdemo.MapData` Gson model (Directions response shape — already exists in `models/`).
- The L29 `currently_servicing.is_me` server-side flag (no backend change).

**What was changed/removed vs L37**:
- L37 said "Skip pulse-dot animation, static badge reads fine" — now restored per user feedback.
- L37 said toolbar still has title + Logout — now both removed.
- L37 had no map polyline at all — now drawn via Directions API, throttled, and privacy-gated.

### L39. Variant B — initials avatar + segmented Morning/Evening toggle + FROM/TO route card

Screenshots from the running build showed three elements still missing vs the bundle's Variant B reference Driver Details:
1. Avatar circle is empty when no profile photo is present (placeholder `person` drawable was set but read as a blank teal disk on the gradient backdrop).
2. No segmented Morning/Evening toggle between the hero and the commute card.
3. The commute card stacks both Morning Pickup + Evening Drop full sections instead of Variant B's single-route card with dotted FROM-dot → TO-square block.

User: "i want the ui exactly like this" + three URLs (all the same bundle as L36/L37). Filling in the gap:

**a) Initials avatar (both pinned + in-sheet)**

The avatar's FrameLayout gains a centered `TextView` (id `driverInitials` pinned / `driverInitialsSheet` in-sheet) **between** the gradient backdrop View and the CircleImageView. New helper `driverInitialsFor(name)` computes:
- 2+ space-separated words → first letter of first + first letter of last (e.g., "John Smith" → "JS")
- Single word ≥2 chars → first two letters uppercased (e.g., "driverzippi" → "DR")
- Single char → uppercased

`populateDriverCard` now:
- Computes initials, writes them into both Initials TextViews.
- When the driver has `profile_pic`: loads into the CircleImageView and HIDES the initials.
- When there's no photo: clears the CircleImageView (`setImageDrawable(null)`) so it's transparent and the initials show through over the gradient backdrop.

The `person` drawable default `src=` on the CircleImageView was removed from both the pinned and in-sheet copies — its dark-on-teal placeholder was the source of the "empty disk" look in the screenshot.

**b) Segmented Morning / Evening toggle**

Net-new `CardView` with `cardCornerRadius=14dp`, `cardElevation=3dp`, sitting at the top of `idleStatusGroup` (above the route card). Two child `LinearLayout`s with `weight=1`, ids `segMorning` / `segEvening`. Each contains:
- A 6dp accent pip (coral `#F2933A` for Morning via `zp_seg_morning_pip.xml`, indigo `#3F6CE0` for Evening via `zp_seg_evening_pip.xml`).
- "Morning" / "Evening" label (15dp ink-bold).
- A sub row: sub-time TextView (id `tvSegMorningSub` / `tvSegEveningSub`, populated with shift_start / shift_end) + status badge (id `tvSegMorningStatus` / `tvSegEveningStatus`) showing "LIVE" / "DONE" / "UP NEXT" in the leg's accent color.

The selected segment gets `background = @drawable/zp_seg_selected_bg` (`#F6F8F9` rounded card). The unselected segment is `@android:color/transparent`. Selection swaps on tap (Kotlin sets the background drawable + re-renders the route card).

A new enum field `CommuteLeg { MORNING, EVENING }` + `selectedLeg` tracks the active toggle. `userOverrodeLeg: Boolean` records whether the user has explicitly tapped a segment — once true, the auto-sync from `body.direction` is suppressed so the user's choice sticks across polls.

**c) Single route card with FROM/TO dotted line**

The old daily-commute card (Morning Pickup row + divider + Evening Drop row) is replaced by a new `CardView` (`cardCornerRadius=18dp`, `cardElevation=2dp`) containing:
- **Header row**: 8dp status dot (id `vRouteStatusDot`, drawable swapped between `zp_status_dot_ok` green for completed and `zp_seg_morning_pip` / `zp_seg_evening_pip` for live/upcoming), status label "Up next" / "Live now" / "Completed" (id `tvRouteHeaderStatus`), right-aligned meta "Mon–Fri" (id `tvRouteHeaderMeta`).
- **Route block**: an 18dp-wide left rail with FROM dot (12dp via `zp_route_dot.xml`), dashed vertical line (`zp_route_line_dashed.xml`, weight=1 to stretch), TO square (14dp via `zp_route_square.xml`). Right column holds two FROM/TO ends.
- **FROM end**: uppercase 11sp label (id `tvRouteFromLabel`, color matches active leg's accent), right-aligned time (id `tvRouteFromTime`), 14sp bold address (id `tvRouteFromAddr`).
- **TO end**: identical structure with ids `tvRouteToLabel` / `tvRouteToTime` / `tvRouteToAddr`.

When Morning is selected: FROM=home (coral label), TO=office (coral label), to-time = "≈ HH:MM" of shift_start.
When Evening is selected: FROM=office (indigo label), TO=home (indigo label), from-time = "≈ HH:MM" of shift_end.

`tvShiftNote` (night-shift hint) is preserved at the bottom of the route card.

**Legacy IDs preserved (binding-compat)**:

`tvHomeAddressMorning` / `tvOfficeAddressMorning` / `tvHomeAddressEvening` / `tvOfficeAddressEvening` / `tvPickupTime` / `tvDropTime` are kept as `0dp` hidden TextViews inside `idleStatusGroup`. `renderDailyCommute` still writes to them so any downstream code reading them keeps working — though the visible UI now reads from the new ids.

**Files**:
- `Zippi-User-Android-main/app/src/main/res/layout/activity_car_dashboard.xml`:
  - Avatar FrameLayouts (pinned + in-sheet) gain `driverInitials` / `driverInitialsSheet` TextViews stacked behind the CircleImageView; CircleImageView `src` removed.
  - Old daily-commute card replaced by toggle CardView + route CardView + 6 legacy hidden views.
- `Zippi-User-Android-main/app/src/main/java/com/zippi/user/activities/CarDashboardActivity.kt`:
  - New enum `CommuteLeg` + fields `selectedLeg`, `userOverrodeLeg`.
  - `onCreate` wires `segMorning` / `segEvening` click handlers (set selectedLeg, set userOverrodeLeg=true, re-render).
  - `populateDriverCard` rewritten to compute initials, toggle Initials/CircleImageView visibility based on photo URL.
  - New helper `driverInitialsFor(name: String?): String`.
  - `renderDailyCommute` rewritten: populates both segments + the selected leg's route card; preserves writes to the legacy hidden TextViews.
- `Zippi-User-Android-main/app/src/main/res/drawable/`:
  - `zp_seg_selected_bg.xml` — light-grey rounded card (selected toggle background).
  - `zp_seg_morning_pip.xml` — small coral dot for Morning accent.
  - `zp_seg_evening_pip.xml` — small indigo dot for Evening accent.
  - `zp_status_dot_ok.xml` — small green dot for "Completed" route header.

**What was changed/removed vs L36/L37**:
- L36 explicitly skipped the segmented toggle, week strip, and route block — toggle + route block now done; week strip + View All My Commutes still deferred (need per-day commute history backend).
- L36's daily-commute card with two stacked rows is now hidden / replaced by the single-route card; the original 6 IDs survive as hidden 0dp stubs so no Kotlin reference is broken.
- L36's avatar showed `person` placeholder over a teal disk; now shows white initials over the gradient backdrop when no photo is present.

### L40. Commute history endpoint + "This week" pill strip + My Commutes screen

The last two Variant B elements (week pills + "View all" → My Commutes) needed a new backend feed since `enterprise_rides` only stores the ride row, not a day-by-day employee-facing schedule. Built additively without touching any of the existing endpoints / activities.

**a) Backend — `GET /api/enterprise/commute-history?month=YYYY-MM`**

New method `EnterpriseController::commuteHistory` (token-gated, same Sanctum group as `getDriverFullDetails`). Optional `month` query param (YYYY-MM); defaults to the current month.

Resolves the employee from the auth token (`UserDetail.enterprise_employee_id` → fallback email lookup, same pattern as `getDriverFullDetails`), then pulls:
- `EnterpriseRide` rows where `JSON_CONTAINS(employee_ids, employee_id)` AND `shift_date` ∈ month (with `DATE(started_at)` fallback for older rows that lack shift_date).
- `EnterpriseEmployeeRide` rows for those ride IDs (per-employee picked/no-show state).
- `EnterpriseEmployeeCancelRide` rows where `date` or `shift_date` ∈ month (leaves + admin no-show + driver no-show).

Walks each calendar day in the month and emits:
```json
{
  "date": "2026-05-23",
  "day_of_week": "FRI",
  "date_num": 23,
  "is_weekend": false,
  "is_today": true,
  "morning": { "state": "done", "label": "Done", "time": "08:12" },
  "evening": { "state": "pending", "label": "Up next", "time": "20:00" }
}
```

State derivation (helper `buildCommuteLeg`):
- `done` — `EnterpriseEmployeeRide` row exists with `pin_verified=1` (employee was successfully picked); time = picked_at (`updated_at` of the employee_ride) formatted `HH:MM`.
- `no_show` — `EnterpriseEmployeeRide.status` in `[no_show, cancelled]` OR leave row with `cancelled_by` in `[driver_no_show, admin_no_show]`.
- `leave` — employee-initiated leave row (`cancelled_by=employee` or `admin`); label includes leave reason snippet.
- `pending` — ride row exists for today, this employee hasn't been picked yet → "Up next".
- `scheduled` — future date with a ride row OR future date with no ride row but the employee has a daily schedule → "Scheduled" + `HH:MM` of shift_start/shift_end.
- `off` — weekend, no ride.
- `none` — past date with no data (catch-all).

Month summary stats also emitted: `completed`, `upcoming`, `leave`, `km_total` (sum of `enterprise_rides.distance` for completed rides this month), `on_time_rate` (null until per-employee picked_at-vs-shift_start delta is tracked — clients show "—").

Route: `routes/api.php` adds `Route::get('/commute-history', [EnterpriseController::class, 'commuteHistory']);` inside the `enterprise/` group right next to `driver-full-details`.

**b) User app — API + model**

- `ApiInterface.kt`: new `getCommuteHistory(token, month: String?)` Retrofit call returning `Call<CommuteHistoryResponse>`.
- New model `com.zippi.user.EnterpriseModel.CommuteHistoryResponse` plus nested `CommuteSummary` / `CommuteDay` / `CommuteLegState`. All fields nullable for forward-compat.

**c) Dashboard — 5-day "This week" pill strip + View all link**

Inserted at the bottom of `idleStatusGroup` (above the action tiles row):
- `weekStripHeader` (LinearLayout) — "THIS WEEK" 11sp tracked label + right-aligned `btnViewAllCommutes` ("View all", teal, bold).
- `weekStrip` (empty horizontal LinearLayout) — populated by Kotlin with 5 inflated `R.layout.item_week_pill` instances (Mon-Fri of the current week).

Three pill states + drawables:
- Today → `zp_daypill_today.xml` (solid teal `#14C9B0`, white text)
- Both legs done → `zp_daypill_done.xml` (`#E4F9F4` fill, teal-dark text)
- Default (future / weekend / partial) → `zp_daypill_default.xml` (white + 1dp `#ECEFF2` border, muted text)

`CarDashboardActivity` additions:
- `fetchWeekStrip()` — called from `onResume` (once per resume; the strip only shifts on day boundaries so 10-s polling is overkill).
- `renderWeekStrip(days)` — computes the Mon..Fri window of the current calendar week, indexes the response's days by date, inflates a pill per day, sets background drawable based on `computeDayPillState(day, isToday)`.
- `btnViewAllCommutes.setOnClickListener { startActivity(Intent(this, ScheduleActivity::class.java)) }`.

**d) My Commutes screen — new `ScheduleActivity`**

Files:
- `res/layout/activity_schedule.xml` — teal hero (back · "My Commutes" · month switcher [prev / "May 2026" / next] · ON-TIME RATE stat) + floating 4-up summary card overlapping the hero by -20dp (Completed / Upcoming / Leave / km total) + RecyclerView of day cards.
- `res/layout/item_schedule_day.xml` — date block (56dp × 60dp, teal when today, light-grey otherwise) + Morning/Evening rows. Each row: accent pip · "Morning"/"Evening" · time · status pill.
- `activities/ScheduleActivity.kt` — month state, prev/next chevron handlers shift `currentMonth/currentYear`, fetch via `getCommuteHistory(token, "YYYY-MM")`, inner `DayAdapter` extends `RecyclerView.Adapter` with one `VH` that binds date + both legs + colors the pills per state.

Status pill colors (in `DayAdapter.VH.bindLeg`):
- `done` → `zp_schedule_pill_done` (light green) + `#2EA76A`
- `leave` → `zp_schedule_pill_leave` (cream) + `#D87715`
- `no_show` → `zp_schedule_pill_leave` (cream) + `#C62828`
- `pending` → neutral pill + indigo text
- `scheduled` / `off` / unknown → neutral pill + muted text

Manifest: registered `ScheduleActivity` (`exported=false`, `parentActivityName=.activities.CarDashboardActivity`). Standard back button → DashboardActivity-side flow unaffected.

**e) Drawables added**

`zp_daypill_today.xml` · `zp_daypill_done.xml` · `zp_daypill_default.xml` · `zp_schedule_date_bg.xml` · `zp_schedule_date_today_bg.xml` · `zp_schedule_pill_neutral.xml` · `zp_schedule_pill_done.xml` · `zp_schedule_pill_leave.xml`.

**Files**:
- `staging/app/Http/Controllers/Api/EnterpriseController.php` — new `commuteHistory()` + private `buildCommuteLeg()` helper.
- `staging/routes/api.php` — new `/commute-history` route.
- `Zippi-User-Android-main/app/src/main/java/com/zippi/user/retrofit/ApiInterface.kt` — new `getCommuteHistory()`.
- `Zippi-User-Android-main/app/src/main/java/com/zippi/user/EnterpriseModel/CommuteHistoryResponse.kt` — new file.
- `Zippi-User-Android-main/app/src/main/java/com/zippi/user/activities/ScheduleActivity.kt` — new activity.
- `Zippi-User-Android-main/app/src/main/res/layout/activity_schedule.xml` + `item_schedule_day.xml` + `item_week_pill.xml` — new layouts.
- `Zippi-User-Android-main/app/src/main/res/layout/activity_car_dashboard.xml` — `weekStripHeader` + `weekStrip` block added at the bottom of `idleStatusGroup`.
- `Zippi-User-Android-main/app/src/main/java/com/zippi/user/activities/CarDashboardActivity.kt` — `fetchWeekStrip()`, `renderWeekStrip()`, `computeDayPillState()`, View all click handler, `onResume` calls `fetchWeekStrip()`.
- `Zippi-User-Android-main/app/src/main/AndroidManifest.xml` — `ScheduleActivity` registered.
- 8 new drawables (see (e) above).

**What's deferred (still)**:
- "Late Xm" / "On time" / "Early Xm" precision pills — depends on storing `picked_at` timestamp separately from `updated_at` on `EnterpriseEmployeeRide` so we can diff against `employee.shift_start`. Current implementation just shows "Done" + the pickup time.
- Filter chips on the schedule screen (All / Completed / Upcoming / Leave / Issues with counts) — the visible 4-up summary already conveys the counts; chips are pure noise without more dense data.
- Group headers ("Upcoming" / "This week" / "Earlier"). The flat list now sorts today-first then past-reverse-chronological, which gives the user the same "now and going backwards" reading order without per-section sticky headers.

**L40 follow-ups (user-reported)**:
- Route card meta no longer hardcoded to "Mon–Fri". Now derived from `body.employee.working_days` via `workingDaysLabel(days)`: full-weekdays → "Mon–Fri", weekends only → "Weekends", all 7 → "Daily", anything else → comma-joined 3-letter day abbreviations ("Mon, Wed, Fri").
- Schedule list was emitting days in pure calendar order (1 → 31) which put today buried in the middle. `renderResponse` now partitions into `(today-or-future, past)`, sorts the first chronologically and the second reverse-chronologically, and concatenates — so today's card is always at the top, future days follow, then past days going back.
- Dashboard week strip now has a fourth pill state, `leave` → `zp_daypill_leave.xml` (cream `#FFF1DC` fill + coral `#D87715` text). `computeDayPillState` checks leave before "done" so a day with even one leg leaved gets the cream highlight at a glance.

### L41. Week-strip pills drive the route card + unified state model for the status header

User: "when i click on other date in 'this week' tab, show the according details in 'ride details tab' and also adjust the status card so that it looks good."

**a) Pill taps drive the route card**

New `CarDashboardActivity` state:
- `selectedDayData: CommuteDay?` — null = today/live (default). When set, drives the route card off that day's snapshot.
- `weekDays: List<CommuteDay>` — cached from the latest `/commute-history` fetch so re-renders don't re-network.

`renderWeekStrip` now wires a click listener on each pill:
```kotlin
pill.setOnClickListener {
    selectedDayData = if (isToday) null else rec
    userOverrodeLeg = false
    lastDriverDetails?.let { renderDailyCommute(it) }
    renderWeekStrip(weekDays)   // re-render so the new selection highlights
}
```

Tapping today clears the override → falls back to the live-ride render path. Tapping any other day routes the route card into that day's snapshot. `userOverrodeLeg=false` is reset so the segmented toggle auto-picks the appropriate leg for the newly-selected day (e.g., yesterday with both legs done → defaults to Evening).

A new pill visual state `selected` (non-today, currently-viewed day) uses `zp_daypill_selected.xml` (white + 2dp teal border + teal text). Today pill stays solid teal — it's always visually identifiable as "today" regardless of selection.

**b) Unified state model for `renderDailyCommute`**

The old function had two parallel branches — one for Morning, one for Evening — each computing its own status text + dot independently. Refactored into a single state-driven pipeline:

1. Derive `morningState` / `morningTime` / `eveningState` / `eveningTime` from EITHER `selectedDayData` (snapshot) OR `body` (live ride). Both paths produce the same vocabulary: `live | done | pending | scheduled | leave | no_show | off | none`.
2. Auto-select the active leg from those states unless `userOverrodeLeg=true` (priority: live morning → live evening → done-morning-only → default morning).
3. Render the segment pills via `legPillText(state)` + `legPillColor(state, isMorning)`.
4. Render the route card via `routeHeaderLabel(state)` + `routeHeaderColor(state)` + `routeHeaderDot(state, isMorning)`.

This means a tapped past day with `morning.state="done"` automatically produces:
- Segment pill: "DONE" in green
- Route card status: "Completed" in green
- Status dot: green (`zp_status_dot_ok`)
- Time field: actual pickup time from the snapshot (e.g., `08:12`)

A tapped future day with `morning.state="leave"`:
- Segment pill: "LEAVE" in coral
- Route card status: "Leave applied" in coral
- Status dot: coral (`zp_status_dot_leave`)
- Time field: blank (leave has no time)

**c) Route card status visual polish**

In the XML:
- Status dot bumped 8dp → 10dp, side-margin 6dp → 8dp.
- Status text bumped 12sp regular muted → 13sp bold ink with state-driven color override in Kotlin (`routeHeaderColor`).
- Right-side meta ("Mon–Fri" / working days) wrapped in a soft `zp_schedule_pill_neutral` pill (paddingStart/End 8dp, paddingTop/Bottom 3dp, 11sp bold muted) so the meta reads as a chip not floating text.

The result: the header line now reads as **[colored dot] · [bold colored status] · · · [pill chip with working days]** — much more scannable than the previous monochrome line.

**New drawables**:
- `zp_daypill_selected.xml` — non-today selected pill (white + teal border).
- `zp_status_dot_muted.xml` — grey dot for scheduled/off states.
- `zp_status_dot_leave.xml` — coral dot for leave state.
- `zp_status_dot_danger.xml` — red dot for no-show state.

**New helpers** (all in `CarDashboardActivity`):
- `legPillText(state)` — UPPERCASE label for the segment status badge.
- `legPillColor(state, isMorning)` — segment status badge color (leg-accent for default, state-specific otherwise).
- `routeHeaderLabel(state)` — title-case label for the route card header.
- `routeHeaderColor(state)` — route header text color (state-driven).
- `routeHeaderDot(state, isMorning)` — route header dot drawable resource.

**Files**:
- `Zippi-User-Android-main/app/src/main/java/com/zippi/user/activities/CarDashboardActivity.kt` — `selectedDayData` field, `weekDays` cache, pill click handler, refactored `renderDailyCommute`, 5 new state-mapping helpers.
- `Zippi-User-Android-main/app/src/main/res/layout/activity_car_dashboard.xml` — route header dot bumped, status text bolded, meta wrapped in chip.
- 4 new drawables (see above).
- `Zippi-User-Android-main/app/src/main/res/drawable/zp_daypill_selected.xml` — new.

**What was changed/removed vs L40**:
- L40's `renderDailyCommute` had two parallel Morning/Evening branches; L41 unifies them into a single state-driven pipeline.
- L40's route header status text was static color `#6B7681`; L41 derives color from the leg's resolved state.
- L40's week pills were tap-inert; L41 makes them drive the route card.

### L42. Track-screen toolbar dropped + stat row populates with real values

**a) Track screen — teal toolbar replaced with a floating back button**

In map (track) mode the entire `topChrome` block (toolbar + driver row) used to stay pinned at the top. User: "remove that tale header and keep only backbutton". Now:

- `topChrome.isVisible = false` in the active branch of `applyMapMode`.
- A new `btnBackFloating` CardView (40dp circular, white bg, `cardElevation=6dp`, ink-black back arrow icon) lives inside the `CoordinatorLayout` with `layout_gravity="top|start"` and `marginStart=12dp / marginTop=12dp`. Shown only in active mode; hidden in idle so it doesn't double up with the toolbar's existing back arrow.
- Click wired in `onCreate` to `finish()` — same exit semantics as the toolbar back button.

The bottom-sheet offset logic (`syncSheetOffsetToChrome`) already falls back to a `dpToPx(56)` floor when `binding.toolbar.height == 0`, so hiding topChrome doesn't break the sheet's positioning; the active-mode 30%-of-screen floor still wins on phones.

**b) Stat row computed properly across all ride states**

Previous behaviour: `eta_minutes` was only computed when `currently_servicing.is_me == true && !meDone`, so the stat row showed "—/—/—" the entire time between Begin Shift and the moment the driver tapped THIS specific employee's name.

The user wanted real numbers from the moment the ride is active (driver started shift) — for any employee on the ride, not just the currently-serviced one. Two new response fields:

- `distance_to_me_km` — direct haversine driver → me, divided by 1000, rounded to 1 decimal.
- `eta_to_destination_minutes` — projected time to my final destination, accounting for the rest of the route.

And `eta_minutes` (driver → me, time) is now computed whenever the ride is active (no `is_me` gate). All three use the same `500 m/min ≈ 30 km/h` constant so they stay internally consistent.

**c) ETA-to-destination algorithm (`computeEtaToDestination`)**

New private helper. Determines remaining stops by:
1. Loading `ride.employee_ids` (the JSON array of who's on this ride).
2. Excluding me + anyone already `pin_verified=1` on `enterprise_employee_rides`.
3. Loading their `pickup_drop_latitude/longitude` (or `latitude/longitude` fallback).
4. Filtering out coords-zero rows.

Then sums haversines based on direction:

- **Pickup**: `driver → me → [remaining nearest-first] → office`. The route ends at the company's lat/lng. If `me` is the first pickup, the trip continues through everyone else before reaching the office; if `me` is the last, almost the whole sum is the `me → office` leg. Either way the total reflects when the user will physically arrive at work.
- **Drop**: `driver → [others nearer than me, nearest-first] → me`. Greedy walk from the driver — pick the closest remaining employee each step; stop when `me` is closer than any remaining other. So if I'm last on the drop route, the sum includes all intermediate drops; if I'm first, it collapses to the direct `driver → me` leg.

Returns `null` when the company location is missing (Pickup) or coordinates aren't usable.

**d) `populateStatRow` rewrite**

Signature changed from `(etaMinutes: Int?)` to `(body: DriverFullDetailsResponse)` so the helper can read all three fields. Each cell renders independently:

| Cell        | Source                              | Format                              |
|-------------|-------------------------------------|-------------------------------------|
| min away    | `body.eta_minutes`                  | `"~ N"` or `"—"`                    |
| km          | `body.distance_to_me_km`            | `"%.1f"` if <10, else int. `"—"`    |
| ETA         | `body.eta_to_destination_minutes`   | clock `HH:mm` (now+N), else `"—"`   |

The call site moved from inside the `showLiveMap` branch of `applyMapMode` to a tail block: `if (rideIsActive) populateStatRow(body)`. So the row populates BOTH in track mode AND in the idle-with-active-ride state where the user can see the banner + stats before tapping Track Driver.

**Files**:
- `staging/app/Http/Controllers/Api/EnterpriseController.php`:
  - `getDriverFullDetails` active-ride branch now computes `etaMinutes` / `distanceToMeKm` / `etaToDestinationMinutes` together and adds all three to the JSON response. The previous `is_me` gate on `etaMinutes` is dropped — now only gated on `!meDone`.
  - New private method `computeEtaToDestination(ride, myEmployeeId, driverLat, driverLng, myLat, myLng, company): ?int`.
- `Zippi-User-Android-main/.../EnterpriseModel/DriverFullDetailsResponse.kt` — added `distance_to_me_km: Double?` and `eta_to_destination_minutes: Int?` to the top-level data class. Existing `eta_minutes` doc updated.
- `Zippi-User-Android-main/.../activities/CarDashboardActivity.kt`:
  - `populateStatRow` rewritten; signature now takes the whole body.
  - `applyMapMode` active branch: `binding.topChrome.isVisible=false`, `binding.btnBackFloating.isVisible=true`.
  - `applyMapMode` idle branch: reverse — show topChrome, hide floating back.
  - Tail block calls `populateStatRow(body)` whenever `rideIsActive` regardless of map-mode.
  - `onCreate` wires `binding.btnBackFloating.setOnClickListener { finish() }`.
- `Zippi-User-Android-main/.../res/layout/activity_car_dashboard.xml` — new `btnBackFloating` CardView inside CoordinatorLayout (top|start, hidden by default).

**Tradeoffs called out**:
- Algorithm is haversine + greedy-nearest-neighbour, not Google Directions API. Estimates are good enough for a "this is roughly when you'll arrive" UX; off by a couple minutes when traffic is heavy. Upgrade path: same Directions API plumbing already used for the L38 polyline.
- Drop-direction greedy heuristic may over-estimate when the actual driver's route order isn't truly nearest-first. The estimate is conservative (won't promise an unrealistically early arrival).

**L42 follow-up — sheet sizing in track mode (user-requested)**:
- Peek (default collapsed) was `25% screen, clamped [140dp, 220dp]` — too shallow on most phones, the status banner + stat row didn't fit. Bumped to `40% screen, clamped [260dp, 440dp]` so the default sheet shows the banner, stat row, and at least the driver row above the fold while still giving 60% of the screen to the map.
- Expanded (drag-up) used to leave only `30%` of the screen for the map. Bumped to `25%` floor (i.e., sheet caps at 75% of screen instead of 70%). Math: `expandedOffset = max(56dp, screenH × 0.25)` — the 56dp safety minimum only matters on tablets / unusually tall windows; phones always hit the `25%` clause.
- Both values are pure ratios so they scale with every screen size automatically; the dp clamps are just floors/ceilings for edge devices.

### L43. My Commutes — compact toolbar, today auto-scrolled, summary card dropped

User noted the schedule screen's chunky hero + summary card felt out of place vs the rest of the app, and that today's date wasn't visible because the strip / list didn't anchor on it.

**a) Compact toolbar replaces the two-row teal hero**

Single horizontal row: back arrow (translucent-white pill, 36dp) · "My Commutes" title (white, 17sp bold) · spacer · month switcher (prev chevron 28dp · month label 14sp · next chevron 28dp). Whole row sits on the `zp_hero_gradient` background but at a slim 10dp/10dp padding instead of the previous 12dp/32dp hero. Matches the standard internal-screen toolbar pattern used elsewhere in the app.

The previous "SCHEDULE" tracked label, "ON-TIME RATE" stat, and the 4-up Completed/Upcoming/Leave/km summary card are all removed from the visible UI. Binding-compat stubs (0dp, visibility=gone) are kept for `scheduleOnTimeRate`, `scheduleSummaryCompleted`, `scheduleSummaryUpcoming`, `scheduleSummaryLeave`, `scheduleSummaryKm` so the existing Kotlin writes don't NPE — easier to clean those writes up later than to touch them in a UI-only pass.

**b) Full chronological month + auto-scroll to today**

`renderResponse` was partitioning into `(today+future, past)` and reverse-sorting the second group to force today to the top of the list. That worked but flattened the natural calendar order — user could only scroll DOWN to see past days. Reverted to a plain `sortedBy { it.date ?: "" }` so the list is calendar-order (May 1 → May 31).

After `adapter.submit(ordered)`, find today's index and call `recyclerView.scrollToPositionWithOffset(idx, 0)` inside a `post {}` (so the layout pass has settled). When today isn't in the currently-viewed month — e.g., user just hit the next-month chevron — anchor at index 0 instead.

Result: open the screen → land on today's card at the top of the visible area → scroll up to see future days in this month, scroll down to see past days. Switch months via the chevrons → land at day 1 of the new month.

**Files**:
- `Zippi-User-Android-main/app/src/main/res/layout/activity_schedule.xml` — rewritten with the compact toolbar + 5 hidden binding-compat stubs; the floating summary card removed.
- `Zippi-User-Android-main/app/src/main/java/com/zippi/user/activities/ScheduleActivity.kt` — `renderResponse` rewritten: chronological ordering + `scrollToPositionWithOffset` to today after layout.

**What was changed/removed vs L40**:
- L40's two-row teal hero (back+title row + SCHEDULE/month/ON-TIME-RATE row) → one compact row.
- L40's floating 4-up summary card → removed (binding compat preserved).
- L40's `(today+future, past-reversed)` ordering in `renderResponse` → plain chronological + auto-scroll.

### L44. Commute history honors `employee.working_days` (no more spurious "Weekend off")

L40's `buildCommuteLeg` had a hard `$d->isWeekend()` check that flipped any Saturday/Sunday to `state="off", label="Weekend off"` — independent of what the admin had actually configured for that employee. So an employee whose admin panel had Sunday ticked still saw "Weekend off" on Sundays in the schedule.

**Fix**: read `enterprise_employees.working_days` (existing JSON column already used by `EnterpriseRideController::pickEmployees` and others) and only flag a day "off" when the day's full weekday name (`Carbon::format('l')` → `Monday` / `Sunday` / etc.) is NOT in the configured set. Empty / null `working_days` continues to mean "works every day" — matching the existing convention used elsewhere in the codebase.

`commuteHistory` loop now precomputes once:

```php
$workingDaysRaw = $employee->working_days;
$workingDays    = is_array($workingDaysRaw)
    ? $workingDaysRaw
    : (json_decode((string) $workingDaysRaw, true) ?: []);
$worksAllDays = empty($workingDays);
```

…then per-day:

```php
$weekdayName  = $d->format('l');                                   // Monday … Sunday
$isWorkingDay = $worksAllDays || in_array($weekdayName, $workingDays, true);
```

The `$isWeekend` parameter of `buildCommuteLeg` was renamed to `$isWorkingDay` (boolean flipped) and the off-branch became:

```php
if (! $isWorkingDay) {
    return ['state' => 'off', 'label' => 'Off day', 'time' => null];
}
```

Label changed from "Weekend off" → "Off day" since it's no longer guaranteed to be a weekend (could be any non-working day the admin has unticked).

The response's `is_weekend` flag is kept for client-side back-compat but now reflects "not a working day for this employee" rather than calendar Saturday/Sunday.

**Files**:
- `staging/app/Http/Controllers/Api/EnterpriseController.php`:
  - `commuteHistory` loop precomputes `$workingDays` / `$worksAllDays`; per-day computes `$isWorkingDay`.
  - `buildCommuteLeg` parameter renamed; off-branch only fires when `!$isWorkingDay`; label updated to "Off day".
  - `days[].is_weekend` field now reflects `!$isWorkingDay`.

**What was changed/removed vs L40**:
- L40's `$d->isWeekend()` calendar-based check → admin-configured `working_days` check.
- L40's "Weekend off" copy → "Off day" (generic, accurate for any unticked day).
- L40's `$isWeekend` param on `buildCommuteLeg` → `$isWorkingDay` with flipped semantics.

**L44 follow-up — hide the working-days meta chip on the route card**:
User: "remove that 'daily' in ride details and dont show weeks also ust keep it empty".

In `renderDailyCommute` the line `binding.tvRouteHeaderMeta.text = workingDaysLabel(body.employee?.working_days)` is replaced by `binding.tvRouteHeaderMeta.visibility = View.GONE`. The chip is permanently hidden — the route card header now reads as just the colored dot + status text on the left, no right-side meta. `workingDaysLabel()` helper is left in source as dead-but-ready code in case the meta is re-enabled later.

### L45. Route-card visual enhancement — status chip + direction label + leg-tinted rail

User: "enhance that ride details screen". Three coordinated polish moves on the route card so the leg + status read at a glance:

**a) Status chip with state-tinted background**

The header status (dot + "Up next" / "Completed" / etc.) was previously colored text on a transparent background. Now wrapped in a `routeStatusChip` `LinearLayout` with a state-driven rounded `10dp` background:

| State    | Drawable               | Fill color |
|----------|------------------------|------------|
| `live`   | `zp_status_chip_live`  | `#E4F9F4` (light teal) |
| `done`   | `zp_status_chip_done`  | `#E3F5EB` (light green) |
| `leave`  | `zp_status_chip_leave` | `#FFF1DC` (cream) |
| `no_show`| `zp_status_chip_danger`| `#FFE5E0` (light red) |
| else     | `zp_status_chip_neutral`|`#F1F4F6` (light grey) |

Dot bumped from 10dp → 8dp inside the chip; text size 13sp → 12sp; padding 10/5/12/5dp. The chip now feels like a proper status pill at the top-left of the card.

Helper `routeStatusChipBg(state)` added next to `routeHeaderDot()` / `routeHeaderColor()` / `routeHeaderLabel()`.

**b) Direction label row with emoji**

A new row sits between the status chip and the route block:

- `tvRouteDirectionIcon` — ☀ for Morning, 🌙 for Evening (`textSize=14sp`)
- `tvRouteDirectionLabel` — "MORNING PICKUP" (coral `#F2933A`) or "EVENING DROP" (indigo `#3F6CE0`), 11sp bold tracked uppercase

Sits at `marginTop=12dp` so it has clear breathing room from the chip above. Reinforces which leg the FROM/TO block below describes.

**c) Leg-tinted route rail (FROM dot · dashed line · TO square)**

Previously hardcoded coral via `zp_route_dot.xml` / `zp_route_line_dashed.xml` / `zp_route_square.xml` — looked off when the Evening leg was selected. Added three indigo siblings and swap in Kotlin:

| View          | Morning (existing)         | Evening (new)                    |
|---------------|----------------------------|----------------------------------|
| FROM dot      | `zp_route_dot`             | `zp_route_dot_indigo`            |
| Dashed line   | `zp_route_line_dashed`     | `zp_route_line_dashed_indigo`    |
| TO square     | `zp_route_square`          | `zp_route_square_indigo`         |

Rail now matches the segmented toggle's color and the direction label, giving the entire route card a coherent leg accent.

**Files**:
- `Zippi-User-Android-main/app/src/main/res/layout/activity_car_dashboard.xml`:
  - Status header wrapped in `routeStatusChip` LinearLayout with chip background.
  - New direction-label row (`tvRouteDirectionIcon` + `tvRouteDirectionLabel`).
  - IDs added to the rail's three views (`vRouteFromDot` / `vRouteLine` / `vRouteToSquare`).
- `Zippi-User-Android-main/app/src/main/java/com/zippi/user/activities/CarDashboardActivity.kt`:
  - `renderDailyCommute` sets the chip background via new `routeStatusChipBg(state)` helper.
  - Sets direction icon (☀/🌙) + label text + accent color based on `isMorning`.
  - Swaps the rail drawables between coral/indigo per leg.
- Drawables added: `zp_route_dot_indigo.xml`, `zp_route_square_indigo.xml`, `zp_route_line_dashed_indigo.xml`, `zp_status_chip_live.xml`, `zp_status_chip_done.xml`, `zp_status_chip_leave.xml`, `zp_status_chip_danger.xml`, `zp_status_chip_neutral.xml` — 8 total.

**What was changed/removed vs L41/L44**:
- L41's plain colored status text → now wrapped in a state-tinted chip background.
- The previously hidden working-days meta chip remains hidden; the direction label takes over the "what leg are we showing" cue.
- L41's coral-only rail → now coral or indigo based on selected leg.

### L46. Drop terminal — route card now flips to "Done" the moment driver marks me dropped

User: "when driver click 'mark employee A as dropped' then in user screen ride is completed but in ride details screen it is still showing 'live now'".

**Root cause**: in `renderDailyCommute` the live-state branch computed `pickupDone` / `dropDone` from `body.today_completed_shifts`. That field is sourced from `buildTodayCompletedShifts` on the backend, which only flags a direction "completed" once the parent `enterprise_rides.status = 'completed'`. But Drop is per-employee terminal (since L19) — `enterprise_employee_rides.pin_verified=1` flips for ME, my `me_done=true`, but the parent ride stays `status='started'` while other passengers are still on board. Result: `dropDone=false`, `rideActive=true`, `eveningLive=true` → route card shows "Live now" even though I'm already home.

**Fix**: add a per-employee terminal check to both legs:

```kotlin
val pickupDoneForMe = pickupDone || (isPickup && meDone)
val dropDoneForMe   = dropDone   || (isDrop   && meDone)
val morningLive = rideActive && isPickup && !pickupDoneForMe
val eveningLive = rideActive && isDrop   && !dropDoneForMe
```

Symmetric across both directions:
- **Drop, driver marks me dropped** → `me_done=true` → `dropDoneForMe=true` → evening leg flips immediately to `state="done"` → status chip turns green / "Completed" / green dot. No more "Live now" lingering.
- **Pickup, driver verifies my OTP** → `me_done=true` → morning leg flips to `state="done"` from MY perspective (I'm in the vehicle on the way to office). The rating prompt still waits for parent ride completion (handled separately by `buildPendingRating`).

The parent-ride-complete path (`pickupDone` / `dropDone` from `today_completed_shifts`) is still honored — it just becomes a SECOND way to flip a leg done, used by other callers and historical days.

**Files**:
- `Zippi-User-Android-main/app/src/main/java/com/zippi/user/activities/CarDashboardActivity.kt` — `renderDailyCommute` live-state branch now derives `pickupDoneForMe` / `dropDoneForMe` from `pickupDone || (isPickup && meDone)` / `dropDone || (isDrop && meDone)`. `morningLive` / `eveningLive` and the resulting `morningState` / `eveningState` use the new per-me flags.

**What was changed/removed vs L41**:
- L41's `morningLive = rideActive && isPickup && !pickupDone` → now `!pickupDoneForMe`. Same for evening.
- `morningState == "done"` now triggers on `pickupDoneForMe`, evening on `dropDoneForMe`.

### L47. Status banner + stat row moved below Track Driver button (and tightened)

User: "put that status and below info card below track driver button and make it little small".

**a) Position swap**: the entire `activeStatusGroup` block (status banner + 3-up stat row + in-sheet driver row) was the first child of `bottomSheetContent` after the drag handle. Now relocated to sit immediately AFTER the `trackRideBtn` Button. Final sheet-content order:

```
1. dragHandle
2. idleStatusGroup     (toggle + route card + week strip)
3. trackRideBtn        (visible in idle-with-active-ride only)
4. activeStatusGroup   (banner + statRow + driverInfoRowSheet)
5. Apply Leave / Call Support tiles
6. upcomingLeavesContainer
```

Behavior across the three visible states:

| State | Visible widgets in order |
|---|---|
| Idle (no ride)            | drag · idleStatusGroup · tiles · leaves |
| Idle + active ride        | drag · idleStatusGroup · **Track Driver · banner · statRow** · tiles |
| Map mode (Track tapped)   | drag · (Track hidden) · **banner · statRow · driverInfoRowSheet** · tiles |

The map-mode order is unchanged because the hidden `trackRideBtn` collapses to 0dp.

**b) Tightened sizing**:

| Element                       | Was        | Now        |
|-------------------------------|------------|------------|
| Status banner inner padding   | 12dp       | 10dp       |
| Status banner emoji icon      | 22sp       | 18sp       |
| Status banner title           | 14sp       | 13sp       |
| Status banner marginBottom    | 12dp       | 10dp       |
| Stat row vertical padding     | 12dp       | 8dp        |
| Stat row value (min/km/ETA)   | 17sp       | 15sp       |
| Stat row label                | 11sp       | 10sp       |
| Stat row marginBottom         | 12dp       | 10dp       |
| OTP inline digit              | 20sp       | 18sp       |

Saves ~24dp of vertical space across the banner + stat row combined, so the visible content above the fold isn't pushed down.

**Files**:
- `Zippi-User-Android-main/app/src/main/res/layout/activity_car_dashboard.xml` — `activeStatusGroup` block removed from its old position (left a one-line comment marker) and re-inserted directly after the `trackRideBtn` Button. All sizes inside the banner + statRow shaved per the table above.

**What was changed/removed vs L41**:
- L41's order had `activeStatusGroup` as the first sheet item after the drag handle; now it's after the Track Driver button.
- L41's status banner padding/title and stat row padding/value sizes shrunk so the combined element is ~24dp shorter.

### L48. Hero compressed + initials avatar fixed + action tiles slimmed

User screenshot showed the avatar circle empty (no initials, no photo), the teal hero feeling too tall, and the action tiles chunky.

**a) Initials avatar visibility — the actual bug**

The pinned-hero avatar's backdrop was `zp_circle_white_translucent.xml` (`#2EFFFFFF` translucent white). The initials TextView text color is `#FFFFFF`. Result: white text on translucent white — almost invisible against the teal hero behind it. That's why the avatar read as "empty".

Fix: switched the backdrop to `zp_avatar_gradient.xml` (the same teal-to-tealDk gradient already used by the in-sheet driver row). White initials now read clearly on the teal disk.

**b) Hero shrunk further**

| Element                       | Was     | Now     |
|-------------------------------|---------|---------|
| `topChrome` paddingBottom     | 16dp    | 10dp    |
| `toolbar` paddingTop          | 8dp     | 6dp     |
| `toolbar` paddingBottom       | 2dp     | 0dp     |
| `driverInfoRow` paddingTop    | 8dp     | 4dp     |
| Avatar size                   | 48dp    | 42dp    |
| Avatar initials text          | 17sp    | 15sp    |
| Phone button                  | 42dp    | 38dp    |
| Phone button corner radius    | 21dp    | 19dp    |
| Phone button icon padding     | 11dp    | 10dp    |

Saves ~18dp of vertical height across the hero. The driver row reads tighter and the hero stops dominating the screen.

**c) Action tiles compacted (Apply Leave + Call Support)**

Layout changed from vertical (icon on top, title + sub below) → horizontal (icon left, title + sub right). Padding 14dp → 10dp, icon container 34dp → 28dp, corner radius 14dp → 12dp, title 14sp → 13sp, sub 12sp → 11sp.

The tiles are now ~50dp tall instead of ~88dp, freeing up bottom-of-screen real estate. They still convey the same info (icon + title + subtitle) just side-by-side.

**Files**:
- `Zippi-User-Android-main/app/src/main/res/layout/activity_car_dashboard.xml`:
  - `topChrome` paddingBottom 16→10dp.
  - `toolbar` paddings tightened (top 8→6, bottom 2→0).
  - `driverInfoRow` paddingTop 8→4dp.
  - Avatar `FrameLayout` 48dp → 42dp; backdrop `zp_circle_white_translucent` → `zp_avatar_gradient`; initials TextView 17sp → 15sp.
  - Phone button CardView 42dp → 38dp, corner 21→19dp, icon padding 11→10dp.
  - `cancelButton` + `supportButton` tiles: re-laid as horizontal LinearLayouts; padding 14→10dp; icon container 34dp → 28dp + padding 8→6dp; title 14sp → 13sp; sub 12sp → 11sp; corner 14→12dp.

**What was changed/removed vs L43/L36**:
- L43's translucent-white avatar backdrop → teal-gradient (initials now readable).
- L36's vertical action tiles → horizontal layout, ~38dp shorter.
- Cumulative hero shrink from original 150dp → now ~112dp (38dp saved across L43 + L48).

**L48 follow-up — floating back button removed in track mode**:
User: "remove that back button" (referring to `btnBackFloating` overlapping the status banner in track mode).

`applyMapMode` active branch now sets `binding.btnBackFloating.isVisible = false` (was `true`). The button is permanently hidden — system back gesture (hardware back button or Android edge-swipe) handles exit via the default `onBackPressed → finish()`. The `btnBackFloating` View + its `onCreate` click listener are left in place in case we ever want to bring it back later.

**L48 follow-up — back button moved onto the bottom sheet; auto-hides at full expansion**:
User: "in tracking screen, in that drag screen move everything little down and add back button but when that drag screen is full then hide that back button and bring back when that drag scren is back to default".

New `btnBackOnSheet` (36dp circular white CardView with `cardElevation=2dp`, ink-black back arrow) sits inside the bottom sheet's content `FrameLayout` at `layout_gravity="start|top"`, with the existing drag handle pill now layout_gravity'd `center_horizontal|top` in the same FrameLayout. The FrameLayout has `marginBottom=12dp` to preserve the original gap before the next sheet section.

Visibility lifecycle:
- **Idle mode** → hidden (the teal `topChrome` toolbar has its own back arrow).
- **Track mode + sheet at default peek / collapsed / dragging** → visible.
- **Track mode + sheet fully expanded (`STATE_EXPANDED`)** → hidden so the sheet content can use the full width without the back-button real-estate.

Wired via a new `BottomSheetBehavior.BottomSheetCallback` registered in `onCreate`:
```kotlin
override fun onStateChanged(view, newState) {
    if (!showLiveMapCurrent) return                   // idle ignores
    binding.btnBackOnSheet.isVisible =
        newState != BottomSheetBehavior.STATE_EXPANDED
}
```

`applyMapMode` active branch sets `isVisible=true` initially (sheet starts at COLLAPSED so the callback would also flip it on, but the explicit set covers the first frame). Idle branch sets `isVisible=false`.

Side-effect — the FrameLayout's height grows ~32dp when the back button is visible, which pushes the activeStatusGroup banner down. That's the "move everything little down" effect the user asked for, achieved naturally instead of with extra padding.

Click handler in `onCreate`: `binding.btnBackOnSheet.setOnClickListener { onBackPressed() }`.

**Two-step back behaviour** — user feedback: "when im in track screen when i press back im redirectng to main dashboard fix this". The previous behaviour fired `finish()` straight away, exiting the activity to the dashboard. New behaviour: back in track mode just drops the user back to the idle Driver Details view; a SECOND back press from idle then exits.

`onBackPressed` override:
```kotlin
override fun onBackPressed() {
    if (showLiveMapCurrent) {
        userOptedIntoTracking = false
        lastDriverDetails?.let { applyMapMode(it) }
        return
    }
    super.onBackPressed()
}
```

`btnBackOnSheet` click handler now calls `onBackPressed()` instead of `finish()` so the in-sheet button matches the system back gesture exactly. From track mode the user can also drag the sheet up to see everything, drag down to peek, or hit the back arrow to return to the route card.

### L49. Polyline visibility — per-direction + per-employee rules

User clarified the polyline gate the previous `currently_servicing.is_me` check was too restrictive. New rules:

**Pickup direction**

| My state                              | Show polyline? | Destination |
|---------------------------------------|----------------|-------------|
| pending (driver heading for someone else) | NO         | —           |
| `currently_servicing.is_me=true` (driver coming for me) | YES | my home |
| `me_done=true` (I'm in vehicle, headed to office) | YES        | office      |
| cancelled / no_show                   | NO             | —           |

Concretely: at "driver started shift", no one sees the line because the driver hasn't picked anyone yet. Driver taps "Start Ride to A" → A sees the line driver→A's home; B and C still pending and see nothing. Driver picks A → A's destination flips to office (still showing line); driver heads to B → B sees driver→B's home, A still sees driver→office. After everyone is picked, all picked employees see driver→office.

**Drop direction**

| My state                            | Show polyline? | Destination |
|-------------------------------------|----------------|-------------|
| pending (assigned, not yet dropped) | YES            | my home     |
| `me_done=true` (already dropped)    | NO             | —           |
| cancelled / no_show                 | NO             | —           |
| (leave — not on ride at all)        | NO             | —           |

Concretely: the moment driver taps "Begin Shift" for Drop, every assigned non-leave employee sees a polyline from driver→their home. As the driver actually drives, each employee's line updates. When driver marks A dropped, A's polyline disappears (`me_done=true`); B and C continue seeing theirs until dropped. Employees on leave never see anything because they're not on the ride's `employee_ids` to begin with (existing `pickEmployees` filter handles that).

**Implementation**

Old single-purpose flag `driverServicingMe` → split into two fields:
- `showRouteForMe: Boolean` — gate, computed from `computeShowRouteForMe(body)`.
- `routeDestination: LatLng?` — endpoint, computed from `computeRouteDestination(body)`. Flips between my home and office for the Pickup post-pickup case.

Both recomputed in `setupMapFromDetails` each poll. Polyline draws when `showRouteForMe=true && routeDestination != null`. Both call sites (REST snapshot + Firebase `onDataChange`) pass `routeDestination` to `maybeRefreshRoute` instead of the hardcoded `employeeMarker.position`.

`drawRoutePolyline` defensive check now uses `showRouteForMe`. `stopFirebaseTracking` resets both fields.

**Files**:
- `Zippi-User-Android-main/.../activities/CarDashboardActivity.kt`:
  - Field rename: `driverServicingMe` → `showRouteForMe`, plus new `routeDestination: LatLng?`.
  - New helpers `computeShowRouteForMe(body)` + `computeRouteDestination(body)`.
  - `setupMapFromDetails` updates both fields per poll, calls `clearRoutePolyline()` on `true→false` transition.
  - Both call sites (REST + Firebase onDataChange) gate on `showRouteForMe` and pass `routeDestination` to `maybeRefreshRoute`.
  - `drawRoutePolyline` early-returns when `!showRouteForMe`.
  - `stopFirebaseTracking` resets both fields.

**What was changed/removed vs L38(d)**:
- L38(d)'s "Pickup polyline only when `is_me=true`" was too restrictive — once a Pickup employee was picked, they lost the polyline even though they were now heading to office. L49 keeps it visible with destination switched to office.
- L38(d)'s "Drop polyline only when `is_me=true`" hid the line for all but the currently-being-dropped employee. L49 shows the line for everyone on the ride until their individual drop completes.

---

### L50. Route-card "done" time = actual pickup/drop time (not configured shift_start)

User screenshot showed a completed Pickup leg displaying `HOME 02:00` on the right — but `02:00` was their configured `shift_start`, not the time they were actually picked up. The today-live branch of `renderDailyCommute` was always assigning `morningTime = shiftStart?.take(5)` even when the leg was `done`.

**Fix — surface the per-employee pin-verified time end-to-end.**

Backend `buildTodayCompletedShifts` now pulls `enterprise_employee_rides` rows where `employee_id=me AND pin_verified=1 AND ride_id IN (...)`, keys them by `ride_id`, and includes the user's actual `updated_at` (= the moment `pin_verified` flipped to 1 = real pickup time for Pickup / real drop time for Drop) as a new `me_completed_at` field on each completed-shift entry:

```php
'completed_at'    => $r->started_at?->toIso8601String(),    // back-compat (parent ride started_at)
'me_completed_at' => $empRide?->updated_at?->toIso8601String(), // L50 new — per-employee
'duration_min'    => $r->duration,
```

Existing `completed_at` is preserved (it was misnamed but other callers may rely on it).

User-app model `CompletedShift` gains the `me_completed_at: String?` field.

`renderDailyCommute` today-live branch now picks the matching completed-shift entry by direction and parses `me_completed_at` into `HH:mm` via a new `isoToHm(iso)` helper. Falls back to `shift_start` / `shift_end` when the field is null (older backend, or somehow no completed-employee-ride row).

**Result**:
- Driver verified your OTP at 02:14 → screen now shows `HOME 02:14` (not `02:00`).
- Driver marked you dropped at 20:08 → screen shows `HOME 20:08` (not `20:00`).

**Files**:
- `staging/app/Http/Controllers/Api/EnterpriseController.php` — `buildTodayCompletedShifts` adds the `me_completed_at` field.
- `Zippi-User-Android-main/.../EnterpriseModel/DriverFullDetailsResponse.kt` — `CompletedShift.me_completed_at: String?`.
- `Zippi-User-Android-main/.../activities/CarDashboardActivity.kt` — `renderDailyCommute` today-live branch uses `me_completed_at` (parsed via `isoToHm`) for `morningTime` / `eveningTime` when state=done. New `isoToHm` helper.

**Note** — for snapshot days (My Commutes / past dates via week strip), the time already came from `buildCommuteLeg` which uses `enterprise_employee_rides.updated_at` directly. That path was already correct; L50 only fixes the today-live branch.

### L59. Enterprise login redesign — 3-step "Verify it's you" flow

Self-contained spec — an LLM agent should be able to rebuild this flow on a different platform purely by reading this section.

#### Scope and entry point

This flow lets an already-logged-in **mobile user** attach an enterprise (company) account to their identity. They reach it from the regular user dashboard via an "Add work account" affordance that opens `InterpriseActivity`. Once it completes, the dashboard switches to enterprise mode (`CarDashboardActivity`) on next open.

It does **not** replace the mobile-signup OTP (`OtpActivity` → /verify-otp). The two flows are now fully separate — different activities, different layouts, different APIs.

#### Three screens

```
InterpriseActivity         EnterpriseOtpActivity         EnterpriseOtpSuccessActivity
(Step 1 of 3, 33% bar)  →  (Step 2 of 3, 66% bar)     →  (Step 3 of 3, 100% bar)
"Let's verify it's you"    "Enter the 6-digit code"      "You're verified"
Company picker + email     6-box OTP grid                See my commute → dashboard
```

Each screen has the same top chrome: a 38dp round back button (top-left), a "Step N / 3" label (top-right), and a 4dp teal-gradient progress bar pinned just below.

#### Color tokens (used by this flow only)

| Purpose | Value |
|---|---|
| Primary teal (CTAs, halos, gradient) | `#40E0D0` |
| Primary teal dark (gradient end) | `#0FA896` |
| Ink (primary text, dark CTA) | `#15212B` |
| Sub-ink (secondary text) | `#6B7681` |
| Faint (placeholders) | `#9BA4AD` |
| Field outline (idle) | `#ECEFF2` |
| Field fill (idle) | `#FFFFFF` |
| Disabled CTA | `#C7CDD2` |
| Surface (screen background) | `#FFFFFF` |
| Error | `#E5564B` |

Drawables are prefixed `zp_login_*`, `zp_otp_*`, `zp_picker_*`, `zp_success_*` so they don't collide with the rest of the app's teal-system tokens (`#14C9B0` family).

---

#### Screen 1 — `InterpriseActivity` (Company + email)

**File**: [InterpriseActivity.kt](Zippi-User-Android-main/app/src/main/java/com/zippi/user/activities/InterpriseActivity.kt) · Layout: [activity_interprise.xml](Zippi-User-Android-main/app/src/main/res/layout/activity_interprise.xml)

**Layout (top → bottom)**

1. Toolbar row: back button (38dp circle, soft grey `#F1F4F6`, ink chevron) + spacer + step label.
2. Progress bar (4dp, 33% fill, gradient `#40E0D0` → `#0FA896`).
3. Headline: `Let's verify\nit's you` — 28sp, bold, ink, line-height 1.05.
4. Subtext: `We just need two things to set you up — your employer and a work email to verify.` — 14sp, sub-ink, line-height 1.4.
5. **Company picker field** — 60dp tappable card, 14dp corners, 1.5dp outline. On click: opens `CompanyPickerDialog`. While the picker is open the field draws with a 4dp teal halo (`zp_login_field_focused`) and the chevron rotates 180° (200ms). Once a company is picked, the placeholder office glyph is hidden and a colored 34dp initial badge takes its place; the name (15sp bold ink) and domain/address (12sp sub-ink) replace the "Tap to select your company" placeholder.
6. **Work email** — 60dp EditText, same 14dp rounded card. Focused state swaps to `zp_login_field_focused` (teal halo). Below it, hint line: `Use the email your employer registered for zippi.`
7. **Bottom CTA** — 54dp, 14dp corners. Disabled (grey `#C7CDD2`) by default; flips to dark ink (`#15212B`) when **both** of these are true:
   - A company is selected (non-null `selectedCompany`)
   - The email matches `Patterns.EMAIL_ADDRESS` **AND** its domain is NOT in the public-domain blocklist (see below)

**Public email blocklist** — rejected as "company emails":

```
gmail.com, yahoo.com, outlook.com, hotmail.com, rediffmail.com, icloud.com,
aol.com, msn.com, proton.me, zoho.com, mail.com, yandex.com
```

**API call on screen entry** — fetch the company list once on `onCreate`:

```
GET /api/enterprise/company-list?page=1&per_page=200
Headers: Authorization: Bearer {session_token}
Response: CompanyResponse { data: List<CompanyDataItem> }

CompanyDataItem fields actually used: id (Int), name (String), companyDomain (String?), address (String?)
```

If this fails the screen still loads — the picker just shows "Loading companies…" toast and re-fetches when the user taps it.

**On CTA tap** — POST send-otp, then forward to step 2 on success:

```
POST /api/enterprise/send-otp
Headers: Authorization: Bearer {session_token}
Query/Form:
  company_id: {selectedCompany.id}
  email:      {emailInput}
  enterprise_type: "employee"

Success (200, body.status == true):
  startActivity(EnterpriseOtpActivity) with extras:
    company   = selectedCompany.name
    companyid = selectedCompany.id.toString()
    email     = emailInput

Failure: show backend message via Toast.LENGTH_LONG.
  Common: "This email does not exist in this company." (404)
```

---

#### Company picker bottom sheet

**Files**:
- Dialog wrapper: [CompanyPickerDialog.kt](Zippi-User-Android-main/app/src/main/java/com/zippi/user/dialogs/CompanyPickerDialog.kt)
- Adapter: [CompanyPickerAdapter.kt](Zippi-User-Android-main/app/src/main/java/com/zippi/user/adapters/CompanyPickerAdapter.kt)
- Layout: [dialog_company_picker.xml](Zippi-User-Android-main/app/src/main/res/layout/dialog_company_picker.xml)
- Row layouts: `item_company_row.xml`, `item_company_letter_header.xml`

**Shape**: Material `BottomSheetDialog` with a custom style `ZpCompanyPickerSheet` (transparent framework background so the dialog's own 22dp-top-rounded white card shows). Height = 78% of screen height, expanded immediately, skipCollapsed = true.

**Contents (top → bottom)**:
1. 44dp × 4dp grabber pill (top center).
2. Title row: `Select your company` (17sp bold ink) + 32dp close button (circle, ink ×).
3. Search bar — 46dp, 12dp rounded pill, soft grey `#F4F6F8`. Search icon, EditText, "Clear" chip (only visible while query is non-empty).
4. `RecyclerView` of grouped rows (described below).
5. Empty state TextView: `No companies match "{query}".` (shown when filter produces 0 rows).
6. Footer: Cancel (1 unit, white + 1dp `#ECEFF2` outline, sub-ink text) + Confirm (1.4 unit, teal-filled when a row is selected, `#C7CDD2` otherwise).

**Grouping rule**: companies sorted alphabetically by name, then grouped by their leading character (uppercased). One letter header per group, followed by N company rows.

**Company row visual**:
- 64dp height
- 38dp colored initial badge on the left. Color is **deterministic** per company — palette `[teal #40E0D0, indigo #3F6CE0, coral #F2933A, green #2EA76A, purple #B463E0, pink #E0608A]` indexed by `Math.floorMod(company.id ?: name.hashCode(), 6)`. The first letter of the name in white 15sp bold sits centered.
- Two-line text block: name (15sp bold ink) + sub line (12sp sub-ink, either `companyDomain` or `address`, falling back to nothing).
- Teal check (`zp_ic_check_circle`) on the right when selected.

**Selection state**:
- A single tap selects the row, drawing the check + enabling Confirm.
- Tap on a second row replaces the selection (only one selected at a time).
- Confirm calls the dialog's `onPicked(CompanyDataItem)` callback and dismisses.
- Cancel / × / backdrop tap dismisses without firing the callback — the host activity keeps whatever was selected before the picker opened (if anything).

**Live filter**: case-insensitive substring match against `company.name` OR `company.companyDomain`. Filter on each keystroke, no debounce — the dataset is small (typically <50 companies).

---

#### Screen 2 — `EnterpriseOtpActivity` (Six-box OTP)

**File**: [EnterpriseOtpActivity.kt](Zippi-User-Android-main/app/src/main/java/com/zippi/user/activities/EnterpriseOtpActivity.kt) · Layout: [activity_enterprise_otp.xml](Zippi-User-Android-main/app/src/main/res/layout/activity_enterprise_otp.xml)

**Intent extras (required)**:
- `email` (String) — verified destination
- `company` (String) — company display name (forwarded to step 3)
- `companyid` (String) — used by Resend

**Layout**:
1. Same toolbar + step indicator (now "2 / 3", 66% bar).
2. Headline: `Enter the\n6-digit code`.
3. Subtext: `We sent it to {email}. It expires in 10 minutes.` — the email is wrapped in `<b><font color=#15212B>...</font></b>`.
4. `↺ Change email` link (13sp bold teal). Tapping it `finish()`es back to step 1.
5. **Six 60dp OTP boxes** in a single LinearLayout row with `layout_weight=1` per box and 10dp `Space`s between them. Each box: 14dp rounded, 1.8dp outline, 26sp bold ink digit, max-length 1, `inputType="number"`.
6. Resend card — 14dp rounded, faint grey `#FAFBFC` ground, 1dp `#ECEFF2` outline. Inside (left → right):
   - 36dp teal mail-glyph circle
   - Title `Didn't get the code?` + caption `Check spam or wait a moment.`
   - **Countdown pill** (starts at `0:42`, ticks down 1 Hz, monospaced text on `#F1F4F6` rounded background) **OR** **Resend button** (teal pill, white bold "Resend"). Pill is visible during countdown; button takes over at 0:00.
7. Bottom **Verify** CTA — 54dp, 14dp corners. Disabled grey by default; flips teal `#40E0D0` once all six boxes are non-empty.

**Box state matrix** (drawables swap as the user types):

| Condition | Drawable | Look |
|---|---|---|
| Empty AND focus / next-up | `zp_otp_box_active` | White fill, teal 1.8dp outline, 4dp teal halo |
| Filled | `zp_otp_box_filled` | `#F8F9FA` fill, ink `#15212B` outline |
| Empty AND not next-up | `zp_otp_box` | White fill, grey `#ECEFF2` outline |
| Server rejected the code | `zp_otp_box_error` (all 6) | White fill, red `#E5564B` outline |

The "next-up" box is the one whose index equals `currentCode.length` (i.e., first empty after the filled prefix).

**Box input behavior**:
- Single-digit typed → box fills, focus advances to next box.
- Backspace on an empty box → focus moves back to previous box AND clears it (single-keystroke "delete back through the row").
- **Paste path**: if a multi-digit number lands in any box (typically because the user pasted from the email/SMS), the digits splay forward across the remaining boxes starting at that index. Example: paste `123456` into box 1 → boxes [1,2,3,4,5,6]; paste `5678` into box 3 → boxes [_, _, 5, 6, 7, 8].
- All non-digit characters are stripped from input (`raw.all { it.isDigit() }` check before splaying).

**Verify API call**:

```
POST /api/enterprise/verify-otp
Headers: Authorization: Bearer {session_token}
Query/Form:
  email:           {email}
  ride_pin:        {6-digit code, joined as a single string}
  enterprise_type: "employee"

Response: EnterpriseOtpVerifiedResponse {
  status:  Boolean
  message: String
  employee: { id: Int, ... }
}

Success → call saveEnterpriseData immediately (see below)
Failure → setErrorState() paints all 6 boxes red; toast the backend message.
```

**Save-data follow-up call** (only if verify returned status=true):

```
POST /api/enterprise/save-data            (alias: saveenterprisedata)
Headers: Authorization: Bearer {session_token}
Query/Form:
  employee_id: {employee.id from verify response}

On success → sessionManager.enterpriseLoggedOutPref = false
              start EnterpriseOtpSuccessActivity (FLAG_ACTIVITY_NEW_TASK | CLEAR_TASK)
              with extras: company_name = company, email = email
```

**Resend**: POSTs the same `/api/enterprise/send-otp` from step 1 with stored `companyid`/`email`. On 200 the countdown restarts at 42s and the button hides behind the pill again.

---

#### Screen 3 — `EnterpriseOtpSuccessActivity` (Success + "See my commute")

**File**: [EnterpriseOtpSuccessActivity.kt](Zippi-User-Android-main/app/src/main/java/com/zippi/user/activities/EnterpriseOtpSuccessActivity.kt) · Layout: [activity_enterprise_otp_success.xml](Zippi-User-Android-main/app/src/main/res/layout/activity_enterprise_otp_success.xml)

**Intent extras**:
- `company_name` (String) — bolded inside the welcome line
- `email` (String) — shown as the summary card subtitle

**Layout**:
1. Toolbar + step indicator ("3 / 3", 100% bar).
2. Centered content (weight=1, vertical gravity center):
   - **128dp pop ring**: 128dp `#E4F9F4` (`zp_teal_50`) outer disc with a 96dp teal `#40E0D0` inner disc inset 16dp, centered white tick glyph (56dp `zp_ic_check_thick`). The whole stack runs `R.anim.zp_success_pop` on entry — overshoot interpolator, 600ms, scale 0.4 → 1.0, alpha 0 → 1.
   - Title `You're verified` (26sp bold ink).
   - Welcome line `Welcome to <b>{company_name}</b> on zippi. You're all set up and ready to ride.` (14sp sub-ink, ink bold on the company name).
   - **Summary card**: 14dp rounded card, faint grey ground, 1dp outline. Colored 34dp initial badge (same palette + hashing as the picker) + name (14sp bold ink) + email (12sp sub-ink) + 24dp green check circle on the right (`#E3F5EB` disc with `#2EA76A` tick).
3. Bottom CTA — teal `#40E0D0` filled, `See my commute` + right-arrow.

**On CTA tap** — hard-reset the back stack so back from the dashboard goes to home, not the login flow:

```kotlin
val dash = Intent(this, DashboardActivity::class.java).apply {
    flags = FLAG_ACTIVITY_NEW_TASK or FLAG_ACTIVITY_CLEAR_TASK
    putExtra("skip_enterprise_check", true)
}
startActivities(arrayOf(dash, Intent(this, CarDashboardActivity::class.java)))
```

The dashboard activity is launched first as the back-stack root, then `CarDashboardActivity` is launched on top — pressing back on the dashboard returns to home, pressing back on `CarDashboardActivity` returns to the regular dashboard.

---

#### Back-stack rules

| From | Back goes to |
|---|---|
| Step 1 (`InterpriseActivity`) | Previous screen that launched it (typically the dashboard) |
| Step 2 (`EnterpriseOtpActivity`) | Step 1 — also exposed via the explicit "↺ Change email" link |
| Step 3 (`EnterpriseOtpSuccessActivity`) | Step 2 via the back button (UI affordance, even though by then verify already succeeded — useful for QA) |
| After CTA in step 3 | `DashboardActivity` then `CarDashboardActivity` — the entire login back stack is cleared |

---

#### Validation summary (everything the screens enforce before hitting an API)

- **Step 1 → /send-otp**: company selected AND email valid AND email domain not in public blocklist.
- **Step 2 → /verify-otp**: all 6 OTP boxes filled.
- **Step 2 Resend**: companyId and email are non-null (they should always be from step 1).
- **Step 3 → dashboard**: none — success implies verify already succeeded server-side.

---

#### What the mobile-signup OTP looks like now

After this redesign the legacy `OtpActivity` is **mobile-signup only** (verify the SMS code on the phone the user typed at Login). Its layout went back to a single EditText + Verify button + Resend OTP link — the simple design it had before this work began. No enterprise code remains in `OtpActivity.kt`. The two flows never share a layout or activity again.

---

#### Manifest

Both new activities are registered in [AndroidManifest.xml](Zippi-User-Android-main/app/src/main/AndroidManifest.xml):

```xml
<activity android:name=".activities.EnterpriseOtpActivity" android:exported="false" />
<activity android:name=".activities.EnterpriseOtpSuccessActivity" android:exported="false" />
```

---

#### File inventory

| Kind | Path |
|---|---|
| Activity | `activities/InterpriseActivity.kt` (modified — picker dialog wiring + validation + new routing) |
| Activity | `activities/EnterpriseOtpActivity.kt` (new) |
| Activity | `activities/EnterpriseOtpSuccessActivity.kt` (new) |
| Activity | `activities/OtpActivity.kt` (restored to original mobile-only) |
| Dialog | `dialogs/CompanyPickerDialog.kt` (new) |
| Adapter | `adapters/CompanyPickerAdapter.kt` (new) |
| Layout | `layout/activity_interprise.xml` (redesigned) |
| Layout | `layout/activity_otp.xml` (restored to original) |
| Layout | `layout/activity_enterprise_otp.xml` (new) |
| Layout | `layout/activity_enterprise_otp_success.xml` (new) |
| Layout | `layout/dialog_company_picker.xml` (new) |
| Layout | `layout/item_company_row.xml` (new) |
| Layout | `layout/item_company_letter_header.xml` (new) |
| Anim | `anim/zp_success_pop.xml` (new) |
| Drawable | `drawable/zp_login_field.xml`, `zp_login_field_focused.xml`, `zp_login_btn_primary.xml`, `zp_login_btn_primary_teal.xml`, `zp_login_btn_disabled.xml`, `zp_login_back_btn.xml`, `zp_login_progress_bar.xml`, `zp_login_logo_placeholder.xml` |
| Drawable | `drawable/zp_picker_search_bg.xml`, `zp_picker_close_btn.xml`, `zp_picker_cancel_btn.xml`, `zp_picker_confirm_btn.xml`, `zp_picker_confirm_btn_disabled.xml`, `zp_picker_grabber.xml`, `zp_bottom_sheet_top_rounded.xml` |
| Drawable | `drawable/zp_otp_box.xml`, `zp_otp_box_active.xml`, `zp_otp_box_filled.xml`, `zp_otp_box_error.xml`, `zp_otp_resend_card.xml`, `zp_otp_mail_circle.xml`, `zp_otp_countdown_pill.xml`, `zp_otp_resend_btn.xml` |
| Drawable | `drawable/zp_success_ring_outer.xml`, `zp_success_ring_inner.xml`, `zp_success_summary_card.xml`, `zp_success_summary_check.xml` |
| Drawable (icon vectors) | `drawable/zp_ic_arrow_left.xml`, `zp_ic_arrow_right.xml`, `zp_ic_chevron_down.xml`, `zp_ic_search.xml`, `zp_ic_close.xml`, `zp_ic_check_circle.xml`, `zp_ic_check_thick.xml`, `zp_ic_check_small_green.xml`, `zp_ic_mail.xml`, `zp_ic_office_outline.xml` |
| Styles | `ZpCompanyPickerSheet`, `ZpCompanyPickerSheet.Modal`, `ZpOtpBox` (in `values/themes.xml`) |
| Manifest | Two new `<activity>` entries |

---

### L58. Geo-fence radii + GPS staleness threshold are env-tunable

All five geofence call sites in `EnterpriseRideController.php` previously had a mix: `rideArrived` (Pickup) already read from `config/enterprise.php`, but `sendOtp` (Pickup pre-arrived), `markEmployeeDropped`, and `completeRide` hardcoded the radius and the 120-second GPS-staleness threshold inline. The four config keys existed (`pickup_arrive_fence_meters`, `drop_fence_meters`, `complete_office_fence_meters`, `driver_location_max_age_seconds`) but three of them were never read — a latent footgun where setting `ENTERPRISE_DROP_FENCE_METERS=750` in `.env` would silently do nothing.

**Fix**: every haversine + staleness check in [EnterpriseRideController.php](staging/app/Http/Controllers/Api/EnterpriseRideController.php) now reads from `config()` instead of literals. Defaults match the previous hardcoded values exactly — zero behaviour change today.

| Call site | Radius config | Staleness config |
|---|---|---|
| `sendOtp` (Pickup geofence) — L1864/L1871 | `enterprise.pickup_arrive_fence_meters` (1000m) | `enterprise.driver_location_max_age_seconds` (120s) |
| `rideArrived` — L2306 | (already used config — unchanged) | (already used config — unchanged) |
| `markEmployeeDropped` — L2614/L2621 | `enterprise.drop_fence_meters` (500m) | `enterprise.driver_location_max_age_seconds` (120s) |
| `completeRide` (Pickup → office) — L2727/L2740 | `enterprise.complete_office_fence_meters` (500m) | `enterprise.driver_location_max_age_seconds` (120s) |

**Why this matters**: ops can now tune any fence radius or the staleness window via `.env` without a code deploy. Useful for:
- Customer complaints ("our parking lot is 600m from the entrance, drivers can't complete shift") — bump `ENTERPRISE_COMPLETE_OFFICE_FENCE_METERS=750`, restart php-fpm, done.
- QA testing the "GPS stale" rejection path without recompiling — set `ENTERPRISE_DRIVER_LOCATION_MAX_AGE_SECONDS=10` for ten minutes.
- Multi-city rollouts where one city's pin metadata is loose enough to need a bigger fence.

**Files**:
- `staging/app/Http/Controllers/Api/EnterpriseRideController.php` — five `(int) config('enterprise.…')` reads replacing the hardcoded literals.
- `staging/config/enterprise.php` — already had the keys (`pickup_arrive_fence_meters`, `drop_fence_meters`, `complete_office_fence_meters`, `driver_location_max_age_seconds`) — unchanged.

**What was changed/removed**: hardcoded `120`, `500`, `1000` literals are gone from the four geofence sites; all flow through `config()`. The `rideArrived` site was already correct since the original Pickup geofence implementation.

---

### L57. Admin "no-show" pushes both driver and employee

User: "from admin panel i clicked on 'emplyee no show' and it is not updated in driver screen and updated in the user screen".

`EnterpriseLiveTrackingController::adminMarkNoShow` did the DB work (deleted unverified `enterprise_employee_rides`, created cancel row with `cancelled_by='admin_no_show'`, cleared `current_servicing_employee_id`), recorded the audit log, and returned a JSON 200. But it **never pushed any FCM event** — neither to the driver nor the employee.

- Employee app picked up the change via the next 10-second `/driver-full-details` poll, so it eventually showed the "Marked as no-show" banner.
- Driver app had **no way to know** until they manually refreshed. The no-show employee continued to appear in their route list as pending.

**Fix**: dispatch two pushes after the transaction commits:

1. To the **driver** — new `EnterpriseAdminNoShowDriverNotification` class. Reuses the existing `notification_type=Enterprise_Employee_Cancelled` data field so the driver app's existing FCM handler + LocalBroadcast receiver fire without needing a new branch — the body text just clarifies it was an admin action ("Admin marked no-show / {name} was marked no-show by admin. They have been removed from your route.").

2. To the **employee** — existing `EnterpriseEmployeeNoShowNotification` with `cancelled_by='admin_no_show'`. Triggers the same FCM handler the user app already wires to via `Enterprise_Employee_NoShow` LocalBroadcast — banner flips to the red "Marked as no-show" copy without waiting for the next poll.

Both wrapped in a `try/catch` with a structured `Log::error` so a missing FCM token on either side doesn't 500 the admin endpoint.

**Files**:
- New: `staging/app/Notifications/EnterpriseAdminNoShowDriverNotification.php`.
- `staging/app/Http/Controllers/Admin/EnterpriseLiveTrackingController.php` — `adminMarkNoShow` now dispatches both notifications after the audit log call.

**What was changed/removed**:
- L29's `adminMarkNoShow` was push-less; L57 adds the two-side FCM dispatch.
- Reused `Enterprise_Employee_Cancelled` notification_type for the driver so the existing handler (which already removes the employee from the route view) does the right thing. No driver-app code change needed.

---

### L56. Floating buttons fade out at full sheet expansion

User: "remove both back and rescenter button when fully opened the sheet".

The previously stub `BottomSheetCallback` now drives both `btnBackFloating` and `btnRecenter`:
- `onSlide(slideOffset)` sets `alpha = (1 - slideOffset).coerceIn(0, 1)` on both buttons — they fade out smoothly as the user drags the sheet up.
- `onStateChanged(STATE_EXPANDED)` flips both to `isVisible=false` so they're fully gone (and don't accept taps) once the sheet settles at the top.
- When the user drags BACK DOWN from expanded, `onSlide` fires with `slideOffset < 1` → buttons get `isVisible=true` restored before the alpha animation reveals them again.
- `applyMapMode` active branch resets `alpha=1f` on both so they're not stuck at 0 from a previous expanded session.
- Idle mode ignores the callback (`if (!showLiveMapCurrent) return`) — the buttons are hidden in idle anyway.

**Visual flow**:
- Sheet at peek (40% screen) → both buttons fully opaque, visible.
- User drags up halfway → buttons at ~50% alpha.
- Sheet snaps to full expansion → both buttons fade to invisible, then `isVisible=false` to remove from hit-testing.
- User drags down → buttons re-visible, fade back to full opacity.

**Files**:
- `Zippi-User-Android-main/app/src/main/java/com/zippi/user/activities/CarDashboardActivity.kt`:
  - `BottomSheetCallback` implements `onSlide` + `onStateChanged` for fade/hide of both buttons.
  - Active branch of `applyMapMode` resets `alpha=1f` on both buttons.

---

### L55. Track-screen buttons + drop pin fixes

User: "in track screen, back button and recenter button dosent function like i said" + "i cant see that drop icon in the map".

Three fixes bundled:

**a) Recenter button now visible the moment track mode opens**

Previously `btnRecenter` had `android:visibility="gone"` in the XML and only became visible when the user manually panned the map (via `onCameraMoveStartedListener`). User expected it available from the start in track mode. Added `binding.btnRecenter.isVisible = true` to the active branch of `applyMapMode`. The button now sits at top-right of the map immediately on entering track mode, regardless of whether the user has interacted with the map yet.

Also `recenterMap()` no longer flips the button to `isVisible=false` after a tap — the button stays put so the user can re-tap after the next pan. The internal `userInteractedWithMap` flag (which gates auto-fit on Firebase updates) is still cleared on tap.

**b) Back button two-step exit confirmed wired**

Re-verified `binding.btnBackFloating.setOnClickListener { onBackPressed() }` and the `onBackPressed` override:
```kotlin
override fun onBackPressed() {
    if (showLiveMapCurrent) {
        userOptedIntoTracking = false
        lastDriverDetails?.let { applyMapMode(it) }
        return
    }
    super.onBackPressed()
}
```
First tap → closes the map, returns to idle Driver Details view. Second tap → exits the activity. System back gesture follows the same path.

**c) Drop pin SVG simplified + bitmap size doubled**

Previous `zp_marker_home.xml` used a complex cubic-Bezier teardrop. Some Android skins were rendering it as a barely-visible blob. Rewrote with a simpler Material-style location pin path (`M24,2 C12.95,2 4,10.95 4,22 C4,38 24,58 24,58 C24,58 44,38 44,22 C44,10.95 35.05,2 24,2 Z`) inside a 48×60dp viewport.

`vectorToBitmap` resize bumped from 80×104 → **144×180 px** so the pin is clearly visible at typical Google Maps zoom levels on high-density phone screens.

Same simplification applied to `zp_marker_office.xml` (kept staged for future Pickup-post-pickup use).

**Files**:
- `Zippi-User-Android-main/app/src/main/java/com/zippi/user/activities/CarDashboardActivity.kt`:
  - `applyMapMode` active branch: `binding.btnRecenter.isVisible = true`.
  - `recenterMap()`: no longer hides the button after tap.
  - `setupMapFromDetails`: employee marker bitmap rendered at 144×180.
- `Zippi-User-Android-main/app/src/main/res/drawable/zp_marker_home.xml` — simpler pin path, 48×60 viewport.
- `Zippi-User-Android-main/app/src/main/res/drawable/zp_marker_office.xml` — same.

---

### L54. abbreviatedDriverName — title-case the last name for lowercase input

User: "show driver full name if Kandhan madhavan then show K Madhavan".

L48's `abbreviatedDriverName` only uppercased the FIRST letter of the first word; the last word was kept at whatever casing the admin panel stored. So "kandhan madhavan" rendered as "K madhavan" — the trailing "m" should be a capital "M".

Tweak: capitalize the last word's first letter too via `replaceFirstChar { it.uppercaseChar() }`.

| Input (admin panel)    | Output (UI)   |
|------------------------|---------------|
| `Kandhan Madhavan`     | `K Madhavan`  |
| `kandhan madhavan`     | `K Madhavan`  |
| `KANDHAN MADHAVAN`     | `K MADHAVAN`  (no down-case — preserves all-caps preference) |
| `John Paul Smith`      | `J Smith`     |
| `driverzippi`          | `driverzippi` (single word, unchanged) |

**Files**:
- `Zippi-User-Android-main/app/src/main/java/com/zippi/user/activities/CarDashboardActivity.kt` — three-line change inside `abbreviatedDriverName`.

---

### L53. Hero avatar — white circle with teal initials (was teal-on-teal, invisible)

User screenshot showed the avatar in the teal hero rendering as a plain teal disk with nothing visible inside. The avatar backdrop was `zp_avatar_gradient` (teal-to-tealDk gradient) sitting on a teal `zp_hero_gradient` background — teal on teal, with white initials text. Net contrast: near-zero.

Switched the pinned hero's avatar to a solid white surface with teal initials:

- New drawable `zp_avatar_on_hero.xml`: `zp_surface_card` (white) fill + 2dp `zp_teal_100` stroke for a soft inner ring.
- Initials TextView text color: `zp_teal_700` (deep teal) instead of `#FFFFFF`.
- Avatar size bumped 42dp → 44dp so it carries visual weight to balance the 38dp white phone button on the right.

In-sheet driver-row avatar (`driverInfoRowSheet`) left as-is — that one sits on a white card surface where the teal-gradient backdrop with white initials works correctly. Only the pinned-on-hero copy needed the flip.

**Files**:
- New: `Zippi-User-Android-main/app/src/main/res/drawable/zp_avatar_on_hero.xml`
- `Zippi-User-Android-main/app/src/main/res/layout/activity_car_dashboard.xml` — pinned `FrameLayout` backdrop swapped, initials color changed, size 42→44dp.

**What was changed/removed vs L48**:
- L48 used `zp_avatar_gradient` on the hero — invisible against teal background.
- L48 initials color `#FFFFFF` — only readable on a non-white backdrop.

---

### L52. Pickup leg "Completed" gated on Complete Shift (not OTP verify)

User: "in user app details screen in pickup only show completed when driver press 'complete shift'."

L46 made both directions flip to `state="done"` when `me_done=true`. For Drop that's correct — once you're dropped, the leg is over. For Pickup it was wrong: OTP verify sets `me_done=true` but the user is in the vehicle on the way to office — the leg isn't really done from a journey-completion standpoint.

Reverting only the Pickup half:

```kotlin
val pickupDoneForMe = pickupDone                       // L52 — wait for parent ride.status='completed'
val dropDoneForMe   = dropDone || (isDrop && meDone)   // L46 unchanged — per-employee terminal
```

`pickupDone` is `"pickup" in today_completed_shifts.directions`. `buildTodayCompletedShifts` only includes a direction when the parent `enterprise_rides.status='completed'`, which is set when the driver taps Complete Shift at the office. So:

| Moment | `meDone` | `pickupDone` | `pickupDoneForMe` | UI state |
|---|---|---|---|---|
| Driver shift started, hasn't picked me yet | false | false | false | "Live" / "Up next" |
| Driver verified my OTP (I'm in vehicle) | **true** | false | **false** | **"Live"** (was "Done" before L52) |
| Driver tapped Complete Shift at office | true | **true** | **true** | "Completed" |

The route-card status chip + segment pill follow the same logic, so "DONE" badge and "Completed" header only appear after Complete Shift.

Drop semantics unchanged (per-employee terminal). The OTP-inline display and "On the way to office" banner copy in `applyCurrentlyServicingFromPoll` were already direction-aware — no change needed there.

**Files**:
- `Zippi-User-Android-main/app/src/main/java/com/zippi/user/activities/CarDashboardActivity.kt` — one line in `renderDailyCommute` live-state branch.

**What was changed/removed vs L46**:
- L46's `pickupDoneForMe = pickupDone || (isPickup && meDone)` → L52's `pickupDoneForMe = pickupDone`.
- L46's Drop branch (`dropDoneForMe = dropDone || (isDrop && meDone)`) preserved.

---

### L51. Enterprise color palette extracted into named tokens

User confirmed the brand main color is `#14C9B0` and asked for "a color palette and change the colors in the enterprise module" so changes can roll from one place. Previously every shade was hardcoded in 30+ drawables and the layout XML — touching a single brand color meant a 30+ file find-and-replace.

**Palette appended to `values/colors.xml`** (legacy `colorPrimary` / `primaryDark` are left at `#40e0d0` — they're used by non-enterprise screens and shouldn't shift without a separate review):

```xml
<!-- Brand teal -->
<color name="zp_teal_50">#E4F9F4</color>
<color name="zp_teal_100">#CDF1E5</color>
<color name="zp_teal_400">#38CFAF</color>
<color name="zp_teal_500">#14C9B0</color>  <!-- brand main -->
<color name="zp_teal_600">#11B49E</color>
<color name="zp_teal_700">#0FA896</color>  <!-- brand dark -->
<color name="zp_teal_900">#086455</color>

<!-- Coral (Pickup direction, Leave accent) -->
<color name="zp_coral_50">#FFF1DC</color>
<color name="zp_coral_500">#F2933A</color>
<color name="zp_coral_700">#D87715</color>

<!-- Indigo (Drop direction) -->
<color name="zp_indigo_50">#E8EFFD</color>
<color name="zp_indigo_500">#3F6CE0</color>
<color name="zp_indigo_700">#2D52BF</color>

<!-- Success / Danger -->
<color name="zp_ok_50">#E3F5EB</color>
<color name="zp_ok_500">#2EA76A</color>
<color name="zp_danger_50">#FFE5E0</color>
<color name="zp_danger_500">#C62828</color>

<!-- Ink (text + surface) -->
<color name="zp_ink_900">#15212B</color>   <!-- primary text -->
<color name="zp_ink_600">#6B7681</color>   <!-- secondary text -->
<color name="zp_ink_400">#9BA4AD</color>   <!-- faint labels -->
<color name="zp_ink_200">#ECEFF2</color>   <!-- hairline borders -->
<color name="zp_ink_100">#F1F4F6</color>   <!-- neutral chips -->
<color name="zp_ink_50">#F6F8F9</color>    <!-- page bg -->
<color name="zp_surface_card">#FFFFFF</color>
<color name="zp_translucent_white">#2EFFFFFF</color>

<!-- Semantic aliases -->
<color name="zp_brand_main">#14C9B0</color>
<color name="zp_brand_dark">#0FA896</color>
<color name="zp_brand_light">#E4F9F4</color>
```

**~25 enterprise drawables refactored** to reference the tokens instead of hardcoded hex:

| Drawable                              | Token used                                              |
|---------------------------------------|---------------------------------------------------------|
| `zp_hero_gradient.xml`                | gradient `zp_teal_500 → zp_teal_700`                    |
| `zp_avatar_gradient.xml`              | same                                                    |
| `zp_pulse_dot_static.xml` + `_ring`   | `zp_teal_500`                                           |
| `zp_route_dot.xml` + `_square` + `_line_dashed`         | `zp_coral_500`                              |
| `zp_route_dot_indigo` + `_square_indigo` + `_line_dashed_indigo` | `zp_indigo_500`                       |
| `zp_seg_morning_pip.xml` / `_evening_pip`        | `zp_coral_500` / `zp_indigo_500`             |
| `zp_seg_selected_bg.xml`              | `zp_ink_50`                                             |
| `zp_status_dot_ok`                    | `zp_ok_500`                                             |
| `zp_status_dot_leave`                 | `zp_coral_700`                                          |
| `zp_status_dot_danger`                | `zp_danger_500`                                         |
| `zp_status_dot_muted`                 | `zp_ink_400`                                            |
| `zp_status_chip_live`                 | `zp_teal_50`                                            |
| `zp_status_chip_done`                 | `zp_ok_50`                                              |
| `zp_status_chip_leave`                | `zp_coral_50`                                           |
| `zp_status_chip_danger`               | `zp_danger_50`                                          |
| `zp_status_chip_neutral`              | `zp_ink_100`                                            |
| `zp_daypill_today`                    | `zp_teal_500`                                           |
| `zp_daypill_done`                     | `zp_teal_50`                                            |
| `zp_daypill_default`                  | `zp_surface_card` + `zp_ink_200` border                 |
| `zp_daypill_selected`                 | `zp_surface_card` + `zp_teal_500` border                |
| `zp_daypill_leave`                    | `zp_coral_50`                                           |
| `zp_schedule_date_bg`                 | `zp_ink_50`                                             |
| `zp_schedule_date_today_bg`           | `zp_teal_500`                                           |
| `zp_schedule_pill_neutral/done/leave` | `zp_ink_100` / `zp_ok_50` / `zp_coral_50`               |
| `zp_stat_row_bg`                      | `zp_ink_50` + `zp_ink_200` border                       |
| `zp_driver_row_bg`                    | `zp_surface_card` + `zp_ink_200` border                 |
| `zp_marker_home` + `zp_marker_office` | `zp_teal_700` + `zp_surface_card`                       |

**Layout XML changes** (`activity_car_dashboard.xml`):
- All `#0FA896` → `@color/zp_teal_700` (tints + text colors)
- `#14C9B0` (in-sheet driver-row phone button) → `@color/zp_teal_500`
- `#E4F9F4` (Call Support tile bg) → `@color/zp_teal_50`

**Kotlin changes** (`CarDashboardActivity.kt`):
- Polyline color `Color.parseColor("#14C9B0")` → `ContextCompat.getColor(this, R.color.zp_teal_500)`
- Other `Color.parseColor("#...")` calls for state-driven colors (status pill text colors, leg-accent colors etc.) left as hex literals — they're already coherent with the palette and changing all of them would mean a larger refactor. Easy follow-up if needed: convert to `ContextCompat.getColor()` referencing the same tokens.

**Files**:
- `Zippi-User-Android-main/app/src/main/res/values/colors.xml` — new palette appended.
- `Zippi-User-Android-main/app/src/main/res/drawable/zp_*.xml` — ~25 files refactored.
- `Zippi-User-Android-main/app/src/main/res/layout/activity_car_dashboard.xml` — brand-color hex → `@color/zp_teal_*`.
- `Zippi-User-Android-main/app/src/main/java/com/zippi/user/activities/CarDashboardActivity.kt` — polyline color via `ContextCompat.getColor`.

**Result**: change `<color name="zp_teal_500">` once → every dashboard pill / chip / button / pin / gradient / polyline updates simultaneously. The enterprise module now has a true design-token layer.

---

**L50 follow-up — dashed dividers actually render on all devices**:
User screenshot showed the horizontal divider between HOME and OFFICE missing entirely. Two issues, both fixed:
1. `shape="line"` with dashed stroke drops the dash pattern on hardware-accelerated canvases (silently — line appears solid OR doesn't render). Added `android:layerType="software"` to both `vRouteDividerH` (horizontal) and `vRouteLine` (left rail vertical) so the stroke pattern always renders.
2. The horizontal divider was 2dp tall — same as the stroke width, no breathing room. Bumped to 6dp tall.
3. Dash pattern bumped slightly for visibility: stroke 2→2.5dp; dashWidth 3→5dp; dashGap 3→4dp. Applied to both `zp_route_line_dashed.xml` (coral) and `zp_route_line_dashed_indigo.xml`.

Result: the dashed divider is now visible between HOME and OFFICE address blocks; the left rail's vertical dashed connector is also more prominent.

---

**L49 follow-up — recenter FAB made static + custom destination pin**:
User: "u can see that recenter button is over lapping, can u keep that button static? and can u add a icon to that drop endpoint so that it looks good?"

- `btnRecenter` was anchored to the bottom sheet (`app:layout_anchor="@+id/bottomSheet"`, `top|end`, `marginBottom=56dp`), so it moved up/down as the sheet dragged — overlapping the sheet's top edge in the default peek state. Now positioned with plain `layout_gravity="top|end"` + `marginTop=12dp` / `marginEnd=12dp`, mirroring the floating back button at top-left. Static — stays put on the map regardless of sheet state.
- Two new vector drawables added: `zp_marker_home.xml` (teal teardrop pin with white inner disk + teal house glyph) and `zp_marker_office.xml` (same pin shape with a building glyph). New helper `vectorToBitmap(resId, w, h)` rasterises a vector drawable into a Bitmap so Google Maps' `BitmapDescriptorFactory.fromBitmap` can consume it (the existing `resizeBitmap` only handles PNGs).
- The employee marker in `setupMapFromDetails` now uses `zp_marker_home` at 80×104px with `.anchor(0.5f, 1f)` so the tip of the pin sits exactly on the lat/lng. Replaces the previous `BitmapDescriptorFactory.defaultMarker(HUE_AZURE)` blue pin. Reads as "your home" at a glance and matches the app's teal brand palette.

The office marker drawable is staged for future use (Pickup post-pickup case when the route destination switches to office) — not wired up yet to avoid double-marker clutter.

**L49 follow-up — back button moved back onto the map; status card pulled up a touch**:
User: "in track screen add that back button at top left (inside maps) and when drag screen is full siezed the status card is again move up...keep it same size when its in default(move it just a litttle above)".

- `btnBackFloating` (the existing CardView anchored to the CoordinatorLayout root, `layout_gravity="top|start"`) is now shown in track mode (`isVisible=true` in the active branch). It sits over the map at top-left, never moves with the sheet, and routes click → `onBackPressed()` for the same two-step exit as the system gesture.
- `btnBackOnSheet` (the in-sheet back button added earlier) is now a hidden 0dp binding-compat stub. Single source of truth = the floating one on the map.
- `BottomSheetCallback` left as a no-op stub — the per-state visibility toggle isn't needed anymore.
- Sheet header FrameLayout (containing the drag-handle pill) trimmed: `marginBottom` 12 → 6dp, drag handle `marginTop` 8 → 4dp. Total ~10dp pulled out, so the status card sits a touch higher in the default peek state. When the user drags the sheet up to full, the status card naturally moves up with the sheet content — that's the "again move up" effect with no extra logic.

**L48 follow-up — shift-agnostic Pickup/Drop labels + horizontal dashed divider**:
User: "remove 'morning pickup' and evening pickup since some employees have night shift too, keeping pickup and drop only is good".

Night-shift employees have their Pickup in the evening and Drop the next morning — the "Morning"/"Evening" prefix was incorrect for them. Three small renames:
- Segmented toggle labels: `"Morning"` → `"Pickup"`, `"Evening"` → `"Drop"`.
- Route card direction label: `"MORNING PICKUP"` → `"PICKUP"`, `"EVENING DROP"` → `"DROP"`.
- Sun/moon emoji (`tvRouteDirectionIcon`) dropped from the visible UI (kept as a 0dp hidden binding-compat stub) — same reasoning, the glyph misleads night-shift users.

Accent colors unchanged: Pickup = coral `#F2933A`, Drop = indigo `#3F6CE0`. The colors map to ride direction, not time of day, so they stay correct for any shift.

**Horizontal dashed divider between FROM and TO blocks** added in the route card's right column. Previously there was a plain 14dp spacer between the two address blocks; now there's a `View` with `@drawable/zp_route_line_dashed` (Pickup) or `@drawable/zp_route_line_dashed_indigo` (Drop) background, 2dp tall, `marginTop=10dp` + `marginBottom=10dp`, swapped by Kotlin to match the selected leg. Gives a clear visual handoff between "where I am" and "where I'm going" while maintaining the same dashed style as the left rail's vertical connector.

New layout id: `vRouteDividerH`. Kotlin's drawable swap added to the same block that recolors the left rail's dot/line/square.

**L48 follow-up — driver name displayed as "K Madhavan" (first-initial + last word)**:
User wants the visible driver name to read like "K Madhavan" instead of the full "Kandhan Madhavan" — keeps the hero compact while staying personal.

New helper `abbreviatedDriverName(name)`:
- 2+ words → `"${first-letter}.uppercase() ${last-word}"`. "Kandhan Madhavan" → "K Madhavan", "John Paul Smith" → "J Smith" (middle words dropped).
- Single word → unchanged. "driverzippi" → "driverzippi".
- Null / blank → null (caller falls back to "No driver assigned").

`populateDriverCard` now writes `abbreviatedDriverName(driver.name)` to both `driverName` (pinned) and `driverNameSheet` (in-sheet) TextViews. The avatar initials (`driverInitialsFor`) still derive from the raw full name so "Kandhan Madhavan" → "KM" inside the circle even when the visible name reads "K Madhavan".

**Reuses (no other functionality changed)**:
- `Constants.GOOGLEAPIKEY` unchanged.
- `populateDriverCard`, `applyMapMode`, `renderDailyCommute`, `fetchDriverFullDetails`, all FCM receivers — untouched.
- Existing leaves / no-show / rating / completed-shift logic unchanged.

---

## PART I — Navigation & Back-Button Safety

You previously hit a regression where pressing back from the ride flow screen took the driver to "Start Ride" again, letting them start a duplicate ride. This part is a checklist to prevent it recurring across every new screen this plan adds.

### I1. Driver app — once a ride is active, back must not lead to "Start Ride"

**`EnterpriseUserList` → `EnterpriseRideFlowActivity` transition** (after POST `/start-ride`):
- After successful start, `EnterpriseUserList.finish()` is called and `EnterpriceRideSchdule.finish()` is also dispatched via `Intent.FLAG_ACTIVITY_CLEAR_TOP`. The schedule screen is removed from the back stack so back from ride flow can't reach it.
- Alternative (already in code per the earlier fix): the navigation intent into `EnterpriseRideFlowActivity` uses `Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TASK` so the entire back stack is replaced. Either approach is fine — pick one and verify it's wired.

**`EnterpriseRideFlowActivity.onBackPressed`** — already has a confirmation dialog from the earlier fix. The dialog must stay and now also covers the new sub-states:
- If OTP entry is open (state `arrived`) → back closes OTP entry only, employee stays in `arrived`. No exit yet.
- If an employee is selected/en_route → back deselects them (returns to `pending`), no exit. For en_route the in-app `current_servicing_employee_id` should clear (separate API call to clear it server-side, see below).
- Only when no employee is selected → confirmation dialog "End shift now?" → exit only if user explicitly confirms. Otherwise the activity stays.
- Hardware back button AND toolbar back arrow both route through `onBackPressed()` — verify both.

**Selection-revert API call**: when driver "switches" from en_route back to nothing, the client calls `POST /api/enterprise/rides/clear-servicing` (or `/notify-en-route` with `employee_id=null` — pick one) so the server clears `current_servicing_employee_id` and invalidates the pending OTP row. Otherwise the user app would still think it's being serviced when it isn't.

**After ride completes** (`POST /ride-complete` success): driver app navigates to `Dashboard` with `FLAG_ACTIVITY_CLEAR_TOP | FLAG_ACTIVITY_NEW_TASK`. Back from Dashboard → standard Android home, not ride flow.

### I2. Driver app — Drop direction back-button cases
- At office, before lock-in (Boarded checkboxes visible): back → confirmation "Cancel boarding setup?" → if yes, the ride is paused (driver returns to schedule), boarding state preserved in DB so reopening picks up where they left off. NOT a hard "end shift."
- After lock-in (route in progress): same rules as Pickup — back inside the flow deselects, back without selection asks to end shift.

### I3. User app — already-completed ride cannot show stale tracking
- When `getDriverFullDetails` reports the ride has just completed (the `hadActiveRide → has_active_ride=false` transition): `CarDashboardActivity` silently resets `hadActiveRide=false` and lets the rating prompt (if any) mount. `EnterpriseRideStatusActivity` `finish()`es silently. No blocking dialog — the FCM push is the user-visible signal. See [[L27]].
- Apply Leave dialog dismissed → user stays on `CarDashboardActivity`. No back-stack pollution.
- Active leave banner with Undo → tapping Undo updates the banner in place; no new activity opened.

### I4. Admin live tracking — back from `/admin/enterprise/live-rides/{company_id}` returns to the company picker, not to "ride detail" or stale state.
- Modal ride detail closes via X / ESC → still on the live dashboard.

### I5. Universal regression test before each release
For each new dialog/banner/sub-screen this plan adds, manually verify:
1. Open it → press back → close cleanly, no crash, no duplicate state.
2. Open it → background the app → reopen → state preserved or recovered.
3. Open it → kill the app → reopen → either recover correctly or land in a safe state (Dashboard).

A short manual matrix:

| Screen / state | Back behavior expected | Implementation note |
|----|----|----|
| Apply Leave dialog | Dismiss, stay on Car Dashboard | Standard dialog dismiss |
| Apply Leave success | Dialog closes, banner appears | No nav change |
| Active leave banner + Undo | Undo updates banner in place | No nav change |
| Driver schedule screen | Back to Dashboard | Standard |
| EnterpriseUserList | Back to schedule screen, until Start Ride is tapped | After start, this screen is finish()'d |
| EnterpriseRideFlowActivity, no employee selected | Confirmation: "End shift?" | Dialog |
| EnterpriseRideFlowActivity, en_route to A | Back deselects A, clears server `current_servicing` | API call + UI revert |
| EnterpriseRideFlowActivity, arrived (OTP entry) | Back closes OTP entry, employee stays arrived (countdown continues) | UI only |
| EnterpriseRideFlowActivity, all picked, Complete Shift visible | Confirmation: "Leave without completing?" | Dialog |
| Drop office boarding (before lock-in) | Confirmation: "Pause and exit?" | Dialog; state in DB |
| Drop after lock-in | Same as Pickup | Confirmation |
| CarDashboardActivity, ride active | Back to Dashboard | Standard |
| EnterpriseRideStatusActivity | Back to Car Dashboard | Standard |
| Ride Completed dialog (user/driver) | OK only → Dashboard | finish() + CLEAR_TOP |
| Admin live picker | Back to admin home | Standard |
| Admin live dashboard | Back to picker | Standard |
| Ride detail modal | Close → live dashboard | Modal dismiss |

This table goes into the help page at `/admin/help/enterprise-flow` so QA can use it as a checklist.

---

## Verification

### Part A — Cancellation (plain-English tests)

1. Open "Apply Leave" → date pickers default to tomorrow, both Pickup + Drop boxes ticked.
2. Apply 5-day leave for both directions → 10 cancellation rows created (5 Pickup + 5 Drop).
3. **Asymmetric — Pickup only**: apply 5-day leave with ONLY Pickup ticked → 5 rows. Driver still does the evening Drop for this employee on those days.
4. **Asymmetric — Drop only**: 1 day with only Drop ticked → driver still picks up the employee in the morning, but skips them at evening drop-off.
5. Try a 60-day leave → rejected (max 30 days).
6. Try to cancel a ride that has already started → blocked.
7. **Pickup cutoff** (2 hours before `shift_start`): for `shift_start=08:00`, try cancel at 06:05 → blocked. At 05:55 → allowed.
8. **Drop has no fixed time cutoff**: at any time before the driver marks you boarded or no-show, the employee can cancel. Even 30 min past `shift_end` is fine if the driver hasn't acted yet.
9. **Race condition (Drop)**: driver taps "Mark No-Show" first → employee's Cancel button vanishes, banner turns red: "Your Drop for [date] was marked as no-show." Reverse case: employee cancels first → driver's no-show button for that employee vanishes.
10. **Undo only what you cancelled**: applied leave for Pickup only on May 21. Tap Undo → only the May 21 Pickup row deletes. (There was no Drop row to begin with — Drop runs normally.) If you'd cancelled both, Undo would delete both. The rule is simple: Undo removes whatever rows your earlier cancel created — nothing more, nothing less.
11. **All-cancel before boarding** (Drop direction only — Pickup's 2-hour cutoff makes this case impossible): driver at office, has NOT ticked anyone as Boarded yet. All 3 expected employees cancel from their apps → driver app shows "All cleared. End this shift?" → tap End Shift → ride completes. **Note**: once the driver ticks even one employee as Boarded, that employee can no longer cancel — rule from test #8 applies.
12. **Pickup no-show**: driver taps Arrived at employee's door, waits 6 min, employee never comes out → red "Mark No-Show" button appears → tap → employee gets push, driver continues to next stop.
13. **Drop no-show**: at office, driver ticks 2 of 3 employees as Boarded. At `shift_end + 12 min`, "Lock In & Start Drop" button appears → tap → 3rd employee auto-marked no-show, gets push, ride starts.

### Part B — Admin live tracking
11. `/admin/enterprise/live-rides` → company picker.
12. Click company → live dashboard for that company only.
13. Driver moves → marker moves in 5 sec.
14. Direction toggle filters Pickup/Drop.
15. Cancel/no-show during ride → toast + card updates.

### Part C — Night shift
16. Night-shift employee (21:00–06:00) applies leave for May 21 × Pickup only → May 21 evening Pickup excluded, May 22 morning Drop still runs.
17. Same employee, May 21 × Drop only → May 21 evening Pickup runs, May 22 morning Drop excluded.
18. Same employee, May 21 × both → both directions excluded.
19. Rule A: night-shift employee whose `shift_start=21:00`, `driver_pickup_at=20:30`. An active Pickup ride for May 21 has started at 20:30 → can't cancel May 21 Pickup (ride is ongoing), can still cancel May 21 Drop (the next-morning one).
20. Rule B for night shift (per-direction):
    - Night-shift Pickup: `shift_start=21:00`. Cutoff = 19:00 (2 hours before). Cancel at 18:55 → allowed. At 19:00 → **rejected**.
    - Night-shift Drop: `shift_end=06:00` next day. Cancel allowed any time until driver acts. At 06:11 next day → allowed (driver no-show option not yet open). At 06:15 — driver still hasn't acted → still allowed. Once driver acts → cancel blocked.
21. **Day-shift canonical example**: `shift_start=08:00`, `shift_end=18:00`.
    - Pickup cutoff = **06:00** — "cancel the morning ride before 6 AM (2 hours before office time)."
    - Drop: cancel any time before driver acts. Driver's no-show option opens at 18:12. Employee and driver race; first action wins. If driver marks no-show, employee sees red "marked as no-show" banner.
22. Admin dashboard groups May 21 21:00 Pickup + May 22 06:00 Drop under "May 21 shift".

### Part D — History
23. `/admin/enterprise/rides` → paginated. Filter by company × last month → only matching rows.
24. Click ride → detail with timeline (started, arrived A, picked A, …, completed).
25. Employee history page → all rides + all leaves + no-shows.
26. EXPLAIN ANALYZE on 100k-row test → uses new indexes.

### Part E — Admin terminology
27. Open Company create form → "Office Location" label + building icon + helper text visible.
28. Open Employee create form → "Home Location" + "Alternate Pickup/Drop Point (optional)" + shift hours night-shift note.
29. Open Assign Driver form → "Driver arrives at employee's home" / "Driver leaves office with this employee" labels + visual diagram.
30. Visit `/admin/help/enterprise-flow` → full reference page renders with diagrams, cutoffs, no-show rules, asymmetric leave explanation.
31. Sidebar shows "How it works" link under Enterprise section.

### Part F — "Driver on the way" notification (both directions)
32. **Pickup, select only**: driver taps Employee A → no push, no banner. Button shows "Start Ride". Other pending cards still tappable.
33. **Pickup, commit**: driver taps "Start Ride" → backend generates OTP (e.g., 1234), creates `enterprise_employee_rides` row, sends push to Employee A: "Driver on the way. OTP: 1234". Employee A's app shows green banner + OTP card with "1234". Button on driver app changes to "Arrived at Employee A's Location". **Other pending cards now non-tappable.**
33a. **Pickup, arrived**: driver taps "Arrived" → backend geo-fence check (1km), updates `arrived_at`. Pushes `Enterprise_Driver_Arrived` to ONLY this employee. Employee A's app: banner text changes to "Driver has arrived. Show your OTP", color shifts green → amber. OTP card stays visible (same OTP). Other employees' apps: unchanged. Driver app shows OTP entry + 6-min countdown anchored to `arrived_at`.
33b. **Switch invalidates old OTP**: driver Start-Ride'd A (OTP 1234 sent), then switches to C → A's row deleted from `enterprise_employee_rides`. If driver returns to A later and Start Rides again, a NEW OTP (e.g., 5678) is generated and pushed. Trying to verify the old 1234 → rejected (row gone).
34. **One-at-a-time enforcement**: while A is `en_route`, driver tries tapping Employee C → no response (greyed). Confirmed via toast or no-op.
35. **Switch with confirmation**: driver taps back arrow → confirmation "Switch from A? They will go back to pending." → Yes → A reverts to pending, other cards re-enable. No → A stays en_route.
36. **Other employees see only the map**: B and C never get a banner until driver taps "Start Ride" for them. They CAN open tracking screen and see driver's live location on the map (Firebase already wired).
37. **Push delivery in background**: lock phone → driver taps Start Ride → notification appears in tray.
38. **Drop, commit**: driver at office has locked in, taps Employee A → "Start Drop" button. Tap → Employee A gets push "Driver has started the drop route to your home". Button changes to "Mark Employee A as Dropped". Other boarded cards greyed.
39. **Drop, no Start Ride for office boarding stage**: before lock-in, the boarding checkboxes are direct — no per-employee push (employees were already notified at the bulk ride-start).
40. **Nearest-neighbor pill in Drop**: after lock-in, the 🎯 NEAREST pill shows on the closest boarded employee. After one is dropped, it advances.
40a. **ETA suppression + name privacy**: ride has 3 employees A, B, C. Driver taps Start Ride for A → A's app shows ETA + green banner. B's and C's apps show neutral grey banner: **"Driver is currently picking up another employee. You're stop X of Y."** — NO name, NO ETA card.
40b. **Switching servicing (still no name)**: driver advances from A (picked) to C → C's app now shows ETA + green/amber banner. B's banner updates but text stays generic: "Driver is currently picking up another employee." A's banner gone (already picked).
40c. **Servicing nobody (between picks)**: driver picked A but hasn't tapped Start Ride for anyone yet → all remaining employees see "Driver has not started the next pickup yet" — no ETA shown.
40d. **API audit**: hit `/getDriverFullDetails` as Employee B while driver is servicing A. Inspect response JSON — confirm it contains `currently_servicing.is_me=false`, `someone_else_active=true`, and NO field anywhere named "name" or containing Employee A's identifier.

### Part G — Nearest-neighbor in driver app
41. Open active ride flow with 3 employees → "🎯 NEAREST" pill on the first pending (Pickup) or first boarded (Drop) card.
42. Pick first → after status PICKED/DROPPED, NEAREST pill moves to the next.
43. Order matches between driver app, admin live-ride detail, and the API's nearest-neighbor sequence.

### Part H — Flow diagrams
44. Render `/admin/help/enterprise-flow` → all four flow diagrams (Pickup, Drop, User, Admin) visible and readable.
45. Diagrams stay in sync with the implementation (manual review checklist when code changes).

### Part K — Security & reliability
K1. **Audit log append-only**: log in as admin → attempt to UPDATE a row in `enterprise_admin_audit_log` (via tinker or SQL console) → query fails (DB permission denied) OR the model rejects with `LogicException`.
K2. **Rate limit on OTP verify**: rapid-fire 6 wrong OTP attempts → 6th returns 429. Row marked `locked`. Subsequent attempts blocked.
K3. **OTP brute force prevention**: try every OTP from 0000 to 9999 → row locks at attempt 6, brute force impossible without admin intervention.
K4. **Time zone math**: change device clock to 23:30 IST, apply leave for "tomorrow" → cancel row's `shift_date` is IST tomorrow (not UTC's date).
K5. **Idempotency**: post `/notify-en-route` with the same `Idempotency-Key` header twice → second call returns the cached response. Only one OTP row created.
K6. **Switch-OTP invalidation**: driver Start-Rides A → A gets OTP. Driver switches to C → A receives `Enterprise_OTP_Invalidated` push, OTP card hides on A's app, banner returns to neutral.
K7. **Location-spoof detection**: send `/update-user-location` jumping from Bangalore to Delhi within 30s → request rejected (or logged as anomaly).
K8. **Backward compat**: hit user-app endpoints with the old app version (no support for `currently_servicing`) → app doesn't crash; new fields silently ignored.
K9. **PII safety in push**: lock device, drive a Start Ride → notification on lock screen shows "Enterprise ride update — open the app", NOT the OTP itself.
K10. **One active session per user**: log in driver on phone A → log in same driver on phone B → phone A's API calls return 401 (token revoked).
K11. **Polling recovery on missed push**: kill FCM service on user app, simulate ride completing → user app polls `getDriverFullDetails`, detects `has_active_ride=false`, shows "Ride Completed" dialog anyway (push-independent recovery).

### Part J — Admin overrides (every uncontrollable case has a control)
J1. **Force-complete stuck ride**: simulate driver phone dead — ride stays in `started`. Admin opens ride detail → Force Complete → enters reason → ride flips to `completed`. Audit log row inserted. Driver + employees notified.
J2. **Override OTP verify**: stuck `arrived` state, employee says they boarded but driver couldn't enter OTP. Admin clicks "Verify on behalf" → employee marked picked. Push to employee confirms. Audit row inserted.
J3. **Resend OTP**: ride detail → Resend OTP. Employee receives same OTP again. Audit row inserted.
J4. **Cancel all for date**: closure page → pick company, date, both directions → Submit → cancel rows inserted for every assigned employee on that date. Driver dispatched for that day sees an empty route.
J5. **Override geo-fence**: driver 700m from office can't complete Pickup (over 500m limit). Admin toggles "Override geo-fence" → driver retries within 10 min → completion succeeds. Toggle auto-revokes. Audit row inserted with geo-fence values.
J6. **Reassign ride**: 3-employee Pickup with driver D1 mid-route (1 picked, 2 pending). D1 phone breaks. Admin reassigns to D2 → old ride ends with `status='reassigned'`, new ride created for D2 with the 2 remaining pending employees. Both drivers + all 3 employees notified.
J7. **Emergency stop**: live tracking → Emergency Stop → ride flips to `emergency_stopped`. Driver gets urgent push "STOP immediately." All employees get push "Ride cancelled due to emergency." Audit row inserted.
J8. **Clear stuck servicing**: simulate driver app crash with `current_servicing_employee_id` set. After 5+ min admin sees "Clear stuck servicing" button → tap → field NULLs. Audit row inserted.
J9. **Stuck-state alerts**: simulate a ride 30 min past expected duration → admin home page alerts widget lists it. Click → ride detail.
J10. **Audit log visibility**: open any ride detail with prior admin overrides → timeline shows "Admin [name] force-completed at [time], reason: [text]". Same on employee/driver history pages.
J11. **High-impact action requires notes**: try Force Complete with empty notes field → backend returns 422 "Notes are required for this action."
J12. **RBAC**: non-admin user hits any override endpoint → 403.

### Part I — Navigation regression tests (CRITICAL — this fixed your earlier bug)
46. **The original bug**: Start a ride in `EnterpriseUserList` → land in `EnterpriseRideFlowActivity` → press hardware back. **Expected**: confirmation dialog "End shift?" → tap Stay → still in ride flow. Tap Leave → goes to Dashboard, NOT to `EnterpriseUserList`. Confirm `EnterpriseUserList` is not in the back stack.
47. **Server-side defense-in-depth**: even with normal navigation, force the driver into `EnterpriseUserList` while their ride is active (e.g., via deep link, dev menu, or by manipulating the back stack). Tap "Start Ride" → POST `/start-ride` must return 409 (or similar) with message "You already have an active ride for this company / direction today." UI surfaces this clearly. This confirms the fix isn't only in the back-stack code — the server refuses to create a duplicate.
48. **OTP entry back**: in ride flow, tap Arrived → OTP entry shown → press back → OTP entry closes, employee status stays `arrived`, 6-min countdown continues. Pressing back again from the "Arrived" sub-state → deselects employee, no exit.
49. **Selection switch back**: tap Employee A → "Start Ride" → A is en_route. Press back → confirmation "Switch from A? They'll go back to pending" → Yes → A reverts to pending, server's `current_servicing_employee_id` cleared (verify via /getDriverFullDetails called as A or B).
50. **App kill + reopen**: in ride flow with A `arrived` (OTP entry visible), kill the app. Reopen → land back on `EnterpriseRideFlowActivity`, A still in `arrived`, countdown resumes from server `arrived_at`.
51. **User app, ride completes during view**: open `EnterpriseRideStatusActivity` mid-ride → driver completes → app shows "Ride Completed" dialog → tap OK → lands on `DashboardActivity` (not Car Dashboard, not stale tracking screen). Press back from Dashboard → goes to Android home, not back into the tracking screen.
52. **Admin nav**: open `/admin/enterprise/live-rides/5` → press browser back → land on company picker. Open ride detail modal → press ESC → modal closes, still on live dashboard. Press browser back → company picker.

---

## Bug Fixes (Post-Rebuild)

### BF1 — Pickup no-show blocked the Drop ride for the employee (2026-05-25)

**Symptom**: Admin marks employee A as no-show from the live-tracking panel during the Pickup ride. When the Drop ride starts later, employee A is missing from the driver's route.

**Root cause**: `getOngoingRide` (employee path) checked for any cancel entry today with no direction filter:
```php
EnterpriseEmployeeCancelRide::where('employee_id', $id)
    ->whereDate('date', today)   // ← missing direction filter
    ->exists();
```
A Pickup no-show cancel entry (`direction='Pickup'`) matched this query and caused `getOngoingRide` to return 404 even for the Drop ride.

**Fix** — `app/Http/Controllers/Api/EnterpriseRideController.php` (`getOngoingRide`):
- Moved the cancel check to **after** finding the active ride, so we know the direction first.
- Added `->where('direction', $ride->direction)` to scope the cancel check to the current ride's direction only.
- On a no-show 404, returns `cancelled_by` field and a direction-specific message:
  - Pickup: *"Your pickup ride for today was marked as no-show. You were not available at the pickup point."*
  - Drop: *"Your drop ride for today was marked as no-show. You did not board the vehicle at the office."*

### BF2 — No-show banner shown for both directions in user app (2026-05-25)

**Symptom**: Employee is marked no-show for Pickup. The user app shows the "no-show" state even when the Drop ride is active.

**Root cause**: Same as BF1 — `getOngoingRide` returned 404 for Drop too, so the app always showed the no-ride/no-show state.

**Fix** — user app files:
- `models/ResponseNormal.kt` — added `cancelledBy: String?` field to parse the `cancelled_by` key from the 404 error body.
- `activities/EnterpriseRideStatusActivity.kt` — `loadOngoingRide` now parses `cancelled_by`; if it is `admin_no_show` or `driver_no_show`, calls `showNoRideUI(isNoShow=true)`.
- `showNoRideUI` updated: when `isNoShow=true` shows a **red banner** ("Marked as No-Show", `#FFEBEE` background, `#B71C1C` text) with the direction-specific message from the backend. Regular "no active ride" state keeps the existing yellow styling.
- `res/layout/activity_enterprise_ride_status.xml` — added `android:id="@+id/tvNoRideTitle"` to the title TextView inside `layoutNoRide` so the activity can update its text/colour dynamically.

### BF3 — "Too many attempts" on Lock-In & Start Drop (2026-05-25)

**Symptom**: Driver boards employees and presses "Lock-In & Start Drop"; gets `429 Too Many Attempts` response after a small number of presses.

**Root cause**: `routes/api.php` had `throttle:10,60` on `POST /ride-mark-no-show-batch` — only 10 calls per 60 minutes. With Android retries or a few test presses this limit is hit quickly.

**Fix** — `routes/api.php`: removed `throttle:10,60` from the lock-in route. The `idempotent` middleware (already present) prevents true duplicate inserts, so the throttle added no safety value here.

```php
// Before
->middleware(['throttle:10,60', 'idempotent']);

// After
->middleware(['idempotent']);
```

---

## PART M — Multi-Trip Per Driver Per Day

(Letter changed from E to M to avoid collision with the existing PART E — Admin Panel Clarity.)

The "1 driver = 1 Pickup + 1 Drop per company per day" assumption baked into the early model is now lifted. A driver can hold N independent scheduled trips on the same `shift_date`, each potentially for a different company, in any direction order. Only one trip can be `started` at a time; the rest queue chronologically on the dashboard.

This part introduces a **scheduling lifecycle** (`scheduled → started → completed | emergency_stopped | reassigned | cancelled`), a **nightly auto-derive job**, **admin trip CRUD**, **CSV bulk import** of employees (re-uses the existing `/admin/enterprise/employees/upload-bulk` endpoint), and a **per-stop time solver** that backward-computes pickup times from each office's arrival deadline. A handful of UX iterations on the driver app are folded in below as **M8–M10**.

> **Read this first — PART M in one minute (plain English).**
>
> 1. **A driver can have many trips in a day** (different companies, pickups and drops, any order). The driver's dashboard lists them all; the admin can see/edit them on a new **Schedule** page. Only one trip runs at a time. *(M1–M6, M8)*
> 2. **The app tells the driver *when to leave*.** Working backward from "must reach the office by 9:15", it computes each pickup time and the driver's "leave home now" moment. *(M7, M10)*
> 3. **Those times use real Google road distances + live traffic**, not straight-line guesses — so "Madhapur → Amberpet" reads ~1 hr, not 29 min. The same engine powers the rider's live "min away / km / ETA" in the user app. *(M15, M16)*
> 4. **Privacy:** only the employee the driver is *currently picking up* sees a live countdown; the next rider sees their static time until it's their turn. *(M16)*
> 5. **Nothing is ever repeated.** Press back, or close and reopen the app, and the driver lands back on the exact same ride screen — no re-starting a pickup, no re-sending OTPs. The server remembers each employee's state. *(M17)*
> 6. **One source of truth.** The dashboard, the admin page, and the schedule screen all read the same rows, which self-heal against the assignment table on every load (e.g. adding a 2nd employee instantly fixes the shown times). *(M11, M18)*
>
> Sections M1–M14 are the build log; **M15–M18 are the most recent rounds** (real ETA, live rider ETA, resume-on-reopen, sync).

### M1. Data model addendum

Migration `2026_05_30_120000_add_scheduling_columns_to_enterprise_rides.php` adds these columns to `enterprise_rides` (all nullable / sensibly defaulted so existing rows stay valid; backfill marks them `source='legacy'`):

| Column | Type | Purpose |
|---|---|---|
| `scheduled_start_at` | dateTime | Sort key for the driver dashboard list; the solver-computed "leave now" moment for Pickup, the office departure for Drop |
| `scheduled_end_at` | dateTime | Solver-computed: office arrival for Pickup, last home for Drop |
| `auto_generated` | boolean | True when the nightly job created the row |
| `admin_edited` | boolean | Generator skip flag — once true, re-runs leave the row alone |
| `published_at` | timestamp | Set by the admin "Publish schedule" footer |
| `bundle_key` | string(64) | Dedup key `"{shift_date}|{company_id}|{direction}|{hour}:{15min_bucket}"`, indexed |
| `sequence` | tinyInt | Per-driver order within a shift_date |
| `source` | string(16) | `auto | admin | csv_import | legacy` |
| `parent_ride_id` | bigInt | Split/merge/reassign lineage; FK → self, nullOnDelete |
| `route_schedule` | json | Ordered list of stops with `{seq, employee_id, kind, scheduled_at, lat, lng, leg_distance_m, leg_minutes, warning?, manually_set?}` |
| `office_arrival_deadline` | dateTime | Pickup-only: `max(employee.shift_start) + grace_minutes` |
| `office_depart_at` | dateTime | Drop-only: `max(employee.shift_end) + per-employee handover` |

Composite index `idx_rides_driver_shift_sched` on `(driver_id, shift_date, scheduled_start_at)` so the dashboard list query is point-lookup fast.

Two new audit tables:
- `enterprise_schedule_runs` — one row per `enterprise:generate-schedule` invocation; tracks `generated_count`, `skipped_admin_edited_count`, errors, trigger (`cron|admin`).
- `enterprise_employee_imports` — one row per CSV upload; tracks success/failure/skip/update counts and stores per-row warnings JSON.

**Status lifecycle**: `status` stays a free-form `string` column; the new logical values are `scheduled` and `cancelled`. `started_at` is NULL while the row is `scheduled` and gets stamped when the row transitions to `started` (via the new B1 `ride_id` payload on `/start-ride`).

### M2. Schedule generation

Artisan command `enterprise:generate-schedule {date?} {--company=} {--dry-run} {--force}` lives at `app/Console/Commands/GenerateEnterpriseSchedule.php` and is scheduled from `app/Console/Kernel.php` at **02:30 server-local** for tomorrow.

Algorithm:
1. Pull every `enterprise_assigned_drivers` row with `status=true`.
2. Filter employees whose `working_days` (JSON) includes the target weekday — null/empty array = "works every day".
3. Group by `(driver_id, company_id)`; emit **one ride per direction** with the bundled `employee_ids[]`.
4. Skip the emit when an existing row matches `(driver, company, direction, shift_date)` AND `admin_edited=true`.
5. Skip when an existing `auto_generated` row matches and `--force` is not set.
6. Call `EnterpriseScheduleSolver::solve($ride)` to fill `route_schedule`, `scheduled_start_at`, `scheduled_end_at`, `office_arrival_deadline` / `office_depart_at`.
7. Write a `bundle_key` from the final `scheduled_start_at` bucket.

Idempotent. Per-run row in `enterprise_schedule_runs` records counts + errors. Manual admin trigger: `POST /admin/enterprise/schedule/generate?date=YYYY-MM-DD&force=1` calls the command synchronously and returns its output.

### M3. Driver dashboard contract

New endpoint `GET /api/enterprise/today-rides` (kept alongside `/ongoing-ride` for one release of compat):

```
{
  "status": true,
  "has_active_ride": false,
  "rides": [
    {
      "id": 1234,
      "direction": "Pickup",
      "status": "scheduled",
      "sequence": 0,
      "is_active": false,
      "can_start": true,
      "company": { "id": 5, "name": "Qualcomm", "latitude": "...", "longitude": "..." },
      "employee_count": 2,
      "destination_summary": "to Qualcomm",
      "scheduled_start_at": "2026-05-30T08:08:00+05:30",
      "scheduled_end_at":   "2026-05-30T09:15:00+05:30",
      "office_arrival_deadline": "2026-05-30T09:15:00+05:30",
      "office_depart_at": null,
      "route_schedule": [ {seq, employee_id, kind, scheduled_at, lat, lng, leg_distance_m, leg_minutes} ],
      "next_action": { "kind": "leave_in", "minutes_until": 23, "target_label": "Leave in 23 min" }
    }
  ],
  "ride": { /* compat shim: first active or first row */ }
}
```

`can_start` is true only when the ride is `scheduled` AND no other ride is `started` — same rule the server enforces in B1 as defence-in-depth.

`next_action.kind` resolves to `leave_now` once `scheduled_start_at − 2 min` passes; the dashboard card pulses and the bottom strip flips to coral "START NOW".

`/api/enterprise/driver/today-overview` gains a `today.rides[]` block with the same projection plus a `summary:{scheduled,started,completed,cancelled}` counts object. The legacy `today.pickup` / `today.drop` keys are kept for one release.

### M4. CSV bulk import — uses existing endpoint

CSV bulk import was already shipped before this rollout at `/admin/enterprise/employees/upload-bulk` (`EnterpriseEmployeeController::uploadBulk`). Plan E's CSV step is a no-op at the route + controller level — admins continue using the existing page.

Existing behaviour (recapped so the doc stands alone):
- `GET /admin/enterprise/employees/upload-bulk` — upload form.
- `POST /admin/enterprise/employees/upload-bulk` — straight insert into `enterprise_employees`; returns a flash message with counts.
- Required CSV columns: `employee_id`, `name`, `company_id`. Optional: `email`, `phone`, `latitude`, `longitude`, `address`, `shift_start`, `shift_end`, `working_days`.
- Dedupe: rejects rows whose `employee_id` already exists, or whose `email` collides with an existing employee. Invalid `company_id` skipped.

**Driver assignment stays separate** — admins use `/admin/enterprise/assign` after the import. The nightly `enterprise:generate-schedule` job picks up the newly-imported employees automatically once they've been attached to a driver.

(The earlier-planned preview-then-commit flow + `enterprise_employee_imports` audit table were dropped in favour of the existing endpoint. If preview semantics are needed later, the cleanest path is to grow `uploadBulk` rather than a parallel controller.)

### M5. Regression-fix interaction

The "single-started-ride-per-driver-per-shift_date" invariant from earlier rebuild parts still holds. `startRide` no longer rejects on `(company, direction)` collision; it now rejects only on "another ride is currently `started`" — preserving the spirit of the original rule while letting two completed rides of the same direction coexist for the same day.

Direction-scoped cancellation (`enterprise_employee_cancel_rides.direction`) continues to power per-trip leave: an employee on leave for `Pickup` only drops out of trips with `direction='Pickup'`; their `Drop` trip is untouched. B1's hydration step re-runs the solver after dropping leave-affected employees so the per-stop times surface the new reality at start time.

### M6. Backwards compatibility

- Legacy rides keep working: `source='legacy'`, all new columns NULL.
- B1's `/start-ride` accepts both the new `ride_id` payload (scheduled draft) and the legacy `company_id + direction` payload. The 409 is now scoped to "another ride is `started`" — completed/reassigned rows no longer block.
- `/ongoing-ride` still returns the first active row, plus a `ride` key on the new `/today-rides` response for one release.
- `today.pickup` / `today.drop` survive on `/driver/today-overview` alongside the new `today.rides[]`.
- Admin schedule + CSV pages are additive; nothing existing renders or behaves differently.

### M7. Per-stop arrival/departure scheduling

`app/Services/EnterpriseScheduleSolver.php` is the source of truth.

**Pickup (backward solve)**:
```
office_arrival_deadline = max(employee.shift_start)              ← on-time target
ordered = nearest-neighbour(driver_depot → homes → office)
office.scheduled_at = office_arrival_deadline
for k in N..1:
    home_k.scheduled_at = next_stop.scheduled_at − leg_minutes(home_k → next) − boarding_seconds
driver.scheduled_start_at = home_1.scheduled_at − depot_leg_minutes − safety_buffer
```

The deadline targets the employee's **actual shift_start** (no buffer added). `pickup_office_arrival_grace_minutes` used to push the deadline LATER ("acceptable lateness window"); we flipped its meaning. Today it survives only as the **display threshold** for the amber late chip on the ride-flow screen (see also `schedule_drift_amber_minutes`). Practical effect: the driver leaves ~15 min earlier than the old math suggested, so traffic + late-show delays still land the employee close to on-time instead of past their shift.

**Drop (forward solve)**:
```
office_depart_at = max(employee.shift_end) + (n × handover_seconds)
ordered = nearest-neighbour(office → home_1 → … → home_n)
for k in 1..N:
    home_k.scheduled_at = previous.scheduled_at + leg_minutes + handover
```

The driver depot falls back to the first home's coordinates when `enterprise_drivers.latitude/longitude` is NULL; that case emits `route_schedule[0].warning='no_depot'`. When backward-solving lands `scheduled_start_at` before midnight of `shift_date` (deadline too tight for the geography), the solver clamps to `shift_date 00:00` and emits `warning='deadline_too_tight'`.

> **Superseded:** the solver no longer drives off a flat `schedule_speed_meters_per_minute` straight-line guess. As of **M15** it asks Google for the real road duration/distance (traffic-aware) via the shared `DriveTimeEstimator`. The constant speed below is now only the **offline fallback** used when Google is unreachable or keyless.

Config tunables live in `config/enterprise.php`:
- `pickup_office_arrival_grace_minutes` (default 15)
- `drop_handover_time_seconds` (default 60)
- `boarding_time_seconds_per_employee` (default 90)
- `schedule_safety_buffer_minutes` (default 5 — the driver-leave "leave a bit early" pad; env-configurable as `ENTERPRISE_SCHEDULE_SAFETY_BUFFER_MINUTES`)
- `schedule_speed_meters_per_minute` (default 300 — **fallback only**, see M15)

Plus `schedule_drift_amber_minutes` (default 5) — the ride-flow screen paints a stop's time chip amber when the live ETA diverges from `scheduled_at` by more than this many minutes (informational only; no cascading recompute).

**Admin per-stop override**: `POST /admin/enterprise/schedule/{ride}/route` lets the admin edit each entry's `scheduled_at`. Every touched entry gets `manually_set: true`; the ride gets `admin_edited=true`. Future generator re-runs preserve those entries; the "Recompute from deadline" button (`/{ride}/resolve`) clears all manual flags and re-solves from scratch.

**B1 hydration**: when the driver taps a `scheduled` trip, the server re-runs the solver against the current `employee_ids` (after dropping fresh leaves) so the times the driver sees on the ride-flow screen reflect today's reality, not the nightly snapshot.

### M8. Driver dashboard — read-only multi-trip card stack

The `rvEnterpriseTrips` list on the main driver dashboard is purely informational now. Cards no longer respond to touch and the "next action" chip (`chipNextAction`) is permanently `GONE`. Rides are started from the **Schedule Rides** screen via swipe-to-confirm (M9), never by tapping a dashboard card. Prevents pocket-taps / glove brushes / notification yanks from accidentally promoting a scheduled draft to `started`.

**Visual hierarchy by status** (drives `itemView.alpha` + `tvScheduledTime` colour + inner background):

| `status` | Alpha | Time colour | Inner background | Pill copy |
|---|---|---|---|---|
| `started` | 1.0 | `#0FA896` (teal) | `#E4F9F4` (light teal) | `Now driving` |
| `scheduled` | 0.65 | `#15212B` (ink) | white | `Up next` |
| `completed` | 0.45 | ink | white | `Done` |
| `cancelled` / `emergency_stopped` | 0.45 | ink | white | `Cancelled` / `Stopped` |
| `reassigned` | 0.45 | ink | white | `Reassigned` |

Active trip stays sticky-top via `Dashboard.kt`'s sort (`compareByDescending { status == "started" }`).

**Plain-English copy** (`EnterpriseTripAdapter.kt`):
- Direction badge: `PICKUP` → `Pick up`, `DROP` → `Drop off`.
- Deadline hint: `Office by 09:15` → `Reach office by 9:15 AM`; `Depart 18:00` → `Leave office at 6:00 PM`.
- Destination summary: `to ZIPPI` → `Going to ZIPPI · 1 rider`; `From ZIPPI to 3 home(s)` → `Dropping 3 riders home`.
- Time format: 24-hour `HH:mm` → 12-hour `h:mm a` (e.g. `8:08 AM`).

**Time-parser robustness**: backend can emit `2026-05-30T08:08:00.000000Z`, `…+05:30`, `…ssXXX`, etc. The adapter walks 5 patterns and strips trailing `Z` → `+00:00` so a microsecond-or-Z-formatted string parses cleanly. Was previously dashing out to `—` whenever Laravel's cast added microseconds.

The legacy `enterpriseOverviewCard` (the "Morning Pickup / Evening Drop" two-pill card) is hidden — only `rvEnterpriseTrips` shows.

### M9. Schedule Rides screen — swipe-to-confirm + driver-friendly chrome

The legacy schedule activity (`EnterpriceRideSchdule.kt`) is the only path that starts a ride. Two safety changes:

1. **Swipe-to-confirm "Begin shift"** — the `Button btnDetails` (kept as a 0dp invisible binding-compat stub) is replaced by a swipe widget. The driver drags a white thumb across a teal track; releasing past 85% travel fires `beginShift(…)`, anywhere short of it springs the thumb back. The label fades as the thumb travels; on commit it flips to `Starting…`. Backed by `wireSwipeBeginShift` in `EnterpriceRideSchdule.kt`. Drawables: `bg_swipe_track.xml`, `bg_swipe_thumb.xml`, reuses `baseline_arrow_forward_24.xml`.

2. **Picker chrome hidden** — the company spinner, ride-shift spinner, the date row + Select link are all `visibility="gone"`. Driver sees only the ride details card + the swipe widget + Super Call for Support. The spinners stay in the view tree so their default first-item selection still auto-fires `getEnterpriseScheduleRoute(…)`. Caveat: drivers assigned to >1 company default to the first alphabetically — fine for the common case, future work for multi-company drivers.

### M10. On-time office arrival (deadline math flipped)

Earlier M7 read `office_arrival_deadline = max(shift_start) + grace_minutes` — i.e. the solver targeted the *outside* of the acceptable-lateness window. We've flipped it: deadline now equals `max(shift_start)` exactly. The driver leaves ~15 min earlier, so traffic / late-show delays still keep the employee close to on-time instead of slipping past their shift_start.

`pickup_office_arrival_grace_minutes` is no longer applied to the deadline. It survives only as the display-only "lateness amber" threshold used by the ride-flow screen (and is also re-used in spirit by `schedule_drift_amber_minutes`). Path: `EnterpriseScheduleSolver::solvePickup`, the line that previously had `addMinutes($grace)` is now `$officeArrivalDeadline = $latestShiftStart->copy();`.

To pull arrival *earlier* than shift_start (e.g. aim for 9:55 on a 10:00 shift), the cleanest path is a negative env override: `ENTERPRISE_PICKUP_OFFICE_ARRIVAL_GRACE_MINUTES=-5` and use the value as a `subMinutes` in the solver. Not wired today; would re-introduce the config dependency.

### M11. JIT generation in `today-rides`

`getTodayRides` (the driver-side dashboard endpoint) now self-heals when nothing exists in `enterprise_rides` for today. After the empty check it calls `jitGenerateForDriver($driverId, $today)`, which mirrors the artisan command's logic (read `enterprise_assigned_drivers` rows, filter by `working_days`, group by company, emit one Pickup + one Drop per group, run the solver, save). Idempotent — skips bundles that already have a row.

Effect: a single source of truth across the legacy `/driver-route` Schedule-Rides screen, the admin Schedule page, and the new dashboard. If the 02:30 cron didn't run (deploy mid-day, server downtime, manual delete), the driver's first poll of the day populates today's trips automatically.

Cost: ~50–200 ms once per driver per day. Subsequent polls return from the existing rows. Nightly cron is still the preferred path — JIT just plugs the gap.

### M12. One-time / ad-hoc trip form (HCL-style scheduling)

For occasional clients (admin gets a call, "schedule one pickup for these employees on this date") there's a dedicated form at `GET /admin/enterprise/schedule/one-time` that doesn't touch `enterprise_assigned_drivers` — the assignment table stays pure (recurring contracts only). Form fields: date, company, driver, direction (radio), office arrival time (Pickup) OR office departure time (Drop), employee multi-select.

On submit it POSTs to the existing `/admin/enterprise/schedule/create` endpoint, which now accepts two new optional fields: `office_arrival_deadline` and `office_depart_at`. The solver was extended to honour these as anchors when set — overriding the otherwise-derived `max(shift_start)` / `max(shift_end)` calculation. Lets the admin set per-trip anchors even when the chosen employees have NULL or "wrong" shift times for this one-off booking.

Source of truth on the form-loaded data: `EnterpriseScheduleController::oneTimeForm` (page) + `employeesForCompany` (employee dropdown JSON). View: `resources/views/admin/enterprise/schedule/one-time.blade.php`. Reachable via the "+ Schedule one-time trip" button on the main Schedule page.

### M13. Permanent delete on schedule cards

`DELETE /admin/enterprise/schedule/{ride}` (Controller method `destroyRide`) hard-deletes a ride row plus any `enterprise_employee_rides` rows that point at it. Wrapped in a transaction; snapshots the row into `enterprise_admin_audit_log` (action `schedule_destroy`) before the delete so the trail survives. Refuses to delete when `status='started'` — must complete or cancel an in-flight trip first.

UI: red **Delete** button on every trip card in the Schedule page (`schedule/index.blade.php`), double-confirmation prompt before the request fires.

### M14. Sidebar nav + the "uses existing" CSV importer

- Admin sidebar (`components/admin/sidebar.blade.php`): new `Schedule` link between Assignments and Cancelled Rides, hits `route('admin.enterprise.schedule.index')`.
- CSV bulk import: redundant `EnterpriseEmployeeImportController` + `import.blade.php` + the `enterprise_employee_imports` migration were deleted. The pre-existing `/admin/enterprise/employees/upload-bulk` (`EnterpriseEmployeeController::uploadBulk`) is the source of truth. Admins import employees there, then attach drivers via the existing `/admin/enterprise/assign` page. Nightly generator picks them up the next morning.

### M15. Real road-distance & travel-time (Google Directions, not straight lines)

**Problem it fixes:** the early solver multiplied the crow-flies distance by a flat speed (30 km/h). For "Madhapur → Amberpet" that produced ~29 min when Google Maps says ~1 hr — so the driver was told to leave far too late and the employee missed their shift.

**What it does now:** a shared service `app/Services/DriveTimeEstimator.php` answers one question — "how long, and how far, to drive A → B?" — using the **real road network**:

- **Strategy 1 (default): Google Directions API**, `mode=driving`, traffic-aware. For a *future* departure it sends `departure_time` + `traffic_model` (`best_guess`); for a *live* "right now" ETA it sends `departure_time=now`. It reads `duration_in_traffic` when present, else `duration`. This is the same number the Maps app shows.
- **Strategy 2 (fallback):** straight-line haversine × `schedule_road_circuity_factor` (1.5) ÷ `schedule_speed_meters_per_minute` (300 m/min). Used only when Google is off, keyless, or the call fails — deliberately conservative so the driver is told to leave *early* rather than late.

**Why Directions and not Distance Matrix:** the project's Google key has the **Directions** API enabled (Distance Matrix returned `REQUEST_DENIED`). Same data, different endpoint.

**Caching:** results are cached (file cache) keyed by `traffic_model + rounded coords + 15-min departure bucket`, TTL `schedule_eta_cache_seconds` (900s). So repeated polls/solves inside a 15-min window reuse one lookup instead of re-billing Google. The traffic model is part of the key, so changing it auto-invalidates.

**Traffic model gotcha (already fixed):** `pessimistic` roughly *doubled* every leg, producing absurd 2-hours-early start times. Default is now `best_guess`. After changing `ENTERPRISE_SCHEDULE_TRAFFIC_MODEL`, run `php artisan cache:clear` to drop stale cached legs.

Consumed by **both** the solver (`EnterpriseScheduleSolver` → "leave at" times) and the user-app live ETA (M16) — so the pre-computed schedule and the live "min away" use the same engine and stay in lock-step.

New `config/enterprise.php` keys: `schedule_use_google_eta` (true), `schedule_traffic_model` (best_guess), `schedule_eta_cache_seconds` (900), `schedule_road_circuity_factor` (1.5), `google_maps_key` (`env('GOOGLE_MAPS_KEY')`).

### M16. User-app live ETA — accurate, and only for the employee being served

**What the rider sees:** on the user app, a being-serviced employee sees three live figures — **`~min away`**, **`km`**, and **ETA clock time** — recomputed from the driver's live GPS each poll. These now use the M15 real-road engine, so they match Google Maps instead of the old straight-line estimate.

**How it's computed** (`EnterpriseController::getDriverFullDetails` → `computeLiveRouteEtas`):
- `min_away_minutes` / `distance_km` = **direct** driver → this employee (what "how far is my cab" means).
- `eta_destination_minutes` = the **full remaining chain** driver → … → office (Pickup) so the rider sees when they'll actually reach work, accounting for co-riders picked up before them.

**Privacy gate — the important rule:** the live `min_away` / `km` is shown **only to the one employee the driver is actively servicing** (`current_servicing_employee_id`), or to an in-vehicle pickup rider. A second employee further down the route does **not** see a live "min away" until the driver actually starts their leg. Implemented as `$showLiveEta = $currentlyServicing['is_me'] || $inVehiclePickup`; when false the live fields are withheld and the app falls back to the static scheduled time. Stops a waiting rider from watching a countdown that isn't really "to them" yet.

Multi-employee correctness: for a 2-rider pickup, employee A (being serviced) sees a live countdown; employee B sees their static scheduled pickup time until the driver finishes A and taps "Start Ride" for B — at which point B becomes the serviced employee and their live ETA switches on.

### M17. Resume an in-progress ride after back-press or app restart

**Problem it fixes:** a driver mid-pickup (e.g. Praneeth "en route", button = "Arrived at Praneeth's Location") who pressed **back** or **killed/reopened** the app came back to a screen where everyone was reset to `pending` — forcing them to re-tap "Start Ride" and re-notify the employee. Actions were being repeated.

**Root cause:** the per-employee progress *was* saved server-side (the `en_route` `enterprise_employee_rides` row + `current_servicing_employee_id` on the ride), but `GET /api/enterprise/ongoing-ride` never sent it back, so the app had nothing to restore from.

**Fix — server is the single source of truth.** Three coordinated changes:

1. **Backend (`getOngoingRide`)** now returns an `employee_statuses` map — `{ "<employee_id>": "pending|en_route|arrived|completed|no_show|cancelled" }` — derived from the authoritative rows (employee_ride rows + servicing flag + cancel/no-show rows). For Drop (which creates no row at en-route) the `current_servicing_employee_id` flag is the en_route signal.
2. **Driver dashboard button** no longer trusts cached state. Tapping the enterprise section calls `checkenterpriseongoiningride(fromButton = true)`, which asks the server: active `started` ride exists → resume the ride-flow screen with those statuses; none → open the begin-shift screen. Never a dead end (404/offline → begin-shift).
3. **Ride-flow screen (`EnterpriseRideFlowActivity`)** restores the selected employee for **`en_route` as well as `arrived`** (previously only `arrived`), so the correct action button — "Arrived at …'s Location" (en route) or the OTP entry (arrived) — reappears exactly where the driver left off.

**Cold-start vs back-press:** auto-navigation into the active ride fires **once per app launch** (flag `hasAutoNavigatedToActiveRide`) — so restarting the app drops the driver back into their ride, but pressing *back* out of it doesn't immediately bounce them back in. Complements the back-button safety already in **PART I (I1)**.

**Net effect:** close/reopen/back at any point and the driver lands on the exact same ride-flow state — no repeated "Start Ride", no re-sent OTPs, no lost progress.

### M18. One source of truth — scheduled rides stay in sync with assignments

A driver's dashboard, the admin Schedule page, and the legacy Schedule-Rides screen all read from the **same** `enterprise_rides` rows. To keep those rows honest against the `enterprise_assigned_drivers` source-of-truth, `getTodayRides` runs two reconciliation passes on each load:

- **`reconcileScheduledRides`** — for any scheduled, non-admin-edited ride, re-sync its `employee_ids` against the current assignment + today's leaves, and re-solve times when they changed. *Fixes the "added employee 2 but it still shows employee-1-only times" bug* — the ride's cached employee list was stale; now it self-heals on the next poll. Returns `{created, changed}`.
- **`sweepOrphanAutoRides`** — remove auto-generated rides whose underlying assignment no longer exists (prevents ghost trips).

**Performance split:** *changed* rides are re-solved **synchronously** (so the corrected times show on this very load), while brand-new *created* rides are solved via `dispatch(...)->afterResponse()` (so the first load stays fast and Google calls happen after the response is flushed).

**Duplicate-start prevention:** the old "Begin Shift" path created a brand-new `started` ride even when a `scheduled` one already existed — producing a "Now driving (no time)" card next to an "Up next" card for the same trip. `startRide` now **promotes** the matching scheduled ride (`scheduled → started`, stamps `started_at`) instead of inserting a duplicate. Combined with M17, the dashboard shows exactly one card per real trip.

---

## Files Modified — consolidated

**Backend** (`staging/`):
- `app/Http/Controllers/Api/EnterpriseRideController.php` — `employeeCancelRide` (rules, range, directions[], shift_date), `undoCancelRide` (new), `markNoShow` (Pickup, new), `markNoShowBatch` (Drop, new), `notifyEnRoute` (new), `startRide`/`completeRide` (shift_date); **BF1/BF2**: direction-scoped cancel check in `getOngoingRide`.
- `app/Http/Controllers/Api/EnterpriseController.php` — `upcoming_leaves` in `getDriverFullDetails`, shift_date in `getDriverRoute`.
- `app/Http/Controllers/Admin/EnterpriseRideController.php` — live picker + dashboard + JSON + history pages + help page.
- `app/Notifications/` — `EnterpriseEmployeeUncancelledNotification.php`, `EnterpriseEmployeeNoShowNotification.php`, `EnterpriseDriverEnRouteNotification.php` — all new.
- `app/Models/EnterpriseRide.php`, `EnterpriseEmployeeCancelRide.php` — shift_date cast.
- `config/enterprise.php` — `pickup_cutoff_minutes` (120), `drop_noshow_buffer_minutes` (12).
- `database/migrations/` — shift_date migration, history-index migration, `current_servicing_employee_id` column on `enterprise_rides`.
- `routes/api.php` — 3 new API routes; **BF3**: removed `throttle:10,60` from `ride-mark-no-show-batch`.
- `routes/web.php` — admin live + history + help routes.
- `resources/views/admin/enterprise/` — Blade files (picker, live dashboard, ride index/detail, employee/driver/company history, help).
- `resources/views/admin/layouts/sidebar.blade.php` — sidebar entries.
- `public/assets/admin/js/enterprise-live-rides.js` + CSS — new.
- `public/assets/admin/img/pickup-drop-diagram.svg` — new asset.

**User app** (`Zippi-User-Android-main/`):
- `activities/CarDashboardActivity.kt`, `activities/EnterpriseRideStatusActivity.kt` (**BF2**: no-show banner), `res/layout/activity_car_dashboard.xml`, `res/layout/activity_enterprise_ride_status.xml` (**BF2**: `tvNoRideTitle` ID), `res/layout/dialog_cancel_ride.xml`, `EnterpriseModel/DriverFullDetailsResponse.kt`, `retrofit/ApiInterface.kt`, `firebase/MyFirebaseMessagingService.kt` (handle `Enterprise_Driver_EnRoute`, `Enterprise_Employee_NoShow`, `Enterprise_Employee_Uncancelled`); `models/ResponseNormal.kt` (**BF2**: `cancelledBy` field).

**Driver app** (`Zippi-Driver-Android-main/`):
- `activities/EnterpriseRideFlowActivity.kt` (selection-push fire-and-forget, no-show timer, Drop boarded checkboxes + Lock-In button, NEAREST pill via adapter), `activities/EnterpriceRideSchdule.kt`, `adapters/EnterpriseEmployeeRideAdapter.kt` (NEAREST pill + boarded/no_show states), `retrofit/ApiInterface.kt`, `firebase/MyFirebaseMessagingService.java`, `res/layout/activity_enterprise_ride_flow.xml`.

### PART M additions (multi-trip + scheduling + real ETA + resume)

**Backend** (`staging/`):
- `app/Services/EnterpriseScheduleSolver.php` *(new)* — backward/forward per-stop time solver (M7), now drive-time via `DriveTimeEstimator`.
- `app/Services/DriveTimeEstimator.php` *(new)* — shared Google-Directions road-time/-distance engine + offline fallback + cache (M15).
- `app/Http/Controllers/Api/EnterpriseRideController.php` — `startRide` promotes scheduled→started (no duplicate); `getTodayRides` (reconcile + orphan-sweep, JIT); `getOngoingRide` now returns `employee_statuses` (M17); `cancelScheduledRide`.
- `app/Http/Controllers/Api/EnterpriseController.php` — `computeLiveRouteEtas` + privacy gate (M16); `today.rides[]` in `todayOverview`.
- `app/Http/Controllers/Admin/EnterpriseScheduleController.php` *(new)* — schedule CRUD, split/merge/publish, per-stop override, one-time form (M2/M7/M12).
- `app/Http/Controllers/Admin/EnterpriseRideHistoryController.php` — scheduled rides sort to top; `destroyRide`.
- `app/Console/Commands/GenerateEnterpriseSchedule.php` *(new, 02:30 nightly)* + `RefreshImminentSchedules.php` *(new, every minute)*; registered in `app/Console/Kernel.php`.
- `config/enterprise.php` — M7 + M15 tunables.
- `database/migrations/2026_05_30_120000_add_scheduling_columns_to_enterprise_rides.php`, `…_120100_create_enterprise_schedule_runs.php` *(new)*.
- `routes/api.php` (`/today-rides`, `/rides/{ride}/cancel-scheduled`), `routes/admin.php` (`schedule.*`, one-time, company-employees, DELETE).
- `resources/views/admin/enterprise/schedule/index.blade.php` + `one-time.blade.php` *(new)*; `enterprise_assign/create+edit.blade.php` (auto-derived times); `components/admin/sidebar.blade.php` (Schedule link).

**Driver app** (`Zippi-Driver-Android-main/`):
- `activities/Dashboard.kt` — `fetchTodayRides` + 10s poll; enterprise button → `checkenterpriseongoiningride(fromButton)` resume logic + once-per-launch auto-nav (M17).
- `activities/EnterpriseRideFlowActivity.kt` — restore `en_route` selection on reopen (M17); office-navigation; `ride_id`/`ride_source` promote path.
- `activities/EnterpriceRideSchdule.kt` — swipe-to-begin, pickers hidden, route fetched on create (M9).
- `adapters/EnterpriseTripAdapter.kt` *(new)* — sectioned multi-trip card stack (CURRENT RIDE / UP NEXT / DONE TODAY) (M8).
- `models/EnterpriseTripDto.kt` *(new)*, `EnterPriseModels/EnterpriseRideDetailsResponse.kt` (`employee_statuses`), `retrofit/ApiInterface.kt` (`getTodayRides`, start-by-id).
- `res/layout/item_enterprise_trip*.xml` + trip drawables *(new)*.

---

## PART N — One-off (ad-hoc) rider instant rides on the One-time page

### N1. Problem

Admins need to run **instant rides for one-off people who don't travel regularly** (a company calls for a single Pickup/Drop today or on a future date). The One-time Trip page (`/admin/enterprise/schedule/one-time`) only lets you pick **existing recurring employees** (it filters out `is_adhoc` riders), so there's nowhere to type a brand-new rider. The legacy `/schedule/instant` CSV flow captures `name, phone, email, latitude, longitude` — not the wanted field set (`name, company, pickup address, drop address, phone, shift timings`), and its button was removed.

### N2. Goal

On the **One-time Trip page**, let the admin add **new one-off riders** — by **manual form (Google address autocomplete)** and/or **CSV** — with **pickup + drop address, phone, shift times**, and create the instant ride in **one step**, alongside any ticked existing-roster employees. Riders are saved as `is_adhoc = true` so the **recurring-rides code stays untouched** (every recurring view/generator already excludes `is_adhoc`).

### N3. Location handling (no solver change)

The solver uses `employee.latitude/longitude` as the rider's single stop for the ride's one direction. Per created rider:
- `latitude/longitude` = **pickup** coords when `direction = Pickup`, **drop** coords when `direction = Drop` (what the solver consumes).
- `address` = pickup address; `pickup_drop_address` = drop address; `pickup_drop_latitude/longitude` = drop coords (record).
- `shift_start`, `shift_end`, `phone`, `name`, `company_id`, `is_adhoc = true`.

### N4. Frontend — `resources/views/admin/enterprise/schedule/one-time.blade.php`

- New **"4 · One-off riders (not in the roster)"** card: a manual rows table (Name, Phone, Pickup address [Google autocomplete → hidden pickup lat/lng], Drop address [autocomplete → hidden drop lat/lng], Shift start, Shift end, add/remove row), plus a **CSV upload** + template (`name, phone, pickup_address, drop_address, shift_start, shift_end`).
- Reuse the Google Places pattern from `enterprise_employees/create.blade.php:156-179` (`maps.google.com/maps/api/js?key={{ config('site.google_maps_key') }}&libraries=places`, `new google.maps.places.Autocomplete(input, { componentRestrictions: { country: "in" } })`, `place_changed` → write lat/lng into the row's hidden fields). Wire it to each newly-added row.
- `#otSubmit` posts a `FormData` (multipart): `company_id, driver_id, direction, shift_date`, the already-computed anchor (`office_arrival_deadline`/`office_depart_at`), `employee_ids[]` (existing, now optional), `new_riders` (JSON string of manual rows), `csv_file` (optional). Client-validate that at least one of {existing, manual, CSV} is present.

### N5. Backend — extend `EnterpriseScheduleController::createRide()` (line 329)

Additive, backward-compatible:
- `employee_ids` → `nullable`; accept `new_riders` (nullable array) and `csv_file` (nullable mimes:csv,txt). Require the union of existing ids + parsed riders to be non-empty.
- For each manual rider (coords from autocomplete) and CSV row (geocode addresses), create an `is_adhoc = true` `EnterpriseEmployee` using N3's direction-aware mapping; reuse the match-or-create idea from `createInstantRide()` (lines 543-569) — CSV has no email, so match on `company_id + phone` when phone present, else create.
- Merge created ids with `employee_ids`; create the `EnterpriseRide` + run the solver exactly as `createRide` does (`source='instant'` when any rider created, else `'admin'`). Insert `enterprise_instant_riders` rows (reuse `createInstantRide` lines 616-624). Wrap in `DB::transaction`.
- Private helper `geocodeAddress(string): ?array` via `Http::get('https://maps.googleapis.com/maps/api/geocode/json', ['address'=>..., 'key'=>config('site.google_maps_key'), 'components'=>'country:in'])`. CSV rows failing to geocode are skipped + counted; manual rows never geocode.
- No route change — keeps posting to `admin.enterprise.schedule.create`. `createInstantRide()` / `/schedule/instant` left in place (superseded).

### N6. Untouched (regression-safe)

`EnterpriseEmployeeController` (regular Add Employee), `GenerateEnterpriseSchedule`, `EnterpriseScheduleSolver`, `EnterpriseAssignedDriver`, and every `where('is_adhoc', false)->orWhereNull('is_adhoc')` filter stay as-is — new riders are `is_adhoc=true`, so they never enter the recurring roster, the assignments picker, or the nightly generator.

### N7. Files (Part N)

- `resources/views/admin/enterprise/schedule/one-time.blade.php` — one-off riders card + autocomplete + FormData submit.
- `app/Http/Controllers/Admin/EnterpriseScheduleController.php` — `createRide()` extended + `geocodeAddress()` helper.

---

### N8. Post-N refinements (shipped after the original PART N spec)

These supersede the matching N3/N5 details above where they conflict.

- **Ad-hoc rider stop = HOME, not office (bug fix).** N3 originally stored the office in `pickup_drop_*`, but the route builder *prefers* `pickup_drop_*` for the stop — so the rider's stop wrongly showed the company address. Fixed in `makeAdhocEmployee()`: store the **direction-relevant home** in `latitude/longitude/address` (Pickup → pickup side, Drop → drop side) and leave `pickup_drop_*` **null**. The office leg comes from the company coords.
- **Office address auto-fill.** On the one-time form, the office side auto-fills from the selected company (Pickup → Drop = company address; Drop → Pickup = company address); the admin only types the rider's home side.
- **"Time anchor" card removed.** Admins no longer enter office arrival/departure. The solver derives it from the riders' shift times (Pickup → office arrival = latest `shift_start`; Drop → office departure = latest `shift_end`). The form requires the relevant shift time per rider; the driver leaves earlier by `ENTERPRISE_SCHEDULE_SAFETY_BUFFER_MINUTES`.
- **Max 4 riders** per instant ride (client cap + server validation).
- **Schedule index default date = today** (was tomorrow), so a freshly created instant ride shows immediately.

---

## PART O — Daily Roster + route-based driver assignment & one-day overrides

> **One minute, plain English.** Employees get a route label **RT No** (e.g. `RT-03`). A driver is assigned to a **route**, not one-by-one — set RT-01's driver and it applies to all employees on RT-01. A **Daily Roster** page shows, per date, who rides and with which driver, grouped by RT No. Two tiers of driver: a **default** (every day) and a **one-day stand-in override** for a single date+direction that auto-reverts the next day. **Login (pickup) and Logout (drop) drivers are independent.**

### O1. Why
Ops think in routes, not individual employees. They also need a daily "who's riding today" view and the ability to swap a driver for just one day/direction (e.g. the regular driver is off for the evening drop only) without permanently changing the route.

### O2. Schema (migrations)
- `2026_06_15_120000_add_rt_no_to_enterprise_employees_table` — `rt_no` (string, nullable) + index `(company_id, rt_no)`. Optional; existing create flow unchanged.
- `2026_06_15_120100_add_drop_driver_id_to_enterprise_assigned_drivers_table` — `drop_driver_id` (nullable). `driver_id` = default **Pickup** driver, `drop_driver_id` = default **Drop** driver (falls back to `driver_id` when null). NULL on existing rows → identical to old single-driver behavior (zero-change-on-deploy).
- `2026_06_15_120200_create_enterprise_route_driver_overrides_table` — `(shift_date, company_id, rt_no, direction, driver_id)`, unique on `(shift_date, company_id, rt_no, direction)`. Model `EnterpriseRouteDriverOverride` — **dates NOT cast** (mirrors `EnterpriseEmployeeCancelRide`).

### O3. The shared resolver — `app/Services/EnterpriseRouteDriverResolver.php`
Single source of truth used by BOTH the admin roster and the driver-app pipeline so they never drift.
- `forDate($dateStr)` — bulk-preloads the date's overrides (no N+1).
- `effectiveDriverId($emp, $assignment, $direction)` = `override(date, company, rt_no, direction)` **??** (`Pickup` → `assignment.driver_id`) / (`Drop` → `assignment.drop_driver_id ?? assignment.driver_id`). The `?? driver_id` fallback lives **only here**.
- `assignmentsForDriver($driverId, $direction, $companyId=null)` — active assignments whose **effective** driver for (date, direction) == this driver (pulls in override-targeted employees, drops override-away ones).

### O4. Pipeline integration (regression-tight)
- **`EnterpriseController::getDriverRoute()`** — replaced the `where('driver_id', X)` assignment load with `EnterpriseRouteDriverResolver::forDate($dateStr)->assignmentsForDriver($driverId, $request->direction, $companyId)`. If the request sends no `direction` (legacy), it **unions** Pickup+Drop so nothing disappears. Everything downstream (grouping, working-day filter, nearest-neighbour, `buildInstantRoutes`) is unchanged.
- **`EnterpriseRideController::reconcileScheduledRides()`** — restructured to loop **direction-outermost**, using `assignmentsForDriver($driverId, $direction)` for membership; the inner find-or-create / employee-set-sync block is byte-identical.
- **`EnterpriseRideController::sweepOrphanAutoRides()`** — valid set is now `(company_id | direction)` from effective membership across both directions; auto+scheduled rides not in it are deleted. This clears a default driver's now-orphaned ride when a one-day override moved that direction to a stand-in (and reverts automatically next day).
- Instant/admin rides (`buildInstantRoutes`, `source in instant|admin`) are never touched.

### O5. Admin — `app/Http/Controllers/Admin/EnterpriseDailyRosterController.php`
- `index()` — date (default today) + optional company filter. Lists **only employees with an active assignment** (deleting an assignment removes them), filtered to those who work the weekday and aren't on leave that day (direction-aware; one-direction leave blanks only that direction). Groups by `(company, rt_no)`. Columns: Date, RT No, Employee, Location(`address`), Mobile(`phone`), Week Offs (inverse of `working_days`, e.g. "Sat & Sun"), **Login = `shift_start`**, Login Driver (effective), **Logout = `shift_end`**, Logout Driver (effective). Driver-name map includes ALL drivers so stand-ins resolve.
- `saveRoute()` — **one Save per route**: sets default Login/Drop drivers (write-through `updateOrCreate` to every in-route employee's assignment), and sets/clears the day's Pickup/Drop overrides (blank = revert to default). Lazy — the next driver poll's sweep+reconcile materializes it.
- `export()` — CSV download honoring the date+company filter (same columns).
- View `resources/views/admin/enterprise/roster/index.blade.php` — one card per RT-No group: "Default driver (every day)" Login+Logout selects and "Today only · stand-in" Login+Logout selects, **single Save**. Toolbar has Date, Company, **Export CSV**.
- Routes `enterprise/roster`: `index` (GET), `export` (GET), `save` (POST). Sidebar item **"Daily Roster"** after Assignments.

### O6. Assignments page changes (driver↔route entry point)
- **RT No on assign create/edit** — a numeric input with fixed `RT-` prefix; entering `3` writes `RT-03` to the employee (`applyRtNo()`, zero-padded to 2). Leading-zero input normalized before validation. (RT No was **removed** from the employee create/edit forms — it lives on the assignment now; bulk employee CSV still accepts an optional `rt_no`.)
- **Multi-select employees** — assign one driver + route to many employees at once; `store()` loops `updateOrCreate(['employee_id'=>id], [...])` (no duplicates) and stamps RT No on each. A **confirm list** shows each selected employee's shift + logout(home) address, with an amber **"different shift timings"** warning when selected shifts don't match.
- **Index grouped by route** — `EnterpriseAssignedDriverController::index()` returns assignments grouped `(company, rt_no)`; the view renders a card per route. The old "Shift" column (always `N/A` since times are solver-derived) was removed.
- **`getEmployeesByCompany` / `getEmployees` exclude `is_adhoc`** — so one-off instant-ride riders don't clutter the assign dropdown (they aren't on the Employees page either).

### O7. Files (Part O)
- New: `app/Services/EnterpriseRouteDriverResolver.php`, `app/Models/EnterpriseRouteDriverOverride.php`, `app/Http/Controllers/Admin/EnterpriseDailyRosterController.php`, `resources/views/admin/enterprise/roster/index.blade.php`, the 3 migrations in O2.
- Modified: `app/Models/EnterpriseAssignedDriver.php` (`dropDriver()`), `app/Http/Controllers/Api/EnterpriseController.php` (getDriverRoute), `app/Http/Controllers/Api/EnterpriseRideController.php` (reconcile + sweep), `app/Http/Controllers/Admin/EnterpriseAssignedDriverController.php` (RT No, multi-select, grouped index, adhoc filter), `app/Http/Controllers/Admin/EnterpriseEmployeeController.php` (rt_no validation + bulk), assign `create.blade.php`/`edit.blade.php`/`index.blade.php`, employee `create.blade.php`/`edit.blade.php`, `routes/admin.php`, `components/admin/sidebar.blade.php`.

---

## PART P — OTP, SMS, email & auth hardening (READ THIS FOR THE iOS APP)

### P1. Random OTPs everywhere (no more static `1234`)
- Enterprise **driver login** (mobile) and **employee login** (email) OTPs → `random_int(1000, 9999)`.
- Enterprise **ride start PINs** (both generation sites) → `random_int(1000, 9999)`.
- Rider/user app login (`Api/UserController`) already used `random_int`.

### P2. Enterprise SMS fixes
- **Template id** — `sendOtpViaSms` passes `config('services.edumarc.alert_template_id') ?: '1707178064151587006'` (fallback so it's never empty; was sending an empty templateId → "template id is Empty", undelivered).
- **Number format bug** — removed `'91' . ltrim($mobile, '91')`. `ltrim` treats `'91'` as a character set and stripped leading 9/1 digits → malformed number the gateway "accepted" but never delivered. Now sends the **raw number**, like the working rider flow.

### P3. OTP verify rate limit (enterprise login, driver + employee)
- Max **5** wrong attempts, then **1-minute lockout**, using the `otps.attempts` / `otps.locked_until` columns (migration `2026_06_15_130000_add_attempts_and_locked_until_to_otps_table`, guarded with `hasColumn`).
- Wrong code → `400 {status:false, message:"Invalid OTP", attempts_remaining:N}`. 5th wrong / locked → `429 {status:false, message:"Too many failed attempts. Try again after 1 minute."}`. Success or a fresh OTP resets the counter.

### P4. OTP send throttle (enterprise login)
- Max **3 OTP requests per 2 minutes** per mobile/email, via Laravel `RateLimiter` (file cache). 4th within the window → `429 {status:false, message:"Too many OTP requests. Please try again in N seconds."}`.

### P5. Email OTP delivery (enterprise employees log in by email)
- Employee OTP is emailed via `Mail::to($email)->send(new EnterpriseOtpMail($code))`, dispatched **afterResponse** (failures caught + logged, never block the request).
- **Infra gotcha:** the server is a **DigitalOcean droplet (139.59.91.80)** and DO **blocks outbound SMTP** (25/465/587) — so Gmail/Titan SMTP **time out**. Email OTP requires either (a) DO support opening outbound 587, or (b) an **HTTPS email API** (Resend/Mailgun/Brevo) over 443. SMS (EDUMARC) is unaffected (HTTPS API).

### P6. Same phone for Shero user + enterprise driver
- `EnterpriseDriverController` no longer blocks creating a driver whose phone exists in `users` (removed the cross-check in `store`/`update`/bulk). `destroy()` no longer deletes the matching `users` row (would have wiped a rider account on the shared number). Uniqueness **within** `enterprise_drivers` still enforced.

### P7. API contract the iOS driver app must handle
Enterprise driver OTP verify (`verifyEnterpriseOTP` → `enterprise_type=driver, mobile, code`):
- **Success** `200 {status:true, message, driver:{...}, token}` — note `driver.assigned_employee_ids` and `driver.schedules` are sent as **JSON strings** (decode as `String?`, not arrays).
- **Wrong code** `400 {status:false, message:"Invalid OTP", attempts_remaining:N}` — **show `message`** to the user.
- **Locked** `429 {status:false, message:"Too many failed attempts. Try again after 1 minute."}`.
- **Send throttled** `429 {status:false, message:"Too many OTP requests. Please try again in N seconds."}`.
- The app should **always render the `message`** on any non-2xx (the iOS app currently clears the OTP field without showing the error — that's the open client-side fix).

### P8. Files (Part P)
- `app/Http/Controllers/Api/EnterpriseController.php` (random OTP, SMS template + number fix, verify rate limit, send throttle, `RateLimiter` import).
- `app/Http/Controllers/Api/EnterpriseRideController.php` (random ride PINs).
- `app/Http/Controllers/Admin/EnterpriseDriverController.php` (shared-phone).
- `database/migrations/2026_06_15_130000_add_attempts_and_locked_until_to_otps_table.php`.
- `.env` — `MAIL_*` (Titan/Resend), `EDUMARC_*` already present.

---

## Out of Scope

- Separate dispatch subdomain (revisit at scale).
- Cancellation fees.
- Web Push to admin browser (toast-only).
- Persistent event-log table (derived from existing rows).
- Monthly aggregate / report tables (defer).
- Holiday calendar auto-leave.
- Two-driver hand-off.
- Cross-employee shift swaps.
- "I want to be dropped at a DIFFERENT address today" (different from home) — adjacent to asymmetric leave but distinct; separate feature if needed.
