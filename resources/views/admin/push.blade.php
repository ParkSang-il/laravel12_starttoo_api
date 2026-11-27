@extends('admin.layouts.app')
@section('title', '알림 · 푸시 발송')
@section('content')

  <div style="flex: 999 1 560px; min-width: 0; padding: 28px clamp(16px, 3vw, 40px) 48px; box-sizing: border-box; display: flex; flex-direction: column; gap: 24px">
    <header style="display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 16px">
      <div style="display: flex; flex-direction: column; gap: 6px">
        <h1 style="margin: 0; font-size: 26px; font-weight: 900; letter-spacing: -0.5px">알림 · 푸시 발송</h1>
        <p style="margin: 0; font-size: 14px; color: #66625B">공지 · 이벤트 푸시를 보내고, 서비스 자동 알림 상태를 확인해요.</p>
      </div>
    </header>
    <section style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(200px, 100%), 1fr)); gap: 14px">
      @foreach ($kpis as $k)
        <div style="background: #FFFFFF; border: 1px solid #E4E0D8; border-radius: 16px; padding: 18px 20px; display: flex; flex-direction: column; gap: 10px">
          <span style="font-size: 13px; color: #66625B; font-weight: 500">{{ $k['label'] }}</span>
          <strong style="font-family: Unbounded, 'Arial Black', sans-serif; font-size: 26px; font-weight: 700; letter-spacing: -1px">{{ $k['value'] }}</strong>
          <span style="{{ $k['subStyle'] }}">{{ $k['sub'] }}</span>
        </div>
      @endforeach
    </section>

    @include('admin.partials.sections', ['sectionsLabel' => '알림 · 푸시 항목'])

    @if ($section === '새 푸시 보내기')
    <section style="display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-start">
      <div style="background: #FFFFFF; border: 1px solid #E4E0D8; border-radius: 16px; flex: 3 1 520px; min-width: 0; padding: 20px; display: flex; flex-direction: column; gap: 16px">
        <h2 style="margin: 0; font-size: 17px; font-weight: 700">새 푸시 보내기</h2>
        <div style="display: flex; flex-direction: column; gap: 8px">
          <span style="font-size: 13px; font-weight: 700; color: #3A3731">유형</span>
          <div style="display: flex; flex-wrap: wrap; gap: 6px">@foreach ($types as $t)<a href="{{ $t['href'] }}" class="btn-a" style="{{ $t['style'] }}">{{ $t['label'] }}</a>@endforeach</div>
          <span style="font-size: 12px; color: #66625B; line-height: 1.5">{{ $typeNote }}</span>
        </div>
        <label style="display: flex; flex-direction: column; gap: 6px"><span style="font-size: 13px; font-weight: 700; color: #3A3731">제목</span><input name="title" form="f-send" required maxlength="{{ $titleMax }}" value="{{ $title }}" data-preview="title" data-draft="push-title" style="height: 44px; border-radius: 10px; border: 1px solid #D9D4CB; padding: 0 12px; font-size: 14px; color: #141413; background: #FFFFFF; box-sizing: border-box; width: 100%"></label>
        <label style="display: flex; flex-direction: column; gap: 6px"><span style="font-size: 13px; font-weight: 700; color: #3A3731">내용</span><textarea name="body" form="f-send" required maxlength="500" rows="3" data-preview="body" data-draft="push-body" style="border-radius: 10px; border: 1px solid #D9D4CB; padding: 12px; font-size: 14px; line-height: 1.5; resize: vertical; color: #141413; box-sizing: border-box; width: 100%">{{ $body }}</textarea></label>
        <div style="display: flex; flex-direction: column; gap: 8px">
          <span style="font-size: 13px; font-weight: 700; color: #3A3731">받는 사람</span>
          <div style="display: flex; flex-wrap: wrap; gap: 6px">@foreach ($targets as $t)<a href="{{ $t['href'] }}" class="btn-a" style="{{ $t['style'] }}">{{ $t['label'] }}</a>@endforeach</div>
          <span style="font-size: 13px; color: #66625B">예상 수신 <b style="color: #141413">{{ $reach }}</b> · {{ $reachNote }}</span>
          @if ($reachList)
            <details style="font-size: 13px; color: #3A3731">
              <summary style="cursor: pointer; font-weight: 700">받을 회원 보기</summary>
              <ul style="margin: 8px 0 0; padding: 0; list-style: none; display: flex; flex-wrap: wrap; gap: 6px">
                @foreach ($reachList as $u)<li style="padding: 4px 10px; border-radius: 12px; background: #F4F2EE; font-size: 12px"><a href="{{ route('admin.members', ['q' => $u['id']]) }}" style="color: #141413"><b style="font-variant-numeric: tabular-nums">{{ $u['id'] }}</b> · {{ $u['nick'] }}</a></li>@endforeach
                @if ($reachMore > 0)<li style="padding: 4px 10px; font-size: 12px; color: #66625B">외 {{ number_format($reachMore) }}명</li>@endif
              </ul>
            </details>
          @endif
        </div>
        <div style="display: flex; flex-wrap: wrap; gap: 14px">
          <label style="flex: 1 1 200px; display: flex; flex-direction: column; gap: 6px"><span style="font-size: 13px; font-weight: 700; color: #3A3731">누르면 열리는 화면</span><select name="landing" form="f-send" data-draft="push-landing" style="height: 44px; border-radius: 10px; border: 1px solid #D9D4CB; padding: 0 12px; font-size: 14px; color: #141413; background: #FFFFFF; box-sizing: border-box; width: 100%">@foreach ($landings as $value => $label)<option value="{{ $value }}" @selected($landing === $value)>{{ $label }}</option>@endforeach</select></label>
          <div style="flex: 1 1 200px; display: flex; flex-direction: column; gap: 6px"><span style="font-size: 13px; font-weight: 700; color: #3A3731">발송 시각</span><div style="display: flex; gap: 6px"><span class="btn-a" style="{{ \App\Support\AdminUi::chip(true) }}; height: 44px; flex: 1">즉시</span></div></div>
        </div>
        @if ($night)
          <div style="border-radius: 12px; background: #FFF1D6; color: #5C3B00; padding: 12px 14px; font-size: 13px; line-height: 1.5">지금은 20시~9시예요. 광고성 알림은 야간에 보내지 않아서 오전 9시부터 순서대로 나가요.</div>
        @endif
        @if ($blocked)
          <div style="border-radius: 12px; background: #ECEAE5; color: #55524C; padding: 12px 14px; font-size: 13px; line-height: 1.5">{{ $blocked }}</div>
        @endif
        @if ($canSend)
          <button type="submit" form="f-send" data-confirm="{{ $info ? '앱 알림을 켠 모든 회원에게 정보성 푸시를 보낼까요? 광고 내용이 없는지 확인해주세요.' : '광고 푸시를 보낼까요?' }} 보낸 뒤에는 남은 발송만 취소할 수 있어요." style="height: 40px; padding: 0 16px; border-radius: 10px; font-size: 14px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; gap: 6px; border: 0; background: #141413; color: #FFFFFF">{{ $sendLabel }}</button>
        @endif
      </div>
      <div style="flex: 2 1 320px; min-width: 0; display: flex; flex-direction: column; gap: 14px">
        <div style="border-radius: 24px; background: #2A2724; padding: 22px 16px 28px; display: flex; flex-direction: column; gap: 10px">
          <span style="font-size: 12px; color: #BDB8B0; text-align: center">미리보기 · 잠금 화면</span>
          <div style="border-radius: 16px; background: rgba(255,255,255,0.92); padding: 12px 14px; display: flex; gap: 10px">
            <span style="width: 32px; height: 32px; border-radius: 8px; background: #0E0E0F; color: #FFFFFF; font-family: Unbounded, 'Arial Black', sans-serif; font-size: 11px; font-weight: 800; display: flex; align-items: center; justify-content: center; flex-shrink: 0">ST.</span>
            <span style="flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px"><span style="display: flex; justify-content: space-between; font-size: 12px; color: #55524C"><b style="color: #141413">STARTTOO</b><span>지금</span></span><span data-preview-out="title" data-prefix="{{ $info ? '' : '(광고) ' }}" style="font-size: 13px; font-weight: 700">{{ $info ? '' : '(광고) ' }}{{ $title !== '' ? $title : '제목' }}</span><span data-preview-out="body" data-prefix="{{ $info ? '' : 'STARTTOO ' }}" style="font-size: 12px; color: #3A3731; line-height: 1.4; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical">{{ $info ? '' : 'STARTTOO ' }}{{ $body !== '' ? $body : '내용' }}</span></span>
          </div>
        </div>
      </div>
    </section>
    @endif

    @if ($section === '서비스 자동 알림')
    <section style="background: #FFFFFF; border: 1px solid #E4E0D8; border-radius: 16px; overflow: hidden">
      <div style="padding: 18px 20px 8px"><h2 style="margin: 0; font-size: 17px; font-weight: 700">서비스 자동 알림</h2><span style="font-size: 13px; color: #66625B">앱을 보고 있으면 소켓으로 바로, 나가 있으면 푸시로 보내요. 끄고 켜기는 서버 설정(.env STARTTOO_PUSH_ENABLED)이에요.</span></div>
      @foreach ($autos as $a)
        <div style="min-height: 54px; padding: 8px 20px; border-top: 1px solid #F0EDE7; display: flex; align-items: center; gap: 10px">
          <span style="flex: 1; display: flex; flex-direction: column; gap: 2px"><span style="font-size: 14px; font-weight: 700">{{ $a['label'] }}</span><span style="font-size: 12px; color: #66625B">{{ $a['ch'] }}</span></span>
          <span role="switch" aria-checked="{{ $a['on'] ? 'true' : 'false' }}" aria-disabled="true" aria-label="{{ $a['aria'] }}" style="{{ $a['track'] }}"><span style="{{ $knob }}"></span></span>
        </div>
      @endforeach
    </section>
    @endif

    @if ($section === '발송 내역')
    <section style="background: #FFFFFF; border: 1px solid #E4E0D8; border-radius: 16px; overflow: hidden">
      <div style="padding: 18px 20px 6px"><h2 style="margin: 0; font-size: 17px; font-weight: 700">발송 내역</h2></div>
        <div style="overflow-x: auto">
          <table style="width: 100%; min-width: 720px; border-collapse: collapse">
            <thead><tr><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">제목</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">유형</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">대상</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">발송</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">오픈율</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">상태</th></tr></thead>
            <tbody>
              @forelse ($history as $h)
              <tr>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; font-weight: 700">{{ $h['title'] }}
                  @if ($h['recipients'])
                    <details style="margin-top: 6px; font-weight: 400; font-size: 12px; color: #3A3731">
                      <summary style="cursor: pointer; color: #66625B">받은 회원 {{ count($h['recipients']) + $h['more'] }}명</summary>
                      <ul style="margin: 6px 0 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 3px">
                        @foreach ($h['recipients'] as $u)<li><a href="{{ route('admin.members', ['q' => $u['id']]) }}" style="color: #141413"><b style="font-variant-numeric: tabular-nums">{{ $u['id'] }}</b> · {{ $u['nick'] }}</a> · <span style="color: #66625B">{{ $u['state'] }}</span></li>@endforeach
                        @if ($h['more'] > 0)<li style="color: #66625B">외 {{ number_format($h['more']) }}명</li>@endif
                      </ul>
                    </details>
                  @endif
                </td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap">{{ $h['type'] }}</td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap">{{ $h['target'] }}</td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap; font-variant-numeric: tabular-nums">{{ $h['sent'] }}</td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap; font-variant-numeric: tabular-nums">{{ $h['open'] }}</td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap"><span style="{{ $h['pill'] }}">{{ $h['state'] }}</span>
                  @if ($h['canCancel'])<form method="POST" action="{{ $h['cancelAction'] }}" style="display: inline">@csrf<button type="submit" data-confirm="남은 발송을 취소할까요?" style="margin-left: 8px; height: 28px; padding: 0 10px; border-radius: 8px; border: 1px solid #D9D4CB; background: #FFFFFF; font-size: 12px; font-weight: 700; color: #141413">취소</button></form>@endif
                </td>
              </tr>
              @empty
              <tr><td colspan="6" style="padding: 32px 16px; font-size: 14px; color: #66625B; text-align: center">아직 보낸 캠페인이 없어요.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
    </section>
    @endif
  </div>
    <form id="f-send" method="POST" action="{{ $sendAction }}" hidden>@csrf<input type="hidden" name="type" value="{{ $type }}"><input type="hidden" name="target" value="{{ $target }}"></form>
@endsection
