<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\SchoolAdminAuditLog;
use App\Models\SchoolCalendar;
use App\Models\SchoolChildAbsence;
use App\Models\SchoolTrip;
use App\Models\SchoolTripChild;
use App\Services\SchoolCalendarService;
use Database\Seeders\PhoenixGreensSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * The four Critical Safety Invariants and the PART L1 date rule.
 * These outrank every other behaviour in the spec, so they get tests.
 */
class SafetyInvariantsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PhoenixGreensSeeder::class);
    }

    /** PART L1 — the enterprise bug that made undo-leave delete zero rows. */
    public function test_date_columns_are_raw_strings_not_carbon(): void
    {
        $trip = SchoolTrip::first();
        $this->assertIsString($trip->service_date,
            'service_date must stay a raw Y-m-d string — a date cast shifts IST dates back one day (PART L1).');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $trip->service_date);

        $absence = SchoolChildAbsence::first();
        $this->assertIsString($absence->service_date);

        $cal = SchoolCalendar::first();
        $this->assertIsString($cal->date);
    }

    /** The predicate that broke in enterprise: match a row by its raw date string. */
    public function test_absence_rows_are_findable_by_raw_date_string(): void
    {
        $absence = SchoolChildAbsence::first();

        $found = SchoolChildAbsence::where('child_id', $absence->child_id)
            ->where('service_date', $absence->service_date)
            ->where('direction', $absence->direction)
            ->count();

        $this->assertGreaterThan(0, $found,
            'An absence must be findable by (child, raw service_date, direction) — this is the undo predicate.');
    }

    /** INVARIANT #2 — a trip cannot complete while a child is unaccounted for. */
    public function test_unaccounted_children_block_trip_completion(): void
    {
        $trip = SchoolTrip::where('status', 'started')->firstOrFail();

        SchoolTripChild::where('trip_id', $trip->id)->limit(1)
            ->update(['status' => 'boarded']);

        $blocking = $trip->fresh()->load('tripChildren')->unaccountedChildIds();

        $this->assertNotEmpty($blocking,
            'A started trip with a child still on board must report that child as unaccounted.');
    }

    public function test_fully_resolved_trip_reports_no_unaccounted_children(): void
    {
        $trip = SchoolTrip::where('status', 'started')->firstOrFail();

        SchoolTripChild::where('trip_id', $trip->id)
            ->whereNotIn('status', ['absent'])
            ->update(['status' => 'arrived_at_school']);

        $this->assertEmpty($trip->fresh()->load('tripChildren')->unaccountedChildIds());
    }

    /** INVARIANT #3 — the sweep is blocking. */
    public function test_started_trip_without_sweep_is_flagged_pending(): void
    {
        $trip = SchoolTrip::where('status', 'started')->firstOrFail();

        $this->assertNull($trip->sweep_verified_at);
        $this->assertTrue($trip->sweepPending(),
            'A started trip with no sweep must report sweepPending() — it blocks /trip-complete.');
    }

    public function test_seeded_force_completed_trip_is_visible_as_sweep_missing(): void
    {
        // The seeder deliberately completes one trip without a sweep so the
        // exception queue has a real row. That must surface, not hide.
        $bad = SchoolTrip::where('status', 'completed')
            ->whereNull('sweep_verified_at')->first();

        $this->assertNotNull($bad, 'Expected one completed-without-sweep trip in the seed.');
        $this->assertFalse((bool) $bad->headcount_verified);
    }

    /** INVARIANT #1 — self-release needs BOTH consent and the grade gate. */
    public function test_self_release_requires_consent_and_minimum_grade(): void
    {
        $child = Child::with('school')->where('self_release_consent', true)->firstOrFail();
        $this->assertTrue($child->canSelfRelease());

        // Revoke consent → blocked even though the grade qualifies.
        $child->self_release_consent = false;
        $this->assertFalse($child->canSelfRelease(),
            'Without a signed consent a child must never self-release, whatever their grade.');
        $child->self_release_consent = true;

        // Grade below the school minimum → blocked even with consent.
        $young = Child::with('school')
            ->where('self_release_consent', true)->get()
            ->first(fn ($c) => (int) $c->grade < 8);
        if ($young) {
            $this->assertFalse($young->canSelfRelease(),
                'A child below self_release_min_grade must never self-release, consent or not.');
        }
    }

    /** INVARIANT #4 / PART K1 — the audit log is append-only. */
    public function test_audit_log_rejects_updates(): void
    {
        $row = SchoolAdminAuditLog::record('test_action', [], 'seeded by test');

        $this->expectException(LogicException::class);
        $row->update(['notes' => 'tampered']);
    }

    public function test_audit_log_rejects_deletes(): void
    {
        $row = SchoolAdminAuditLog::record('test_action_2', [], 'seeded by test');

        $this->expectException(LogicException::class);
        $row->delete();
    }

    /** PART C3 — the calendar drives generation and range expansion. */
    public function test_range_expansion_skips_non_school_days(): void
    {
        $school = \App\Models\School::first();
        $cal = SchoolCalendarService::for($school);

        $holiday = SchoolCalendar::where('school_id', $school->id)
            ->where('day_type', 'holiday')
            ->where('label', 'Ganesh Chaturthi')
            ->firstOrFail();

        $start = \Carbon\Carbon::parse($holiday->date)->subDays(2)->toDateString();
        $end   = \Carbon\Carbon::parse($holiday->date)->addDays(2)->toDateString();

        $out = $cal->expandRange($start, $end);

        $this->assertNotContains($holiday->date, $out['dates'],
            'A declared holiday must be skipped when expanding an absence range.');
        $this->assertArrayHasKey($holiday->date, $out['skipped']);
    }

    public function test_next_school_day_skips_weekends_and_holidays(): void
    {
        $school = \App\Models\School::first();
        $cal = SchoolCalendarService::for($school);

        $next = $cal->nextSchoolDay($cal->today());

        $this->assertTrue($cal->isSchoolDay($next),
            '"Next school day" must land on an actual school day, never a weekend or holiday.');
    }

    /** PART K9 — ops-facing payloads never carry a raw phone number. */
    public function test_live_board_payload_masks_phone_numbers(): void
    {
        $user = \App\Models\User::where('role', 'zippi_admin')->firstOrFail();

        $json = $this->actingAs($user)->getJson('/live/data')->assertOk()->json();

        $blob = json_encode($json);
        $this->assertStringNotContainsString('+9198', $blob,
            'Raw phone numbers must never appear in an ops-facing payload (PART K9).');
        $this->assertStringContainsString('phone_masked', $blob);
    }
}
