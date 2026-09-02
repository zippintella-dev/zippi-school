<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  {{-- viewport-fit=cover + the safe-area padding in parent.css keeps the bottom
       bar clear of the iPhone home indicator when installed to the home screen. --}}
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#0FA896">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <meta name="apple-mobile-web-app-title" content="Zippi Parent">
  <title>@yield('title', 'Zippi Parent')</title>
  <link rel="manifest" href="{{ asset('parent.webmanifest') }}">
  <link rel="stylesheet" href="{{ asset('css/zippi.css') }}">
  <link rel="stylesheet" href="{{ asset('css/parent.css') }}">
  <link rel="apple-touch-icon" href="{{ asset('parent-icon.png') }}">
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🚌</text></svg>">
</head>
<body class="p-body">

<header class="p-top">
  <div class="p-top-inner">
    @hasSection('back')
      <a class="p-back" href="@yield('back')" aria-label="Back">‹</a>
    @endif
    <div class="p-top-title">@yield('heading', 'Zippi Parent')</div>
    @auth('guardian')
      <form method="POST" action="{{ route('parent.logout') }}" class="p-top-action">
        @csrf
        <button class="p-linkbtn" type="submit">Sign out</button>
      </form>
    @endauth
  </div>
</header>

<main class="p-main">
  @if(session('ok'))
    <div class="p-flash ok">{{ session('ok') }}</div>
  @endif
  @if(session('error'))
    <div class="p-flash bad">{{ session('error') }}</div>
  @endif

  @yield('content')
</main>

@auth('guardian')
<nav class="p-tabs">
  @php $rn = request()->route()?->getName(); @endphp
  <a class="p-tab {{ $rn === 'parent.dashboard' ? 'on' : '' }}" href="{{ route('parent.dashboard') }}">
    <span class="p-tab-ico">▦</span><span>Home</span>
  </a>
  @isset($child)
    <a class="p-tab {{ $rn === 'parent.child' ? 'on' : '' }}" href="{{ route('parent.child', $child->id) }}">
      <span class="p-tab-ico">◉</span><span>Live</span>
    </a>
    <a class="p-tab {{ $rn === 'parent.child.journey' ? 'on' : '' }}" href="{{ route('parent.child.journey', $child->id) }}">
      <span class="p-tab-ico">↻</span><span>Journey</span>
    </a>
  @endisset
</nav>
@endauth

<script>
  // PWA shell. Registered only over HTTPS or on localhost — service workers are
  // refused on plain http, and a failed registration logs a console error that
  // looks like a bug when it is just the protocol.
  if ('serviceWorker' in navigator &&
      (location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1')) {
    navigator.serviceWorker.register('{{ asset('parent-sw.js') }}').catch(function () {});
  }
</script>
@yield('scripts')
</body>
</html>
