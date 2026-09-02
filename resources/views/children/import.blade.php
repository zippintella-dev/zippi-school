@extends('layouts.app')
@section('title', 'Import students')
@section('subtitle', 'Preview before anything is written (PART M4)')

@section('actions')
  <a class="btn ghost" href="{{ route('children.import.template') }}">Download template</a>
  <a class="btn ghost" href="{{ route('children.index') }}">Back to students</a>
@endsection

@section('content')
@include('partials.errors')

<div class="grid main-side">
  <div class="card">
    <div class="card-head"><h2>Upload CSV</h2></div>
    <div class="card-body">
      <div class="note" style="margin-bottom:16px">
        <b>Nothing is written until you confirm.</b> The enterprise importer does
        a straight insert; a student import is higher stakes — a bad file silently
        creates hundreds of wrong parent relationships. This one parses, reports
        per-row warnings, and only writes on your say-so.
      </div>

      <form method="POST" action="{{ route('children.import.preview') }}" enctype="multipart/form-data">
        @csrf
        <div class="field">
          <label>CSV file</label>
          <input class="input" type="file" name="csv_file" accept=".csv,text/csv" required>
          <div class="help">Max 5 MB. First row must be the header.</div>
        </div>
        <button class="btn" type="submit">Preview import</button>
      </form>
    </div>
  </div>

  <div>
    <div class="card" style="margin-bottom:16px">
      <div class="card-head"><h2>Columns</h2></div>
      <div class="card-body">
        <div class="sectitle">Required</div>
        <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px">
          @foreach($required as $c)<span class="pill danger">{{ $c }}</span>@endforeach
        </div>
        <div class="sectitle">Optional</div>
        <div style="display:flex;flex-wrap:wrap;gap:6px">
          @foreach(array_diff($known, $required) as $c)<span class="pill muted">{{ $c }}</span>@endforeach
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h2>How matching works</h2></div>
      <div class="card-body">
        <dl class="kv" style="grid-template-columns:120px 1fr">
          <dt>Duplicates</dt>
          <dd>Matched on <code>admission_no</code> within this school. An existing
            student is <b>updated</b>, never duplicated.</dd>
          <dt>Routes</dt>
          <dd>Matched on <code>route_code</code> (e.g. RT-01) and stop <b>name</b>.
            An unknown name warns — the student still imports, just unassigned.</dd>
          <dt>Guardians</dt>
          <dd>Matched on phone. Created but <b>not invited</b> — invites are a
            separate action so a mis-import never SMSes 500 families.</dd>
        </dl>
        @if($routes->count())
          <div class="sectitle" style="margin-top:14px">Your route codes</div>
          <div style="display:flex;flex-wrap:wrap;gap:6px">
            @foreach($routes as $r)<span class="pill teal">{{ $r->code }}</span>@endforeach
          </div>
        @endif
      </div>
    </div>
  </div>
</div>
@endsection
