<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\Guardian;
use App\Models\SchoolChildAbsence;
use App\Models\SchoolChildHandover;
use App\Models\SchoolIncident;
use App\Models\SchoolStaff;
use App\Models\SchoolStopArrival;
use App\Models\SchoolTrip;
use App\Models\SchoolTripChild;
use App\Models\SchoolTripEvent;
use Database\Seeders\PhoenixGreensSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Zippi Fleet — the vehicle API (Layer 3).
 *
 * ⚠ THE SERVER IS THE AUTHORITY. The Flutter app enforces the same four
 * invariants locally so a crew member is told before they tap rather than
 * after — but a phone can be old, offline, rooted, or running a build from
 * March. These tests assert that the SERVER refuses, with the request shaped
 * the way a tampered client would shape it.
 */
class FleetApiTest extends TestCase
{
    use RefreshDatabase;

    private SchoolTrip $morning;
    private SchoolTrip $afternoon;
    private SchoolStaff $attendant;
    private SchoolStaff $driver;

    /**
     * A real generated school day, both directions.
     *
     * ⚠ The seeder only lays down today's MORNING demo trips, so the afternoon
     * — where Invariant #1 lives — would be untested against a hand-built
     * fixture. Running SchoolTripGenerator instead means these tests exercise
     * the same rows a real 02:30 cron produces: solved stop times, crew from
     * the roster, one trip-child row per child per direction.
     *
     * ⚠ A SCHOOL DAY THAT IS DELIBERATELY NOT TODAY, resolved through the
     * calendar rather than written down.
     *
     * This was the fixed string '2026-08-24', picked as a weekday because the
     * suite's "today" can fall on a Saturday, which the calendar correctly
     * refuses to generate for (PART C3). It was a time bomb: on 2026-08-24 that
     * date BECAME the seeder's own "today", and PhoenixGreensSeeder lays down
     * today's morning trips already mid-flight — three completed, two running,
     * one with the sweep skipped on purpose. `--force` is right to leave a
     * started or completed trip alone, so setUp picked up a finished trip whose
     * children were long past `pending`, and ten tests failed on that one day.
     *
     * `nextSchoolDay()` keeps the weekday guarantee — it skips weekends and the
     * seeded holidays — and lands on a date the seeder never touches, whatever
     * the wall clock says.
     *
     * ⚠ Unlike FleetLinkTest, these tests must NOT run on today. That file
     * asserts a fleet write reaches the family card, which FamilyCardBuilder
     * builds from *today's* trips only. This file asserts the server's
     * refusals, and every one of them is date-agnostic.
     */
    private string $serviceDate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PhoenixGreensSeeder::class);

        $school = \App\Models\School::firstOrFail();

        $this->serviceDate = (new \App\Services\SchoolCalendarService($school))->nextSchoolDay();

        app(\App\Services\SchoolTripGenerator::class)
            ->generate($school, $this->serviceDate, true);

        // ⚠ Compared as a RAW STRING (PART L1). A Carbon instance here shifts
        // the IST date back a day and matches nothing.
        $this->morning = SchoolTrip::where('service_date', $this->serviceDate)
            ->where('direction', 'Morning')
            ->whereNotNull('attendant_id')->whereNotNull('driver_id')->firstOrFail();

        $this->afternoon = SchoolTrip::where('service_date', $this->serviceDate)
            ->where('direction', 'Afternoon')
            ->where('route_id', $this->morning->route_id)
            ->whereNotNull('attendant_id')->whereNotNull('driver_id')->firstOrFail();

        $this->attendant = SchoolStaff::findOrFail($this->morning->attendant_id);
        $this->driver = SchoolStaff::findOrFail($this->morning->driver_id);

        // The afternoon run is crewed by the same people in the demo roster;
        // if the roster ever differs, act as whoever is actually on it.
        if ($this->afternoon->attendant_id !== $this->attendant->id) {
            $this->afternoon->forceFill([
                'attendant_id' => $this->attendant->id,
                'driver_id' => $this->driver->id,
            ])->save();
        }
    }

    /* ================================================================= */
    /* helpers                                                           */
    /* ================================================================= */

    private function asAttendant(?SchoolStaff $staff = null): self
    {
        Sanctum::actingAs($staff ?? $this->attendant, ['*']);
        return $this;
    }

    /** Puts a trip in the running state without going through the UI flow. */
    private function startTrip(SchoolTrip $trip, SchoolStaff $staff): SchoolTrip
    {
        $trip->forceFill([
            'status' => 'started',
            'started_at' => now(),
            'started_by_staff_id' => $staff->id,
        ])->save();

        return $trip->refresh();
    }

    private function firstPending(SchoolTrip $trip): SchoolTripChild
    {
        return SchoolTripChild::where('trip_id', $trip->id)
            ->where('status', 'pending')->firstOrFail();
    }

    /* ================================================================= */
    /* Auth, and the token confusion that minting staff tokens created   */
    /* ================================================================= */

    public function test_a_guardian_token_cannot_reach_the_fleet_api(): void
    {
        // ⚠ THE REGRESSION THAT ADDING STAFF TOKENS COULD HAVE CAUSED. Sanctum
        // authenticates whoever a token belongs to; without token.holder:staff
        // a parent's token would authenticate against endpoints that mark
        // children boarded and released.
        Sanctum::actingAs(Guardian::firstOrFail(), ['*']);

        $this->getJson('/api/fleet/duties')->assertStatus(403);
        $this->getJson("/api/fleet/trips/{$this->morning->id}")->assertStatus(403);
    }

    public function test_a_staff_token_cannot_reach_the_parent_api(): void
    {
        // The same door, the other way round. An attendant holding a parent
        // token would be reading children they have no relationship with.
        $this->asAttendant();

        $this->getJson('/api/parent/family-dashboard')->assertStatus(403);
    }

    public function test_an_unknown_number_is_told_nothing_about_who_drives(): void
    {
        // ⚠ ENUMERATION. The response for a stranger's number and a driver's
        // number must be identical — those people are alone with children.
        $unknown = $this->postJson('/api/fleet/otp', ['phone' => '+919000000000']);
        $known = $this->postJson('/api/fleet/otp', ['phone' => $this->attendant->phone]);

        $unknown->assertOk();
        $known->assertOk();
        $this->assertSame($unknown->json('message'), $known->json('message'));
    }

    public function test_sign_in_returns_one_token_per_role_link(): void
    {
        // PART P6 — the phone is the identity; the role links are the rows.
        $this->postJson('/api/fleet/otp', ['phone' => $this->attendant->phone])->assertOk();

        // The code is stored hashed and only ever delivered; overwrite the hash
        // rather than reaching for the plaintext, exactly as ParentApiTest does.
        \App\Models\Otp::latest('id')->firstOrFail()
            ->update(['code_hash' => \Illuminate\Support\Facades\Hash::make('4321')]);

        $res = $this->postJson('/api/fleet/otp/verify', [
            'phone' => $this->attendant->phone,
            'code' => '4321',
            'device_name' => 'test-device',
        ]);

        $res->assertOk()->assertJsonPath('status', true);
        $this->assertNotEmpty($res->json('roles'));
        $this->assertNotEmpty($res->json('roles.0.token'));
        $this->assertContains($res->json('roles.0.role'), ['driver', 'attendant']);
    }

    /**
     * ⚠ The OTP TYPE keeps the two apps apart. A wrong code typed in the Fleet
     * app must not consume or lock the same number's parent code.
     */
    public function test_the_fleet_otp_is_scoped_to_staff(): void
    {
        $this->postJson('/api/fleet/otp', ['phone' => $this->attendant->phone])->assertOk();

        $this->assertDatabaseHas('otps', [
            'identity_type' => 'staff',
            'identity' => \App\Support\PhoneNumber::canonical($this->attendant->phone),
        ]);

        $this->assertDatabaseMissing('otps', ['identity_type' => 'guardian']);
    }

    /* ================================================================= */
    /* Authorization — the trip is resolved through the crew member      */
    /* ================================================================= */

    public function test_a_crew_member_cannot_open_a_trip_they_are_not_on(): void
    {
        $other = SchoolTrip::where('id', '!=', $this->morning->id)
            ->where('attendant_id', '!=', $this->attendant->id)
            ->where('driver_id', '!=', $this->attendant->id)
            ->whereNotNull('attendant_id')
            ->firstOrFail();

        $this->asAttendant();

        // 404 rather than 403 so the response cannot be used to probe which
        // trips — and therefore which routes and children — exist.
        $this->getJson("/api/fleet/trips/{$other->id}")->assertStatus(404);
    }

    /* ================================================================= */
    /* The role split                                                    */
    /* ================================================================= */

    public function test_a_driver_device_cannot_mark_a_child_boarded(): void
    {
        $trip = $this->startTrip($this->morning, $this->driver);
        $row = $this->firstPending($trip);

        // ⚠ The Fleet app renders no such control on a driver device. This is
        // the half that matters: a hidden button is not a permission.
        Sanctum::actingAs($this->driver, ['*']);

        $this->postJson("/api/fleet/trips/{$trip->id}/children/{$row->child_id}/board")
            ->assertStatus(403)
            ->assertJsonPath('message', 'Driver devices cannot mark children. Hand this to the attendant.');

        $this->assertSame('pending', $row->refresh()->status);
    }

    /* ================================================================= */
    /* 4 · the checklist is blocking                                     */
    /* ================================================================= */

    public function test_a_trip_cannot_start_until_the_checklist_is_complete(): void
    {
        $this->asAttendant();
        $trip = $this->morning;

        $this->postJson("/api/fleet/trips/{$trip->id}/start")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Finish the pre-trip checklist before the bus moves — 0 of 4 ticked.');

        $this->postJson("/api/fleet/trips/{$trip->id}/checklist", [
            'items' => [
                ['key' => 'clean', 'label' => 'Vehicle clean and seats intact'],
                ['key' => 'first_aid', 'label' => 'First aid box present'],
                ['key' => 'extinguisher', 'label' => 'Fire extinguisher present'],
                ['key' => 'exit', 'label' => 'Emergency exit clear'],
            ],
        ])->assertOk();

        $this->postJson("/api/fleet/trips/{$trip->id}/start")->assertOk();

        $trip->refresh();
        $this->assertSame('started', $trip->status);
        $this->assertSame($this->attendant->id, $trip->started_by_staff_id);

        // Each tick is timestamped into the trip record — evidence, not a flag.
        $this->assertCount(4, $trip->pretrip_checklist);
        $this->assertNotEmpty($trip->pretrip_checklist[0]['ticked_at']);
    }

    public function test_only_one_device_may_start_a_trip(): void
    {
        $trip = $this->startTrip($this->morning, $this->driver);
        $this->asAttendant();

        // ⚠ A second start would fork the journey record and push "the bus has
        // left" to 22 families twice.
        $this->postJson("/api/fleet/trips/{$trip->id}/start")
            ->assertStatus(409)
            ->assertJsonPath('status', false);
    }

    /* ================================================================= */
    /* Invariant #1 — nobody is released without a verified receiver     */
    /* ================================================================= */

    public function test_self_release_is_refused_without_consent(): void
    {
        $trip = $this->startTrip($this->afternoon, $this->attendant);
        $this->asAttendant();

        $row = SchoolTripChild::where('trip_id', $trip->id)->firstOrFail();
        $row->forceFill(['status' => 'boarded', 'boarded_at' => now()])->save();

        $row->child->forceFill(['self_release_consent' => false])->save();

        $this->postJson(
            "/api/fleet/trips/{$trip->id}/children/{$row->child_id}/handover",
            ['method' => 'self_release'],
        )->assertStatus(403);

        $this->assertSame('boarded', $row->refresh()->status,
            'A refused handover must not move the child.');
    }

    public function test_a_wrong_handover_code_is_refused_and_counts_down(): void
    {
        $trip = $this->startTrip($this->afternoon, $this->attendant);
        $this->asAttendant();

        $row = SchoolTripChild::where('trip_id', $trip->id)->firstOrFail();
        $row->forceFill(['status' => 'boarded', 'boarded_at' => now()])->save();

        // Mint today's code so the hash exists, then present a different one.
        $real = $row->child->todaysHandoverCode();
        $wrong = $real === '1111' ? '2222' : '1111';

        $res = $this->postJson(
            "/api/fleet/trips/{$trip->id}/children/{$row->child_id}/handover",
            ['method' => 'handover_code', 'code' => $wrong],
        );

        $res->assertStatus(422);
        $this->assertStringContainsString('attempts left', $res->json('message'));
        $this->assertSame('boarded', $row->refresh()->status);
    }

    public function test_five_wrong_codes_lock_the_keypad_but_not_the_child(): void
    {
        $trip = $this->startTrip($this->afternoon, $this->attendant);
        $this->asAttendant();

        $row = SchoolTripChild::where('trip_id', $trip->id)->firstOrFail();
        $row->forceFill(['status' => 'boarded', 'boarded_at' => now()])->save();
        $row->child->todaysHandoverCode();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson(
                "/api/fleet/trips/{$trip->id}/children/{$row->child_id}/handover",
                ['method' => 'handover_code', 'code' => '0000'],
            );
        }

        $this->postJson(
            "/api/fleet/trips/{$trip->id}/children/{$row->child_id}/handover",
            ['method' => 'handover_code', 'code' => '0000'],
        )->assertStatus(423);

        // ⚠ THE LOCK IS ON THE CODE, NOT ON THE CHILD. A locked keypad must
        // never become the reason a child is let off unverified — the other two
        // verification routes stay open.
        $receiver = $row->child->authorizedReceivers()->where('is_active', true)->first();

        $this->postJson(
            "/api/fleet/trips/{$trip->id}/children/{$row->child_id}/handover",
            ['method' => 'authorized_person', 'authorized_receiver_id' => $receiver->id],
        )->assertOk();

        $this->assertSame('alighted_to_guardian', $row->refresh()->status);
    }

    public function test_there_is_no_not_at_stop_on_an_afternoon_trip(): void
    {
        $trip = $this->startTrip($this->afternoon, $this->attendant);
        $this->asAttendant();

        $row = SchoolTripChild::where('trip_id', $trip->id)->firstOrFail();
        $row->forceFill(['status' => 'pending'])->save();

        // In the afternoon the child is ON THE BUS; "not at stop" would mean
        // putting them out at a kerb where nobody came.
        $this->postJson("/api/fleet/trips/{$trip->id}/children/{$row->child_id}/not-at-stop")
            ->assertStatus(422)
            ->assertJsonPath('status', false);
    }

    public function test_not_at_stop_waits_the_full_window_then_records_an_absence(): void
    {
        $trip = $this->startTrip($this->morning, $this->attendant);
        $this->asAttendant();

        $row = $this->firstPending($trip);

        $this->postJson("/api/fleet/trips/{$trip->id}/stops/{$row->stop_id}/reach", [
            'latitude' => 17.41, 'longitude' => 78.43,
        ])->assertOk();

        $this->postJson("/api/fleet/trips/{$trip->id}/children/{$row->child_id}/not-at-stop")
            ->assertStatus(422);

        // Wind the arrival back past the school's wait.
        $wait = (int) $trip->school->stop_wait_seconds;
        SchoolStopArrival::where('trip_id', $trip->id)->where('stop_id', $row->stop_id)
            ->update(['arrived_at' => now()->subSeconds($wait + 5)]);

        $this->postJson("/api/fleet/trips/{$trip->id}/children/{$row->child_id}/not-at-stop")
            ->assertOk();

        $this->assertSame('not_at_stop', $row->refresh()->status);

        // ⚠ The absence row is how the office and the guardians hear about it.
        $this->assertDatabaseHas('school_child_absences', [
            'child_id' => $row->child_id,
            'service_date' => $trip->service_date,
            'direction' => 'Morning',
            'marked_by' => 'attendant_not_at_stop',
        ]);
    }

    public function test_a_drop_stop_cannot_be_departed_with_a_child_still_aboard(): void
    {
        $trip = $this->startTrip($this->afternoon, $this->attendant);
        $this->asAttendant();

        $row = SchoolTripChild::where('trip_id', $trip->id)->whereNotNull('stop_id')->firstOrFail();
        $row->forceFill(['status' => 'boarded', 'boarded_at' => now()])->save();

        $res = $this->postJson("/api/fleet/trips/{$trip->id}/stops/{$row->stop_id}/depart");

        $res->assertStatus(422);
        $this->assertStringContainsString($row->child->name, $res->json('message'));
        $this->assertStringContainsString('has not been handed over', $res->json('message'));
    }

    public function test_return_to_school_is_locked_until_the_escalation_window_runs_out(): void
    {
        $trip = $this->startTrip($this->afternoon, $this->attendant);
        $this->asAttendant();

        $row = SchoolTripChild::where('trip_id', $trip->id)->firstOrFail();
        $row->forceFill(['status' => 'boarded', 'boarded_at' => now()])->save();

        // Not even available before the ladder starts.
        $this->postJson("/api/fleet/trips/{$trip->id}/children/{$row->child_id}/return-to-school")
            ->assertStatus(422);

        $this->postJson("/api/fleet/trips/{$trip->id}/children/{$row->child_id}/escalate")->assertOk();

        // ⚠ The bus waits. A guardian two streets away in traffic is the
        // ordinary case, and driving off with their child is not neutral.
        $this->postJson("/api/fleet/trips/{$trip->id}/children/{$row->child_id}/return-to-school")
            ->assertStatus(422);

        $window = (int) $trip->school->drop_wait_seconds;
        $row->forceFill(['escalation_started_at' => now()->subSeconds($window + 5)])->save();

        $this->postJson("/api/fleet/trips/{$trip->id}/children/{$row->child_id}/return-to-school")
            ->assertOk();

        $this->assertSame('returned_to_school', $row->refresh()->status);

        // Invariant #4 — every override is audited, and this one raises an
        // incident the Control Tower has to close.
        $this->assertDatabaseHas('school_incidents', [
            'trip_id' => $trip->id,
            'child_id' => $row->child_id,
            'incident_type' => 'child_returned_to_school',
        ]);

        $this->assertDatabaseHas('school_child_handovers', [
            'child_id' => $row->child_id,
            'verification_method' => 'returned_to_school',
        ]);
    }

    /* ================================================================= */
    /* Invariant #2 — a trip cannot complete with a child unaccounted     */
    /* ================================================================= */

    public function test_completion_is_refused_and_names_the_child(): void
    {
        $trip = $this->startTrip($this->afternoon, $this->attendant);
        $this->asAttendant();

        // Satisfy Invariant #3 so this test is only about #2.
        $trip->forceFill(['sweep_verified_at' => now(), 'sweep_photo_path' => 'x.jpg'])->save();

        $row = SchoolTripChild::where('trip_id', $trip->id)->firstOrFail();
        $row->forceFill(['status' => 'boarded', 'boarded_at' => now()])->save();

        SchoolTripChild::where('trip_id', $trip->id)
            ->where('id', '!=', $row->id)
            ->update(['status' => 'absent']);

        $res = $this->postJson("/api/fleet/trips/{$trip->id}/complete");

        $res->assertStatus(422);
        $this->assertStringContainsString($row->child->name, $res->json('message'),
            'A count is dismissed; a name is a person somebody goes and finds.');
        $this->assertStringContainsString('still on board', $res->json('message'));
        $this->assertContains($row->child_id, $res->json('child_ids'));

        $this->assertNull($trip->refresh()->completed_at);
    }

    /* ================================================================= */
    /* Invariant #3 — the sweep                                          */
    /* ================================================================= */

    public function test_the_sweep_cannot_be_confirmed_away_from_the_school(): void
    {
        Storage::fake('local');

        $trip = $this->startTrip($this->morning, $this->attendant);
        $this->asAttendant();

        // A point well outside the gate geofence.
        $this->post("/api/fleet/trips/{$trip->id}/sweep", [
            'photo' => UploadedFile::fake()->image('aisle.jpg'),
            'latitude' => 17.9,
            'longitude' => 78.9,
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('status', false);

        $this->assertNull($trip->refresh()->sweep_verified_at);
    }

    public function test_the_sweep_succeeds_at_the_gate_and_gates_completion(): void
    {
        Storage::fake('local');

        $trip = $this->startTrip($this->morning, $this->attendant);
        $this->asAttendant();

        SchoolTripChild::where('trip_id', $trip->id)->update(['status' => 'arrived_at_school']);

        // ⚠ Completion is refused while the sweep is pending, even with every
        // child accounted for.
        $this->postJson("/api/fleet/trips/{$trip->id}/complete")
            ->assertStatus(422)
            ->assertJsonPath('message',
                'Cannot complete: the bus has not been swept. Walk to the back and confirm every seat is empty.');

        $school = $trip->school;

        $this->post("/api/fleet/trips/{$trip->id}/sweep", [
            'photo' => UploadedFile::fake()->image('aisle.jpg'),
            'latitude' => (float) $school->latitude,
            'longitude' => (float) $school->longitude,
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertNotNull($trip->refresh()->sweep_verified_at);

        $this->postJson("/api/fleet/trips/{$trip->id}/complete")->assertOk();
        $this->assertNotNull($trip->refresh()->completed_at);
    }

    /* ================================================================= */
    /* Invariant #4 — an SOS locks child records                          */
    /* ================================================================= */

    public function test_an_open_sos_locks_every_child_state_change(): void
    {
        $trip = $this->startTrip($this->morning, $this->attendant);
        $this->asAttendant();

        $row = $this->firstPending($trip);

        $this->postJson("/api/fleet/trips/{$trip->id}/sos", ['type' => 'breakdown'])
            ->assertOk()
            ->assertJsonPath('is_drill', false);

        $this->postJson("/api/fleet/trips/{$trip->id}/children/{$row->child_id}/board")
            ->assertStatus(423);

        $this->assertSame('pending', $row->refresh()->status);

        // ⚠ Only ops release it. There is no crew-side control, so the test
        // closes the incident the way the dashboard would.
        SchoolIncident::where('trip_id', $trip->id)->update(['closed_at' => now()]);

        $this->postJson("/api/fleet/trips/{$trip->id}/children/{$row->child_id}/board")->assertOk();
        $this->assertSame('boarded', $row->refresh()->status);
    }

    public function test_a_drill_locks_records_exactly_like_a_real_alert(): void
    {
        $trip = $this->startTrip($this->morning, $this->attendant);
        $this->asAttendant();

        $row = $this->firstPending($trip);

        $this->postJson("/api/fleet/trips/{$trip->id}/sos", ['type' => 'other', 'drill' => true])
            ->assertOk()
            ->assertJsonPath('is_drill', true);

        // A drill that behaves differently trains the wrong reflex.
        $this->postJson("/api/fleet/trips/{$trip->id}/children/{$row->child_id}/board")
            ->assertStatus(423);
    }

    /* ================================================================= */
    /* 6 · boarding, the undo window, and idempotency                     */
    /* ================================================================= */

    public function test_boarding_is_idempotent_rather_than_throttled(): void
    {
        $trip = $this->startTrip($this->morning, $this->attendant);
        $this->asAttendant();

        $row = $this->firstPending($trip);

        // ⚠ Enterprise BF3 — never throttle an action an operator must repeat
        // under pressure. A double-tap while 22 children board is not an error.
        $this->postJson("/api/fleet/trips/{$trip->id}/children/{$row->child_id}/board")->assertOk();
        $first = $row->refresh()->boarded_at;

        $this->postJson("/api/fleet/trips/{$trip->id}/children/{$row->child_id}/board")->assertOk();

        $this->assertEquals($first, $row->refresh()->boarded_at,
            'A second board must not move the boarding time.');
    }

    public function test_the_undo_window_closes(): void
    {
        $trip = $this->startTrip($this->morning, $this->attendant);
        $this->asAttendant();

        $row = $this->firstPending($trip);
        $this->postJson("/api/fleet/trips/{$trip->id}/children/{$row->child_id}/board")->assertOk();

        $this->deleteJson("/api/fleet/trips/{$trip->id}/children/{$row->child_id}/board")->assertOk();
        $this->assertSame('pending', $row->refresh()->status);

        $this->postJson("/api/fleet/trips/{$trip->id}/children/{$row->child_id}/board")->assertOk();
        $row->refresh()->forceFill(['boarded_at' => now()->subSeconds(120)])->save();

        // Past the window this is an audited ops override, not a kerb-side tap.
        $this->deleteJson("/api/fleet/trips/{$trip->id}/children/{$row->child_id}/board")
            ->assertStatus(422);

        $this->assertSame('boarded', $row->refresh()->status);
    }

    /* ================================================================= */
    /* 7 · the head count gates the arrival                               */
    /* ================================================================= */

    public function test_a_head_count_mismatch_blocks_disembark_and_raises_an_exception(): void
    {
        $trip = $this->startTrip($this->morning, $this->attendant);
        $this->asAttendant();

        $row = $this->firstPending($trip);
        $this->postJson("/api/fleet/trips/{$trip->id}/children/{$row->child_id}/board")->assertOk();

        $marked = SchoolTripChild::where('trip_id', $trip->id)->where('status', 'boarded')->count();

        $this->postJson("/api/fleet/trips/{$trip->id}/head-count", ['counted' => $marked + 1])
            ->assertOk()
            ->assertJsonPath('matched', false);

        // ⚠ The mismatch is a Control Tower row even though the crew recount —
        // an attendant who counted wrong once has had a moment worth seeing.
        $this->assertDatabaseHas('school_trip_events', [
            'trip_id' => $trip->id,
            'event_type' => 'headcount_mismatch',
            'severity' => 'critical',
        ]);

        $this->postJson("/api/fleet/trips/{$trip->id}/disembark")->assertStatus(422);

        $this->postJson("/api/fleet/trips/{$trip->id}/head-count", ['counted' => $marked])
            ->assertOk()
            ->assertJsonPath('matched', true);

        $this->postJson("/api/fleet/trips/{$trip->id}/disembark")->assertOk();
        $this->assertNotNull($trip->refresh()->arrived_at_school_at);
    }

    /* ================================================================= */
    /* 9 · afternoon lock-in tells the office                             */
    /* ================================================================= */

    public function test_lock_in_marks_the_rest_absent_and_notifies_the_office(): void
    {
        $trip = $this->startTrip($this->afternoon, $this->attendant);
        $this->asAttendant();

        // Departure time has passed, so the lock-in unlocks.
        $trip->forceFill(['scheduled_start_at' => now()->subMinute()])->save();

        $pending = SchoolTripChild::where('trip_id', $trip->id)->where('status', 'pending')->count();
        $this->assertGreaterThan(0, $pending);

        $this->postJson("/api/fleet/trips/{$trip->id}/lock-in")
            ->assertOk()
            ->assertJsonPath('marked_absent', $pending);

        $this->assertSame(0, SchoolTripChild::where('trip_id', $trip->id)
            ->where('status', 'pending')->count());

        // ⚠ The notification IS the action. An expected child who did not come
        // out is somewhere on school premises.
        $this->assertDatabaseHas('school_trip_events', [
            'trip_id' => $trip->id,
            'event_type' => 'unaccounted_child',
        ]);

        $this->assertDatabaseHas('school_child_absences', [
            'service_date' => $trip->service_date,
            'direction' => 'Afternoon',
            'marked_by' => 'attendant_not_boarded_at_school',
        ]);
    }

    /* ================================================================= */
    /* The payload never carries a live release code                     */
    /* ================================================================= */

    public function test_the_trip_payload_never_contains_a_handover_code(): void
    {
        $trip = $this->startTrip($this->afternoon, $this->attendant);
        $this->asAttendant();

        $child = SchoolTripChild::where('trip_id', $trip->id)->firstOrFail()->child;
        $code = $child->todaysHandoverCode();

        $body = $this->getJson("/api/fleet/trips/{$trip->id}")->assertOk()->content();

        // ⚠ The attendant's device posts what was TYPED; the server compares it
        // against a hash. A code in this payload is a code in a proxy log.
        $this->assertStringNotContainsString('handover_code_hash', $body);
        $this->assertStringNotContainsString('"handover_code"', $body);
        $this->assertStringNotContainsString($child->handover_code_hash ?? '###', $body);
        $this->assertNotEmpty($code);
    }

    public function test_ops_facing_crew_numbers_are_masked(): void
    {
        $trip = $this->startTrip($this->morning, $this->attendant);
        $this->asAttendant();

        $body = $this->getJson("/api/fleet/trips/{$trip->id}")->assertOk()->content();

        // PART K9 — names and masked numbers, never a raw one.
        $this->assertStringNotContainsString($this->driver->phone, $body);
        $this->assertStringContainsString('phone_masked', $body);
    }

    /* ================================================================= */
    /* PART A7 (extended) · the MORNING boarding code                     */
    /* ================================================================= */

    public function test_a_correct_boarding_code_boards_the_child(): void
    {
        $trip = $this->startTrip($this->morning, $this->attendant);
        $this->asAttendant();

        $row = $this->firstPending($trip);
        $code = $row->child->todaysBoardingCode();

        $this->postJson("/api/fleet/trips/{$trip->id}/children/{$row->child_id}/board", [
            'method' => 'boarding_code',
            'code' => $code,
        ])->assertOk();

        $row->refresh();
        $this->assertSame('boarded', $row->status);
        $this->assertSame('boarding_code', $row->boarding_verification);
    }

    public function test_a_wrong_boarding_code_is_refused_and_counted(): void
    {
        $trip = $this->startTrip($this->morning, $this->attendant);
        $this->asAttendant();

        $row = $this->firstPending($trip);
        $wrong = $row->child->todaysBoardingCode() === '1111' ? '2222' : '1111';

        $this->postJson("/api/fleet/trips/{$trip->id}/children/{$row->child_id}/board", [
            'method' => 'boarding_code',
            'code' => $wrong,
        ])->assertStatus(422);

        $this->assertSame('pending', $row->refresh()->status,
            'A refused code must not board the child.');
    }

    /**
     * ⚠ THE MORNING CODE IS NOT THE AFTERNOON CODE, and this is the test that
     * says so.
     *
     * The boarding code is read aloud at a public kerb every morning, in front
     * of the other families at that stop. If the two were one value, every
     * morning boarding would broadcast that afternoon's RELEASE code — the
     * check that stands between a child and a stranger under Invariant #1.
     */
    public function test_the_boarding_code_is_not_the_handover_code(): void
    {
        $child = $this->firstPending($this->morning)->child;

        $boarding = $child->todaysBoardingCode();
        $handover = $child->todaysHandoverCode();

        $this->assertNotSame($boarding, $handover,
            'The kerb-side boarding code must never equal the release code.');

        $child->refresh();
        $this->assertFalse($child->verifyHandoverCode($boarding),
            'The boarding code must not open an afternoon handover.');
        $this->assertFalse($child->verifyBoardingCode($handover),
            'The handover code must not board a child.');
    }

    /**
     * ⚠ The two keypads lock out separately.
     *
     * Before the morning code existed the attempt counter was keyed by day
     * alone. Sharing it would mean five wrong codes at a 07:15 kerb silently
     * locked that child's 15:30 RELEASE — a family arriving to collect their
     * child refused over something that happened eight hours earlier, on a
     * different code.
     */
    public function test_a_morning_lockout_does_not_lock_the_afternoon_release(): void
    {
        $trip = $this->startTrip($this->morning, $this->attendant);
        $this->asAttendant();

        $row = $this->firstPending($trip);
        $child = $row->child;

        for ($i = 0; $i < 5; $i++) {
            $this->postJson("/api/fleet/trips/{$trip->id}/children/{$child->id}/board", [
                'method' => 'boarding_code',
                'code' => '0000',
            ]);
        }

        // The morning keypad is locked.
        $this->postJson("/api/fleet/trips/{$trip->id}/children/{$child->id}/board", [
            'method' => 'boarding_code',
            'code' => $child->todaysBoardingCode(),
        ])->assertStatus(423);

        // The afternoon one is not.
        $pm = $this->startTrip($this->afternoon, $this->attendant);
        $pmRow = SchoolTripChild::where('trip_id', $pm->id)
            ->where('child_id', $child->id)->first();

        if (! $pmRow) {
            $this->markTestSkipped('This child has no afternoon leg to test against.');
        }

        $pmRow->forceFill(['status' => 'boarded', 'boarded_at' => now()])->save();

        $this->postJson("/api/fleet/trips/{$pm->id}/children/{$child->id}/handover", [
            'method' => 'handover_code',
            'code' => $child->todaysHandoverCode(),
        ])->assertOk();
    }

    /**
     * ⚠⚠ THE ONE THAT MUST NEVER STOP PASSING.
     *
     * A locked keypad in the morning must not strand a child. The bus is at the
     * kerb, the child is on the pavement, and the guardian's phone is flat. The
     * photo roster is still a real verification and the child gets on.
     */
    public function test_a_locked_boarding_keypad_still_lets_the_child_board(): void
    {
        $trip = $this->startTrip($this->morning, $this->attendant);
        $this->asAttendant();

        $row = $this->firstPending($trip);

        for ($i = 0; $i < 6; $i++) {
            $this->postJson("/api/fleet/trips/{$trip->id}/children/{$row->child_id}/board", [
                'method' => 'boarding_code',
                'code' => '0000',
            ]);
        }

        $this->postJson("/api/fleet/trips/{$trip->id}/children/{$row->child_id}/board", [
            'method' => 'roster_photo',
        ])->assertOk();

        $row->refresh();
        $this->assertSame('boarded', $row->status,
            'A child must never be left at a kerb because a keypad locked.');
        $this->assertSame('roster_photo', $row->boarding_verification);

        // ⚠ Recorded, not silent. Ops sees the ones that fell back after a
        // failed code — that is the case worth looking at.
        $this->assertDatabaseHas('school_trip_events', [
            'trip_id' => $trip->id,
            'event_type' => 'boarding_code_bypassed',
        ]);
    }

    public function test_boarding_without_a_code_is_recorded_as_roster_verified(): void
    {
        $trip = $this->startTrip($this->morning, $this->attendant);
        $this->asAttendant();

        $row = $this->firstPending($trip);

        // An older build of the Fleet app sends no method at all. It must keep
        // boarding children rather than failing every tap at a kerb.
        $this->postJson("/api/fleet/trips/{$trip->id}/children/{$row->child_id}/board")
            ->assertOk();

        $this->assertSame('roster_photo', $row->refresh()->boarding_verification);

        // No code was attempted, so this is the ordinary path and raises nothing.
        $this->assertDatabaseMissing('school_trip_events', [
            'trip_id' => $trip->id,
            'event_type' => 'boarding_code_bypassed',
        ]);
    }

    public function test_the_boarding_code_never_appears_in_a_payload(): void
    {
        $trip = $this->startTrip($this->morning, $this->attendant);
        $this->asAttendant();

        $child = $this->firstPending($trip)->child;
        $code = $child->todaysBoardingCode();

        $body = $this->getJson("/api/fleet/trips/{$trip->id}")->assertOk()->content();

        $this->assertStringNotContainsString('boarding_code_hash', $body);
        $this->assertStringNotContainsString('"boarding_code"', $body);
        $this->assertStringNotContainsString($child->boarding_code_hash ?? '###', $body);
        $this->assertNotEmpty($code);
    }

    /**
     * Undoing a boarding withdraws the claim about how it was verified. A row
     * back at 'pending' carrying 'boarding_code' would assert that a guardian
     * handed over a child who, by that same row, never got on.
     */
    public function test_undoing_a_boarding_clears_the_verification(): void
    {
        $trip = $this->startTrip($this->morning, $this->attendant);
        $this->asAttendant();

        $row = $this->firstPending($trip);

        $this->postJson("/api/fleet/trips/{$trip->id}/children/{$row->child_id}/board", [
            'method' => 'boarding_code',
            'code' => $row->child->todaysBoardingCode(),
        ])->assertOk();

        $this->assertSame('boarding_code', $row->refresh()->boarding_verification);

        $this->deleteJson("/api/fleet/trips/{$trip->id}/children/{$row->child_id}/board")
            ->assertOk();

        $row->refresh();
        $this->assertSame('pending', $row->status);
        $this->assertNull($row->boarding_verification);
    }
}
