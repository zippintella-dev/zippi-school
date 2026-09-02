@extends('layouts.app')
@section('title', 'Import preview')
@section('subtitle', $summary['total'] . ' rows parsed · nothing written yet')

@section('actions')
  <a class="btn ghost" href="{{ route('children.import') }}">← Upload a different file</a>
@endsection

@section('content')

<div class="grid c4" style="margin-bottom:18px">
  <div class="stat ok"><span class="rail"></span><div class="label">Will create</div>
    <div class="value">{{ $summary['create'] }}</div><div class="foot">new students</div></div>
  <div class="stat"><span class="rail"></span><div class="label">Will update</div>
    <div class="value">{{ $summary['update'] }}</div><div class="foot">already enrolled</div></div>
  <div class="stat {{ $summary['skip'] ? 'danger' : '' }}"><span class="rail"></span>
    <div class="label">Will skip</div><div class="value">{{ $summary['skip'] }}</div>
    <div class="foot">unusable rows</div></div>
  <div class="stat {{ $summary['warned'] ? 'warn' : 'ok' }}"><span class="rail"></span>
    <div class="label">With warnings</div><div class="value">{{ $summary['warned'] }}</div>
    <div class="foot">imported, but check them</div></div>
</div>

@if($unknown)
  <div class="note warn" style="margin-bottom:16px">
    <b>Unrecognised column(s):</b> {{ implode(', ', $unknown) }} — these will be
    ignored. Check for a typo if you expected them to import.
  </div>
@endif

<div class="card" style="margin-bottom:16px">
  <div class="card-head"><h2>Row by row</h2><span class="spacer"></span>
    <span class="hint">showing all {{ count($rows) }}</span></div>
  <div class="tbl-wrap" style="max-height:520px;overflow-y:auto">
    <table class="tbl">
      <thead><tr>
        <th>Line</th><th>Action</th><th>Admission</th><th>Name</th>
        <th>Grade</th><th>Route / stop</th><th>Guardians</th><th>Warnings</th>
      </tr></thead>
      <tbody>
      @foreach($rows as $r)
        <tr style="{{ $r['action'] === 'skip' ? 'opacity:.55' : '' }}">
          <td class="muted">{{ $r['line'] }}</td>
          <td>
            @if($r['action'] === 'create')<span class="pill ok">Create</span>
            @elseif($r['action'] === 'update')<span class="pill info">Update</span>
            @else<span class="pill danger">Skip</span>@endif
          </td>
          <td><b>{{ $r['data']['admission_no'] ?: '—' }}</b></td>
          <td>{{ $r['data']['name'] ?: '—' }}</td>
          <td class="muted">{{ $r['data']['grade'] }}{{ $r['data']['section'] ? '-' . $r['data']['section'] : '' }}</td>
          <td class="muted" style="font-size:12px">
            @if($r['data']['route_code'])
              {{ $r['data']['route_code'] }}
              @if($r['resolved']['morning_stop_id'])
                <span class="pill ok" style="font-size:10px">matched</span>
              @else
                <span class="pill warn" style="font-size:10px">unmatched</span>
              @endif
            @else — @endif
          </td>
          <td class="num">
            {{ ($r['data']['guardian1_phone'] ? 1 : 0) + ($r['data']['guardian2_phone'] ? 1 : 0) }}
          </td>
          <td>
            @foreach($r['warnings'] as $w)
              <div class="pill warn" style="margin-bottom:3px;white-space:normal">{{ $w }}</div>
            @endforeach
          </td>
        </tr>
      @endforeach
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-body" style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
    <form method="POST" action="{{ route('children.import.commit') }}">
      @csrf
      <button class="btn" type="submit">
        Import {{ $summary['create'] + $summary['update'] }} student(s)
      </button>
    </form>
    <a class="btn ghost" href="{{ route('children.import') }}">Cancel</a>
    <span class="spacer" style="flex:1"></span>
    <span class="muted" style="font-size:12.5px">
      Guardians will be created but <b>not invited</b>. Send invites separately
      once you've checked the import.
    </span>
  </div>
</div>
@endsection
