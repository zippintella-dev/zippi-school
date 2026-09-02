@extends('layouts.app')
@section('title', 'Compliance')
@section('subtitle', 'CMVR school-bus obligations tracked per vehicle and per staff member (PART K15)')

@section('content')

<div class="note" style="margin-bottom:18px">
  <b>This board is school-facing, not just ops-facing.</b> The school is the
  legally accountable party. Being handed a green/amber/red compliance board is,
  on its own, a reason to choose Zippi over a tracker that only draws dots on a map.
</div>

<div class="grid c3" style="margin-bottom:18px">
  <div class="stat {{ $expired ? 'danger' : 'ok' }}"><span class="rail"></span>
    <div class="label">Expired / missing</div><div class="value">{{ $expired }}</div>
    <div class="foot">must be resolved before the vehicle runs</div></div>
  <div class="stat {{ $expiring ? 'warn' : 'ok' }}"><span class="rail"></span>
    <div class="label">Expiring in 30 days</div><div class="value">{{ $expiring }}</div>
    <div class="foot">alerts fire at 30 / 15 / 7 / 0 days</div></div>
  <div class="stat ok"><span class="rail"></span>
    <div class="label">Clear</div><div class="value">{{ $ok }}</div>
    <div class="foot">all documents valid</div></div>
</div>

<div class="card">
  <div class="card-head"><h2>Subjects</h2><span class="spacer"></span>
    <span class="hint">worst first</span></div>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Subject</th><th>Type</th><th>Status</th><th>Documents</th></tr></thead>
      <tbody>
      @foreach($rows as $r)
        <tr>
          <td><b>{{ $r['subject'] }}</b>
            <div class="muted" style="font-size:11.5px">{{ $r['meta'] }}</div></td>
          <td class="muted">{{ $r['kind'] }}</td>
          <td>
            @if($r['status'] === 'ok')<span class="pill ok">OK</span>
            @elseif($r['status'] === 'expiring')<span class="pill warn">Expiring</span>
            @else<span class="pill danger">Action needed</span>@endif
          </td>
          <td>
            <div style="display:flex;flex-wrap:wrap;gap:6px">
              @foreach($r['all'] as $doc)
                @php
                  $d = $doc['date'];
                  $left = $d ? (int) \Carbon\Carbon::today()->diffInDays(\Carbon\Carbon::parse($d), false) : null;
                  $tone = $left === null ? 'danger' : ($left < 0 ? 'danger' : ($left <= 30 ? 'warn' : 'ok'));
                @endphp
                <span class="pill {{ $tone }}" title="{{ $d ?? 'missing' }}">
                  {{ $doc['label'] }}
                  @if($left === null) · missing
                  @elseif($left < 0) · {{ abs($left) }}d overdue
                  @elseif($left <= 30) · {{ $left }}d
                  @endif
                </span>
              @endforeach
            </div>
          </td>
        </tr>
      @endforeach
      </tbody>
    </table>
  </div>
</div>

<div class="card" style="margin-top:16px">
  <div class="card-head"><h2>Also enforced at runtime</h2></div>
  <div class="card-body">
    <dl class="kv" style="grid-template-columns:280px 1fr">
      <dt>Capacity</dt>
      <dd>Boarding beyond the bus's rated capacity returns 422 at the tap —
        not a report after the fact (PART L17).</dd>
      <dt>Speed limit</dt>
      <dd>{{ $activeSchool->max_speed_kmph }} km/h, with a live in-cab readout that
        turns red above it. Visible feedback changes behaviour; a report the
        driver never sees does not (PART R3).</dd>
      <dt>Wrong-bus boarding</dt>
      <dd>Blocked, not warned. An override needs a reason and raises a High
        alert immediately (PART L18).</dd>
      <dt>Vehicle sweep</dt>
      <dd>Photo + geo-stamped, taken after the last stop, blocking trip
        completion. Deliberately annoying (Invariant #3).</dd>
    </dl>
  </div>
</div>
@endsection
