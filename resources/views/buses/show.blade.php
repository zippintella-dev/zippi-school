@extends('layouts.app')
@section('title', $bus->reg_no)
@section('subtitle', trim(($bus->model ?: 'Bus') . ' · ' . $bus->capacity . ' seats'
    . ($bus->is_ev ? ' · Electric' : '')))

@section('actions')
  <a class="btn" href="{{ route('buses.index') }}">← All buses</a>
@endsection

@section('content')

@if($bus->status !== 'active')
  <div class="note warn" style="margin-bottom:18px">
    <b>{{ ucfirst($bus->status) }}.</b> {{ $bus->reg_no }} is not assigned to new
    trips. Its trip history is retained.
  </div>
@endif

@php $blockers = $bus->complianceBlockers(); @endphp
@if($blockers)
  <div class="note danger" style="margin-bottom:18px">
    <b>This vehicle will not be assigned to trips.</b>
    {{ implode(' · ', $blockers) }}.
    {{-- PART K15 — the gate is at generation (02:30), so there is still time
         to renew or swap the vehicle before children are at a stop. --}}
    Trip generation withholds a vehicle whose documents have expired or were
    never recorded. Renew the document and the next run will assign it again.
  </div>
@endif

<div class="grid c2">

  <div class="col">
    <div class="card">
      <div class="card-head"><h2>Vehicle</h2></div>
      <div class="card-body">
        <table class="tbl">
          <tbody>
            <tr><td class="muted" style="width:38%">Registration</td>
              <td><b style="font-size:15px">{{ $bus->reg_no }}</b></td></tr>
            <tr><td class="muted">Model</td><td>{{ $bus->model ?: '—' }}</td></tr>
            <tr><td class="muted">Capacity</td>
              <td>{{ $bus->capacity }} seats
                <span class="muted">· enforced at the tap (PART L17)</span></td></tr>
            <tr><td class="muted">Power</td>
              <td><span class="pill {{ $bus->is_ev ? 'ok' : '' }}">
                {{ $bus->is_ev ? 'Electric' : 'Diesel' }}</span></td></tr>
            <tr><td class="muted">Camera</td>
              <td>{{ $bus->has_camera ? 'Fitted' : 'None' }}</td></tr>
            <tr><td class="muted">GPS device</td>
              <td>{{ $bus->gps_device_id ?: '—' }}</td></tr>
            <tr><td class="muted">Last position</td>
              <td>
                @if($bus->last_ping_at)
                  {{ $bus->last_ping_at->format('D j M, g:i A') }}
                  @if($bus->isGpsStale())
                    <span class="pill warn">stale</span>
                  @else
                    <span class="pill ok">live</span>
                  @endif
                  @if($bus->latitude && $bus->longitude)
                    <div class="muted" style="font-size:11px">
                      {{ $bus->latitude }}, {{ $bus->longitude }}</div>
                  @endif
                @else
                  <span class="muted">never reported</span>
                  {{-- The crew's handset is the tracker until a hardware unit
                       exists, so "never" usually means the Fleet app has not
                       run a trip on this bus yet. --}}
                @endif
              </td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card" style="margin-top:18px">
      <div class="card-head"><h2>Documents</h2><span class="spacer"></span>
        @php $st = $bus->complianceStatus(); @endphp
        @if($st === 'ok')<span class="pill ok">OK</span>
        @elseif($st === 'expiring')<span class="pill warn">expiring</span>
        @else<span class="pill danger">action needed</span>@endif
      </div>
      <div class="card-body tight">
        <table class="tbl">
          <thead><tr><th>Document</th><th>Expires</th><th>State</th></tr></thead>
          <tbody>
          @foreach(\App\Models\Bus::DOCUMENTS as $col => $label)
            @php
              $val  = $bus->$col;
              $left = $val ? (int) \Carbon\Carbon::today()
                        ->diffInDays(\Carbon\Carbon::parse($val), false) : null;
            @endphp
            <tr>
              <td>{{ $label }}</td>
              <td class="muted">{{ $val ?: '—' }}</td>
              <td>
                @if($val === null)<span class="pill danger">missing</span>
                @elseif($left < 0)<span class="pill danger">expired {{ abs($left) }}d ago</span>
                @elseif($left <= 30)<span class="pill warn">{{ $left }}d left</span>
                @else<span class="pill ok">valid</span>@endif
              </td>
            </tr>
          @endforeach
            <tr><td>First aid checked</td>
              <td class="muted">{{ $bus->first_aid_checked_on ?: '—' }}</td>
              <td>@if($bus->first_aid_checked_on)<span class="pill ok">done</span>
                  @else<span class="pill warn">not recorded</span>@endif</td></tr>
            <tr><td>Extinguisher checked</td>
              <td class="muted">{{ $bus->extinguisher_checked_on ?: '—' }}</td>
              <td>@if($bus->extinguisher_checked_on)<span class="pill ok">done</span>
                  @else<span class="pill warn">not recorded</span>@endif</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col">
    <div class="card">
      <div class="card-head"><h2>Everyday crew</h2><span class="spacer"></span>
        <span class="hint">the default — a stand-in is set on the Daily roster</span>
      </div>
      <div class="card-body tight">
        @if($assignments->count())
          <table class="tbl">
            <thead><tr><th>Route</th><th>Direction</th><th>Driver</th><th>Attendant</th></tr></thead>
            <tbody>
            @foreach($assignments as $a)
              <tr>
                <td><b>{{ $a->route?->code }}</b>
                  <span class="muted">{{ $a->route?->name }}</span>
                  @if($a->bell_tier)<div class="muted" style="font-size:11px">{{ $a->bell_tier }}</div>@endif</td>
                <td><span class="pill {{ $a->direction === 'Morning' ? 'morning' : 'afternoon' }}">
                  {{ $a->direction }}</span></td>
                <td>@if($a->driver)
                      <a href="{{ route('staff.show', $a->driver) }}">{{ $a->driver->name }}</a>
                    @else <span class="muted">—</span> @endif</td>
                <td>@if($a->attendant)
                      <a href="{{ route('staff.show', $a->attendant) }}">{{ $a->attendant->name }}</a>
                    @else
                      {{-- Invariant #1 lives on the attendant. --}}
                      <span class="pill danger">none</span>
                    @endif</td>
              </tr>
            @endforeach
            </tbody>
          </table>
        @else
          <div class="empty"><div class="big">🚌</div>
            <div class="t">Not on any route</div>
            <div class="s">{{ $bus->reg_no }} has no everyday route assignment yet.</div></div>
        @endif
      </div>
    </div>

    <div class="card" style="margin-top:18px">
      <div class="card-head"><h2>Trips</h2><span class="spacer"></span>
        <span class="hint">last {{ $trips->count() }} · who was actually on board</span>
      </div>
      <div class="card-body tight">
        @if($trips->count())
          <table class="tbl">
            <thead><tr><th>Date</th><th>Route</th><th>Crew</th><th>Status</th></tr></thead>
            <tbody>
            @foreach($trips as $t)
              <tr>
                <td class="muted">{{ $t->service_date }}
                  <div><span class="pill {{ $t->direction === 'Morning' ? 'morning' : 'afternoon' }}"
                        style="font-size:10px">{{ $t->direction }}</span></div></td>
                <td><a href="{{ route('trips.show', $t) }}"><b>{{ $t->route?->code }}</b></a>
                  <div class="muted" style="font-size:11px">{{ $t->bell_tier }}</div></td>
                <td class="muted" style="font-size:12px">
                  {{ $t->driver?->name ?? '—' }}
                  <div>{{ $t->attendant?->name ?? '—' }}</div>
                </td>
                <td>
                  @if($t->status === 'completed')<span class="pill ok">Done</span>
                  @elseif($t->status === 'started')<span class="pill teal">Running</span>
                  @else<span class="pill">{{ ucfirst($t->status) }}</span>@endif
                  @if($t->status === 'completed' && ! $t->sweep_verified_at)
                    <div><span class="pill danger" style="font-size:10px">no sweep</span></div>
                  @endif
                </td>
              </tr>
            @endforeach
            </tbody>
          </table>
        @else
          <div class="empty"><div class="big">🗓</div>
            <div class="t">No trips yet</div>
            <div class="s">Trips appear here once {{ $bus->reg_no }} runs one.</div></div>
        @endif
      </div>
    </div>
  </div>

</div>
@endsection
