<?php

use App\Http\Controllers\Api\FleetApiController;
use App\Http\Controllers\Api\FleetAuthApiController;
use App\Http\Controllers\Api\ParentApiController;
use App\Http\Controllers\Api\ParentAuthApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Zippi Parent — mobile API (PART P7 / H4)
|--------------------------------------------------------------------------
|
| Consumed by the Flutter app in `mobile/zippi_parent`. Token auth via Sanctum;
| the guardian provider is the same `guardians` table the web app uses, so a
| parent has one identity across both surfaces.
|
| ⚠ THIS GROUP USED TO BE SAFE BY ACCIDENT. The comment here once read: "a staff
| token could never satisfy these routes because no staff token is ever minted."
| Zippi Fleet mints staff tokens, so that stopped being true — `auth:sanctum`
| authenticates whoever a bearer token belongs to and does not care which model
| that is. `token.holder:guardian` is what makes the statement true again, and
| it must not be removed: without it an attendant's token authenticates here,
| where every route resolves children through `$request->user()`.
|
| ⚠ Every non-2xx response carries a `message` the app renders VERBATIM (P7).
|
*/

/*
|--------------------------------------------------------------------------
| Health — is this address the school server?
|--------------------------------------------------------------------------
|
| Unauthenticated and deliberately tiny. The pilot server runs on a laptop
| whose LAN address moves, and every failure downstream of a wrong address
| looks the same to a crew member or a parent: a spinner, then a sentence about
| the server not responding. That sentence cannot distinguish "wrong address"
| from "server down" from "phone on the wrong network" — and the person holding
| the phone is standing next to a bus, not a laptop.
|
| ⚠ It answers with NOTHING sensitive: no school data, no counts, no names.
| An unauthenticated endpoint on a server holding children's locations gets
| exactly one job — confirming that a socket reached Zippi and not, say, a
| router's admin page on the same port.
*/
Route::get('/health', function () {
    return response()->json([
        'status'  => true,
        'service' => 'zippi-school',
        'time'    => now()->toIso8601String(),
    ]);
})->name('api.health');

Route::prefix('parent')->name('api.parent.')->group(function () {

    // PART P4 — the send endpoint is throttled inside OtpService per phone
    // number; this outer limit is a blunt guard on the endpoint itself.
    Route::middleware('throttle:20,1')->group(function () {
        Route::post('/otp',        [ParentAuthApiController::class, 'sendOtp'])->name('otp');
        Route::post('/otp/verify', [ParentAuthApiController::class, 'verifyOtp'])->name('otp.verify');
    });

    Route::middleware(['auth:sanctum', 'token.holder:guardian'])->group(function () {
        Route::post('/logout',       [ParentAuthApiController::class, 'logout'])->name('logout');
        Route::post('/device-token', [ParentAuthApiController::class, 'deviceToken'])->name('device');

        Route::get('/family-dashboard', [ParentApiController::class, 'familyDashboard'])->name('dashboard');

        Route::get('/child/{child}',          [ParentApiController::class, 'child'])->name('child');
        Route::get('/child/{child}/journey',  [ParentApiController::class, 'journey'])->name('child.journey');
        Route::post('/child/{child}/absence', [ParentApiController::class, 'markAbsent'])->name('child.absence');
        Route::delete('/child/{child}/absence', [ParentApiController::class, 'undoAbsence'])->name('child.absence.undo');
    });
});

/*
|--------------------------------------------------------------------------
| Zippi Fleet — vehicle API (Layer 3)
|--------------------------------------------------------------------------
|
| Consumed by the Flutter app in `mobile/zippi_fleet`. This is the WRITE side of
| the platform: until it existed, everything the Parent app displayed was put
| there by hand or by the seeder, because nothing marked a child boarded.
|
| ⚠ NO SEPARATE FLEET STORE, AND THAT IS THE WHOLE LINKING DESIGN. These routes
| write `school_trips`, `school_trip_children`, `school_stop_arrivals`,
| `school_child_handovers`, `school_child_absences`, `school_trip_events`,
| `school_incidents` and `buses` — the same rows the dashboard's live board and
| the Parent app's FamilyCardBuilder already read. There is no sync step and no
| second copy of a child's state, so an attendant's tap reaches the Control
| Tower and the family on their next poll.
|
| ⚠ `token.holder:staff` pins this group the same way. A parent's token must not
| reach endpoints that mark children boarded and released.
|
| ⚠ EVERY route resolves the trip through FleetTripService::authorizeTrip().
| There is no endpoint here that accepts a trip id and trusts it.
|
*/

Route::prefix('fleet')->name('api.fleet.')->group(function () {

    Route::middleware('throttle:20,1')->group(function () {
        Route::post('/otp',        [FleetAuthApiController::class, 'sendOtp'])->name('otp');
        Route::post('/otp/verify', [FleetAuthApiController::class, 'verifyOtp'])->name('otp.verify');
    });

    Route::middleware(['auth:sanctum', 'token.holder:staff'])->group(function () {
        Route::post('/logout',       [FleetAuthApiController::class, 'logout'])->name('logout');
        Route::post('/device-token', [FleetAuthApiController::class, 'deviceToken'])->name('device');

        Route::get('/me',     [FleetApiController::class, 'me'])->name('me');
        Route::get('/duties', [FleetApiController::class, 'duties'])->name('duties');

        Route::prefix('trips/{trip}')->name('trip.')->group(function () {
            Route::get('/', [FleetApiController::class, 'show'])->name('show');

            // 4 · pre-trip checklist, then the swipe
            Route::post('/checklist', [FleetApiController::class, 'checklist'])->name('checklist');
            Route::post('/start',     [FleetApiController::class, 'start'])->name('start');

            // 5 · stops, in authored order
            Route::post('/stops/{stop}/reach',  [FleetApiController::class, 'reachStop'])->name('stop.reach');
            Route::post('/stops/{stop}/depart', [FleetApiController::class, 'departStop'])->name('stop.depart');

            // 6 · marking children — attendant only, server-enforced
            Route::post('/children/{child}/board',       [FleetApiController::class, 'board'])->name('child.board');
            Route::delete('/children/{child}/board',     [FleetApiController::class, 'undoBoard'])->name('child.board.undo');
            Route::post('/children/{child}/not-at-stop', [FleetApiController::class, 'notAtStop'])->name('child.notAtStop');

            // 7 · head count · 8 · arrival · 9 · afternoon lock-in
            Route::post('/head-count', [FleetApiController::class, 'headCount'])->name('headCount');
            Route::post('/disembark',  [FleetApiController::class, 'disembark'])->name('disembark');
            Route::post('/lock-in',    [FleetApiController::class, 'lockIn'])->name('lockIn');

            // 10 · handover · 11 · escalation — Invariant #1
            Route::post('/children/{child}/handover',         [FleetApiController::class, 'handover'])->name('child.handover');
            Route::post('/children/{child}/escalate',         [FleetApiController::class, 'escalate'])->name('child.escalate');
            Route::post('/children/{child}/return-to-school', [FleetApiController::class, 'returnToSchool'])->name('child.return');

            // 12 · sweep — Invariant #3 · 13 · complete — Invariant #2
            Route::post('/sweep',    [FleetApiController::class, 'sweep'])->name('sweep');
            Route::post('/complete', [FleetApiController::class, 'complete'])->name('complete');

            // 15 · SOS — Invariant #4
            Route::post('/sos', [FleetApiController::class, 'sos'])->name('sos');

            // The vehicle's position. ⚠ This is what makes the PARENT app's
            // live map move — see FleetTripService::ping(). Deliberately not
            // throttled: a dropped ping is a bus that appears to have stopped
            // on 22 parents' screens.
            Route::post('/ping', [FleetApiController::class, 'ping'])->name('ping');
        });
    });
});
