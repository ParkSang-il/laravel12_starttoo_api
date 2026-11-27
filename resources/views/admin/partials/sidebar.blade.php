@php
  $badges = \App\Support\AdminUi::navBadges();
  $groups = [
    ['label' => '운영', 'items' => [
      ['route' => 'admin.dashboard', 'label' => '운영 대시보드', 'badge' => null, 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 13h8V3H3zM13 21h8V11h-8zM3 21h8v-6H3zM13 3v6h8V3z"/></svg>'],
      ['route' => 'admin.members', 'label' => '회원 관리', 'badge' => null, 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM2 21c1-4 4-6 7-6s6 2 7 6M17 11a3 3 0 1 0 0-6M22 20c-.6-2.6-2.2-4.2-4-4.8"/></svg>'],
      ['route' => 'admin.artist-review', 'label' => '아티스트 심사', 'badge' => $badges['artistReview'] ?? null, 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l8 3v6c0 4.5-3.4 8.2-8 9-4.6-.8-8-4.5-8-9V6zM8.5 12l2.5 2.5 4.5-5"/></svg>'],
      ['route' => 'admin.contents', 'label' => '게시물 · 작품', 'badge' => null, 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 4h18v16H3zM21 17l-5-5-9 8M9 10a2 2 0 1 0 0-.01"/></svg>'],
      ['route' => 'admin.reports', 'label' => '신고 · 차단', 'badge' => $badges['reports'] ?? null, 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 21V4h11l-1 4 4 1H5"/></svg>'],
    ]],
    ['label' => 'AI 서비스', 'items' => [
      ['route' => 'admin.ai-usage', 'label' => 'AI 사용량 · 비용', 'badge' => null, 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>'],
      ['route' => 'admin.ai-jobs', 'label' => 'AI · 미디어 작업', 'badge' => (($badges['aiJobs'] ?? 0) + ($badges['videoFailed'] ?? 0)) ?: null, 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 7v5l3 2M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z"/></svg>'],
      ['route' => 'admin.ai-outputs', 'label' => 'AI 결과물 검수', 'badge' => null, 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 5h8v14H3zM13 5h8v14h-8zM15 12l1.5 1.5L19 11"/></svg>'],
      ['route' => 'admin.virtual-models', 'label' => '가상 모델 라이브러리', 'badge' => null, 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 7a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM6 23v-8l-2-5 5-2h6l5 2-2 5v8"/></svg>'],
    ]],
    ['label' => '소통 · 고객', 'items' => [
      ['route' => 'admin.chats', 'label' => '상담 · 채팅 모니터링', 'badge' => $badges['chats'] ?? null, 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 5h16v11H9l-5 4zM8 10h8M8 13h5"/></svg>'],
      ['route' => 'admin.inquiries', 'label' => '1:1 문의', 'badge' => $badges['inquiries'] ?? null, 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21a9 9 0 1 0-8-4.9L3 21l4.9-1A9 9 0 0 0 12 21zM9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6M12 16.5v.01"/></svg>'],
      ['route' => 'admin.push', 'label' => '알림 · 푸시 발송', 'badge' => null, 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 16V11a6 6 0 1 1 12 0v5l2 2H4zM10 21h4"/></svg>'],
      ['route' => 'admin.notices', 'label' => '공지 · 배너 · FAQ', 'badge' => null, 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4h16v16H4zM8 9h8M8 13h8M8 17h5"/></svg>'],
    ]],
    ['label' => '비즈니스', 'items' => [
      ['route' => 'admin.payments', 'label' => '예약 · 결제', 'badge' => null, 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 5h18v16H3zM3 10h18M8 3v4M16 3v4"/></svg>'],
      ['route' => 'admin.ads', 'label' => '광고 · 프리미엄', 'badge' => null, 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 10v4h3l6 4V6L7 10zM17 9a4 4 0 0 1 0 6"/></svg>'],
      ['route' => 'admin.ad-sales', 'label' => '광고 문의 · 계약', 'badge' => $badges['adSales'] ?? null, 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 3h10l4 4v14H3V3zM7 3v5h10M8 13h8M8 17h5"/></svg>'],
    ]],
    ['label' => '시스템', 'items' => [
      ['route' => 'admin.app-version', 'label' => '앱 버전 · 강제 업데이트', 'badge' => null, 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 2h10v20H7zM11 18h2M12 6v7M9 10l3 3 3-3"/></svg>'],
      ['route' => 'admin.search-tags', 'label' => '검색 · 태그 관리', 'badge' => null, 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.5 18a7.5 7.5 0 1 0 0-15 7.5 7.5 0 0 0 0 15zM16 16l5 5"/></svg>'],
      ['route' => 'admin.settings', 'label' => '설정 · 감사 로그', 'badge' => null, 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM4 12h2M18 12h2M12 4v2M12 18v2M6.3 6.3l1.4 1.4M16.3 16.3l1.4 1.4M6.3 17.7l1.4-1.4M16.3 7.7l1.4-1.4"/></svg>'],
    ]],
  ];
  // 아직 만들지 않은 화면(라우트 없음)은 메뉴에서 뺀다
  foreach ($groups as $i => $g) {
    $groups[$i]['items'] = array_values(array_filter($g['items'], fn ($m) => \Illuminate\Support\Facades\Route::has($m['route'])));
  }
  $groups = array_values(array_filter($groups, fn ($g) => $g['items']));
@endphp
<aside style="flex: 1 1 232px; background: #0E0E0F; color: #F2EFEA; padding: 24px 14px; box-sizing: border-box; display: flex; flex-direction: column; gap: 22px">
  <div style="padding: 0 10px; display: flex; flex-direction: column; gap: 4px">
    <span style="font-family: Unbounded, 'Arial Black', sans-serif; font-weight: 800; font-size: 19px; letter-spacing: -0.5px">STARTTOO<span style="color: #FF5A36">.</span></span>
    <span style="font-size: 12px; color: #A19D96">운영 콘솔</span>
  </div>
  <nav aria-label="관리자 메뉴" style="display: flex; flex-direction: column; gap: 14px">
    @foreach ($groups as $g)
      <div style="display: flex; flex-direction: column; gap: 2px">
        <span style="padding: 0 12px 4px; font-size: 11px; font-weight: 700; color: #8A867F; letter-spacing: 0.3px">{{ $g['label'] }}</span>
        @foreach ($g['items'] as $m)
          @php $on = request()->routeIs($m['route']) || request()->routeIs($m['route'] . '.*'); @endphp
          <a href="{{ route($m['route']) }}" @if ($on) aria-current="page" @endif class="admin-nav" style="height: 38px; padding: 0 12px; border-radius: 10px; display: flex; align-items: center; gap: 10px; font-size: 14px; {{ $on ? 'background: #232326; color: #FFFFFF; font-weight: 700' : 'color: #BDB8B0; font-weight: 500' }}">
            {!! $m['icon'] !!}{{ $m['label'] }}
            @if ($m['badge'])
              <span style="margin-left: auto; min-width: 22px; height: 20px; padding: 0 6px; box-sizing: border-box; border-radius: 10px; background: #FF5A36; color: #0E0E0F; font-size: 11px; font-weight: 700; display: flex; align-items: center; justify-content: center">{{ $m['badge'] > 99 ? '99+' : $m['badge'] }}</span>
            @endif
          </a>
        @endforeach
      </div>
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
