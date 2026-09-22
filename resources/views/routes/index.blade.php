@extends('layouts.app')
@section('title', 'Routes & stops')
@section('subtitle', 'Stop order is authored and frozen — never re-sequenced at trip time (PART G1)')

@section('actions')
  <a class="btn" href="{{ route('routes.create') }}">+ New route</a>
@endsection

@section('content')
<div class="note" style="margin-bottom:18px">
  <b>Why there is no nearest-neighbour here.</b> The enterprise module re-orders
  stops from the driver's start position on every ride. A school cannot: parents
  stand at a published stop at a published time, term after term. Optimisation
  moves to route <i>design</i> time; trip execution renders the authored order.
</div>

<div class="grid c2">
  @foreach($routes as $route)
    @php $am = $route->staffAssignments->firstWhere('direction','Morning'); @endphp
    <div class="card">
      <div class="card-head">
        <span class="dir-strip Morning" style="height:26px"></span>
        <div>
          <h2>{{ $route->code }}</h2>
          <div class="hint">{{ $route->name }}</div>
        </div>
        <span class="spacer"></span>
        <span class="pill neutral">{{ $route->bell_tier }}</span>
        <span class="pill teal">{{ $counts[$route->id] ?? 0 }} students</span>
      </div>
      <div class="card-body tight">
        @foreach($route->stops as $s)
          <div style="display:flex;align-items:center;gap:10px;padding:8px 16px;
                      border-bottom:1px solid var(--line-soft)">
            <span class="avatar sq" style="width:24px;height:24px;flex:0 0 24px;font-size:10px">
              {{ $s->sequence }}</span>
            <div style="flex:1;min-width:0">
              <div style="font-weight:600;font-size:13px">{{ $s->name }}</div>
              <div class="muted" style="font-size:11px">
                {{ number_format((float) $s->latitude, 4) }}, {{ number_format((float) $s->longitude, 4) }}</div>
            </div>
            <span class="pill muted">{{ $s->childCount() }}</span>
          </div>
        @endforeach
        <div style="display:flex;align-items:center;gap:10px;padding:8px 16px;background:#FAFBFC">
          <span class="avatar sq" style="width:24px;height:24px;flex:0 0 24px;font-size:11px;
                background:var(--ink);color:#fff;border-color:var(--ink)">🏫</span>
          <div style="flex:1;font-weight:650;font-size:13px">School gate</div>
        </div>
      </div>
      <div class="card-body" style="border-top:1px solid var(--line-soft);
           display:flex;gap:14px;font-size:12px;color:var(--ink-3);flex-wrap:wrap">
        <span>🚍 {{ $am?->driver?->name ?? 'No driver' }}</span>
        <span>👤 {{ $am?->attendant?->name ?? 'No attendant' }}</span>
        <span>▭ {{ $am?->bus?->reg_no ?? 'No bus' }}</span>
        <span class="spacer" style="flex:1"></span>
        <a href="{{ route('routes.show', $route) }}">Detail &amp; edit →</a>
      </div>
    </div>
  @endforeach
</div>
@endsection
