@extends('layouts.app')
@section('title', 'Students')
@section('subtitle', $total . ' enrolled · a child has zero logins and N guardians (PART E1)')

@section('actions')
  @if(($removedTotal ?? 0) > 0)
    <a class="btn ghost" href="{{ route('children.removed') }}">Removed ({{ $removedTotal }})</a>
  @endif
  <a class="btn ghost" href="{{ route('children.import') }}">Import CSV</a>
  <a class="btn" href="{{ route('children.create') }}">+ Add student</a>
@endsection

@section('content')

@if($unassignedCount > 0)
  <div class="note warn" style="margin-bottom:16px">
    <b>{{ $unassignedCount }} student(s) have no stop assignment.</b>
    They will not appear on any route, roster or trip until assigned.
    <a href="{{ route('children.index') }}?unassigned=1">Show them →</a>
  </div>
@endif
<div class="card">
  <form method="GET" class="filters">
    <div class="field"><label>Search</label>
      <input class="input" name="q" placeholder="Name or admission no"
             value="{{ $filters['q'] ?? '' }}"></div>
    <div class="field"><label>Grade</label>
      <select class="input" name="grade"><option value="">All</option>
        @foreach($grades as $g)
          <option value="{{ $g }}" @selected(($filters['grade'] ?? '') == $g)>Grade {{ $g }}</option>
        @endforeach
      </select></div>
    <div class="field"><label>Bell tier</label>
      <select class="input" name="tier"><option value="">All tiers</option>
        @foreach($tiers as $l)
          <option value="{{ $l }}" @selected(($filters['tier'] ?? '') === $l)>{{ $l }}</option>
        @endforeach
      </select></div>
    <div class="field"><label>Route</label>
      <select class="input" name="route_id"><option value="">All</option>
        @foreach($routes as $r)
          <option value="{{ $r->id }}" @selected(($filters['route_id'] ?? '') == $r->id)>{{ $r->code }}</option>
        @endforeach
      </select></div>
    <div class="field"><label>Assignment</label>
      <select class="input" name="unassigned">
        <option value="">All</option>
        <option value="1" @selected(($filters['unassigned'] ?? '') === '1')>Unassigned only</option>
      </select></div>
    <button class="btn" type="submit">Filter</button>
    <a class="btn ghost" href="{{ route('children.index') }}">Reset</a>
  </form>

  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr>
        <th>Student</th><th>Grade</th><th>Bell tier</th><th>Route &amp; stop</th>
        <th>Guardians</th><th>Self release</th><th></th>
      </tr></thead>
      <tbody>
      @forelse($children as $c)
        @php $am = $c->assignmentFor('Morning'); @endphp
        <tr>
          <td>
            <div class="who-cell">
              <span class="avatar">{{ \Illuminate\Support\Str::of($c->name)->substr(0,1) }}</span>
              <div><div class="nm">{{ $c->name }}</div>
                <div class="mt">{{ $c->admission_no }}</div></div>
            </div>
          </td>
          <td>{{ $c->grade }}-{{ $c->section }}</td>
          <td class="muted">{{ $c->bell_tier ?? '—' }}</td>
          <td>
            @if($am)
              <b>{{ $am->route?->code }}</b>
              <div class="muted" style="font-size:11.5px">{{ $am->stop?->name }}</div>
            @else <span class="muted">Unassigned</span> @endif
          </td>
          <td class="num">{{ $c->guardians->count() }}</td>
          <td>
            @if($c->canSelfRelease())<span class="pill ok">Yes</span>
            @else<span class="pill muted">No</span>@endif
          </td>
          <td style="white-space:nowrap">
            <a class="btn sm ghost" href="{{ route('children.journey', $c) }}">Journey</a>
            <a class="btn sm ghost" href="{{ route('children.show', $c) }}">Record</a>
            <a class="btn sm ghost" href="{{ route('children.edit', $c) }}">Edit</a>
            <form method="POST" action="{{ route('children.destroy', $c) }}" style="display:inline"
                  onsubmit="return confirm('Remove {{ addslashes($c->name) }} from transport? Journey history is kept and you can put them back from the Removed list.')">
              @csrf @method('DELETE')
              <button class="btn sm danger" type="submit" title="Remove from transport">Remove</button>
            </form>
          </td>
        </tr>
      @empty
        <tr><td colspan="7"><div class="empty"><div class="big">🔍</div>
          <div class="t">No students match</div>
          @if(($removedTotal ?? 0) > 0)
            <div class="s">{{ $removedTotal }} student{{ $removedTotal === 1 ? ' is' : 's are' }}
              off transport — see <a href="{{ route('children.removed') }}">Removed</a>.</div>
          @endif
          </div></td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  <div class="card-body">{{ $children->links() }}</div>
</div>
@endsection
