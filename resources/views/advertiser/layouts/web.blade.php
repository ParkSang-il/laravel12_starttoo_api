<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
@hasSection('noindex')<meta name="robots" content="noindex, nofollow">@endif
<meta name="referrer" content="no-referrer">
<title>@yield('title') · STARTTOO 광고</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Noto+Sans+KR:wght@400;500;700;900&family=Unbounded:wght@500;700;800&display=swap">
<link rel="stylesheet" href="{{ asset('advertiser/adv.css') }}?v={{ @filemtime(public_path('advertiser/adv.css')) }}">
</head>
<body>
<header class="adv-top" style="background: #0E0E0F; color: #F2EFEA">
  <div class="adv-wrap adv-top-in" style="min-height: 68px; display: flex; flex-wrap: wrap; align-items: center; gap: 12px 24px">
    <a href="{{ route('advertiser.products') }}" class="adv-brand" style="display: flex; align-items: baseline; gap: 10px; color: #F2EFEA; text-decoration: none">
      <span class="adv-display" style="font-weight: 800; font-size: 20px">STARTTOO<span style="color: #FF5A36">.</span></span>
      <span style="font-size: 13px; color: #A19D96">광고 · 제휴</span>
    </a>
    <nav aria-label="광고 메뉴" class="adv-menu" style="display: flex; flex-wrap: wrap; gap: 4px; flex: 1">
      <a href="{{ route('advertiser.products') }}" class="adv-nav" @if (request()->routeIs('advertiser.products')) aria-current="page" @endif>상품 소개</a>
      <a href="{{ route('advertiser.inquiry') }}" class="adv-nav" @if (request()->routeIs('advertiser.inquiry*')) aria-current="page" @endif>광고 문의</a>
      @if ($advertiserMe ?? null)
        <a href="{{ route('advertiser.account') }}" class="adv-nav" @if (request()->routeIs('advertiser.account*')) aria-current="page" @endif>내 광고</a>
      @endif
    </nav>
    @yield('top-right')
    @if ($advertiserMe ?? null)
      <form method="POST" action="{{ route('advertiser.logout') }}" class="adv-acct" style="display: flex; align-items: center; gap: 10px">@csrf
        <span class="adv-acct-name" style="font-size: 13px; color: #BDB8B0">{{ $advertiserMe->company }}</span>
        <button type="submit" style="height: 36px; padding: 0 12px; border-radius: 10px; border: 1px solid #3A3632; background: none; color: #F2EFEA; font-size: 13px; font-weight: 700">로그아웃</button>
      </form>
    @elseif (! request()->routeIs('advertiser.login'))
      <a href="{{ route('advertiser.login') }}" class="adv-acct" style="height: 36px; padding: 0 14px; border-radius: 10px; border: 1px solid #3A3632; color: #F2EFEA; font-size: 13px; font-weight: 700; display: inline-flex; align-items: center; text-decoration: none">광고주 로그인</a>
    @endif
  </div>
</header>

@if (session('status'))
  <div role="status" class="adv-wrap" style="margin-top: 20px"><div style="border-radius: 12px; background: #E3F4E9; color: #1D6B3A; padding: 12px 16px; font-size: 14px; font-weight: 700">{{ session('status') }}</div></div>
@endif

@yield('content')

<footer class="adv-foot" style="border-top: 1px solid #E4E0D8; margin-top: 56px">
  @php $co = config('advertiser.company'); @endphp
  <div class="adv-wrap" style="padding-top: 24px; padding-bottom: 40px; display: flex; flex-wrap: wrap; justify-content: space-between; gap: 12px; font-size: 12px; color: #77736B; line-height: 1.7">
    <span>{{ $co['name'] }} · 대표 {{ $co['ceo'] }} · 사업자등록번호 {{ $co['biz_no'] }} · 통신판매업 신고 {{ $co['mail_order_no'] }}<br>광고 문의 {{ $co['contact'] }}</span>
    @if (config('advertiser.privacy_url'))<span><a href="{{ config('advertiser.privacy_url') }}" style="color: #77736B">개인정보 처리방침</a></span>@endif
  </div>
</footer>
</body>
</html>
