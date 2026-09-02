@extends('layouts.app')
@section('title', 'School calendar')
@section('subtitle', $monthLabel . ' · ' . $schoolDays . ' school days')

@section('actions')
  <a class="btn ghost" href="{{ route('calendar') }}?month={{ $prev }}">←</a>
  <a class="btn ghost" href="{{ route('calendar') }}?month={{ $next }}">→</a>
@endsection

@section('content')

<div class="note" style="margin-bottom:18px">
  <b>The calendar is load-bearing.</b> The enterprise module deferred this; for a
  school it drives everything. Generation produces nothing on a holiday, half-days
  re-solve the afternoon against the earlier dismissal, and a parent marking a
  date range gets the holidays skipped with visible confirmation copy (PART C3).
</div>


<details class="panel" @if($errors->any()) open @endif>
  <summary>Set day type for a date or range
    <span class="hint">holiday · half day · exam · closure</span></summary>
  <div class="panel-body">
    @include('partials.errors')
    <form method="POST" action="{{ route('calendar.store') }}">
      @csrf
      <div class="form-grid c3">
        <div class="field"><label>From</label>
          <input class="input" type="date" name="start_date" value="{{ old('start_date') }}" required></div>
        <div class="field"><label>To</label>
          <input class="input" type="date" name="end_date" value="{{ old('end_date') }}" required></div>
        <div class="field"><label>Day type</label>
          <select class="input" name="day_type" required>
            @foreach(['school'=>'School day','holiday'=>'Holiday','half_day'=>'Half day',
                      'exam'=>'Exam day','event'=>'Event','vacation'=>'Vacation'] as $v=>$l)
              <option value="{{ $v }}" @selected(old('day_type')===$v)>{{ $l }}</option>
            @endforeach
          </select></div>
        <div class="field"><label>Label</label>
          <input class="input" name="label" value="{{ old('label') }}" placeholder="Ganesh Chaturthi"></div>
        <div class="field"><label>Dismissal override</label>
          <input class="input" type="time" name="override_end_time" value="{{ old('override_end_time') }}">
          <div class="help">Half days re-solve the afternoon from this time.</div></div>
        <div class="field" style="padding-top:20px">
          <div class="check-row">
            <input type="checkbox" id="am" name="morning_trips_run" value="1" @checked(old('morning_trips_run', true))>
            <label for="am" style="margin:0">Morning trips run</label>
          </div>
          <div class="check-row">
            <input type="checkbox" id="pm" name="afternoon_trips_run" value="1" @checked(old('afternoon_trips_run', true))>
            <label for="pm" style="margin:0">Afternoon trips run</label>
          </div>
        </div>
      </div>

      <div class="check-row">
        <input type="checkbox" id="cx" name="cancel_existing_trips" value="1">
        <label for="cx" style="margin:0"><b>Also cancel trips already generated for these dates</b></label>
      </div>
      <div class="help" style="margin-bottom:12px">
        Tick this for a late-breaking closure (rain, strike, air quality). It
        writes the calendar rows <b>and</b> cancels the generated trips in one
        transaction — otherwise buses get dispatched into an empty school (PART J2 #7).
      </div>

      <div class="form-actions"><button class="btn" type="submit">Apply to calendar</button></div>
    </form>

    <div style="margin-top:18px;padding-top:14px;border-top:1px solid var(--line-soft)">
      <div class="sectitle">Or import the year</div>
      <form method="POST" action="{{ route('calendar.import') }}" enctype="multipart/form-data" class="inline-form">
        @csrf
        <div class="field"><label>CSV</label>
          <input class="input" type="file" name="csv_file" accept=".csv,text/csv" required></div>
        <button class="btn ghost" type="submit">Import</button>
      </form>
      <div class="help" style="margin-top:6px">
        Columns: <code>date, day_type, label, morning_trips_run, afternoon_trips_run, override_end_time</code>.
        Most schools have the whole year in a PDF in July — this turns it into
        the system of record in ten minutes.
      </div>
    </div>
  </div>
</details>

<div class="card">
  <div class="card-head"><h2>{{ $monthLabel }}</h2><span class="spacer"></span>
    <div class="legend">
      <span><i style="background:var(--ok)"></i>School</span>
      <span><i style="background:var(--warn)"></i>Half day / exam</span>
      <span><i style="background:var(--ink-4)"></i>Holiday</span>
      <span><i style="background:var(--afternoon)"></i>Event</span>
    </div>
  </div>
  <div class="card-body">
    <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:6px">
      @foreach(['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $d)
        <div class="sectitle" style="text-align:center;margin:0 0 4px">{{ $d }}</div>
      @endforeach

      @foreach($days as $d)
        @php
          $tone = match($d['type']) {
            'school' => 'var(--ok-bg)', 'half_day','exam' => 'var(--warn-bg)',
            'event' => 'var(--afternoon-bg)', default => '#F1F4F6',
          };
          $edge = match($d['type']) {
            'school' => 'var(--ok)', 'half_day','exam' => 'var(--warn)',
            'event' => 'var(--afternoon)', default => 'var(--ink-4)',
          };
        @endphp
        <div style="min-height:72px;border-radius:9px;padding:7px 8px;
                    background:{{ $d['in_month'] ? $tone : '#FAFBFC' }};
                    border:1px solid {{ $d['is_today'] ? 'var(--teal)' : 'var(--line)' }};
                    {{ $d['is_today'] ? 'box-shadow:0 0 0 2px var(--teal-line)' : '' }}
                    opacity:{{ $d['in_month'] ? 1 : .45 }}">
          <div style="display:flex;align-items:center;gap:5px">
            <b style="font-size:13px">{{ $d['day'] }}</b>
            <span style="width:6px;height:6px;border-radius:50%;background:{{ $edge }}"></span>
          </div>
          @if($d['label'] && ! in_array($d['label'], ['Saturday','Sunday']))
            <div style="font-size:10.5px;color:var(--ink-2);margin-top:3px;line-height:1.3">
              {{ $d['label'] }}</div>
          @endif
          @if($d['row']?->override_end_time)
            <div style="font-size:10px;color:var(--warn);margin-top:2px">
              ends {{ \Carbon\Carbon::parse($d['row']->override_end_time)->format('g:i A') }}</div>
          @endif
          @if($d['row'] && $d['in_month'])
            <form method="POST" action="{{ route('calendar.destroy', $d['row']) }}"
                  style="margin-top:4px" onsubmit="return confirm('Remove this calendar entry?')">
              @csrf @method('DELETE')
              <button class="icon-btn danger" style="width:18px;height:18px;font-size:11px"
                      title="Remove entry">×</button>
            </form>
          @endif
        </div>
      @endforeach
    </div>
  </div>
</div>
@endsection
