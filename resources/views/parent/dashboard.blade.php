@extends('layouts.parent')
@section('title', 'Home · Zippi Parent')
@section('heading', 'My children')

@section('content')

@forelse($cards as $card)
  <a class="p-card" href="{{ route('parent.child', $card['child_id']) }}">
    <div class="p-card-head">
      <span class="p-name">{{ $card['name'] }}</span>
      <span class="p-meta">Grade {{ $card['grade'] }}@if($card['bell_tier']) · {{ $card['bell_tier'] }}@endif</span>
    </div>

    @php
      // Colour AND words — never colour alone (see parent.css).
      $tone = match (true) {
        $card['absent']                                 => 'idle',
        $card['status'] === 'not_at_stop'                => 'bad',
        $card['status'] === 'returned_to_school'         => 'bad',
        $card['show_live_map']                           => 'live',
        in_array($card['status'], ['arrived_at_school','alighted_to_guardian','alighted_self_release'], true) => 'ok',
        default                                          => 'idle',
      };
    @endphp

    <span class="p-pill {{ $tone }}">
      {{ $card['absent'] ? 'Marked absent' : $card['status_label'] }}
    </span>

    @if($card['trip'])
      <span class="p-pill {{ $card['trip']['direction'] === 'Morning' ? 'morning' : 'evening' }}"
            style="margin-left:6px">{{ $card['trip']['direction'] }}</span>
    @endif

    <dl style="margin:12px 0 0">
      @if($card['trip'])
        <div class="p-row"><dt>Route</dt><dd>{{ $card['trip']['route'] }}</dd></div>
        @if($card['stop'])
          <div class="p-row">
            <dt>{{ $card['trip']['direction'] === 'Morning' ? 'Pickup' : 'Drop' }}</dt>
            <dd>{{ $card['stop']['name'] }}@if($card['stop']['scheduled_at'])
              · {{ \Carbon\Carbon::parse($card['stop']['scheduled_at'])->format('g:i A') }}@endif</dd>
          </div>
        @endif
      @else
        <div class="p-row"><dt>Today</dt><dd>No trip scheduled</dd></div>
      @endif
    </dl>
  </a>
@empty
  <div class="p-card">
    <div class="p-name">No children linked yet</div>
    <p class="p-hint" style="margin-top:8px">
      Your school hasn't linked any students to this number. Please contact the
      school office.
    </p>
  </div>
@endforelse

<p class="p-hint" style="margin-top:18px">
  Signed in as {{ $guardian->name }} · {{ $guardian->phone }}
</p>
@endsection
