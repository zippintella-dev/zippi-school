@extends('layouts.app')
@section('title', 'Removed students')
@section('subtitle', 'Off transport — journey history retained (PART K13)')

@section('actions')
  <a class="btn ghost" href="{{ route('children.index') }}">← Students</a>
@endsection

@section('content')
@include('partials.errors')

<div class="note" style="margin-bottom:16px">
  These students are <b>off transport</b>. They are excluded from routes, the
  daily roster and trip generation, and they do not appear on the Students list.
  Their journey history is kept, so a question about a past trip can still be
  answered. <b>Restore</b> puts a student straight back on the buses from the
  next trip generation.
</div>

<div class="card" style="margin-bottom:16px">
  <div class="card-body">
    <form method="GET" class="filters">
      <div class="field"><label>Search</label>
        <input class="input" name="q" value="{{ $filters['q'] ?? '' }}"
               placeholder="Name or admission no"></div>
      <div class="field"><label>Grade</label>
        <select class="input" name="grade">
          <option value="">All</option>
          @foreach($grades as $g)
            <option value="{{ $g }}" @selected(($filters['grade'] ?? '') == $g)>{{ $g }}</option>
          @endforeach
        </select></div>
      <div class="field"><label>Bell tier</label>
        <select class="input" name="tier">
          <option value="">All</option>
          @foreach($tiers as $t)
            <option value="{{ $t }}" @selected(($filters['tier'] ?? '') === $t)>{{ $t }}</option>
          @endforeach
        </select></div>
      <button class="btn" type="submit">Filter</button>
      <a class="btn ghost" href="{{ route('children.removed') }}">Reset</a>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-head">
    <h2>Removed students</h2><span class="spacer"></span>
    <span class="pill muted">{{ $removedTotal }} off transport</span>
    <span class="pill teal">{{ $total }} active</span>
  </div>
  <div class="card-body tight">
    <table class="tbl">
      <thead><tr>
        <th>Student</th><th>Grade</th><th>Bell tier</th><th>Last route &amp; stop</th>
        <th>Guardians</th><th></th>
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
          <td style="white-space:nowrap">
            <a class="btn sm ghost" href="{{ route('children.journey', $c) }}">Journey</a>
            <a class="btn sm ghost" href="{{ route('children.show', $c) }}">Record</a>
            <form method="POST" action="{{ route('children.restore', $c) }}" style="display:inline">
              @csrf
              <button class="btn sm" type="submit" title="Put back on transport">Restore</button>
            </form>
          </td>
        </tr>
      @empty
        <tr><td colspan="6"><div class="empty"><div class="big">✓</div>
          <div class="t">Nothing removed</div>
          <div class="s">Every student is currently on transport.</div></div></td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  @if($children->hasPages())
    <div class="card-body">{{ $children->links('vendor.pagination.zippi') }}</div>
  @endif
</div>

{{-- Permanent deletion is NOT offered from this list. It is guarded by the
     journey-history check and lives on the student record, so it can never be
     a one-tap action from a bulk view (PART K13). --}}
<div class="note warn" style="margin-top:16px">
  To delete a student outright, open their <b>Record</b>. Deletion is only
  possible when there is no trip, handover, absence or incident attached —
  otherwise the journey record must be kept.
</div>
@endsection
