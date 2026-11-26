@extends('admin.layouts.app')
@section('title', '광고 · 프리미엄 운영')
@section('content')

  <div style="flex: 999 1 560px; min-width: 0; padding: 28px clamp(16px, 3vw, 40px) 48px; box-sizing: border-box; display: flex; flex-direction: column; gap: 24px">
    <header style="display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 16px">
      <div style="display: flex; flex-direction: column; gap: 6px">
        <h1 style="margin: 0; font-size: 26px; font-weight: 900; letter-spacing: -0.5px">광고 · 프리미엄 운영</h1>
        <p style="margin: 0; font-size: 14px; color: #66625B">스폰서 노출 슬롯과 프리미엄 아티스트 구독을 관리해요.</p>
      </div>
    </header>
    <section style="background: #FFFFFF; border: 1px solid #E4E0D8; border-radius: 16px; overflow: hidden">
      <div style="padding: 18px 20px; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 10px">
        <div style="display: flex; flex-direction: column; gap: 4px">
          <h2 style="margin: 0; font-size: 17px; font-weight: 700">스폰서 노출 슬롯</h2>
          <span style="font-size: 13px; color: #66625B">모든 광고 노출에는 앱에서 ‘광고’ 표시가 붙어요.</span>
        </div>
      </div>
      <div style="overflow-x: auto">
        <table style="width: 100%; min-width: 900px; border-collapse: collapse">
          <thead><tr><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">노출 위치</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">광고 작가 · 상점</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">기간</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">노출</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">클릭</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">상태</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">노출</th></tr></thead>
          <tbody>
            @forelse ($slots as $s)
              <tr>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap; font-weight: 700">{{ $s['where'] }}<span style="display: block; font-size: 12px; font-weight: 400; color: #66625B">{{ $s['title'] }}</span></td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap">{{ $s['who'] }}</td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap; color: #66625B">{{ $s['period'] }}</td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap; font-variant-numeric: tabular-nums">{{ $s['imp'] }}</td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap; font-variant-numeric: tabular-nums">{{ $s['clk'] }}</td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap"><span style="{{ $s['pill'] }}">{{ $s['state'] }}</span></td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap">
                  @if ($s['canToggle'])
                    <form method="POST" action="{{ $s['action'] }}" style="display: contents">@csrf<button type="submit" role="switch" aria-checked="{{ $s['on'] ? 'true' : 'false' }}" aria-label="{{ $s['aria'] }}" style="{{ $s['track'] }}"><span style="{{ $s['knob'] }}"></span></button></form>
                  @else
                    <span role="switch" aria-checked="{{ $s['on'] ? 'true' : 'false' }}" aria-disabled="true" aria-label="{{ $s['aria'] }}" style="{{ $s['track'] }}"><span style="{{ $s['knob'] }}"></span></span>
                  @endif
                </td>
              </tr>
            @empty
              <tr><td colspan="7" style="padding: 32px 16px; font-size: 14px; color: #66625B; text-align: center">등록된 광고가 없어요. 등록은 서버 명령 starttoo:ad add 로 해요.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </section>

    <section style="display: flex; flex-direction: column; gap: 14px">
      <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: baseline; gap: 8px">
        <h2 style="margin: 0; font-size: 17px; font-weight: 700">프리미엄 아티스트 구독</h2>
        <span style="height: 28px; padding: 0 10px; border-radius: 14px; background: #ECEAE5; color: #55524C; font-size: 12px; font-weight: 700; display: inline-flex; align-items: center">작가 확보 후 오픈 예정</span>
      </div>
      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(280px, 100%), 1fr)); gap: 14px">
        <article style="background: #FFFFFF; border: 1px solid #E4E0D8; border-radius: 16px; padding: 22px; display: flex; flex-direction: column; gap: 14px">
          <span style="font-size: 13px; font-weight: 700; color: #66625B">기본</span>
          <strong style="font-family: Unbounded, 'Arial Black', sans-serif; font-size: 26px; font-weight: 700">무료</strong>
          <ul style="margin: 0; padding: 0 0 0 18px; display: flex; flex-direction: column; gap: 6px; font-size: 14px; line-height: 1.5">
            <li>상점 · 포트폴리오 · 도안 등록</li><li>상담 · 견적 · 예약 관리</li><li>기본 통계</li>
          </ul>
          <span style="margin-top: auto; font-size: 13px; color: #66625B">이용 작가 {{ number_format($artists) }}명</span>
        </article>
        <article style="background: #141413; color: #F2EFEA; border-radius: 16px; padding: 22px; display: flex; flex-direction: column; gap: 14px">
          <span style="font-size: 13px; font-weight: 700; color: #FF5A36">프리미엄</span>
          <strong style="font-family: Unbounded, 'Arial Black', sans-serif; font-size: 26px; font-weight: 700">오픈 예정</strong>
          <ul style="margin: 0; padding: 0 0 0 18px; display: flex; flex-direction: column; gap: 6px; font-size: 14px; line-height: 1.5">
            <li>고급 통계 · 상담 전환 분석</li><li>고객 관리 · 반복 고객 알림</li><li>예약 통계 리포트</li>
          </ul>
        </article>
      </div>
    </section>
  </div>
@endsection
