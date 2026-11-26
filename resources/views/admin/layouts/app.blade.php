<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title') · STARTTOO 운영 콘솔</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Noto+Sans+KR:wght@400;500;700;900&family=Unbounded:wght@500;700;800&display=swap">
<link rel="stylesheet" href="{{ asset('admin-assets/admin.css') }}?v={{ @filemtime(public_path('admin-assets/admin.css')) }}">
</head>
<body>
<div style="min-height: 100vh; background: #F4F2EE; color: #141413; font-family: 'Noto Sans KR', system-ui, sans-serif; display: flex; flex-wrap: wrap">
  @include('admin.partials.sidebar')
  @yield('content')
</div>
@include('admin.partials.flash')
<script src="{{ asset('admin-assets/admin.js') }}?v={{ @filemtime(public_path('admin-assets/admin.js')) }}" defer></script>
</body>
</html>
