<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\Guardian;
use App\Models\Otp;
use App\Models\School;
use App\Models\SchoolCalendar;
use App\Models\SchoolChildAbsence;
use App\Models\SchoolTripChild;
use App\Services\SchoolCalendarService;
use App\Services\SchoolTripGenerator;
use Carbon\Carbon;
use Database\Seeders\PhoenixGreensSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The mobile API the Flutter app consumes (PART P7 / H4).
 *
 * The contract tests matter as much as the happy paths: the app renders the
 * server's `message` verbatim and reads `attempts_remaining` to tell a parent
 * how many tries are left, so the SHAPE of a failure is part of the product.
 */
class ParentApiTest extends TestCase
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
        // nothing. Pinning the day here keeps the fixture independent of when
        // the suite happens to run.
        SchoolCalendar::updateOrCreate(
            ['school_id' => School::firstOrFail()->id, 'date' => $today],
            ['day_type' => 'school', 'label' => null,
             'morning_trips_run' => true, 'afternoon_trips_run' => true]
        );

        app(SchoolTripGenerator::class)->generate(School::firstOrFail(), $today);

        $this->child = Child::whereHas('tripRows.trip',
                fn ($q) => $q->where('service_date', $today)->where('direction', 'Afternoon'))
            ->whereHas('guardians')
            ->firstOrFail();

        $this->guardian = $this->child->guardians()->firstOrFail();
    }

    private function asGuardian(): static
    {
        Sanctum::actingAs($this->guardian, ['*'], 'sanctum');

        return $this;
    }

    /* ---------------- auth ---------------- */

    public function test_the_otp_endpoint_issues_a_code(): void
    {
        $this->postJson('/api/parent/otp', ['phone' => $this->guardian->phone])
            ->assertOk()
            ->assertJson(['status' => true]);

        $this->assertDatabaseCount('otps', 1);
    }

    /** Verify returns a bearer token the app stores in the Keychain. */
    public function test_verifying_a_code_returns_a_bearer_token(): void
    {
        $this->postJson('/api/parent/otp', ['phone' => $this->guardian->phone]);

        Otp::latest('id')->firstOrFail()->update(['code_hash' => Hash::make('4321')]);

        $response = $this->postJson('/api/parent/otp/verify', [
            'phone' => $this->guardian->phone,
            'code' => '4321',
            'device_name' => 'Android',
        ])->assertOk()->assertJsonStructure(['status', 'token', 'guardian' => ['id', 'name', 'phone']]);

        $this->assertNotEmpty($response->json('token'));
        $this->assertSame(1, $this->guardian->fresh()->tokens()->count());
    }

    /** Re-login on the same device replaces the token rather than stacking. */
    public function test_signing_in_twice_on_one_device_leaves_one_token(): void
    {
        foreach ([1, 2] as $ignored) {
            $this->postJson('/api/parent/otp', ['phone' => $this->guardian->phone]);
            Otp::latest('id')->firstOrFail()->update(['code_hash' => Hash::make('4321')]);
            $this->postJson('/api/parent/otp/verify', [
                'phone' => $this->guardian->phone, 'code' => '4321', 'device_name' => 'Android',
            ])->assertOk();
        }

        $this->assertSame(1, $this->guardian->fresh()->tokens()->count());
    }

    /** ⚠ PART P7 — the wrong-code shape the app depends on. */
    public function test_a_wrong_code_returns_400_with_attempts_remaining(): void
    {
        $this->postJson('/api/parent/otp', ['phone' => $this->guardian->phone]);

        $this->postJson('/api/parent/otp/verify', [
            'phone' => $this->guardian->phone, 'code' => '0000',
        ])
            ->assertStatus(400)
            ->assertJson(['status' => false, 'attempts_remaining' => 4])
            ->assertJsonStructure(['message']);
    }

    /** PART P3 — lockout is a 429 the app can render as a wait. */
    public function test_repeated_wrong_codes_return_429(): void
    {
        $this->postJson('/api/parent/otp', ['phone' => $this->guardian->phone]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/parent/otp/verify', [
                'phone' => $this->guardian->phone, 'code' => '0000',
            ]);
        }

        $this->postJson('/api/parent/otp/verify', [
            'phone' => $this->guardian->phone, 'code' => '0000',
        ])->assertStatus(429)->assertJson(['status' => false]);
    }

    /**
     * Found on a real device: a stray keystroke made a 14-digit number, the
     * server issued a code for it, and the app said "we sent you a code" for a
     * number that cannot exist. The parent then waits for an SMS forever.
     */
    public function test_an_implausible_phone_number_is_rejected_before_any_code_is_sent(): void
    {
        $this->postJson('/api/parent/otp', ['phone' => '12345678901479'])
            ->assertStatus(422)
            ->assertJson(['status' => false])
            ->assertJsonStructure(['message']);

        $this->postJson('/api/parent/otp', ['phone' => '12345'])
            ->assertStatus(422);

        $this->assertDatabaseCount('otps', 0);

        // A normal number, and the country-code form, both still work.
        $this->postJson('/api/parent/otp', ['phone' => '1234567890'])->assertOk();
        $this->postJson('/api/parent/otp', ['phone' => '+91 98765 43210'])->assertOk();
    }

    /**
     * ⚠ THE REDESIGN BUG. The app's login screen has a fixed `+91` prefix, so
     * it began sending `+911234567890` for a guardian stored as `1234567890`.
     * The code verified, the guardian lookup missed, and the parent was told
     * "this number is not registered" for a number that plainly was.
     *
     * Every spelling of the same number must resolve to the same guardian.
     */
    public function test_a_number_signs_in_however_it_is_written(): void
    {
        $stored = $this->guardian->phone;              // as the school has it
        $digits = preg_replace('/\D/', '', $stored);
        $national = strlen($digits) > 10 ? substr($digits, -10) : $digits;

        foreach ([$stored, "+91$national", "0091$national", "0$national",
                  '+91 ' . $national] as $spelling) {
            $this->postJson('/api/parent/otp', ['phone' => $spelling])->assertOk();

            Otp::latest('id')->firstOrFail()->update(['code_hash' => Hash::make('4321')]);

            $this->postJson('/api/parent/otp/verify', [
                'phone' => $spelling, 'code' => '4321', 'device_name' => 'Android',
            ])
                ->assertOk()
                ->assertJson(['status' => true, 'guardian' => ['id' => $this->guardian->id]]);

            \Illuminate\Support\Facades\RateLimiter::clear(
                'otp-send:guardian:' . $national);
        }
    }

    /** All spellings share ONE OTP identity, so a resend supersedes properly. */
    public function test_every_spelling_shares_one_otp_identity(): void
    {
        $digits = preg_replace('/\D/', '', $this->guardian->phone);
        $national = strlen($digits) > 10 ? substr($digits, -10) : $digits;

        $this->postJson('/api/parent/otp', ['phone' => "+91$national"]);
        $this->postJson('/api/parent/otp', ['phone' => $national]);

        $this->assertSame(1, Otp::whereNull('consumed_at')->count(),
            'Two spellings produced two live codes — a stale SMS would still work.');
    }

    /** An unknown number must look identical at the send step. */
    public function test_an_unknown_number_gets_the_same_send_response(): void
    {
        $known = $this->postJson('/api/parent/otp', ['phone' => $this->guardian->phone]);
        $unknown = $this->postJson('/api/parent/otp', ['phone' => '+919999000011']);

        $this->assertSame($known->status(), $unknown->status());
        $this->assertSame($known->json('message'), $unknown->json('message'));
    }

    public function test_every_data_endpoint_requires_a_token(): void
    {
        $this->getJson('/api/parent/family-dashboard')->assertUnauthorized();
        $this->getJson('/api/parent/child/' . $this->child->id)->assertUnauthorized();
        $this->getJson('/api/parent/child/' . $this->child->id . '/journey')->assertUnauthorized();
    }

    /* ---------------- data ---------------- */

    public function test_the_family_dashboard_returns_this_guardians_children(): void
    {
        $expected = $this->guardian->children()->pluck('children.id')->all();

        $body = $this->asGuardian()->getJson('/api/parent/family-dashboard')
            ->assertOk()
            ->assertJson(['status' => true])
            ->json();

        $this->assertEqualsCanonicalizing(
            $expected,
            array_column($body['children'], 'child_id')
        );
    }

    /** ⚠ The one that matters. */
    public function test_another_familys_child_is_not_reachable(): void
    {
        $other = Child::whereNotIn('id', $this->guardian->children()->pluck('children.id'))
            ->firstOrFail();

        $this->asGuardian();

        $this->getJson('/api/parent/child/' . $other->id)->assertNotFound();
        $this->getJson('/api/parent/child/' . $other->id . '/journey')->assertNotFound();
        $this->postJson('/api/parent/child/' . $other->id . '/absence', [
            'service_date' => Carbon::now('Asia/Kolkata')->toDateString(),
            'direction' => 'Both',
        ])->assertNotFound();
    }

    /** PART A7 — the code is minted only when the server says one is warranted. */
    public function test_the_handover_code_appears_only_on_an_afternoon_trip(): void
    {
        $today = Carbon::now('Asia/Kolkata')->toDateString();

        $row = SchoolTripChild::where('child_id', $this->child->id)
            ->whereHas('trip', fn ($q) => $q->where('service_date', $today)
                                             ->where('direction', 'Afternoon'))
            ->firstOrFail();

        $row->trip->update(['status' => 'started']);

        $body = $this->asGuardian()
            ->getJson('/api/parent/child/' . $this->child->id)
            ->assertOk()->json('child');

        $this->assertTrue($body['show_handover_code']);
        $this->assertMatchesRegularExpression('/^\d{4}$/', $body['handover_code']);

        // Once handed over, both the flag and the code go away.
        $row->update(['status' => 'alighted_to_guardian', 'alighted_at' => now()]);

        $after = $this->asGuardian()
            ->getJson('/api/parent/child/' . $this->child->id)
            ->assertOk()->json('child');

        $this->assertFalse($after['show_handover_code']);
        $this->assertNull($after['handover_code']);
    }

    /** ⚠ Enterprise L29 — a closed map ships no coordinates at all. */
    public function test_the_bus_position_is_absent_once_the_map_closes(): void
    {
        $today = Carbon::now('Asia/Kolkata')->toDateString();

        $row = SchoolTripChild::where('child_id', $this->child->id)
            ->whereHas('trip', fn ($q) => $q->where('service_date', $today)
                                             ->where('direction', 'Afternoon'))
            ->firstOrFail();

        $row->trip->update(['status' => 'started']);
        $row->trip->bus->update(['latitude' => 17.45, 'longitude' => 78.34, 'last_ping_at' => now()]);

        $open = $this->asGuardian()->getJson('/api/parent/child/' . $this->child->id)
            ->json('child');

        $this->assertTrue($open['show_live_map']);
        $this->assertNotNull($open['trip']['bus']['latitude']);

        $row->update(['status' => 'alighted_to_guardian', 'alighted_at' => now()]);

        $closed = $this->asGuardian()->getJson('/api/parent/child/' . $this->child->id)
            ->json('child');

        $this->assertFalse($closed['show_live_map']);
        $this->assertNull($closed['trip']['bus']['latitude']);
        $this->assertNull($closed['trip']['bus']['longitude']);
    }

    /** PART K9 — no raw phone may reach a parent's device. */
    public function test_the_api_never_returns_a_raw_phone_for_crew(): void
    {
        $body = $this->asGuardian()
            ->get('/api/parent/family-dashboard')
            ->getContent();

        // The guardian's own number is legitimately present; a +91… crew number
        // is not. Assert on the crew objects specifically.
        $children = json_decode($body, true)['children'];

        foreach ($children as $c) {
            foreach (['driver', 'attendant'] as $role) {
                $person = $c['trip'][$role] ?? null;
                if (! $person) continue;

                $this->assertArrayNotHasKey('phone', $person);
                $this->assertStringStartsWith('•', $person['phone_masked']);
            }
        }
    }

    public function test_the_journey_endpoint_groups_by_day(): void
    {
        $today = Carbon::now('Asia/Kolkata')->toDateString();

        SchoolTripChild::where('child_id', $this->child->id)
            ->whereHas('trip', fn ($q) => $q->where('service_date', $today))
            ->first()
            ->update(['status' => 'boarded', 'boarded_at' => now()]);

        $days = $this->asGuardian()
            ->getJson('/api/parent/child/' . $this->child->id . '/journey')
            ->assertOk()->json('days');

        $this->assertNotEmpty($days);
        $this->assertArrayHasKey('service_date', $days[0]);
        $this->assertArrayHasKey('trips', $days[0]);
    }

    /* ---------------- absence ---------------- */

    public function test_a_parent_can_mark_and_undo_an_absence(): void
    {
        $date = SchoolCalendarService::for($this->child->school)->nextSchoolDay();

        $this->asGuardian()->postJson(
            '/api/parent/child/' . $this->child->id . '/absence',
            ['service_date' => $date, 'direction' => 'Both', 'reason_code' => 'sick']
        )->assertOk()->assertJson(['status' => true]);

        $this->assertSame(2, SchoolChildAbsence::where('child_id', $this->child->id)
            ->where('service_date', $date)->count());

        $this->asGuardian()->deleteJson(
            '/api/parent/child/' . $this->child->id . '/absence',
            ['service_date' => $date]
        )->assertOk();

        // ⚠ PART L1 — if this is not zero, a Carbon crept into the predicate
        // and the undo matched nothing. That is the enterprise bug verbatim.
        $this->assertSame(0, SchoolChildAbsence::where('child_id', $this->child->id)
            ->where('service_date', $date)->count());
    }

    /**
     * PART M14 — an absence marked AFTER the roster was generated must reach
     * `school_trip_children`, not just the absence table.
     *
     * ⚠ THE REGRESSION. The generator drops absent children at 02:30; a parent
     * marks absence at 06:40. Before this was fixed the absence row existed and
     * the fleet roster still listed the child as a rider to board — the
     * attendant waited at a kerb for a child who was at home, and
     * `FleetApiController::absenceFor()` never fired because it keys off the
     * roster row's status, not the absence table.
     */
    public function test_an_absence_marked_after_generation_reaches_the_fleet_roster(): void
    {
        $date = SchoolCalendarService::for($this->child->school)->nextSchoolDay();

        // The roster for that day already exists and lists the child.
        app(SchoolTripGenerator::class)->generate($this->child->school, $date);

        $rosterRow = fn () => SchoolTripChild::where('child_id', $this->child->id)
            ->whereIn('trip_id', \App\Models\SchoolTrip::where('service_date', $date)
                ->where('direction', 'Morning')->pluck('id'))
            ->first();

        $this->assertNotNull($rosterRow(), 'child should be on the generated roster');
        $this->assertSame('pending', $rosterRow()->status);

        $this->asGuardian()->postJson(
            '/api/parent/child/' . $this->child->id . '/absence',
            ['service_date' => $date, 'direction' => 'Morning', 'reason_code' => 'sick']
        )->assertOk();

        // The crew must see this, which means the ROSTER ROW moved.
        $this->assertSame('absent', $rosterRow()->fresh()->status);

        // Undo puts them back on the bus.
        $this->asGuardian()->deleteJson(
            '/api/parent/child/' . $this->child->id . '/absence',
            ['service_date' => $date]
        )->assertOk();

        $this->assertSame('pending', $rosterRow()->fresh()->status);
    }

    /**
     * A late absence must never rewrite what already happened on the bus —
     * only `pending` rows move (PART A7: that history answers a dispute).
     */
    public function test_a_late_absence_does_not_erase_a_child_already_boarded(): void
    {
        $date = SchoolCalendarService::for($this->child->school)->nextSchoolDay();
        app(SchoolTripGenerator::class)->generate($this->child->school, $date);

        $row = SchoolTripChild::where('child_id', $this->child->id)
            ->whereIn('trip_id', \App\Models\SchoolTrip::where('service_date', $date)
                ->where('direction', 'Morning')->pluck('id'))
            ->firstOrFail();

        $row->forceFill(['status' => 'boarded'])->save();

        $this->asGuardian()->postJson(
            '/api/parent/child/' . $this->child->id . '/absence',
            ['service_date' => $date, 'direction' => 'Morning']
        )->assertOk();

        $this->assertSame('boarded', $row->fresh()->status);
    }

    /** PART P7 — a rejected date returns a sentence, not just a status code. */
    public function test_a_past_date_is_rejected_with_a_readable_message(): void
    {
        $yesterday = Carbon::now('Asia/Kolkata')->subDay()->toDateString();

        $this->asGuardian()->postJson(
            '/api/parent/child/' . $this->child->id . '/absence',
            ['service_date' => $yesterday, 'direction' => 'Both']
        )
            ->assertStatus(422)
            ->assertJson(['status' => false])
            ->assertJsonStructure(['message']);
    }

    /* ---------------- device token ---------------- */

    /** PART L10 — one FCM token belongs to exactly one guardian. */
    public function test_registering_a_device_token_steals_it_from_other_guardians(): void
    {
        $other = Guardian::where('id', '!=', $this->guardian->id)->firstOrFail();
        $other->forceFill(['fcm_token' => 'shared-token'])->save();

        $this->asGuardian()->postJson('/api/parent/device-token', [
            'fcm_token' => 'shared-token',
        ])->assertOk();

        $this->assertSame('shared-token', $this->guardian->fresh()->fcm_token);
        $this->assertNull($other->fresh()->fcm_token,
            'An FCM token left on two guardians sends one family\'s push to the other.');
    }
}
