<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>@yield('title', 'Dashboard') · Zippi School Mobility</title>
  <link rel="stylesheet" href="{{ asset('css/zippi.css') }}">
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🚌</text></svg>">
</head>
<body>
<div class="shell">

  <aside class="sidebar">
    <div class="sidebar-brand">
      <div class="mark"><span class="dot">Z</span> Zippi School</div>
      <div class="sub">{{ $activeSchool->name ?? 'Control Tower' }}</div>
    </div>

    @php
      $r = request()->route()?->getName();
      $is = fn($n) => str_starts_with((string) $r, $n) ? 'active' : '';
    @endphp

    <div class="nav-group">
      <div class="nav-group-label">Operations</div>
      <a class="nav-item {{ $is('dashboard') }}" href="{{ route('dashboard') }}">
        <span class="ico">▦</span> Dashboard</a>
      <a class="nav-item {{ $is('live') }}" href="{{ route('live') }}">
        <span class="ico">◉</span> Live board
        @if(($navOpenAlerts ?? 0) > 0)
          <span class="count alert">{{ $navOpenAlerts }}</span>
        @endif
      </a>
      <a class="nav-item {{ $is('roster') }}" href="{{ route('roster') }}">
        <span class="ico">▤</span> Daily roster</a>
      <a class="nav-item {{ $is('trips') }}" href="{{ route('trips.index') }}">
        <span class="ico">↻</span> Trips &amp; history</a>
    </div>

    <div class="nav-group">
      <div class="nav-group-label">School</div>
      {{-- ⚠ Exact match, not str_starts_with: `children.removed` must not also
           light up Students, or the sidebar shows two active items at once. --}}
      <a class="nav-item {{ $r === 'children.removed' ? '' : $is('children') }}"
         href="{{ route('children.index') }}">
        <span class="ico">☺</span> Students</a>
      <a class="nav-item {{ $r === 'children.removed' ? 'active' : '' }}"
         href="{{ route('children.removed') }}">
        <span class="ico">⊘</span> Removed
        @if(($navRemovedStudents ?? 0) > 0)
          <span class="count">{{ $navRemovedStudents }}</span>
        @endif
      </a>
      <a class="nav-item {{ $is('routes') }}" href="{{ route('routes.index') }}">
        <span class="ico">⤳</span> Routes &amp; stops</a>
      <a class="nav-item {{ $is('calendar') }}" href="{{ route('calendar') }}">
        <span class="ico">▣</span> Calendar</a>
      <a class="nav-item {{ $is('settings') }}" href="{{ route('settings') }}">
        <span class="ico">⚙</span> Settings</a>
    </div>

    <div class="nav-group">
      <div class="nav-group-label">Fleet</div>
      <a class="nav-item {{ $is('buses') }}" href="{{ route('buses.index') }}">
        <span class="ico">▭</span> Buses</a>
      <a class="nav-item {{ $is('staff') }}" href="{{ route('staff.index') }}">
        <span class="ico">☗</span> Drivers &amp; attendants</a>
      <a class="nav-item {{ $is('compliance') }}" href="{{ route('compliance') }}">
        <span class="ico">✓</span> Compliance
        @if(($navCompliance ?? 0) > 0)
          <span class="count alert">{{ $navCompliance }}</span>
        @endif
      </a>
    </div>

    <div class="sidebar-foot">
      <div class="who">{{ auth()->user()->name }}</div>
      <div class="role">{{ auth()->user()->roleLabel() }}</div>
      <form method="POST" action="{{ route('logout') }}">@csrf
        <button type="submit">Sign out</button>
      </form>
    </div>
  </aside>

  <div class="main">
    <header class="topbar">
      <div>
        <h1>@yield('title', 'Dashboard')</h1>
        @hasSection('subtitle')<div class="sub">@yield('subtitle')</div>@endif
      </div>
      <div class="spacer"></div>
      @yield('actions')
    </header>

    <main class="content">
      @if(session('ok'))    <div class="flash">{{ session('ok') }}</div>@endif
      @if(session('error')) <div class="flash err">{{ session('error') }}</div>@endif
      @yield('content')
    </main>
  </div>

</div>
</body>
</html>
