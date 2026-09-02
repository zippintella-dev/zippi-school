<?php

namespace App\Http\Controllers;

use App\Models\SchoolAdminAuditLog;
use App\Models\SchoolCalendar;
use App\Models\SchoolTrip;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** PART C3 — the calendar is the school's operating rhythm, not a nicety. */
class CalendarController extends Controller
{
    public function index(Request $request)
    {
        $school = $this->activeSchool();
        $cal    = $this->calendar();

        $month = $request->get('month', Carbon::parse($this->today())->format('Y-m'));
        $start = Carbon::parse($month . '-01');
        $end   = $start->copy()->endOfMonth();

        $rows = SchoolCalendar::where('school_id', $school->id)
            ->where('date', '>=', $start->toDateString())
            ->where('date', '<=', $end->toDateString())
            ->get()->keyBy('date');

        $days = [];
        $cursor = $start->copy()->startOfWeek(Carbon::MONDAY);
        $last   = $end->copy()->endOfWeek(Carbon::SUNDAY);

        while ($cursor->lte($last)) {
            $ds = $cursor->toDateString();
            $days[] = [
                'date' => $ds, 'day' => $cursor->day,
                'in_month' => $cursor->month === $start->month,
                'is_today' => $ds === $this->today(),
                'type' => $cal->dayType($ds), 'label' => $cal->label($ds),
                'school_day' => $cal->isSchoolDay($ds),
                'row' => $rows->get($ds),
            ];
            $cursor->addDay();
        }

        return $this->view('calendar.index', [
            'month' => $month,
            'monthLabel' => $start->format('F Y'),
            'prev' => $start->copy()->subMonth()->format('Y-m'),
            'next' => $start->copy()->addMonth()->format('Y-m'),
            'days' => $days,
            'schoolDays' => collect($days)->where('in_month', true)->where('school_day', true)->count(),
        ]);
    }

    public function store(Request $request)
    {
        $school = $this->activeSchool();

        $data = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date'   => ['required', 'date', 'after_or_equal:start_date'],
            'day_type'   => ['required', Rule::in(['school','holiday','half_day','exam','event','vacation'])],
            'label'      => ['nullable', 'string', 'max:120'],
            'override_end_time' => ['nullable', 'date_format:H:i'],
            'morning_trips_run'   => ['nullable', 'boolean'],
            'afternoon_trips_run' => ['nullable', 'boolean'],
            'cancel_existing_trips' => ['nullable', 'boolean'],
        ]);

        // A holiday or vacation categorically means no trips run — the
        // checkboxes cannot override that, or the calendar ends up holding
        // contradictory data (day_type=holiday with morning_trips_run=1) and
        // the generator happily dispatches buses into an empty school.
        $closed = in_array($data['day_type'], ['holiday', 'vacation'], true);

        $am = $closed ? false : $request->boolean('morning_trips_run', true);
        $pm = $closed ? false : $request->boolean('afternoon_trips_run', true);

        $cursor = Carbon::parse($data['start_date']);
        $endAt  = Carbon::parse($data['end_date']);

        if ($cursor->diffInDays($endAt) > 200) {
            return back()->with('error', 'Please set at most 200 days at a time.');
        }

        $written = 0; $cancelled = 0;

        DB::transaction(function () use ($cursor, $endAt, $data, $school, $am, $pm, $request, &$written, &$cancelled) {
            while ($cursor->lte($endAt)) {
                $ds = $cursor->toDateString();

                SchoolCalendar::updateOrCreate(
                    ['school_id' => $school->id, 'date' => $ds],   // raw string (PART L1)
                    ['day_type' => $data['day_type'],
                     'label' => $data['label'] ?? null,
                     'morning_trips_run'   => $am,
                     'afternoon_trips_run' => $pm,
                     'override_end_time'   => $data['override_end_time'] ?? null]
                );
                $written++;

                // PART J2 #7 — a bulk closure writes calendar rows AND cancels
                // already-generated trips, in one transaction. Otherwise buses
                // get dispatched into an empty school.
                if ($request->boolean('cancel_existing_trips')) {
                    // Cancel exactly the directions that are NOT running.
                    $stopped = array_values(array_filter([
                        $am ? null : 'Morning',
                        $pm ? null : 'Afternoon',
                    ]));

                    if ($stopped) {
                        $cancelled += SchoolTrip::where('school_id', $school->id)
                            ->where('service_date', $ds)
                            ->whereIn('status', ['scheduled', 'started'])
                            ->whereIn('direction', $stopped)
                            ->update(['status' => 'cancelled']);
                    }
                }

                $cursor->addDay();
            }
        });

        SchoolAdminAuditLog::record('calendar_updated', [
            'school_id' => $school->id,
            'payload'   => $data + ['days_written' => $written, 'trips_cancelled' => $cancelled],
        ], $data['label'] ?? null);

        $msg = "Set {$written} day(s) as {$data['day_type']}.";
        if ($cancelled) $msg .= " Cancelled {$cancelled} already-generated trip(s).";

        return back()->with('ok', $msg);
    }

    public function destroy(SchoolCalendar $entry)
    {
        abort_unless($entry->school_id === $this->activeSchool()->id, 403);

        $date = $entry->date;
        $entry->delete();

        return back()->with('ok', 'Removed the calendar entry for '
            . Carbon::parse($date)->format('j M') . '. It reverts to the weekday default.');
    }

    /** Most schools have the year in a PDF in July; the CSV turns it into data. */
    public function import(Request $request)
    {
        $request->validate(['csv_file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);

        $school = $this->activeSchool();
        $handle = fopen($request->file('csv_file')->getRealPath(), 'r');
        $headers = fgetcsv($handle);

        if (! $headers) {
            return back()->with('error', 'That file appears to be empty.');
        }

        $headers = array_map(fn ($h) => strtolower(trim((string) $h, " \t\n\r\0\x0B\xEF\xBB\xBF")), $headers);

        if (! in_array('date', $headers, true) || ! in_array('day_type', $headers, true)) {
            fclose($handle);
            return back()->with('error', 'CSV needs at least a "date" and "day_type" column.');
        }

        $written = 0; $bad = 0;

        DB::transaction(function () use ($handle, $headers, $school, &$written, &$bad) {
            while (($raw = fgetcsv($handle)) !== false) {
                $row = [];
                foreach ($headers as $i => $h) $row[$h] = isset($raw[$i]) ? trim((string) $raw[$i]) : '';
                if ($row['date'] === '') continue;

                try {
                    $ds = Carbon::parse($row['date'])->toDateString();
                } catch (\Throwable) { $bad++; continue; }

                if (! in_array($row['day_type'], ['school','holiday','half_day','exam','event','vacation'], true)) {
                    $bad++; continue;
                }

                SchoolCalendar::updateOrCreate(
                    ['school_id' => $school->id, 'date' => $ds],
                    ['day_type' => $row['day_type'],
                     'label' => $row['label'] ?? null,
                     'morning_trips_run'   => ! in_array(strtolower($row['morning_trips_run'] ?? '1'), ['0','no','false'], true),
                     'afternoon_trips_run' => ! in_array(strtolower($row['afternoon_trips_run'] ?? '1'), ['0','no','false'], true),
                     'override_end_time'   => ($row['override_end_time'] ?? '') ?: null]
                );
                $written++;
            }
        });

        fclose($handle);

        SchoolAdminAuditLog::record('calendar_imported', [
            'school_id' => $school->id, 'payload' => compact('written', 'bad'),
        ]);

        return back()->with('ok', "Imported {$written} calendar day(s)."
            . ($bad ? " Skipped {$bad} unreadable row(s)." : ''));
    }
}
