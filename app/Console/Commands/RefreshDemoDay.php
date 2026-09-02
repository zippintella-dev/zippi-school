<?php

namespace App\Console\Commands;

use App\Models\SchoolCalendar;
use App\Models\SchoolChildAbsence;
use App\Models\SchoolTrip;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rolls the seeded demo data forward so "today" is always today.
 *
 * Without this, a demo environment left running overnight shows an empty
 * dashboard the next morning — the trips are all dated yesterday. Shifts every
 * date column by the same number of days so relative relationships (bell times,
 * stop arrivals, calendar entries) stay intact.
 *
 *   php artisan school:refresh-demo
 */
class RefreshDemoDay extends Command
{
    protected $signature = 'school:refresh-demo {--to= : Target date (Y-m-d), defaults to today}';

    protected $description = 'Roll seeded demo trips and absences forward to today';

    public function handle(): int
    {
        $latest = SchoolTrip::orderByDesc('service_date')->value('service_date');

        if (! $latest) {
            $this->warn('No trips found — run `php artisan migrate:fresh --seed` first.');
            return self::FAILURE;
        }

        $target = $this->option('to') ?: Carbon::now(config('app.timezone'))->toDateString();
        // Carbon 3 returns a float here — cast so the === 0 guard actually fires.
        $shift  = (int) Carbon::parse($latest)->diffInDays(Carbon::parse($target), false);

        if ($shift === 0) {
            $this->info("Demo data is already on {$target}. Nothing to do.");
            return self::SUCCESS;
        }

        $this->info("Shifting demo data by {$shift} day(s): {$latest} → {$target}");

        DB::transaction(function () use ($shift) {
            // DATE columns are raw strings (PART L1), so shift them as strings.
            foreach (SchoolTrip::cursor() as $trip) {
                $trip->forceFill([
                    'service_date' => Carbon::parse($trip->service_date)->addDays($shift)->toDateString(),
                ]);

                foreach (['scheduled_start_at', 'scheduled_end_at', 'school_arrival_deadline',
                          'school_depart_at', 'started_at', 'arrived_at_school_at',
                          'completed_at', 'sweep_verified_at'] as $col) {
                    if ($trip->$col) $trip->$col = $trip->$col->addDays($shift);
                }

                $trip->save();
            }

            foreach (SchoolChildAbsence::cursor() as $a) {
                $a->forceFill([
                    'service_date' => Carbon::parse($a->service_date)->addDays($shift)->toDateString(),
                ])->save();
            }

            // school_calendars has a UNIQUE(school_id, date). Shifting row-by-row
            // in arbitrary order makes a row land on a neighbour's date before
            // that neighbour has moved. Walking in the direction of travel —
            // highest date first when shifting forward — keeps the target slot
            // free at every step.
            SchoolCalendar::orderBy('date', $shift > 0 ? 'desc' : 'asc')
                ->cursor()
                ->each(function (SchoolCalendar $c) use ($shift) {
                    $c->forceFill([
                        'date' => Carbon::parse($c->date)->addDays($shift)->toDateString(),
                    ])->save();
                });

            DB::table('school_stop_arrivals')->get()->each(function ($row) use ($shift) {
                $vals = [];
                foreach (['scheduled_at', 'arrived_at', 'departed_at', 'approach_notified_at'] as $col) {
                    if ($row->$col) $vals[$col] = Carbon::parse($row->$col)->addDays($shift);
                }
                if ($vals) DB::table('school_stop_arrivals')->where('id', $row->id)->update($vals);
            });

            DB::table('school_trip_children')->get()->each(function ($row) use ($shift) {
                $vals = [];
                foreach (['boarded_at', 'alighted_at'] as $col) {
                    if ($row->$col) $vals[$col] = Carbon::parse($row->$col)->addDays($shift);
                }
                if ($vals) DB::table('school_trip_children')->where('id', $row->id)->update($vals);
            });

            DB::table('school_trip_events')->get()->each(function ($row) use ($shift) {
                $vals = [];
                foreach (['started_at', 'ended_at'] as $col) {
                    if ($row->$col) $vals[$col] = Carbon::parse($row->$col)->addDays($shift);
                }
                if ($vals) DB::table('school_trip_events')->where('id', $row->id)->update($vals);
            });
        });

        $this->info('Done. Dashboard now shows live data for ' . $target . '.');

        return self::SUCCESS;
    }
}
