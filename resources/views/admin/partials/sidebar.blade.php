@php
  $badges = \App\Support\AdminUi::navBadges();
  $menu = [
    ['route' => 'admin.dashboard', 'label' => '운영 대시보드', 'badge' => null, 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 13h8V3H3zM13 21h8V11h-8zM3 21h8v-6H3zM13 3v6h8V3z"/></svg>'],
    ['route' => 'admin.members', 'label' => '회원 관리', 'badge' => null, 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM2 21c1-4 4-6 7-6s6 2 7 6M17 11a3 3 0 1 0 0-6M22 20c-.6-2.6-2.2-4.2-4-4.8"/></svg>'],
    ['route' => 'admin.artist-review', 'label' => '아티스트 심사', 'badge' => $badges['artistReview'] ?? null, 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l8 3v6c0 4.5-3.4 8.2-8 9-4.6-.8-8-4.5-8-9V6zM8.5 12l2.5 2.5 4.5-5"/></svg>'],
    ['route' => 'admin.contents', 'label' => '게시물 · 작품', 'badge' => null, 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 4h18v16H3zM21 17l-5-5-9 8M9 10a2 2 0 1 0 0-.01"/></svg>'],
    ['route' => 'admin.reports', 'label' => '신고 · 차단', 'badge' => $badges['reports'] ?? null, 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 21V4h11l-1 4 4 1H5"/></svg>'],
    ['route' => 'admin.payments', 'label' => '예약 · 결제', 'badge' => null, 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 5h18v16H3zM3 10h18M8 3v4M16 3v4"/></svg>'],
    ['route' => 'admin.ads', 'label' => '광고 · 프리미엄', 'badge' => null, 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 10v4h3l6 4V6L7 10zM17 9a4 4 0 0 1 0 6"/></svg>'],
    ['route' => 'admin.settings', 'label' => '설정 · 감사 로그', 'badge' => null, 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM4 12h2M18 12h2M12 4v2M12 18v2M6.3 6.3l1.4 1.4M16.3 16.3l1.4 1.4M6.3 17.7l1.4-1.4M16.3 7.7l1.4-1.4"/></svg>'],
  ];
@endphp
<aside style="flex: 1 1 232px; background: #0E0E0F; color: #F2EFEA; padding: 24px 14px; box-sizing: border-box; display: flex; flex-direction: column; gap: 28px">
  <div style="padding: 0 10px; display: flex; flex-direction: column; gap: 4px">
    <span style="font-family: Unbounded, 'Arial Black', sans-serif; font-weight: 800; font-size: 19px; letter-spacing: -0.5px">STARTTOO<span style="color: #FF5A36">.</span></span>
    <span style="font-size: 12px; color: #A19D96">운영 콘솔</span>
  </div>
  <nav aria-label="관리자 메뉴" style="display: flex; flex-direction: column; gap: 2px">
    @foreach ($menu as $m)
      @php $on = request()->routeIs($m['route']); @endphp
      <a href="{{ route($m['route']) }}" @if ($on) aria-current="page" @endif class="admin-nav" style="height: 44px; padding: 0 12px; border-radius: 10px; display: flex; align-items: center; gap: 10px; font-size: 14px; {{ $on ? 'background: #232326; color: #FFFFFF; font-weight: 700' : 'color: #BDB8B0; font-weight: 500' }}">
        {!! $m['icon'] !!}{{ $m['label'] }}
        @if ($m['badge'])
          <span style="margin-left: auto; min-width: 22px; height: 20px; padding: 0 6px; box-sizing: border-box; border-radius: 10px; background: #FF5A36; color: #0E0E0F; font-size: 11px; font-weight: 700; display: flex; align-items: center; justify-content: center">{{ $m['badge'] > 99 ? '99+' : $m['badge'] }}</span>
        @endif
      </a>
    @endforeach
  </nav>
  <div style="margin-top: auto; padding: 14px 10px 0; border-top: 1px solid #232326; display: flex; align-items: center; gap: 10px">
    <span style="width: 32px; height: 32px; border-radius: 50%; background: #3A3632; flex-shrink: 0"></span>
    <span style="flex: 1; display: flex; flex-direction: column; gap: 1px">
      <span style="font-size: 13px; font-weight: 700">{{ $admin->name }}</span>
      <span style="font-size: 11px; color: #A19D96">{{ app(\App\Services\Starttoo\AdminAccounts::class)->roleLabel($admin->role) }} 권한</span>
    </span>
    <form method="POST" action="{{ route('admin.logout') }}" style="display: contents">
      @csrf
      <button type="submit" aria-label="로그아웃" style="width: 36px; height: 36px; border: 0; background: none; display: flex; align-items: center; justify-content: center; color: #BDB8B0; cursor: pointer"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 4h4v16h-4M10 8l-4 4 4 4M6 12h10"/></svg></button>
    </form>
  </div>
</aside>
