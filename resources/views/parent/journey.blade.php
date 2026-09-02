@extends('layouts.parent')
@section('title', 'Journey · ' . $child->name)
@section('heading', 'Journey')
@section('back', route('parent.child', $child->id))

@section('content')

<p class="p-hint" style="margin:0 0 14px">
  {{ $child->name }}'s last 90 days. Every entry is recorded by the bus
  attendant at the time it happened.
</p>

@forelse($days as $date => $rows)
  <div class="p-section-title">{{ \Carbon\Carbon::parse($date)->format('D j M Y') }}</div>
  <div class="p-card">
    @if($absences->has($date))
      <span class="p-pill idle">
        Absent —
        {{ $absences[$date]->pluck('direction')->unique()->implode(' & ') }}
      </span>
    @endif

    @foreach($rows as $r)
      @php $t = $r->trip; @endphp
      <div style="margin-top:10px">
        <span class="p-pill {{ $t->direction === 'Morning' ? 'morning' : 'evening' }}">
          {{ $t->direction }}</span>
        <span class="p-meta" style="margin-left:6px">{{ $t->route?->code }}</span>
      </div>

      <ul class="p-timeline" style="margin-top:6px">
        @if($r->boarded_at)
          <li><span class="p-time">{{ $r->boarded_at->format('g:i A') }}</span>
              <span>Boarded{{ $r->stop ? ' at ' . $r->stop->name : '' }}</span></li>
        @endif
        @if($t->arrived_at_school_at && $r->status === 'arrived_at_school')
          <li><span class="p-time">{{ $t->arrived_at_school_at->format('g:i A') }}</span>
              <span>Reached school</span></li>
        @endif
        @if($r->alighted_at)
          <li><span class="p-time">{{ $r->alighted_at->format('g:i A') }}</span>
              <span>@switch($r->status)
                @case('alighted_self_release') Got off{{ $r->stop ? ' at ' . $r->stop->name : '' }} @break
                @case('returned_to_school') Returned to school — nobody at the stop @break
                @default Handed over{{ $r->stop ? ' at ' . $r->stop->name : '' }}
              @endswitch</span></li>
        @endif
        @if($r->status === 'not_at_stop')
          <li><span class="p-time">—</span><span>Not at the stop when the bus arrived</span></li>
        @endif
        @if($r->status === 'pending' && $t->status === 'completed')
          <li><span class="p-time">—</span><span>No boarding recorded</span></li>
        @endif
      </ul>
    @endforeach
  </div>
@empty
  <div class="p-card">
    <div class="p-name">Nothing yet</div>
    <p class="p-hint" style="margin-top:8px">
      {{ $child->name }}'s trips will appear here once the bus starts running.
    </p>
  </div>
@endforelse
@endsection
