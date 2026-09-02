<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\Guardian;
use App\Models\Otp;
use App\Models\School;
use App\Models\SchoolCalendar;
use App\Models\SchoolChildAbsence;
use App\Models\SchoolTrip;
use App\Models\SchoolTripChild;
use App\Models\User;
use App\Services\OtpService;
use App\Services\SchoolCalendarService;
use App\Services\SchoolTripGenerator;
use Carbon\Carbon;
use Database\Seeders\PhoenixGreensSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Zippi Parent (Layer 1) — PART H4 auth, authorization and the live-map rules.
 *
 * The authorization tests here are the important ones. A bug in the child
 * lookup hands any parent the live GPS position of any child in the school.
 */
class ParentAppTest extends TestCase
{
    use RefreshDatabase;

    private Guardian $guardian;
    private Child $child;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PhoenixGreensSeeder::class);

        $today = Carbon::now('Asia/Kolkata')->toDateString();

        // ⚠ Force TODAY to be an ordinary school day before generating.
        // These tests read "today" from the card builder, and a suite run on a
        // Saturday would otherwise find no trips at all — the calendar falls
        // back to weekend => holiday and the generator correctly produces
        // nothing. Pinning the day keeps the fixture independent of when the
        // suite happens to run.
        SchoolCalendar::updateOrCreate(
            ['school_id' => School::firstOrFail()->id, 'date' => $today],
            ['day_type' => 'school', 'label' => null,
             'morning_trips_run' => true, 'afternoon_trips_run' => true]
        );

        // The seeder writes MORNING trips only (a mid-morning demo state). The
        // afternoon half of the app — handover codes, the L29 map-closing rule
        // — needs both directions, so generate today properly.
        app(SchoolTripGenerator::class)->generate(School::firstOrFail(), $today);

        // A child with a trip row in BOTH directions today, and a guardian on it.
        $this->child = Child::whereHas('tripRows.trip',
                fn ($q) => $q->where('service_date', $today)->where('direction', 'Morning'))
            ->whereHas('tripRows.trip',
                fn ($q) => $q->where('service_date', $today)->where('direction', 'Afternoon'))
            ->whereHas('guardians')
            ->firstOrFail();

        $this->guardian = $this->child->guardians()->firstOrFail();
    }

    private function signIn(): static
    {
        $this->actingAs($this->guardian, 'guardian');

        return $this;
    }

    /* ---------------- PART P — OTP ---------------- */

    /** PART P1 — codes are random and stored hashed, never in plaintext. */
    public function test_otp_is_random_and_stored_hashed(): void
    {
        $this->post(route('parent.code'), ['phone' => $this->guardian->phone]);

        $otp = Otp::latest('id')->firstOrFail();

        $this->assertNotNull($otp->code_hash);
        $this->assertDatabaseMissing('otps', ['code_hash' => '1234']);
        // Hash, not the code itself.
        $this->assertNotSame(4, strlen($otp->code_hash));
    }

    /** PART P3 — a wrong code says how many attempts remain. */
    public function test_a_wrong_code_reports_attempts_remaining(): void
    {
        $phone = $this->guardian->phone;
        app(OtpService::class)->send($phone);

        $result = app(OtpService::class)->verify($phone, '0000');

        $this->assertFalse($result['ok']);
        $this->assertSame(4, $result['attempts_remaining']);
        $this->assertStringContainsString('4 attempts left', $result['message']);
    }

    /** PART P3 — 5 wrong attempts locks the code for a minute. */
    public function test_five_wrong_attempts_locks_the_code(): void
    {
        $phone = $this->guardian->phone;
        $svc = app(OtpService::class);
        $svc->send($phone);

        for ($i = 0; $i < 5; $i++) {
            $svc->verify($phone, '0000');
        }

        $result = $svc->verify($phone, '0000');

        $this->assertFalse($result['ok']);
        $this->assertSame(429, $result['status']);
        $this->assertTrue(Otp::latest('id')->firstOrFail()->isLocked());
    }

    /** PART P4 — 3 sends per 2 minutes, then a wait with the seconds named. */
    public function test_the_fourth_send_in_two_minutes_is_throttled(): void
    {
        $phone = $this->guardian->phone;
        $svc = app(OtpService::class);

        RateLimiter::clear('otp-send:guardian:' . $phone);

        $this->assertTrue($svc->send($phone)['ok']);
        $this->assertTrue($svc->send($phone)['ok']);
        $this->assertTrue($svc->send($phone)['ok']);

        $fourth = $svc->send($phone);

        $this->assertFalse($fourth['ok']);
        $this->assertArrayHasKey('retry_after', $fourth);
    }

    /** Two live codes at once means a stale SMS still logs someone in. */
    public function test_issuing_a_new_code_supersedes_the_previous_one(): void
    {
        $phone = $this->guardian->phone;
        $svc = app(OtpService::class);

        $svc->send($phone);
        $first = Otp::latest('id')->firstOrFail();

        $svc->send($phone);

        $this->assertNotNull($first->fresh()->consumed_at,
            'The previous code must be consumed when a new one is issued.');
    }

    /**
     * An unknown number must not be distinguishable from a known one at the
     * send step. Revealing which numbers are parents at a school is a
     * child-safety leak, not merely an information leak.
     */
    public function test_an_unknown_phone_does_not_reveal_itself(): void
    {
        $known = $this->post(route('parent.code'), ['phone' => $this->guardian->phone]);
        $unknown = $this->post(route('parent.code'), ['phone' => '+919999000011']);

        $known->assertRedirect(route('parent.verify.form'));
        $unknown->assertRedirect(route('parent.verify.form'));
    }

    /** The full happy path, through HTTP. */
    public function test_a_guardian_can_sign_in_with_a_valid_code(): void
    {
        $phone = $this->guardian->phone;

        $this->post(route('parent.code'), ['phone' => $phone])
             ->assertRedirect(route('parent.verify.form'));

        // Read the code the way the parent would — by knowing it. The service
        // hashes it, so reissue one we control.
        $otp = Otp::latest('id')->firstOrFail();
        $otp->update(['code_hash' => Hash::make('4321')]);

        $this->post(route('parent.verify'), ['code' => '4321'])
             ->assertRedirect(route('parent.dashboard'));

        $this->assertAuthenticatedAs($this->guardian, 'guardian');
        $this->assertNotNull($this->guardian->fresh()->last_login_at);
    }

    /* ---------------- authorization ---------------- */

    /** ⚠ The one that matters: no parent may open another family's child. */
    public function test_a_guardian_cannot_open_another_familys_child(): void
    {
        $mine = $this->guardian->children->pluck('id');
        $other = Child::whereNotIn('id', $mine)->firstOrFail();

        $this->signIn();

        $this->get(route('parent.child', $other->id))->assertNotFound();
        $this->get(route('parent.child.data', $other->id))->assertNotFound();
        $this->get(route('parent.child.journey', $other->id))->assertNotFound();
        $this->post(route('parent.child.absence', $other->id), [
            'service_date' => Carbon::now('Asia/Kolkata')->toDateString(),
            'direction' => 'Both',
        ])->assertNotFound();
    }

    /** A guest is sent to the PARENT login, not the ops one. */
    public function test_a_guest_is_redirected_to_the_parent_login(): void
    {
        $this->get(route('parent.dashboard'))->assertRedirect(route('parent.login'));
    }

    /** The two identities are separate: an ops session is not a parent session. */
    public function test_an_ops_user_session_does_not_grant_parent_access(): void
    {
        $this->actingAs(User::firstOrFail());     // default 'web' guard

        $this->get(route('parent.dashboard'))->assertRedirect(route('parent.login'));
    }

    /** ...and the reverse: a guardian cannot reach the ops dashboard. */
    public function test_a_guardian_session_does_not_grant_ops_access(): void
    {
        $this->signIn();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    /* ---------------- the live map (enterprise L29) ---------------- */

    private function todaysRow(string $direction): SchoolTripChild
    {
        $today = Carbon::now('Asia/Kolkata')->toDateString();

        return SchoolTripChild::where('child_id', $this->child->id)
            ->whereHas('trip', fn ($q) => $q->where('service_date', $today)
                                             ->where('direction', $direction))
            ->firstOrFail();
    }

    public function test_the_map_is_open_while_the_trip_is_running(): void
    {
        $row = $this->todaysRow('Morning');
        $row->trip->update(['status' => 'started']);
        $row->update(['status' => 'boarded', 'boarded_at' => now()]);

        $data = $this->signIn()->getJson(route('parent.child.data', $this->child->id))->json();

        $this->assertTrue($data['show_live_map']);
        $this->assertTrue($data['has_active_trip']);
    }

    /** Morning: the map closes once the child is inside the school. */
    public function test_the_map_closes_when_a_morning_child_reaches_school(): void
    {
        $row = $this->todaysRow('Morning');
        $row->trip->update(['status' => 'started']);
        $row->update(['status' => 'arrived_at_school']);

        $data = $this->signIn()->getJson(route('parent.child.data', $this->child->id))->json();

        $this->assertFalse($data['show_live_map']);
    }

    /**
     * Afternoon: the map closes for THIS family at handover, even though the
     * bus is still running the rest of the route.
     */
    public function test_the_map_closes_for_this_family_at_handover(): void
    {
        $row = $this->todaysRow('Afternoon');
        $row->trip->update(['status' => 'started']);
        $row->update(['status' => 'alighted_to_guardian', 'alighted_at' => now()]);

        $data = $this->signIn()->getJson(route('parent.child.data', $this->child->id))->json();

        $this->assertFalse($data['show_live_map'],
            'The map must close for a family whose child is already handed over.');
        $this->assertTrue($data['has_active_trip'], 'The trip itself is still running.');
    }

    /** ⚠ When the map is closed, the bus position must not ship at all. */
    public function test_the_bus_position_is_withheld_once_the_map_closes(): void
    {
        $row = $this->todaysRow('Afternoon');
        $row->trip->update(['status' => 'started']);
        $row->trip->bus->update(['latitude' => 17.45, 'longitude' => 78.34, 'last_ping_at' => now()]);
        $row->update(['status' => 'alighted_to_guardian', 'alighted_at' => now()]);

        $data = $this->signIn()->getJson(route('parent.child.data', $this->child->id))->json();

        $this->assertNull($data['trip']['bus']['latitude']);
        $this->assertNull($data['trip']['bus']['longitude']);
    }

    /* ---------------- PART K9 — masking ---------------- */

    /**
     * PART K9 — the CREW's numbers are masked.
     *
     * The school's own office number is deliberately present (it powers the
     * "Call school" button) and the guardian's own number is their own, so this
     * asserts on the crew objects rather than scanning the whole body for
     * anything phone-shaped.
     */
    public function test_the_parent_payload_never_carries_a_raw_crew_phone(): void
    {
        $row = $this->todaysRow('Morning');
        $row->trip->update(['status' => 'started']);

        $data = $this->signIn()
            ->getJson(route('parent.child.data', $this->child->id))
            ->json();

        foreach (['driver', 'attendant'] as $role) {
            $person = $data['trip'][$role] ?? null;
            if (! $person) continue;

            $this->assertArrayNotHasKey('phone', $person,
                "A raw {$role} phone reached the parent app (PART K9).");
            $this->assertStringStartsWith('•', $person['phone_masked']);
            $this->assertDoesNotMatchRegularExpression('/\d{7,}/',
                $person['phone_masked']);
        }
    }

    /* ---------------- PART A7 — handover code ---------------- */

    public function test_the_handover_code_is_stored_hashed_and_verifies(): void
    {
        $code = $this->child->todaysHandoverCode();

        $this->assertMatchesRegularExpression('/^\d{4}$/', $code);

        $fresh = $this->child->fresh();
        $this->assertNotSame($code, $fresh->handover_code_hash);
        $this->assertTrue($fresh->verifyHandoverCode($code));
        $this->assertFalse($fresh->verifyHandoverCode('0000'));
    }

    /** The same code all day — a code that changes on refresh is unusable. */
    public function test_the_handover_code_is_stable_within_the_day(): void
    {
        $first = $this->child->todaysHandoverCode();
        $second = $this->child->fresh()->todaysHandoverCode();

        $this->assertSame($first, $second);
    }

    /** ⚠ PART L1 — handover_code_date stays a raw Y-m-d string. */
    public function test_the_handover_code_date_is_a_raw_string(): void
    {
        $this->child->todaysHandoverCode();

        $this->assertIsString($this->child->fresh()->handover_code_date);
    }

    /* ---------------- PART A1/A2 — absence ---------------- */

    private function nextSchoolDay(): string
    {
        return SchoolCalendarService::for($this->child->school)->nextSchoolDay();
    }

    public function test_a_parent_can_mark_their_child_absent_both_ways(): void
    {
        $date = $this->nextSchoolDay();

        $this->signIn()->post(route('parent.child.absence', $this->child->id), [
            'service_date' => $date,
            'direction' => 'Both',
            'reason_code' => 'sick',
        ])->assertSessionHasNoErrors();

        $rows = SchoolChildAbsence::where('child_id', $this->child->id)
            ->where('service_date', $date)->get();

        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(['Morning', 'Afternoon'], $rows->pluck('direction')->all());
        $this->assertSame('parent', $rows->first()->marked_by);
    }

    /** BF3 — never throttle an action taken under pressure; make it idempotent. */
    public function test_marking_absent_twice_does_not_duplicate(): void
    {
        $date = $this->nextSchoolDay();

        foreach ([1, 2] as $ignored) {
            $this->signIn()->post(route('parent.child.absence', $this->child->id), [
                'service_date' => $date, 'direction' => 'Both',
            ]);
        }

        $this->assertSame(2, SchoolChildAbsence::where('child_id', $this->child->id)
            ->where('service_date', $date)->count());
    }

    /** ⚠ PART L1 — the enterprise bug: undo matching zero rows. */
    public function test_undoing_an_absence_actually_deletes_the_row(): void
    {
        $date = $this->nextSchoolDay();

        $this->signIn()->post(route('parent.child.absence', $this->child->id), [
            'service_date' => $date, 'direction' => 'Both',
        ]);

        $this->assertSame(2, SchoolChildAbsence::where('child_id', $this->child->id)
            ->where('service_date', $date)->count());

        $this->signIn()->delete(route('parent.child.absence.undo', $this->child->id), [
            'service_date' => $date,
        ]);

        $this->assertSame(0, SchoolChildAbsence::where('child_id', $this->child->id)
            ->where('service_date', $date)->count(),
            'Undo deleted zero rows — the PART L1 date-cast bug is back.');
    }

    public function test_a_past_day_cannot_be_changed(): void
    {
        $yesterday = Carbon::now('Asia/Kolkata')->subDay()->toDateString();

        $this->signIn()->post(route('parent.child.absence', $this->child->id), [
            'service_date' => $yesterday, 'direction' => 'Both',
        ])->assertSessionHasErrors('service_date');
    }

    public function test_a_holiday_cannot_be_marked_absent(): void
    {
        $date = $this->nextSchoolDay();

        \App\Models\SchoolCalendar::updateOrCreate(
            ['school_id' => $this->child->school_id, 'date' => $date],
            ['day_type' => 'holiday', 'label' => 'Test', 'morning_trips_run' => false,
             'afternoon_trips_run' => false]
        );

        $this->signIn()->post(route('parent.child.absence', $this->child->id), [
            'service_date' => $date, 'direction' => 'Both',
        ])->assertSessionHasErrors('service_date');
    }

    /* ---------------- screens ---------------- */

    public function test_the_family_screens_render(): void
    {
        $this->signIn();

        $this->get(route('parent.dashboard'))->assertOk()->assertSee($this->child->name);
        $this->get(route('parent.child', $this->child->id))->assertOk();
        $this->get(route('parent.child.journey', $this->child->id))->assertOk();
    }

    /** PART D — the journey record is the killer feature; it must show events. */
    public function test_the_journey_screen_lists_a_recorded_boarding(): void
    {
        $row = $this->todaysRow('Morning');
        $row->update(['status' => 'boarded', 'boarded_at' => now()]);

        $this->signIn()
            ->get(route('parent.child.journey', $this->child->id))
            ->assertOk()
            ->assertSee('Boarded');
    }
}
