<?php

namespace Tests\Feature;

use App\Models\Guardian;
use App\Models\School;
use App\Models\SchoolStaff;
use App\Models\SchoolTrip;
use App\Models\SchoolTripChild;
use App\Models\User;
use App\Services\SchoolTripGenerator;
use Database\Seeders\PhoenixGreensSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * THE THREE LAYERS, LINKED.
 *
 * Zippi Fleet (Layer 3) is the only surface that writes what actually happened
 * on a bus. This file asserts that what it writes ARRIVES:
 *
 *   Fleet → Layer 2  the dashboard's live board and Control Tower queue
 *   Fleet → Layer 1  the family's card, timeline, live map and handover code
 *
 * ⚠ THERE IS NO SYNC STEP, AND THAT IS THE DESIGN. All three layers read and
 * write the SAME rows — `school_trips`, `school_trip_children`, `buses`,
 * `school_trip_events`. Nothing here polls a queue or copies a record between
 * stores, because a second copy of a child's state re-opens the question "which
 * one is right about where this child is", and there is no acceptable answer.
 *
 * These tests are therefore mostly about proving the ABSENCE of a seam. If one
 * of them fails, somebody has introduced one.
 */
class FleetLinkTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ⚠ THESE TESTS MUST RUN ON *TODAY*, and that is not a convenience.
     *
     * FamilyCardBuilder builds a family's card from TODAY's trips only — a
     * parent opening the app wants this morning's bus, not last Tuesday's. So a
     * link test on a fixed future date would assert nothing: the fleet write
     * would land, and the parent card would correctly show no trip at all.
     *
     * The suite's "today" can be a weekend, which the calendar rightly refuses
     * to generate for (PART C3). So the day is opened first, exactly the way a
     * school opening a Saturday for an exam would, and then generated.
     */
    private string $serviceDate;

    private SchoolTrip $morning;
    private SchoolTrip $afternoon;
    private SchoolStaff $attendant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PhoenixGreensSeeder::class);

        $school = School::firstOrFail();

        // ⚠ Raw Y-m-d string in the SCHOOL's timezone, never the server's
        // (PART L1). Carbon::today() here would be a different day in IST.
        $this->serviceDate = \Carbon\Carbon::now($school->timezone)->toDateString();

        \App\Models\SchoolCalendar::updateOrCreate(
            ['school_id' => $school->id, 'date' => $this->serviceDate],
            [
                // ⚠ 'school', not 'school_day'. SchoolCalendarService::RUNS_TRIPS
                // is ['school','half_day','exam','event']; anything else is a
                // day the generator correctly refuses to put buses on.
                'day_type' => 'exam',
                'morning_trips_run' => true,
                'afternoon_trips_run' => true,
                'label' => 'Fleet link test — a Saturday opened for an exam',
            ],
        );

        app(SchoolTripGenerator::class)->generate($school, $this->serviceDate, true);

        $this->morning = SchoolTrip::where('service_date', $this->serviceDate)
            ->where('direction', 'Morning')->whereNotNull('attendant_id')->firstOrFail();

        $this->afternoon = SchoolTrip::where('service_date', $this->serviceDate)
            ->where('direction', 'Afternoon')
            ->where('route_id', $this->morning->route_id)->firstOrFail();

        $this->attendant = SchoolStaff::findOrFail($this->morning->attendant_id);

        $this->afternoon->forceFill([
            'attendant_id' => $this->attendant->id,
            'driver_id' => $this->morning->driver_id,
        ])->save();

        // The seeder deliberately leaves exception rows on today's demo trips
        // so the Control Tower queue is never empty. They are not what these
        // tests are about, so start from a clean roster on both directions.
        SchoolTripChild::whereIn('trip_id', [$this->morning->id, $this->afternoon->id])
            ->update(['status' => 'pending', 'boarded_at' => null, 'alighted_at' => null]);

        \App\Models\SchoolChildAbsence::where('service_date', $this->serviceDate)->delete();
    }

    private function startTrip(SchoolTrip $trip): SchoolTrip
    {
        $trip->forceFill([
            'status' => 'started',
            'started_at' => now(),
            'started_by_staff_id' => $this->attendant->id,
        ])->save();

        return $trip->refresh();
    }

    /** The first still-pending child on a trip whose family has the app. */
    private function childOn(SchoolTrip $trip): SchoolTripChild
    {
        return SchoolTripChild::with('child.guardians')
            ->where('trip_id', $trip->id)
            ->where('status', 'pending')
            ->get()
            ->first(fn ($r) => $r->child->guardians->isNotEmpty());
    }

    private function guardianOf(SchoolTripChild $row): Guardian
    {
        return $row->child->guardians->first();
    }

    /* ================================================================= */
    /* Fleet → Layer 1 · Zippi Parent                                     */
    /* ================================================================= */

    /**
     * ⚠ THE HEADLINE LINK. "When the attendant marks a child boarded, that
     * child's parents see it within seconds."
     */
    public function test_a_boarding_marked_on_the_bus_reaches_the_family_card(): void
    {
        $trip = $this->startTrip($this->morning);
        $row = $this->childOn($trip);
        $guardian = $this->guardianOf($row);

        // Before: the family sees a running trip and a child not yet aboard.
        Sanctum::actingAs($guardian, ['*']);

        $before = $this->getJson('/api/parent/family-dashboard')->assertOk();
        $card = collect($before->json('children'))->firstWhere('child_id', $row->child_id);

        $this->assertSame('pending', $card['status']);

        // The attendant taps the row at the kerb.
        Sanctum::actingAs($this->attendant, ['*']);

        $this->postJson(
            "/api/fleet/trips/{$trip->id}/children/{$row->child_id}/board",
            ['latitude' => 17.4162, 'longitude' => 78.4295],
        )->assertOk();

        // The family's very next poll has it. No queue, no sync, no delay.
        Sanctum::actingAs($guardian, ['*']);

        $after = $this->getJson('/api/parent/family-dashboard')->assertOk();
        $card = collect($after->json('children'))->firstWhere('child_id', $row->child_id);

        $this->assertSame('boarded', $card['status']);
        $this->assertTrue($card['has_active_trip']);

        // PART D — and it is on the timeline, in SERVER time.
        $texts = collect($card['timeline'])->pluck('text')->implode(' | ');
        $this->assertStringContainsString('Boarded the bus', $texts);
    }

    /**
     * ⚠ THE LIVE MAP IS DRIVEN BY THE CREW'S DEVICE. `/ping` writes
     * `buses.latitude/longitude`, which is the single position of record.
     */
    public function test_a_position_ping_from_the_bus_moves_the_parents_map(): void
    {
        $trip = $this->startTrip($this->morning);
        $row = $this->childOn($trip);
        $guardian = $this->guardianOf($row);

        Sanctum::actingAs($this->attendant, ['*']);

        $this->postJson("/api/fleet/trips/{$trip->id}/ping", [
            'latitude' => 17.4239, 'longitude' => 78.4128, 'speed_kmph' => 28,
        ])->assertOk();

        Sanctum::actingAs($guardian, ['*']);

        $card = collect($this->getJson('/api/parent/family-dashboard')->json('children'))
            ->firstWhere('child_id', $row->child_id);

        $this->assertTrue($card['show_live_map']);
        $this->assertEqualsWithDelta(17.4239, $card['trip']['bus']['latitude'], 0.0001);
        $this->assertEqualsWithDelta(78.4128, $card['trip']['bus']['longitude'], 0.0001);
        $this->assertNotNull($card['trip']['bus']['last_ping_at']);
    }

    /**
     * ⚠ ENTERPRISE L29 — the afternoon map closes for THIS family the moment
     * their child is handed over, while the bus keeps running for everyone
     * else. And when it closes, the coordinates are OMITTED from the payload
     * rather than hidden by the client.
     */
    public function test_a_handover_closes_that_familys_map_and_nobody_elses(): void
    {
        $trip = $this->startTrip($this->afternoon);

        $rows = SchoolTripChild::with('child.guardians')
            ->where('trip_id', $trip->id)->get()
            ->filter(fn ($r) => $r->child->guardians->isNotEmpty())
            ->values();

        $mine = $rows[0];
        $theirs = $rows[1];

        SchoolTripChild::whereIn('id', [$mine->id, $theirs->id])
            ->update(['status' => 'boarded', 'boarded_at' => now()]);

        Sanctum::actingAs($this->attendant, ['*']);

        $this->postJson("/api/fleet/trips/{$trip->id}/ping", [
            'latitude' => 17.42, 'longitude' => 78.42,
        ])->assertOk();

        // Hand over the first child with an approved face.
        $receiver = $mine->child->authorizedReceivers()->where('is_active', true)->firstOrFail();

        $this->postJson(
            "/api/fleet/trips/{$trip->id}/children/{$mine->child_id}/handover",
            ['method' => 'authorized_person', 'authorized_receiver_id' => $receiver->id],
        )->assertOk();

        // The collected child's family: map closed, coordinates GONE.
        Sanctum::actingAs($this->guardianOf($mine), ['*']);
        $card = $this->getJson("/api/parent/child/{$mine->child_id}")->json('child');

        $this->assertFalse($card['show_live_map']);
        $this->assertNull($card['trip']['bus']['latitude'],
            'A closed map must omit the position, not merely hide it.');
        $this->assertSame('alighted_to_guardian', $card['status']);

        // The next child's family: still running, still tracking.
        Sanctum::actingAs($this->guardianOf($theirs), ['*']);
        $other = $this->getJson("/api/parent/child/{$theirs->child_id}")->json('child');

        $this->assertTrue($other['show_live_map']);
        $this->assertNotNull($other['trip']['bus']['latitude']);
    }

    /**
     * ⚠ PART A7, END TO END, ACROSS TWO APPS. The code the PARENT is shown is
     * the code the ATTENDANT's endpoint accepts — and the plaintext never
     * travels to the crew's device.
     *
     * This is the one test that would catch the two apps disagreeing about a
     * child's release code, which is the failure that hands a child to a
     * stranger or strands them at a kerb.
     */
    public function test_the_code_the_parent_is_shown_is_the_code_the_attendant_can_verify(): void
    {
        $trip = $this->startTrip($this->afternoon);
        $row = $this->childOn($trip);
        $row->forceFill(['status' => 'boarded', 'boarded_at' => now()])->save();

        // 1 · The parent reads the code off their app at the kerb.
        Sanctum::actingAs($this->guardianOf($row), ['*']);

        $card = $this->getJson("/api/parent/child/{$row->child_id}")->json('child');

        $this->assertTrue($card['show_handover_code']);
        $code = $card['handover_code'];
        $this->assertMatchesRegularExpression('/^\d{4}$/', $code);

        // 2 · The attendant types it in. The crew's device never held it.
        Sanctum::actingAs($this->attendant, ['*']);

        $tripBody = $this->getJson("/api/fleet/trips/{$trip->id}")->assertOk()->content();
        $this->assertStringNotContainsString($code, $tripBody,
            'A live release code must never appear in an ops payload (PART K9).');

        $this->postJson(
            "/api/fleet/trips/{$trip->id}/children/{$row->child_id}/handover",
            ['method' => 'handover_code', 'code' => $code],
        )->assertOk();

        $this->assertSame('alighted_to_guardian', $row->refresh()->status);

        // 3 · And the family is told, on their next poll.
        Sanctum::actingAs($this->guardianOf($row), ['*']);

        $after = $this->getJson("/api/parent/child/{$row->child_id}")->json('child');

        $this->assertSame('alighted_to_guardian', $after['status']);
        $this->assertFalse($after['show_handover_code'],
            'A code left on screen after handover is just noise.');
    }

    /** A morning "not at stop" reaches the family as an absence, not silence. */
    public function test_not_at_stop_reaches_the_family(): void
    {
        $trip = $this->startTrip($this->morning);
        $row = $this->childOn($trip);

        Sanctum::actingAs($this->attendant, ['*']);

        $this->postJson("/api/fleet/trips/{$trip->id}/stops/{$row->stop_id}/reach")->assertOk();

        \App\Models\SchoolStopArrival::where('trip_id', $trip->id)
            ->where('stop_id', $row->stop_id)
            ->update(['arrived_at' => now()->subSeconds(600)]);

        $this->postJson("/api/fleet/trips/{$trip->id}/children/{$row->child_id}/not-at-stop")
            ->assertOk();

        Sanctum::actingAs($this->guardianOf($row), ['*']);

        $card = $this->getJson("/api/parent/child/{$row->child_id}")->json('child');

        $this->assertSame('not_at_stop', $card['status']);
        $this->assertTrue($card['absent'], 'The guardians must be told, not left guessing.');
        $this->assertFalse($card['show_live_map'],
            'A child who never boarded has no bus to follow.');
    }

    /** The whole morning run, as the family sees it afterwards (PART D). */
    public function test_the_journey_record_is_written_by_the_bus(): void
    {
        Storage::fake('local');

        $trip = $this->startTrip($this->morning);
        $row = $this->childOn($trip);

        Sanctum::actingAs($this->attendant, ['*']);

        $this->postJson("/api/fleet/trips/{$trip->id}/children/{$row->child_id}/board")->assertOk();

        SchoolTripChild::where('trip_id', $trip->id)
            ->where('id', '!=', $row->id)->update(['status' => 'absent']);

        $this->postJson("/api/fleet/trips/{$trip->id}/head-count", ['counted' => 1])
            ->assertOk()->assertJsonPath('matched', true);

        $this->postJson("/api/fleet/trips/{$trip->id}/disembark")->assertOk();

        $school = $trip->school;

        $this->post("/api/fleet/trips/{$trip->id}/sweep", [
            'photo' => UploadedFile::fake()->image('aisle.jpg'),
            'latitude' => (float) $school->latitude,
            'longitude' => (float) $school->longitude,
        ], ['Accept' => 'application/json'])->assertOk();

        $this->postJson("/api/fleet/trips/{$trip->id}/complete")->assertOk();

        Sanctum::actingAs($this->guardianOf($row), ['*']);

        $days = $this->getJson("/api/parent/child/{$row->child_id}/journey")->json('days');
        $day = collect($days)->firstWhere('service_date', $this->serviceDate);

        $this->assertNotNull($day, 'The day the bus ran must appear in the journey record.');

        $morning = collect($day['trips'])->firstWhere('direction', 'Morning');

        $this->assertSame('arrived_at_school', $morning['status']);
        $this->assertNotNull($morning['boarded_at']);
        $this->assertNotNull($morning['arrived_at_school_at']);
    }

    /* ================================================================= */
    /* Fleet → Layer 2 · the dashboard and the Control Tower              */
    /* ================================================================= */

    /** The ops user the dashboard tests sign in as. */
    private function opsUser(): User
    {
        return User::where('role', 'zippi_admin')->firstOrFail();
    }

    /**
     * ⚠ The live board is the Control Tower. A boarding marked on the bus is on
     * it at the next poll, because both read `school_trip_children`.
     */
    public function test_a_boarding_marked_on_the_bus_reaches_the_live_board(): void
    {
        $trip = $this->startTrip($this->morning);
        $row = $this->childOn($trip);

        Sanctum::actingAs($this->attendant, ['*']);

        $this->postJson("/api/fleet/trips/{$trip->id}/children/{$row->child_id}/board")->assertOk();
        $this->postJson("/api/fleet/trips/{$trip->id}/ping", [
            'latitude' => 17.43, 'longitude' => 78.41, 'speed_kmph' => 30,
        ])->assertOk();

        $data = $this->actingAs($this->opsUser(), 'web')
            ->getJson('/live/data')->assertOk()->json();

        $mine = collect($data['trips'])->firstWhere('trip_id', $trip->id);

        $this->assertNotNull($mine, 'The running trip must be on the live board.');
        $this->assertSame('started', $mine['status']);

        // The count the ops person is watching is the attendant's tap.
        $this->assertSame(1, (int) $mine['progress']['on_board']);

        // And the position the crew's device just sent.
        $this->assertEqualsWithDelta(17.43, (float) $mine['bus']['latitude'], 0.0001);
        $this->assertSame(30, (int) $mine['bus']['speed_kmph']);

        // ⚠ The board's invariant flags are the MODEL's, not a second opinion.
        // Asserting equality rather than a literal is the point: if the live
        // board ever computed "is a child unaccounted for" differently from
        // SchoolTrip, a trip could look clean on one surface and blocked on the
        // other, and the crew and the ops desk would be arguing about a child.
        $this->assertSame($trip->fresh()->sweepPending(), $mine['flags']['sweep_pending']);
        $this->assertTrue($mine['flags']['unaccounted_child'],
            'A started trip with pending children is an open exception.');
    }

    /**
     * ⚠ PART K9 — the crew's raw phone number never reaches an ops payload,
     * and neither does a child's live release code.
     */
    public function test_the_live_board_still_masks_phones_after_a_fleet_write(): void
    {
        $trip = $this->startTrip($this->morning);
        $row = $this->childOn($trip);

        Sanctum::actingAs($this->attendant, ['*']);
        $this->postJson("/api/fleet/trips/{$trip->id}/children/{$row->child_id}/board")->assertOk();

        $body = $this->actingAs($this->opsUser(), 'web')->getJson('/live/data')->assertOk()->content();

        $this->assertStringNotContainsString($this->attendant->phone, $body);
        $this->assertDoesNotMatchRegularExpression('/\+9198\d{8}/', $body);
    }

    /**
     * ⚠ INVARIANT #4 — an SOS raised on the bus lands on the exception queue as
     * a critical incident somebody has to close.
     */
    public function test_an_sos_from_the_bus_lands_on_the_control_tower(): void
    {
        $trip = $this->startTrip($this->morning);

        Sanctum::actingAs($this->attendant, ['*']);

        $this->postJson("/api/fleet/trips/{$trip->id}/sos", [
            'type' => 'medical', 'latitude' => 17.42, 'longitude' => 78.42,
        ])->assertOk();

        $this->assertDatabaseHas('school_incidents', [
            'trip_id' => $trip->id,
            'incident_type' => 'sos_medical',
            'severity' => 'critical',
            'is_drill' => false,
            'closed_at' => null,
        ]);

        // And the ops page renders without blowing up on the new row.
        $this->actingAs($this->opsUser(), 'web')->get('/live')->assertOk();
    }

    /** PART R3 — overspeed from the driver's device becomes an exception row. */
    public function test_overspeed_from_the_bus_raises_an_exception_row(): void
    {
        $trip = $this->startTrip($this->morning);
        $limit = (int) $trip->school->max_speed_kmph;

        Sanctum::actingAs($this->attendant, ['*']);

        $this->postJson("/api/fleet/trips/{$trip->id}/ping", [
            'latitude' => 17.42, 'longitude' => 78.42, 'speed_kmph' => $limit + 15,
        ])->assertOk();

        $this->assertDatabaseHas('school_trip_events', [
            'trip_id' => $trip->id,
            'event_type' => 'overspeed',
        ]);

        // ⚠ Not one row per ping. A bus over the limit for two minutes is one
        // exception to look at, not forty.
        $this->postJson("/api/fleet/trips/{$trip->id}/ping", [
            'latitude' => 17.42, 'longitude' => 78.42, 'speed_kmph' => $limit + 16,
        ])->assertOk();

        $this->assertSame(1, \App\Models\SchoolTripEvent::where('trip_id', $trip->id)
            ->where('event_type', 'overspeed')->count());
    }

    /**
     * ⚠ The dashboard's own completion gate and the Fleet API's are the SAME
     * predicate. If they ever diverged, a trip could be closed on one surface
     * while a child was still aboard according to the other.
     */
    public function test_both_surfaces_agree_about_who_is_unaccounted(): void
    {
        $trip = $this->startTrip($this->afternoon);
        $row = $this->childOn($trip);

        SchoolTripChild::where('trip_id', $trip->id)->update(['status' => 'absent']);
        $row->forceFill(['status' => 'boarded', 'boarded_at' => now()])->save();

        $trip->load('tripChildren');

        // Layer 2's helper…
        $this->assertSame([$row->child_id], $trip->unaccountedChildIds());

        // …and Layer 3's refusal name the same child.
        $trip->forceFill(['sweep_verified_at' => now(), 'sweep_photo_path' => 'x.jpg'])->save();

        Sanctum::actingAs($this->attendant, ['*']);

        $res = $this->postJson("/api/fleet/trips/{$trip->id}/complete")->assertStatus(422);

        $this->assertSame([$row->child_id], $res->json('child_ids'));
    }
}
