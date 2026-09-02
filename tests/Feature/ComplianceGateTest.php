<?php

namespace Tests\Feature;

use App\Models\Bus;
use App\Models\School;
use App\Models\SchoolStaff;
use App\Models\SchoolTrip;
use App\Services\SchoolTripGenerator;
use Carbon\Carbon;
use Database\Seeders\PhoenixGreensSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PART K15 — compliance gates ASSIGNMENT, at generation time.
 *
 * The compliance board used to only report. A bus with lapsed insurance and a
 * driver with no police verification were both still crewed onto trips, while
 * the card above them read "must be resolved before the vehicle runs" — an
 * instruction to a human, not a rule the system applied.
 *
 * ⚠ The gate is at GENERATION, not at trip start. 02:30 is the last moment a
 * school can still swap a vehicle before children are at a stop; refusing a
 * loaded bus at the kerb over a lapsed PUC would be worse than the lapse.
 */
class ComplianceGateTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private string $date;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PhoenixGreensSeeder::class);

        $this->school = School::firstOrFail();

        // A weekday far enough out that the seeder wrote no calendar row for it.
        $cursor = Carbon::parse(Carbon::now('Asia/Kolkata')->toDateString())->addDays(30);
        while ($cursor->isWeekend()) {
            $cursor->addDay();
        }
        $this->date = $cursor->toDateString();
    }

    private function generate(): void
    {
        app(SchoolTripGenerator::class)->generate($this->school, $this->date, true);
    }

    public function test_a_bus_with_expired_insurance_is_not_assigned(): void
    {
        $bus = Bus::where('school_id', $this->school->id)->firstOrFail();
        $bus->forceFill(['insurance_expiry' => Carbon::today()->subDays(6)->toDateString()])->save();

        $this->generate();

        $this->assertSame(0,
            SchoolTrip::where('service_date', $this->date)->where('bus_id', $bus->id)->count(),
            'A bus whose insurance lapsed six days ago was still put on a trip.');
    }

    public function test_a_document_that_was_never_recorded_blocks_too(): void
    {
        // ⚠ THE HOLE THIS CLOSES. expiringDocuments() skips a null column, so a
        // bus with NO insurance date read as "OK" — greener than one that
        // lapsed yesterday. Clearing a date must never be the way around the gate.
        $bus = Bus::where('school_id', $this->school->id)->firstOrFail();
        $bus->forceFill(['insurance_expiry' => null])->save();

        $this->assertContains('Insurance missing', $bus->complianceBlockers());
        $this->assertSame('expired', $bus->complianceStatus());

        $this->generate();

        $this->assertSame(0,
            SchoolTrip::where('service_date', $this->date)->where('bus_id', $bus->id)->count());
    }

    public function test_a_driver_without_police_verification_is_not_crewed(): void
    {
        $driver = SchoolStaff::where('school_id', $this->school->id)
            ->where('role', 'driver')->firstOrFail();

        $driver->forceFill(['police_verification_status' => 'pending'])->save();

        $this->generate();

        $this->assertSame(0,
            SchoolTrip::where('service_date', $this->date)->where('driver_id', $driver->id)->count(),
            'An adult with no background check on file was crewed onto a bus of children.');
    }

    public function test_the_trip_still_exists_and_the_run_says_why(): void
    {
        // Withholding the RESOURCE, not the trip. A route that silently vanishes
        // from the board is a route nobody notices is missing.
        $bus = Bus::where('school_id', $this->school->id)->firstOrFail();
        $bus->forceFill(['permit_expiry' => Carbon::today()->subDay()->toDateString()])->save();

        $run = app(SchoolTripGenerator::class)
            ->generate($this->school, $this->date, true);

        $this->assertGreaterThan(0, SchoolTrip::where('service_date', $this->date)->count(),
            'Compliance withheld the bus but should never have withheld the trip.');

        $warnings = json_encode($run->warnings);
        $this->assertStringContainsString('bus_non_compliant', $warnings);
        $this->assertStringContainsString('Permit expired', $warnings,
            'The warning must name the document, not just say "non compliant".');
    }

    public function test_a_document_merely_expiring_soon_still_runs(): void
    {
        // Stopping a bus over a document that is still valid would teach a
        // school to ignore the board.
        $bus = Bus::where('school_id', $this->school->id)->firstOrFail();
        $bus->forceFill(['puc_expiry' => Carbon::today()->addDays(12)->toDateString()])->save();

        $this->assertEmpty($bus->complianceBlockers());
        $this->assertSame('expiring', $bus->complianceStatus());

        $this->generate();

        $this->assertGreaterThan(0,
            SchoolTrip::where('service_date', $this->date)->where('bus_id', $bus->id)->count(),
            'A bus with 12 days left on its PUC is legal and must still run.');
    }
}
