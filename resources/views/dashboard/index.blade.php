@extends('layouts.app')
@section('title', 'Dashboard')
@section('subtitle', $dayDescription)

@section('actions')
  <a class="btn ghost" href="{{ route('roster') }}">Daily roster</a>
  <a class="btn" href="{{ route('live') }}">Live board →</a>
@endsection

@section('content')

  @unless($isSchoolDay)
    <div class="note warn" style="margin-bottom:18px">
      <b>No school today.</b> {{ $dayDescription }} — no trips generated.
      Next school day is {{ \Carbon\Carbon::parse($nextSchoolDay)->format('D j M') }}.
      <span class="muted">The calendar drives generation (PART C3), so nothing is dispatched into an empty school.</span>
    </div>
  @endunless

  {{-- ---------------- Stat row ---------------- --}}
  <div class="grid c4" style="margin-bottom:18px">
    <div class="stat {{ $counts['on_board'] > 0 ? '' : 'ok' }}">
      <span class="rail"></span>
      <div class="label">On board now</div>
      <div class="value">{{ $counts['on_board'] }}<small> / {{ $counts['expected'] }}</small></div>
      <div class="foot">{{ $counts['at_school'] }} already at school</div>
    </div>

    <div class="stat ok">
      <span class="rail"></span>
      <div class="label">Boarded today</div>
      <div class="value">{{ $counts['boarded'] }}</div>
      <div class="foot">
        @php $rate = $counts['expected'] > 0 ? round($counts['boarded'] / $counts['expected'] * 100) : 0; @endphp
        {{ $rate }}% of expected riders
      </div>
    </div>

    <div class="stat {{ $counts['not_at_stop'] > 0 ? 'warn' : '' }}">
      <span class="rail"></span>
      <div class="label">Absent / not at stop</div>
      <div class="value">{{ $counts['absent'] }}<small> · {{ $counts['not_at_stop'] }}</small></div>
      <div class="foot">marked absent · missed the stop</div>
    </div>

    <div class="stat {{ $alerts->where('severity','critical')->count() ? 'danger' : ($alerts->count() ? 'warn' : 'ok') }}">
      <span class="rail"></span>
      <div class="label">Needs attention</div>
      <div class="value">{{ $alerts->count() }}</div>
      <div class="foot">
        {{ $alerts->where('severity','critical')->count() }} critical ·
        {{ $openIncidents->count() }} open incident{{ $openIncidents->count() === 1 ? '' : 's' }}
      </div>
    </div>
  </div>

  <div class="grid main-side">

    {{-- ---------------- Today's trips ---------------- --}}
    <div class="card">
      <div class="card-head">
        <h2>Today's trips</h2>
        <span class="spacer"></span>
        <span class="hint">{{ $trips->where('status','started')->count() }} running ·
          {{ $trips->where('status','completed')->count() }} done ·
          {{ $trips->where('status','scheduled')->count() }} up next</span>
      </div>
      <div class="card-body tight">
        @forelse($trips as $trip)
          @php
            $expected = $trip->tripChildren->whereNotIn('status','absent')->count();
            $done = $trip->tripChildren->whereIn('status',
                     ['boarded','arrived_at_school','alighted_to_guardian','alighted_self_release'])->count();
            $pct = $expected > 0 ? round($done / $expected * 100) : 0;
          @endphp
          <a class="trip" href="{{ route('trips.show', $trip) }}" style="color:inherit;text-decoration:none">
            <span class="dir-strip {{ $trip->direction }}"></span>
            <div class="body">
              <div class="row1">
                <span class="code">{{ $trip->route->code }}</span>
                <span class="rname">{{ $trip->route->name }}</span>
                <span class="pill {{ $trip->direction === 'Morning' ? 'morning' : 'afternoon' }}">
                  {{ $trip->direction }}</span>
                @if($trip->bell_tier)<span class="pill neutral">{{ $trip->bell_tier }}</span>@endif
                @if($trip->status === 'started')
                  <span class="pill teal live">Now running</span>
                @elseif($trip->status === 'completed')
                  <span class="pill ok">Done</span>
                @else
                  <span class="pill muted">Up next</span>
                @endif
                @if($trip->sweepPending())
                  <span class="pill danger">Sweep pending</span>
                @endif
              </div>

              <div class="row2">
                <span><b>{{ $done }}</b> / {{ $expected }} students</span>
                <span class="muted">Bus {{ $trip->bus?->reg_no ?? '—' }}</span>
                <span class="muted">{{ $trip->driver?->name ?? 'No driver' }} ·
                  {{ $trip->attendant?->name ?? 'No attendant' }}</span>
                @if($trip->bell_time)
                  <span class="muted">Bell {{ \Carbon\Carbon::parse($trip->bell_time)->format('g:i A') }}</span>
                @endif
              </div>

              <div class="prog"><i style="width: {{ $pct }}%"></i></div>
            </div>
          </a>
        @empty
          <div class="empty">
            <div class="big">🚌</div>
            <div class="t">No trips today</div>
            <div class="s">{{ $dayDescription }}</div>
          </div>
        @endforelse
      </div>
    </div>

    {{-- ---------------- Needs attention (PART J3) ---------------- --}}
    <div>
      <div class="card" style="margin-bottom:16px">
        <div class="card-head">
          <h2>Needs attention</h2>
          <span class="spacer"></span>
          <span class="hint">severity, then age</span>
        </div>
        <div class="card-body tight">
          <div class="queue">
            @forelse($alerts->take(8) as $a)
              <div class="queue-row">
                <span class="sev {{ $a->severity }}"></span>
                <div class="qmain">
                  <div class="qtitle">{{ $a->typeLabel() }}</div>
                  <div class="qmeta">
                    {{ $a->trip?->route?->code ?? '—' }} · {{ $a->detail }}
                  </div>
                </div>
                <div style="text-align:right">
                  <div class="pill {{ $a->severity === 'critical' ? 'critical' : ($a->severity === 'high' ? 'danger' : 'warn') }}">
                    {{ ucfirst($a->severity) }}</div>
                  <div class="qage" style="margin-top:3px">{{ $a->ageMinutes() }} min</div>
                </div>
              </div>
            @empty
              <div class="empty">
                <div class="big">✓</div>
                <div class="t">Queue is clear</div>
                <div class="s">No open exceptions right now.</div>
              </div>
            @endforelse
          </div>
        </div>
      </div>

      @if($openIncidents->count())
        <div class="card" style="margin-bottom:16px">
          <div class="card-head"><h2>Open incidents</h2></div>
          <div class="card-body tight">
            @foreach($openIncidents as $inc)
              <div class="queue-row">
                <span class="sev {{ $inc->severity }}"></span>
                <div class="qmain">
                  <div class="qtitle">{{ $inc->typeLabel() }}</div>
                  <div class="qmeta">{{ $inc->description }}</div>
                </div>
              </div>
            @endforeach
          </div>
          <div class="card-body" style="border-top:1px solid var(--line-soft);padding-top:11px">
            <div class="note warn" style="font-size:12px">
              An incident cannot be closed without a resolution narrative and a
              closing update to guardians (PART Q2).
            </div>
          </div>
        </div>
      @endif

      {{-- Fleet snapshot --}}
      <div class="card">
        <div class="card-head">
          <h2>Fleet</h2><span class="spacer"></span>
          <span class="hint">{{ $fleet->where('is_ev', true)->count() }} of {{ $fleet->count() }} electric</span>
        </div>
        <div class="card-body">
          <dl class="kv">
            <dt>Students enrolled</dt><dd>{{ $childTotal }}</dd>
            <dt>Buses</dt><dd>{{ $fleet->count() }}</dd>
            <dt>GPS reporting</dt>
            <dd>{{ $fleet->filter(fn($b) => ! $b->isGpsStale())->count() }} / {{ $fleet->count() }}</dd>
            <dt>Absences today</dt><dd>{{ $absences->count() }}</dd>
          </dl>
        </div>
      </div>
    </div>

  </div>

  {{-- ---------------- Absence detail ---------------- --}}
  @if($absences->count())
    <div class="card" style="margin-top:18px">
      <div class="card-head">
        <h2>Absences today</h2><span class="spacer"></span>
        <span class="hint">reason codes feed the attendance export (PART D4)</span>
      </div>
      <div class="card-body tight">
        <div class="tbl-wrap">
          <table class="tbl">
            <thead><tr>
              <th>Child</th><th>Grade</th><th>Direction</th>
              <th>Marked by</th><th>Reason</th>
            </tr></thead>
            <tbody>
            @foreach($absences as $ab)
              <tr>
                <td>
                  <div class="who-cell">
                    <span class="avatar">{{ \Illuminate\Support\Str::of($ab->child?->name)->substr(0,1) }}</span>
                    <span class="nm">{{ $ab->child?->name }}</span>
                  </div>
                </td>
                <td class="muted">{{ $ab->child?->grade }}-{{ $ab->child?->section }}</td>
                <td><span class="pill {{ $ab->direction === 'Morning' ? 'morning' : 'afternoon' }}">
                  {{ $ab->direction }}</span></td>
                <td><span class="pill {{ $ab->bannerTone() }}">{{ $ab->markedByLabel() }}</span></td>
                <td class="muted">{{ $ab->reason ?: '—' }}</td>
              </tr>
            @endforeach
            </tbody>
          </table>
        </div>
      </div>
    </div>
  @endif

@endsection
