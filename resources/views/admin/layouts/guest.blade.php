<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>@yield('title') · STARTTOO 운영 콘솔</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Noto+Sans+KR:wght@400;500;700;900&family=Unbounded:wght@500;700;800&display=swap">
<link rel="stylesheet" href="{{ asset('admin-assets/admin.css') }}">
</head>
<body>
@yield('content')
@include('admin.partials.flash')
<script src="{{ asset('admin-assets/admin.js') }}" defer></script>
</body>
</html>
