<?php

namespace Database\Seeders;

use App\Models\AuthorizedReceiver;
use App\Models\Bus;
use App\Models\Child;
use App\Models\ChildStopAssignment;
use App\Models\Guardian;
use App\Models\Route;
use App\Models\RouteStaffAssignment;
use App\Models\RouteStop;
use App\Models\School;
use App\Models\SchoolBellTime;
use App\Models\SchoolCalendar;
use App\Models\SchoolChildAbsence;
use App\Models\SchoolChildHandover;
use App\Models\SchoolIncident;
use App\Models\SchoolStaff;
use App\Models\SchoolStopArrival;
use App\Models\SchoolTrip;
use App\Models\SchoolTripChild;
use App\Models\SchoolTripEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Pilot School #1 — Phoenix Greens.
 *
 * Deliberately sized to the Phase-1 scope in the spec: 6 buses / ~220 children.
 * Seeds a live mid-morning state so the Control Tower, the live board and the
 * exception queue all have real rows to render rather than empty states.
 */
class PhoenixGreensSeeder extends Seeder
{
    private string $today;

    public function run(): void
    {
        $this->today = Carbon::now('Asia/Kolkata')->toDateString();

        $school = $this->school();
        $this->users($school);
        $this->bellTimes($school);
        $this->calendar($school);

        $staff  = $this->staff($school);
        $buses  = $this->buses($school);
        $routes = $this->routes($school, $staff, $buses);

        $this->children($school, $routes);
        $this->trips($school, $routes);
    }

    private function school(): School
    {
        return School::create([
            'name' => 'Phoenix Greens International School',
            'code' => 'PGIS',
            'address' => 'Kondapur, Hyderabad, Telangana 500084',
            'latitude' => 17.4620000,
            'longitude' => 78.3390000,
            'timezone' => 'Asia/Kolkata',
            'gate_geofence_radius_m' => 200,
            'self_release_min_grade' => '8',
            'require_office_approval_for_parent_collection' => false,
            'stop_wait_seconds' => 120,
            'drop_wait_seconds' => 180,
            'max_speed_kmph' => 40,
            'contact_phone' => '+914023115500',
            'status' => 'active',
        ]);
    }

    private function users(School $school): void
    {
        User::create([
            'name' => 'Zippi Ops', 'role' => 'zippi_admin',
            'email' => 'ops@zippi.in', 'password' => Hash::make('password'),
            'school_id' => null,
        ]);

        User::create([
            'name' => 'Ravi Nair', 'role' => 'ops_lead',
            'email' => 'lead@zippi.in', 'password' => Hash::make('password'),
            'school_id' => null,
        ]);

        User::create([
            'name' => 'Anita Rao', 'role' => 'school_user',
            'email' => 'transport@phoenixgreens.edu.in', 'password' => Hash::make('password'),
            'school_id' => $school->id,
        ]);
    }

    /** PART C2 — three bell tiers, which is why PART M is Phase 1. */
    private function bellTimes(School $school): void
    {
        $tiers = [
            ['Senior',  '9',  '12', '07:40', '14:40'],
            ['Middle',  '6',  '8',  '08:15', '15:15'],
            ['Primary', '1',  '5',  '08:45', '15:30'],
        ];

        foreach ($tiers as [$tier, $from, $to, $start, $end]) {
            SchoolBellTime::create([
                'school_id' => $school->id,
                'bell_tier' => $tier,
                'grade_from' => $from, 'grade_to' => $to,
                'start_time' => $start, 'end_time' => $end,
                'effective_from' => Carbon::parse($this->today)->startOfYear()->toDateString(),
            ]);
        }
    }

    /** PART C3 — a term's worth of calendar so range-expansion has holidays to skip. */
    private function calendar(School $school): void
    {
        $rows = [];
        $cursor = Carbon::parse($this->today)->subDays(30);

        for ($i = 0; $i < 120; $i++) {
            $d = $cursor->copy()->addDays($i);
            $ds = $d->toDateString();

            if ($d->isWeekend()) {
                $rows[] = [$ds, 'holiday', $d->isSaturday() ? 'Saturday' : 'Sunday', true, true, null];
            }
        }

        // Explicit named days, including one half-day and one exam block.
        $named = [
            [Carbon::parse($this->today)->addDays(6)->toDateString(),  'holiday',  'Ganesh Chaturthi', false, false, null],
            [Carbon::parse($this->today)->addDays(13)->toDateString(), 'half_day', 'Parent-Teacher Meeting', true, true, '12:00'],
            [Carbon::parse($this->today)->addDays(20)->toDateString(), 'exam',     'Term 1 exams begin', true, true, '12:30'],
            [Carbon::parse($this->today)->addDays(2)->toDateString(),  'event',    'Annual Sports Day', true, true, null],
        ];

        foreach (array_merge($rows, $named) as [$date, $type, $label, $am, $pm, $endT]) {
            SchoolCalendar::updateOrCreate(
                ['school_id' => $school->id, 'date' => $date],
                ['day_type' => $type, 'label' => $label,
                 'morning_trips_run' => $am, 'afternoon_trips_run' => $pm,
                 'override_end_time' => $endT]
            );
        }
    }

    /** @return array{drivers: SchoolStaff[], attendants: SchoolStaff[]} */
    private function staff(School $school): array
    {
        $drivers = [
            ['Suresh Kumar',   '+919848011201', 'male',   'TS0920190001234', 12, 'verified'],
            ['Ramesh Yadav',   '+919848011202', 'male',   'TS0920180004521', 9,  'verified'],
            ['Venkat Reddy',   '+919848011203', 'male',   'TS0920200008812', 7,  'verified'],
            ['Mahesh Babu',    '+919848011204', 'male',   'TS0920170002210', 15, 'verified'],
            ['Imran Shaikh',   '+919848011205', 'male',   'TS0920210009930', 6,  'verified'],
            ['Prakash Rao',    '+919848011206', 'male',   'TS0920160001100', 18, 'pending'],
        ];

        $attendants = [
            ['Lakshmi Devi',   '+919848012201', 'female'],
            ['Padma Sri',      '+919848012202', 'female'],
            ['Sunitha Reddy',  '+919848012203', 'female'],
            ['Kavitha Rani',   '+919848012204', 'female'],
            ['Shobha Rani',    '+919848012205', 'female'],
            ['Vijaya Lakshmi', '+919848012206', 'female'],
        ];

        $d = [];
        foreach ($drivers as $i => [$name, $phone, $gender, $lic, $yrs, $pv]) {
            $d[] = SchoolStaff::create([
                'school_id' => $school->id, 'role' => 'driver',
                'name' => $name, 'phone' => $phone, 'gender' => $gender,
                'licence_no' => $lic,
                // One licence expiring soon so the compliance board has an amber row.
                'licence_expiry' => Carbon::parse($this->today)->addDays($i === 2 ? 11 : 400)->toDateString(),
                'heavy_vehicle_years' => $yrs,
                'police_verification_status' => $pv,
                'police_verified_on' => $pv === 'verified'
                    ? Carbon::parse($this->today)->subMonths(8)->toDateString() : null,
                'medical_fitness_expiry' => Carbon::parse($this->today)->addDays(220)->toDateString(),
                'training_completed_on' => Carbon::parse($this->today)->subMonths(4)->toDateString(),
            ]);
        }

        $a = [];
        foreach ($attendants as $i => [$name, $phone, $gender]) {
            $a[] = SchoolStaff::create([
                'school_id' => $school->id, 'role' => 'attendant',
                'name' => $name, 'phone' => $phone, 'gender' => $gender,
                'police_verification_status' => 'verified',
                'police_verified_on' => Carbon::parse($this->today)->subMonths(6)->toDateString(),
                'medical_fitness_expiry' => Carbon::parse($this->today)->addDays(300)->toDateString(),
                'training_completed_on' => Carbon::parse($this->today)->subMonths(3)->toDateString(),
            ]);
        }

        return ['drivers' => $d, 'attendants' => $a];
    }

    /** @return Bus[] */
    private function buses(School $school): array
    {
        $defs = [
            ['TS09UB1234', 'Tata Starbus EV', 42, true],
            ['TS09UB1235', 'Tata Starbus EV', 42, true],
            ['TS09UB1236', 'Ashok Leyland Sunshine', 38, false],
            ['TS09UB1237', 'Tata Starbus EV', 42, true],
            ['TS09UB1238', 'Ashok Leyland Sunshine', 38, false],
            ['TS09UB1239', 'Force Traveller', 26, false],
        ];

        $out = [];
        foreach ($defs as $i => [$reg, $model, $cap, $ev]) {
            $out[] = Bus::create([
                'school_id' => $school->id,
                'reg_no' => $reg, 'model' => $model, 'capacity' => $cap, 'is_ev' => $ev,
                'gps_device_id' => 'GPS-' . (1000 + $i),
                'has_camera' => true,
                'fitness_expiry'   => Carbon::parse($this->today)->addDays($i === 4 ? 8 : 300)->toDateString(),
                'permit_expiry'    => Carbon::parse($this->today)->addDays(280)->toDateString(),
                'insurance_expiry' => Carbon::parse($this->today)->addDays($i === 5 ? -3 : 190)->toDateString(),
                'puc_expiry'       => Carbon::parse($this->today)->addDays(95)->toDateString(),
                'speed_governor_expiry' => Carbon::parse($this->today)->addDays(250)->toDateString(),
                'first_aid_checked_on'  => Carbon::parse($this->today)->subDays(20)->toDateString(),
                'extinguisher_checked_on' => Carbon::parse($this->today)->subDays(20)->toDateString(),
            ]);
        }

        return $out;
    }

    /** @return Route[] */
    private function routes(School $school, array $staff, array $buses): array
    {
        // Real-ish Hyderabad geography around Kondapur / Gachibowli / Madhapur.
        $defs = [
            ['RT-01', 'Kondapur – Botanical Garden', 'Primary', [
                ['Phoenix Gate 2',        17.4699, 78.3581],
                ['Silver Oak Gate',       17.4645, 78.3652],
                ['Botanical Garden Road', 17.4588, 78.3722],
                ['Kothaguda Junction',    17.4551, 78.3801],
                ['Harsha Toyota',         17.4502, 78.3844],
            ]],
            ['RT-02', 'Gachibowli – DLF', 'Primary', [
                ['DLF Circle',            17.4435, 78.3772],
                ['Indira Nagar Gate',     17.4402, 78.3688],
                ['Gachibowli Stadium',    17.4401, 78.3489],
                ['Wipro Circle',          17.4312, 78.3455],
            ]],
            ['RT-03', 'Madhapur – Hitec City', 'Middle', [
                ['Ayyappa Society',       17.4498, 78.3899],
                ['Image Gardens Road',    17.4472, 78.3925],
                ['Madhapur PS',           17.4489, 78.3971],
                ['Durgam Cheruvu',        17.4401, 78.3906],
                ['Hitec City MMTS',       17.4501, 78.3799],
            ]],
            ['RT-04', 'Miyapur – Chanda Nagar', 'Senior', [
                ['Miyapur X Roads',       17.4966, 78.3583],
                ['Alwyn Colony',          17.4901, 78.3502],
                ['Chanda Nagar',          17.4988, 78.3298],
                ['Beeramguda',            17.5052, 78.3201],
            ]],
            ['RT-05', 'Manikonda – Puppalaguda', 'Middle', [
                ['Manikonda Main Road',   17.4022, 78.3801],
                ['Puppalaguda Junction',  17.3982, 78.3722],
                ['Lanco Hills Gate',      17.4055, 78.3855],
                ['OU Colony',             17.4011, 78.3901],
            ]],
            ['RT-06', 'Nallagandla – Serilingampally', 'Senior', [
                ['Nallagandla Circle',    17.4712, 78.3122],
                ['Aparna Sarovar',        17.4688, 78.3055],
                ['Serilingampally',       17.4922, 78.3211],
            ]],
        ];

        $out = [];
        foreach ($defs as $i => [$code, $name, $tier, $stops]) {
            $route = Route::create([
                'school_id' => $school->id,
                'code' => $code, 'name' => $name, 'bell_tier' => $tier,
                'afternoon_mirrors_morning' => true,
                'version' => 1, 'status' => 'active',
            ]);

            foreach ($stops as $seq => [$sname, $lat, $lng]) {
                RouteStop::create([
                    'route_id' => $route->id,
                    'direction' => 'Morning',
                    'sequence' => $seq + 1,
                    'name' => $sname,
                    'latitude' => $lat, 'longitude' => $lng,
                ]);
            }

            foreach (['Morning', 'Afternoon'] as $dir) {
                RouteStaffAssignment::create([
                    'route_id' => $route->id,
                    'direction' => $dir,
                    'bell_tier' => $tier,
                    'driver_id' => $staff['drivers'][$i]->id,
                    'attendant_id' => $staff['attendants'][$i]->id,
                    'bus_id' => $buses[$i]->id,
                    'effective_from' => Carbon::parse($this->today)->startOfYear()->toDateString(),
                ]);
            }

            $out[] = $route->load('stops');
        }

        return $out;
    }

    private function children(School $school, array $routes): void
    {
        $first = ['Aarav','Diya','Ishaan','Ananya','Vihaan','Saanvi','Aditya','Myra','Arjun','Kiara',
                  'Reyansh','Aadhya','Krishna','Anika','Rudra','Navya','Shaurya','Pari','Devansh','Ira',
                  'Atharv','Riya','Kabir','Meera','Yuvan','Tara','Neel','Sara','Rohan','Zara'];
        $last  = ['Sharma','Reddy','Rao','Nair','Iyer','Gupta','Menon','Kapoor','Verma','Patel',
                  'Chowdary','Malhotra','Bose','Pillai','Joshi'];

        /**
         * ⚠ PART F2 — the line that stops the adjacent-row mis-tap.
         *
         * Something an attendant can see from a metre away in a bouncing bus,
         * not something they have to read: a colour, an object, a height. 17 of
         * them against 15 surnames, so two children who share a surname cannot
         * share a detail.
         */
        $details = [
            'Green water bottle', 'Yellow raincoat', 'Blue name tag', 'Red name tag',
            'Wears glasses', 'Purple backpack', 'Two plaits, blue ribbons',
            'Tallest at this stop', 'Orange shoes', 'Cricket bag',
            'Short hair, silver watch', 'Dinosaur backpack', 'Pink hairband',
            'Headphones round neck', 'Football under arm', 'Blue tiffin bag',
            'Youngest at this stop',
        ];

        $rel = ['mother', 'father'];
        $n = 0;

        foreach ($routes as $route) {
            $stops = $route->stops;
            $tier = $route->bell_tier;

            [$gFrom, $gTo] = match ($tier) {
                'Senior'  => [9, 12],
                'Middle'  => [6, 8],
                default   => [1, 5],
            };

            // ~36 children per route → ~215 total, matching the Phase-1 pilot size.
            for ($i = 0; $i < 36; $i++) {
                $n++;
                $stop = $stops[$i % $stops->count()];
                $grade = (string) random_int($gFrom, $gTo);
                $name = $first[($n * 7) % count($first)] . ' ' . $last[($n * 3) % count($last)];

                $child = Child::create([
                    'school_id' => $school->id,
                    'admission_no' => 'PG' . str_pad((string) (1000 + $n), 5, '0', STR_PAD_LEFT),
                    'name' => $name,
                    'grade' => $grade,
                    'section' => ['A','B','C'][$n % 3],
                    'bell_tier' => $tier,
                    'home_address' => $stop->name . ' area, Hyderabad',
                    'blood_group' => ['A+','B+','O+','AB+','O-'][$n % 5],
                    'medical_notes' => $n % 23 === 0 ? 'Peanut allergy — EpiPen in bag' : null,
                    // ⚠ The anti-mis-tap line the Fleet attendant actually
                    // reads. Cycled by $n rather than by grade so that two
                    // children with the same surname at the same stop — which
                    // this seeder produces, because surnames repeat — never
                    // land on the same detail.
                    'distinguishing_detail' => $details[$n % count($details)],
                    'self_release_consent' => (int) $grade >= 8 && $n % 4 === 0,
                    'transport_fee_zone' => 'Zone ' . (1 + ($i % 3)),
                ]);

                // Two guardians for most children, one for some.
                $gcount = $n % 5 === 0 ? 1 : 2;
                for ($g = 0; $g < $gcount; $g++) {
                    $guardian = Guardian::create([
                        'name' => ($g === 0 ? 'Meera ' : 'Rajesh ') . explode(' ', $name)[1],
                        'phone' => '+9198' . str_pad((string) (4000000 + $n * 3 + $g), 8, '0', STR_PAD_LEFT),
                        'email' => strtolower(explode(' ', $name)[0]) . $n . $g . '@example.com',
                        'app_access' => true,
                        'invited_at' => now()->subDays(random_int(5, 60)),
                        'last_login_at' => now()->subHours(random_int(1, 72)),
                    ]);

                    $child->guardians()->attach($guardian->id, [
                        'relationship' => $rel[$g], 'is_primary' => $g === 0,
                    ]);

                    AuthorizedReceiver::create([
                        'child_id' => $child->id,
                        'guardian_id' => $guardian->id,
                        'name' => $guardian->name,
                        'relationship' => $rel[$g],
                        'phone' => $guardian->phone,
                        'is_active' => true,
                    ]);
                }

                // A grandparent who collects but has no app — the exact case
                // the authorized-receiver list exists for (PART A7).
                if ($n % 6 === 0) {
                    AuthorizedReceiver::create([
                        'child_id' => $child->id,
                        'name' => 'Kamala ' . explode(' ', $name)[1],
                        'relationship' => 'grandparent',
                        'phone' => '+9198' . str_pad((string) (5000000 + $n), 8, '0', STR_PAD_LEFT),
                        'is_active' => true,
                    ]);
                }

                foreach (['Morning', 'Afternoon'] as $dir) {
                    ChildStopAssignment::create([
                        'child_id' => $child->id,
                        'route_id' => $route->id,
                        'stop_id' => $stop->id,
                        'direction' => $dir,
                        'effective_from' => Carbon::parse($this->today)->startOfYear()->toDateString(),
                    ]);
                }

                // A handful of absences for today so the roster and dashboard
                // show real absence handling rather than a clean slate.
                if ($n % 17 === 0) {
                    SchoolChildAbsence::create([
                        'child_id' => $child->id,
                        'service_date' => $this->today,
                        'direction' => 'Morning',
                        'bell_tier' => $tier,
                        'marked_by' => 'parent',
                        'reason_code' => 'sick',
                        'reason' => 'Fever',
                    ]);
                }
                if ($n % 29 === 0) {
                    SchoolChildAbsence::create([
                        'child_id' => $child->id,
                        'service_date' => $this->today,
                        'direction' => 'Afternoon',
                        'bell_tier' => $tier,
                        'marked_by' => 'parent_collecting',
                        'reason_code' => 'parent_collecting',
                        'reason' => 'Dentist appointment',
                    ]);
                }
            }
        }
    }

    /**
     * Seeds today's morning trips in a realistic mid-flight state:
     * some completed, some running, one with an exception. This is what makes
     * the Control Tower's exception queue meaningful on first load.
     */
    private function trips(School $school, array $routes): void
    {
        $now = Carbon::now('Asia/Kolkata');

        foreach ($routes as $idx => $route) {
            $tier = $route->bell_tier;
            $bell = match ($tier) { 'Senior' => '07:40', 'Middle' => '08:15', default => '08:45' };
            $assignment = RouteStaffAssignment::where('route_id', $route->id)
                ->where('direction', 'Morning')->first();

            $childIds = ChildStopAssignment::where('route_id', $route->id)
                ->where('direction', 'Morning')->pluck('child_id')->unique()->values();

            $absentIds = SchoolChildAbsence::whereIn('child_id', $childIds)
                ->where('service_date', $this->today)
                ->where('direction', 'Morning')->pluck('child_id')->all();

            $deadline = Carbon::parse($this->today . ' ' . $bell, 'Asia/Kolkata')->subMinutes(10);
            $start    = $deadline->copy()->subMinutes(58);

            // Routes 0–2 completed, 3–4 running now, 5 still scheduled.
            $status = match (true) {
                $idx <= 2 => 'completed',
                $idx <= 4 => 'started',
                default   => 'scheduled',
            };

            $trip = SchoolTrip::create([
                'school_id' => $school->id,
                'route_id' => $route->id,
                'route_version' => 1,
                'bus_id' => $assignment?->bus_id,
                'driver_id' => $assignment?->driver_id,
                'attendant_id' => $assignment?->attendant_id,
                'service_date' => $this->today,           // raw string (L1)
                'direction' => 'Morning',
                'bell_tier' => $tier,
                'sequence' => 0,
                'status' => $status,
                'child_ids' => $childIds->all(),
                'scheduled_start_at' => $start,
                'scheduled_end_at' => $deadline,
                'bell_time' => $bell,
                'school_arrival_deadline' => $deadline,
                'started_at' => $status === 'scheduled' ? null : $start->copy()->addMinutes(random_int(-2, 4)),
                'arrived_at_school_at' => $status === 'completed' ? $deadline->copy()->addMinutes(random_int(-4, 6)) : null,
                'completed_at' => $status === 'completed' ? $deadline->copy()->addMinutes(random_int(6, 12)) : null,
                'sweep_verified_at' => $status === 'completed' && $idx !== 2
                    ? $deadline->copy()->addMinutes(8) : null,
                'sweep_by_staff_id' => $status === 'completed' && $idx !== 2 ? $assignment?->attendant_id : null,
                'headcount_reported' => $status === 'completed' ? max(0, $childIds->count() - count($absentIds)) : null,
                'headcount_verified' => $status === 'completed' && $idx !== 2,
                'source' => 'auto',
                'distance_m' => random_int(9000, 21000),
                'duration_min' => random_int(42, 68),
            ]);

            // Stop arrivals in authored sequence (PART G1).
            $cursor = $trip->started_at?->copy();
            foreach ($route->stops as $stop) {
                $sched = $deadline->copy()->subMinutes((int) (($route->stops->count() - $stop->sequence + 1) * 9));
                $arrived = null; $departed = null;

                if ($status === 'completed') {
                    $arrived = $sched->copy()->addMinutes(random_int(-3, 5));
                    $departed = $arrived->copy()->addMinutes(2);
                } elseif ($status === 'started' && $cursor && $stop->sequence <= 2) {
                    $arrived = $sched->copy()->addMinutes(random_int(-2, 4));
                    $departed = $arrived->copy()->addMinutes(2);
                }

                SchoolStopArrival::create([
                    'trip_id' => $trip->id,
                    'stop_id' => $stop->id,
                    'sequence' => $stop->sequence,
                    'scheduled_at' => $sched,
                    'arrived_at' => $arrived,
                    'departed_at' => $departed,
                    'approach_notified_at' => $arrived?->copy()->subMinutes(4),
                    'latitude' => $stop->latitude,
                    'longitude' => $stop->longitude,
                ]);
            }

            // Per-child rows.
            foreach ($childIds as $ci => $childId) {
                $assign = ChildStopAssignment::where('child_id', $childId)
                    ->where('direction', 'Morning')->first();
                $stop = $assign?->stop;

                $childStatus = 'pending';
                $boardedAt = null;

                if (in_array($childId, $absentIds, true)) {
                    $childStatus = 'absent';
                } elseif ($status === 'completed') {
                    $childStatus = $ci % 31 === 0 ? 'not_at_stop' : 'arrived_at_school';
                    $boardedAt = $childStatus === 'arrived_at_school'
                        ? $deadline->copy()->subMinutes(random_int(12, 50)) : null;
                } elseif ($status === 'started' && $stop && $stop->sequence <= 2) {
                    $childStatus = 'boarded';
                    $boardedAt = $now->copy()->subMinutes(random_int(3, 20));
                }

                SchoolTripChild::create([
                    'trip_id' => $trip->id,
                    'child_id' => $childId,
                    'stop_id' => $assign?->stop_id,
                    'sequence' => $stop?->sequence,
                    'status' => $childStatus,
                    'boarded_at' => $boardedAt,
                    'boarded_lat' => $boardedAt ? $stop?->latitude : null,
                    'boarded_lng' => $boardedAt ? $stop?->longitude : null,
                    'acted_by_type' => $boardedAt ? 'attendant' : null,
                    'acted_by_id' => $boardedAt ? $trip->attendant_id : null,
                ]);
            }

            // Live bus position for running trips.
            if ($status === 'started' && $trip->bus_id) {
                $stop = $route->stops[min(2, $route->stops->count() - 1)];
                Bus::where('id', $trip->bus_id)->update([
                    'latitude' => (float) $stop->latitude + 0.0031,
                    'longitude' => (float) $stop->longitude - 0.0024,
                    'speed_kmph' => random_int(18, 44),
                    'last_ping_at' => $now->copy()->subSeconds(random_int(3, 40)),
                ]);
            }

            $this->exceptions($school, $trip, $idx, $status, $now);
        }
    }

    /** Seeds the exception queue (PART J3) with one row of each severity tier. */
    private function exceptions(School $school, SchoolTrip $trip, int $idx, string $status, Carbon $now): void
    {
        if ($idx === 2 && $status === 'completed') {
            // Invariant #3 violated — completed without a sweep. High.
            SchoolTripEvent::create([
                'trip_id' => $trip->id, 'school_id' => $school->id,
                'event_type' => 'sweep_missing', 'severity' => 'high',
                'detail' => 'Trip completed without a verified vehicle sweep.',
                'started_at' => $trip->completed_at,
            ]);
            SchoolIncident::create([
                'school_id' => $school->id, 'trip_id' => $trip->id,
                'incident_type' => 'sweep_not_performed', 'severity' => 'high',
                'description' => 'Attendant completed the trip without confirming the empty-vehicle sweep.',
                'raised_by_type' => 'system',
            ]);
        }

        if ($idx === 3 && $status === 'started') {
            SchoolTripEvent::create([
                'trip_id' => $trip->id, 'school_id' => $school->id,
                'event_type' => 'deviation', 'severity' => 'high',
                'detail' => 'Bus 620 m off the published corridor for 2 min 10 s.',
                'payload' => ['max_distance_m' => 620, 'duration_s' => 130],
                'started_at' => $now->copy()->subMinutes(6),
                'driver_reason' => 'road_closed',
            ]);
        }

        if ($idx === 4 && $status === 'started') {
            SchoolTripEvent::create([
                'trip_id' => $trip->id, 'school_id' => $school->id,
                'event_type' => 'overspeed', 'severity' => 'high',
                'detail' => 'Sustained 48 km/h against a 40 km/h school-bus limit for 41 s.',
                'payload' => ['peak_kmph' => 48, 'limit_kmph' => 40, 'duration_s' => 41],
                'started_at' => $now->copy()->subMinutes(11),
            ]);
            SchoolTripEvent::create([
                'trip_id' => $trip->id, 'school_id' => $school->id,
                'event_type' => 'delay', 'severity' => 'medium',
                'detail' => 'Projected school arrival 12 min past the bell.',
                'payload' => ['delay_minutes' => 12],
                'started_at' => $now->copy()->subMinutes(3),
            ]);
        }
    }
}
