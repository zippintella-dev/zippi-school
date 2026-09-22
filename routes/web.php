<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BusController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\ChildController;
use App\Http\Controllers\ChildImportController;
use App\Http\Controllers\ComplianceController;
use App\Http\Controllers\Parent\ParentAuthController;
use App\Http\Controllers\Parent\ParentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LiveBoardController;
use App\Http\Controllers\RosterController;
use App\Http\Controllers\RouteController;
use App\Http\Controllers\SchoolSettingsController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\TripController;
use App\Http\Controllers\TripSimulationController;
use Illuminate\Support\Facades\Route;

Route::get('/',        [AuthController::class, 'show'])->name('login');
Route::post('/login',  [AuthController::class, 'login'])->name('login.post');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::middleware('auth:web')->group(function () {

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    /* ---------- Control Tower (PART B / T) ---------- */
    Route::get('/live',      [LiveBoardController::class, 'index'])->name('live');
    Route::get('/live/data', [LiveBoardController::class, 'data'])->name('live.data');
    Route::post('/alerts/{event}/ack', [LiveBoardController::class, 'acknowledge'])->name('alerts.ack');

    /* ---------- Daily roster (PART O) ---------- */
    Route::get('/roster',              [RosterController::class, 'index'])->name('roster');
    Route::post('/roster/staff',       [RosterController::class, 'saveStaff'])->name('roster.staff');

    /* ---------- Trips & journey record (PART D) ---------- */
    Route::get('/trips',                    [TripController::class, 'index'])->name('trips.index');
    Route::get('/trips/{trip}',             [TripController::class, 'show'])->name('trips.show');

    /* ---------- ⚠ TEST HARNESS — stands in for Zippi Fleet (Layer 3) ----------
       These fabricate the events an attendant's app would record. Zippi staff
       only, every action audited as simulated, and the safety invariants are
       still enforced. Delete this block the day Fleet ships.                 */
    Route::post('/trips/{trip}/sim/start',    [TripSimulationController::class, 'start'])->name('sim.start');
    Route::post('/trips/{trip}/sim/advance',  [TripSimulationController::class, 'advance'])->name('sim.advance');
    Route::post('/trips/{trip}/sim/arrive',   [TripSimulationController::class, 'arriveAtSchool'])->name('sim.arrive');
    Route::post('/trips/{trip}/sim/sweep',    [TripSimulationController::class, 'sweep'])->name('sim.sweep');
    Route::post('/trips/{trip}/sim/complete', [TripSimulationController::class, 'complete'])->name('sim.complete');
    Route::post('/trips/{trip}/sim/reset',    [TripSimulationController::class, 'reset'])->name('sim.reset');
    Route::post('/trips/{trip}/sim/board/{row}',     [TripSimulationController::class, 'board'])->name('sim.board');
    Route::post('/trips/{trip}/sim/notatstop/{row}', [TripSimulationController::class, 'notAtStop'])->name('sim.notatstop');
    Route::post('/trips/{trip}/sim/handover/{row}',  [TripSimulationController::class, 'handover'])->name('sim.handover');
    Route::get('/children/{child}/journey', [TripController::class, 'journey'])->name('children.journey');

    /* ---------- School settings (PART S1 step 1–2) ---------- */
    Route::get('/settings',              [SchoolSettingsController::class, 'edit'])->name('settings');
    Route::put('/settings',              [SchoolSettingsController::class, 'update'])->name('settings.update');
    Route::post('/settings/bell-times',  [SchoolSettingsController::class, 'storeBellTime'])->name('bells.store');
    Route::delete('/settings/bell-times/{bell}', [SchoolSettingsController::class, 'destroyBellTime'])->name('bells.destroy');

    /* ---------- Calendar (PART S1 step 3 / C3) ---------- */
    Route::get('/calendar',            [CalendarController::class, 'index'])->name('calendar');
    Route::post('/calendar',           [CalendarController::class, 'store'])->name('calendar.store');
    Route::delete('/calendar/{entry}', [CalendarController::class, 'destroy'])->name('calendar.destroy');
    Route::post('/calendar/import',    [CalendarController::class, 'import'])->name('calendar.import');

    /* ---------- Students (PART S1 step 4) ---------- */
    Route::get('/children',                [ChildController::class, 'index'])->name('children.index');
    Route::get('/children/removed', [ChildController::class, 'removed'])->name('children.removed');
    // ⚠ BEFORE /children/{child}, or the router reads "bell-tier" as an id.
    Route::get('/children/bell-tier',      [ChildController::class, 'bellTierFor'])->name('children.bellTier');
    Route::get('/children/create',         [ChildController::class, 'create'])->name('children.create');
    Route::post('/children',               [ChildController::class, 'store'])->name('children.store');
    Route::get('/children/{child}',        [ChildController::class, 'show'])->name('children.show');
    Route::get('/children/{child}/edit',   [ChildController::class, 'edit'])->name('children.edit');
    Route::put('/children/{child}',        [ChildController::class, 'update'])->name('children.update');
    Route::delete('/children/{child}',     [ChildController::class, 'destroy'])->name('children.destroy');
    Route::post('/children/{child}/restore',      [ChildController::class, 'restore'])->name('children.restore');
    // ⚠ Refuses when the child has any transport history (PART K13).
    Route::delete('/children/{child}/permanent',  [ChildController::class, 'forceDestroy'])->name('children.force-destroy');
    Route::post('/children/{child}/guardians',            [ChildController::class, 'addGuardian'])->name('children.guardians.add');
    Route::put('/children/{child}/guardians/{guardian}',    [ChildController::class, 'updateGuardian'])->name('children.guardians.update');
    Route::delete('/children/{child}/guardians/{guardian}', [ChildController::class, 'removeGuardian'])->name('children.guardians.remove');
    Route::post('/children/{child}/receivers',            [ChildController::class, 'addReceiver'])->name('children.receivers.add');
    Route::delete('/receivers/{receiver}',                [ChildController::class, 'removeReceiver'])->name('receivers.remove');
    Route::post('/children/{child}/assign',   [ChildController::class, 'assignStop'])->name('children.assign');
    Route::post('/children/{child}/absence',  [ChildController::class, 'markAbsent'])->name('children.absence');
    Route::delete('/absences/{absence}',      [ChildController::class, 'removeAbsence'])->name('absences.remove');

    /* CSV import — preview then commit (PART M4) */
    Route::get('/children-import',         [ChildImportController::class, 'form'])->name('children.import');
    Route::post('/children-import/preview',[ChildImportController::class, 'preview'])->name('children.import.preview');
    Route::post('/children-import/commit', [ChildImportController::class, 'commit'])->name('children.import.commit');
    Route::get('/children-import/template',[ChildImportController::class, 'template'])->name('children.import.template');

    /* ---------- Routes & stops (PART S1 step 5 / G) ---------- */
    Route::get('/routes',                 [RouteController::class, 'index'])->name('routes.index');
    Route::get('/routes/create',          [RouteController::class, 'create'])->name('routes.create');
    Route::post('/routes',                [RouteController::class, 'store'])->name('routes.store');
    Route::get('/routes/{route}',         [RouteController::class, 'show'])->name('routes.show');
    Route::put('/routes/{route}',         [RouteController::class, 'update'])->name('routes.update');
    Route::delete('/routes/{route}',      [RouteController::class, 'destroy'])->name('routes.destroy');
    Route::post('/routes/{route}/stops',  [RouteController::class, 'storeStop'])->name('stops.store');
    Route::put('/stops/{stop}',           [RouteController::class, 'updateStop'])->name('stops.update');
    Route::delete('/stops/{stop}',        [RouteController::class, 'destroyStop'])->name('stops.destroy');
    Route::post('/stops/{stop}/move',     [RouteController::class, 'moveStop'])->name('stops.move');
    Route::post('/routes/{route}/crew',   [RouteController::class, 'saveCrew'])->name('routes.crew');

    /* ---------- Fleet (PART S1 step 6) ---------- */
    Route::get('/buses',            [BusController::class, 'index'])->name('buses.index');
    Route::get('/buses/{bus}',      [BusController::class, 'show'])->name('buses.show');
    Route::post('/buses',           [BusController::class, 'store'])->name('buses.store');
    Route::put('/buses/{bus}',      [BusController::class, 'update'])->name('buses.update');
    Route::delete('/buses/{bus}',   [BusController::class, 'destroy'])->name('buses.destroy');

    Route::get('/staff',             [StaffController::class, 'index'])->name('staff.index');
    Route::get('/staff/{member}',    [StaffController::class, 'show'])->name('staff.show');
    Route::post('/staff',            [StaffController::class, 'store'])->name('staff.store');
    Route::put('/staff/{member}',    [StaffController::class, 'update'])->name('staff.update');
    Route::delete('/staff/{member}', [StaffController::class, 'destroy'])->name('staff.destroy');

    Route::get('/compliance', [ComplianceController::class, 'index'])->name('compliance');
});

/*
|--------------------------------------------------------------------------
| Zippi Parent (Layer 1) — PART H4
|--------------------------------------------------------------------------
|
| The family-facing app, served as a mobile web app from this same Laravel
| install. Separate URL prefix, separate auth guard, separate layout.
|
| ⚠ These routes use `auth:guardian`, NOT `auth`. A guardian session must never
| satisfy the ops routes above, and an ops session must never satisfy these.
| The two identities live in different tables and are not interchangeable.
|
*/
Route::prefix('parent')->name('parent.')->group(function () {

    Route::get('/',            [ParentAuthController::class, 'show'])->name('login');
    Route::post('/code',       [ParentAuthController::class, 'sendCode'])->name('code');
    Route::get('/verify',      [ParentAuthController::class, 'verifyForm'])->name('verify.form');
    Route::post('/verify',     [ParentAuthController::class, 'verify'])->name('verify');
    Route::post('/resend',     [ParentAuthController::class, 'resend'])->name('resend');
    Route::post('/logout',     [ParentAuthController::class, 'logout'])->name('logout');

    Route::middleware('auth:guardian')->group(function () {
        Route::get('/home',                  [ParentController::class, 'dashboard'])->name('dashboard');
        Route::get('/child/{child}',         [ParentController::class, 'child'])->name('child');
        Route::get('/child/{child}/data',    [ParentController::class, 'childData'])->name('child.data');
        Route::get('/child/{child}/journey', [ParentController::class, 'journey'])->name('child.journey');
        Route::post('/child/{child}/absence',      [ParentController::class, 'markAbsent'])->name('child.absence');
        Route::delete('/child/{child}/absence',    [ParentController::class, 'undoAbsence'])->name('child.absence.undo');
    });
});
