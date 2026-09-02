@extends('layouts.app')
@section('title', 'Trips & history')
@section('subtitle', 'Every trip, every child, derived from the operational tables (PART D1)')

@section('content')
<div class="card">
  <form method="GET" class="filters">
    <div class="field"><label>From</label>
      <input class="input" type="date" name="from" value="{{ $filters['from'] ?? '' }}"></div>
    <div class="field"><label>To</label>
      <input class="input" type="date" name="to" value="{{ $filters['to'] ?? '' }}"></div>
    <div class="field"><label>Route</label>
      <select class="input" name="route_id"><option value="">All</option>
        @foreach($routes as $r)
          <option value="{{ $r->id }}" @selected(($filters['route_id'] ?? '') == $r->id)>
            {{ $r->code }} — {{ $r->name }}</option>
        @endforeach
      </select></div>
    <div class="field"><label>Direction</label>
      <select class="input" name="direction"><option value="">All</option>
        @foreach(['Morning','Afternoon'] as $d)
          <option value="{{ $d }}" @selected(($filters['direction'] ?? '') === $d)>{{ $d }}</option>
        @endforeach
      </select></div>
    <div class="field"><label>Status</label>
      <select class="input" name="status"><option value="">All</option>
        @foreach(['scheduled','started','completed','cancelled'] as $s)
          <option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ ucfirst($s) }}</option>
        @endforeach
      </select></div>
    <button class="btn" type="submit">Filter</button>
    <a class="btn ghost" href="{{ route('trips.index') }}">Reset</a>
  </form>

  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr>
        <th>Date</th><th>Route</th><th>Direction</th><th>Bell tier</th>
        <th>Crew</th><th>Students</th><th>Sweep</th><th>Status</th><th></th>
      </tr></thead>
      <tbody>
      @forelse($trips as $t)
        @php
          $rows = $t->tripChildren;
          $exp = $rows->whereNotIn('status','absent')->count();
          $got = $rows->whereIn('status',['boarded','arrived_at_school',
                   'alighted_to_guardian','alighted_self_release'])->count();
        @endphp
        <tr>
          <td>{{ \Carbon\Carbon::parse($t->service_date)->format('D j M') }}</td>
          <td><b>{{ $t->route->code }}</b>
            <div class="muted" style="font-size:11.5px">{{ $t->route->name }}</div></td>
          <td><span class="pill {{ $t->direction === 'Morning' ? 'morning' : 'afternoon' }}">
            {{ $t->direction }}</span></td>
          <td class="muted">{{ $t->bell_tier ?? '—' }}</td>
          <td class="muted" style="font-size:12px">
            {{ $t->driver?->name ?? '—' }}<br>{{ $t->attendant?->name ?? '—' }}</td>
          <td class="num">{{ $got }} / {{ $exp }}</td>
          <td>
            @if($t->sweep_verified_at)<span class="pill ok">✓</span>
            @elseif($t->status === 'completed')<span class="pill danger">missing</span>
            @else <span class="pill muted">—</span>@endif
          </td>
          <td>
            @if($t->status === 'started')<span class="pill teal live">Running</span>
            @elseif($t->status === 'completed')<span class="pill ok">Done</span>
            @else<span class="pill muted">{{ $t->statusLabel() }}</span>@endif
          </td>
          <td><a class="btn sm ghost" href="{{ route('trips.show', $t) }}">Open</a></td>
        </tr>
      @empty
        <tr><td colspan="9"><div class="empty"><div class="big">🔍</div>
          <div class="t">No trips match</div></div></td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  <div class="card-body">{{ $trips->links() }}</div>
</div>
@endsection
