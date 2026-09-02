@extends('layouts.app')
@section('title', 'Daily roster')
@section('subtitle', $dayLabel)

@section('actions')
  <form method="GET"><input class="input" type="date" name="date" value="{{ $date }}"
    onchange="this.form.submit()"></form>
@endsection

@section('content')

@unless($isSchoolDay)
  <div class="note warn" style="margin-bottom:18px">
    <b>Not a school day.</b> {{ $dayLabel }} — no roster runs.
  </div>
@endunless

<div class="note" style="margin-bottom:18px">
  <b>Two tiers of staffing.</b> A <i>default</i> assignment applies every day.
  A <i>stand-in</i> covers a single date and direction and reverts automatically
  the next day — so swapping the evening attendant for one Tuesday never
  permanently changes the route (PART O5).
</div>

@foreach($groups as $g)
  @php $route = $g['route']; @endphp
  <div class="card" style="margin-bottom:16px">
    <div class="card-head">
      <span class="dir-strip Morning" style="height:26px"></span>
      <div><h2>{{ $route->code }}</h2><div class="hint">{{ $route->name }}</div></div>
      <span class="spacer"></span>
      <span class="pill teal">{{ $g['rows']->count() }} riding</span>
    </div>

    <div class="card-body" style="border-bottom:1px solid var(--line-soft);
         display:grid;grid-template-columns:1fr 1fr;gap:16px">
      @foreach(['Morning','Afternoon'] as $dir)
        @php $s = $g['staff'][$dir]; @endphp
        <div>
          <div class="sectitle">
            {{ $dir }}
            @if($s['is_override'])<span class="pill warn">stand-in today</span>@endif
          </div>
          <dl class="kv" style="grid-template-columns:88px 1fr">
            <dt>Driver</dt><dd>{{ $s['driver']?->name ?? '—' }}</dd>
            <dt>Attendant</dt><dd>{{ $s['attendant']?->name ?? '—' }}</dd>
            <dt>Bus</dt><dd>{{ $s['bus']?->reg_no ?? '—' }}
              @if($s['bus']?->is_ev)<span class="pill ok">EV</span>@endif</dd>
          </dl>

          <details style="margin-top:9px">
            <summary style="cursor:pointer;font-size:12px;font-weight:650;color:var(--teal-dark)">
              Change {{ strtolower($dir) }} crew</summary>
            <form method="POST" action="{{ route('roster.staff') }}"
                  style="margin-top:9px;padding:11px;background:#FAFBFC;border-radius:8px">
              @csrf
              <input type="hidden" name="route_id" value="{{ $route->id }}">
              <input type="hidden" name="service_date" value="{{ $date }}">
              <input type="hidden" name="direction" value="{{ $dir }}">

              <div class="field"><label>Driver</label>
                <select class="input" name="driver_id">
                  <option value="">— none —</option>
                  @foreach($drivers as $d)
                    <option value="{{ $d->id }}" @selected($s['driver']?->id == $d->id)>{{ $d->name }}</option>
                  @endforeach
                </select></div>
              <div class="field"><label>Attendant</label>
                <select class="input" name="attendant_id">
                  <option value="">— none —</option>
                  @foreach($attendants as $t)
                    <option value="{{ $t->id }}" @selected($s['attendant']?->id == $t->id)>{{ $t->name }}</option>
                  @endforeach
                </select></div>
              <div class="field"><label>Bus</label>
                <select class="input" name="bus_id">
                  <option value="">— none —</option>
                  @foreach($buses as $b)
                    <option value="{{ $b->id }}" @selected($s['bus']?->id == $b->id)>{{ $b->reg_no }}</option>
                  @endforeach
                </select></div>

              <div style="display:flex;gap:7px;flex-wrap:wrap">
                <button class="btn sm" type="submit" name="scope" value="override">
                  Stand-in for this day only</button>
                <button class="btn sm ghost" type="submit" name="scope" value="default">
                  Set as default</button>
              </div>
              <div class="help" style="margin-top:7px">
                A stand-in covers {{ \Carbon\Carbon::parse($date)->format('j M') }} only and
                reverts the next day. Clear all three and save as stand-in to
                revert immediately.
              </div>
            </form>
          </details>
        </div>
      @endforeach
    </div>

    <div class="card-body tight">
      <div class="tbl-wrap">
        <table class="tbl">
          <thead><tr>
            <th>#</th><th>Student</th><th>Grade</th><th>Stop</th>
            <th>Guardian</th><th>Morning</th><th>Afternoon</th>
          </tr></thead>
          <tbody>
          @foreach($g['rows'] as $a)
            @php
              $abs = $g['absences']->get($a->child_id, collect());
              $am = $abs->firstWhere('direction', 'Morning');
              $pm = $abs->firstWhere('direction', 'Afternoon');
              $guardian = $a->child?->guardians->first();
            @endphp
            <tr>
              <td class="muted">{{ $a->stop?->sequence }}</td>
              <td>
                <div class="who-cell">
                  <span class="avatar">{{ \Illuminate\Support\Str::of($a->child?->name)->substr(0,1) }}</span>
                  <div><div class="nm">{{ $a->child?->name }}</div>
                    <div class="mt">{{ $a->child?->admission_no }}</div></div>
                </div>
              </td>
              <td>{{ $a->child?->grade }}-{{ $a->child?->section }}</td>
              <td>{{ $a->stop?->name }}</td>
              <td class="muted">{{ $guardian?->maskedPhone() ?? '—' }}</td>
              <td>@if($am)<span class="pill {{ $am->bannerTone() }}">{{ $am->markedByLabel() }}</span>
                  @else<span class="pill ok">Riding</span>@endif</td>
              <td>@if($pm)<span class="pill {{ $pm->bannerTone() }}">{{ $pm->markedByLabel() }}</span>
                  @else<span class="pill ok">Riding</span>@endif</td>
            </tr>
          @endforeach
          </tbody>
        </table>
      </div>
    </div>
  </div>
@endforeach
@endsection
