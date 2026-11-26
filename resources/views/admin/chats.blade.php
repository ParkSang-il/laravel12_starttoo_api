@extends('admin.layouts.app')
@section('title', '상담 · 채팅 모니터링')
@section('content')

  <div style="flex: 999 1 560px; min-width: 0; padding: 28px clamp(16px, 3vw, 40px) 48px; box-sizing: border-box; display: flex; flex-direction: column; gap: 24px">
    <header style="display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 16px">
      <div style="display: flex; flex-direction: column; gap: 6px">
        <h1 style="margin: 0; font-size: 26px; font-weight: 900; letter-spacing: -0.5px">상담 · 채팅 모니터링</h1>
        <p style="margin: 0; font-size: 14px; color: #66625B">신고된 채팅방만 열람할 수 있어요. 열람할 때마다 사유가 감사 로그에 남아요.</p>
      </div>
      <div style="display: flex; flex-wrap: wrap; gap: 8px"><a href="{{ route('admin.payments') }}" style="height: 40px; padding: 0 16px; border-radius: 10px; font-size: 14px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; gap: 6px; border: 1px solid #D9D4CB; background: #FFFFFF; color: #141413">예약 보기</a></div>
    </header>
    <section style="display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-start">
      <div style="background: #FFFFFF; border: 1px solid #E4E0D8; border-radius: 16px; flex: 3 1 560px; min-width: 0; overflow: hidden">
        <div role="tablist" style="padding: 14px 16px; display: flex; flex-wrap: wrap; gap: 6px; border-bottom: 1px solid #E4E0D8">
          @foreach ($tabs as $t)
            <a href="{{ $t['href'] }}" class="btn-a" role="tab" style="{{ $t['style'] }}">{{ $t['label'] }}</a>
          @endforeach
        </div>
        <div style="overflow-x: auto">
          <table style="width: 100%; min-width: 720px; border-collapse: collapse">
            <thead><tr><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">채팅방</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">고객</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">아티스트</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">단계</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">최근 메시지</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">사유</th></tr></thead>
            <tbody>
              @forelse ($rooms as $r)
              <tr style="{{ $r['rowStyle'] }}">
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap; font-family: ui-monospace, Menlo, monospace; font-size: 13px"><a href="{{ $r['href'] }}" class="btn-a" style="padding: 0; border: 0; background: none; font-size: 14px; font-weight: 700; color: #141413; text-align: left">{{ $r['id'] }}</a></td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap"><a href="{{ $r['customerHref'] }}" style="color: #141413; text-decoration: underline; text-underline-offset: 3px">{{ $r['customer'] }}</a></td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap"><a href="{{ $r['artistHref'] }}" style="color: #141413; text-decoration: underline; text-underline-offset: 3px">{{ $r['artist'] }}</a></td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap"><span style="{{ $r['stagePill'] }}">{{ $r['stage'] }}</span></td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap; color: #66625B">{{ $r['last'] }}</td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap"><span style="{{ $r['pill'] }}">{{ $r['flag'] }}</span></td>
              </tr>
              @empty
              <tr><td colspan="6" style="padding: 32px 16px; font-size: 14px; color: #66625B; text-align: center">신고된 채팅방이 없어요.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
      @if ($sel)
      <div style="background: #FFFFFF; border: 1px solid #E4E0D8; border-radius: 16px; flex: 2 1 380px; min-width: 0; overflow: hidden; display: flex; flex-direction: column">
        <div style="padding: 18px 20px; border-bottom: 1px solid #E4E0D8; display: flex; justify-content: space-between; align-items: flex-start; gap: 8px">
          <div style="display: flex; flex-direction: column; gap: 4px"><span style="font-size: 13px; color: #66625B; font-family: ui-monospace, Menlo, monospace">{{ $sel['id'] }}</span><h2 style="margin: 0; font-size: 17px; font-weight: 700">{{ $sel['customer'] }} ↔ {{ $sel['artist'] }}</h2></div>
          <span style="{{ $sel['pill'] }}">{{ $sel['flag'] }}</span>
        </div>
        @if (! $opened)
          <div style="padding: 24px 20px; display: flex; flex-direction: column; gap: 14px">
            <div style="display: flex; align-items: center; gap: 10px; color: #55524C"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 11h12v10H6zM8 11V7a4 4 0 0 1 8 0v4"/></svg><span style="font-size: 14px; font-weight: 700">대화 내용은 잠겨 있어요</span></div>
            <span style="font-size: 13px; color: #66625B; line-height: 1.6">메시지 {{ $sel['msgs'] }}개 · 사진 {{ $sel['files'] }}개 · 견적 · 안내 {{ $sel['events'] }}개 · 신고 {{ $sel['reportsCount'] }}건. 열람 사유를 고르면 이 방의 대화(최근 500개)를 30분 동안 볼 수 있어요.</span>
            @if ($canOpen)
              <div style="display: flex; flex-wrap: wrap; gap: 6px">@foreach ($reasons as $o)<a href="{{ $o['href'] }}" class="btn-a" style="{{ $o['style'] }}">{{ $o['label'] }}</a>@endforeach</div>
              <form method="POST" action="{{ $sel['openAction'] }}" style="display: contents">@csrf<input type="hidden" name="reason" value="{{ $reason }}"><button type="submit" style="height: 40px; padding: 0 16px; border-radius: 10px; font-size: 14px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; gap: 6px; border: 0; background: #141413; color: #FFFFFF">사유 기록하고 열람</button></form>
            @else
              <span style="font-size: 12px; color: #66625B">열람은 운영자 이상만 할 수 있어요.</span>
            @endif
          </div>
        @else
          <div style="padding: 10px 20px; background: #F4F2EE; font-size: 12px; color: #55524C">{{ $sel['grantText'] }}</div>
          <div style="padding: 16px 20px; display: flex; flex-direction: column; gap: 10px; background: #FBFAF8; max-height: 560px; overflow-y: auto">
            @forelse ($msgs as $g)
              <div style="{{ $g['wrap'] }}">
                <span style="font-size: 11px; color: #77736B">{{ $g['who'] }} · {{ $g['t'] }}</span>
                <span style="{{ $g['bubble'] }}">{{ $g['text'] }}</span>
              </div>
            @empty
              <span style="font-size: 13px; color: #66625B">메시지가 없어요.</span>
            @endforelse
          </div>
          <div style="padding: 16px 20px; border-top: 1px solid #E4E0D8; display: flex; flex-wrap: wrap; gap: 8px">
            @if ($canWarn)
              <form method="POST" action="{{ $sel['warnAction'] }}" style="display: contents">@csrf<button type="submit" data-confirm="{{ $sel['subjectName'] }}님에게 운영 정책 위반 경고를 보낼까요? 제재 이력에 남고 앱 알림이 가요." style="height: 40px; padding: 0 16px; border-radius: 10px; font-size: 14px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; gap: 6px; border: 1px solid #D9D4CB; background: #FFFFFF; color: #141413; flex: 1 1 110px">{{ $sel['subjectName'] }}님 경고</button></form>
            @endif
            <a href="{{ $sel['reportsHref'] }}" class="btn-a" style="height: 40px; padding: 0 16px; border-radius: 10px; font-size: 14px; font-weight: 700; gap: 6px; border: 1px solid #F2C4B8; background: #FFFFFF; color: #A3301A; flex: 1 1 110px">신고 처리 · 정지</a>
          </div>
        @endif
      </div>
      @endif
    </section>
  </div>
@endsection
