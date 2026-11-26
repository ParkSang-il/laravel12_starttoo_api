@extends('admin.layouts.app')
@section('title', '아티스트 심사')
@section('content')

  <div style="flex: 999 1 560px; min-width: 0; padding: 28px clamp(16px, 3vw, 40px) 48px; box-sizing: border-box; display: flex; flex-direction: column; gap: 24px">
    <header style="display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 16px">
      <div style="display: flex; flex-direction: column; gap: 6px">
        <h1 style="margin: 0; font-size: 26px; font-weight: 900; letter-spacing: -0.5px">아티스트 자격증 심사 · 승인 관리</h1>
        <p style="margin: 0; font-size: 14px; color: #66625B">포트폴리오 심사와 시술 자격증(면허) 검증을 나눠서 처리하고, 기능별 권한을 부여해요.</p>
      </div>
      <div style="display: flex; flex-wrap: wrap; gap: 8px"><span style="height: 40px; padding: 0 14px; border-radius: 10px; background: #ECEAE5; color: #55524C; font-size: 13px; font-weight: 700; display: inline-flex; align-items: center">사업자 인증 미사용 · 열람 이력 감사 기록</span></div>
    </header>
    <section style="display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-start">
      <div style="background: #FFFFFF; border: 1px solid #E4E0D8; border-radius: 16px; flex: 1 1 320px; min-width: 0; overflow: hidden">
        <div style="padding: 14px 16px; display: flex; flex-wrap: wrap; gap: 6px; border-bottom: 1px solid #E4E0D8">
          @foreach ($tabs as $t)
            <a href="{{ $t['href'] }}" class="btn-a" style="{{ $t['style'] }}">{{ $t['label'] }}</a>
          @endforeach
        </div>
        @forelse ($apps as $a)
          <a href="{{ $a['href'] }}" class="btn-a" style="{{ $a['rowStyle'] }}">
            @if ($a['avatar'])
              <img src="{{ $a['avatar'] }}" alt="" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover; flex-shrink: 0">
            @else
              <span style="width: 40px; height: 40px; border-radius: 50%; background: #D9D4CB; flex-shrink: 0"></span>
            @endif
            <span style="flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 3px; text-align: left">
              <span style="font-size: 14px; font-weight: 700; color: #141413">{{ $a['name'] }}</span>
              <span style="font-size: 12px; color: #66625B">{{ $a['region'] }} · 신청 {{ $a['date'] }}</span>
            </span>
            <span style="{{ $a['pill'] }}">{{ $a['status'] }}</span>
          </a>
        @empty
          <p style="margin: 0; padding: 32px 16px; font-size: 14px; color: #66625B; text-align: center">이 탭에 신청서가 없어요.</p>
        @endforelse
      </div>

      @if ($sel)
      <div style="background: #FFFFFF; border: 1px solid #E4E0D8; border-radius: 16px; flex: 2 1 560px; min-width: 0; padding: 24px; display: flex; flex-direction: column; gap: 20px">
        <div style="display: flex; flex-wrap: wrap; gap: 16px; align-items: center">
          @if ($sel['avatar'])
            <img src="{{ $sel['avatar'] }}" alt="" style="width: 60px; height: 60px; border-radius: 50%; object-fit: cover">
          @else
            <span style="width: 60px; height: 60px; border-radius: 50%; background: #D9D4CB"></span>
          @endif
          <div style="flex: 1; display: flex; flex-direction: column; gap: 4px">
            <h2 style="margin: 0; font-size: 22px; font-weight: 900">{{ $sel['name'] }}</h2>
            <span style="font-size: 14px; color: #66625B">실명 {{ $sel['real'] }} · {{ $sel['region'] }} · 회원 {{ $sel['uid'] }}</span>
          </div>
          <span style="{{ $sel['pill'] }}">{{ $sel['code'] }}</span>
        </div>

        <section style="display: flex; flex-direction: column; gap: 12px; padding: 18px; border-radius: 12px; border: 1px solid #E4E0D8">
          <div style="display: flex; justify-content: space-between; align-items: center"><h3 style="margin: 0; font-size: 15px; font-weight: 700">① 포트폴리오 심사</h3><span style="font-size: 12px; color: #66625B">상점 · 작품 공개 권한</span></div>
          <div style="display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 6px">
            @foreach ($sel['photos'] as $p)
              <a href="{{ $p }}" target="_blank" rel="noopener noreferrer" style="aspect-ratio: 1; border-radius: 8px; overflow: hidden; background: #D9D4CB; display: block"><img src="{{ $p }}" alt="포트폴리오 {{ $loop->iteration }}" loading="lazy" style="width: 100%; height: 100%; object-fit: cover; display: block"></a>
            @endforeach
          </div>
          <span style="font-size: 13px; color: #55524C">작품 {{ $sel['works'] }}점 · 링크 {{ $sel['link'] }} · 소개 “{{ $sel['bio'] }}”</span>
        </section>

        <section style="display: flex; flex-direction: column; gap: 12px; padding: 18px; border-radius: 12px; border: 1px solid #E4E0D8">
          <div style="display: flex; justify-content: space-between; align-items: center"><h3 style="margin: 0; font-size: 15px; font-weight: 700">② 시술 자격증(면허) 검증</h3><span style="{{ $sel['licPill'] }}">{{ $sel['licCode'] }}</span></div>
          @if (! $sel['licensed'])
            <p style="margin: 0; font-size: 14px; line-height: 1.6; color: #55524C">법정 면허 발급 · 검증 경로가 아직 없어 제출을 받지 않아요. 이 상태에서는 ‘확인 완료’ 배지와 시술 상담 · 예약 권한을 줄 수 없어요.</p>
          @else
            <dl style="margin: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(min(170px, 100%), 1fr)); gap: 14px">
              <div style="display: flex; flex-direction: column; gap: 4px"><dt style="font-size: 12px; color: #66625B">면허 번호</dt><dd style="margin: 0; font-size: 14px; font-weight: 700; font-variant-numeric: tabular-nums">{{ $sel['licNumber'] }}</dd></div>
            </dl>
            @if ($sel['licUrl'])
              <a href="{{ $sel['licUrl'] }}" target="_blank" rel="noopener noreferrer" style="display: block; max-width: 360px; border-radius: 10px; overflow: hidden; border: 1px solid #E4E0D8"><img src="{{ $sel['licUrl'] }}" alt="자격증 이미지" style="width: 100%; display: block"></a>
              <span style="font-size: 12px; color: #66625B">5분 동안만 열리는 주소예요 · 열람 기록이 감사 로그에 남았어요</span>
            @elseif ($sel['licHref'])
              <a href="{{ $sel['licHref'] }}" class="btn-a" style="height: 40px; padding: 0 16px; border-radius: 10px; font-size: 14px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; border: 1px solid #D9D4CB; background: #FFFFFF; color: #141413; align-self: flex-start">자격증 이미지 열람 · 기록됨</a>
            @endif
          @endif
        </section>

        <section style="display: flex; flex-direction: column; gap: 10px">
          <h3 style="margin: 0; font-size: 15px; font-weight: 700">부여할 권한</h3>
          <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(160px, 100%), 1fr)); gap: 8px">
            @foreach ($sel['caps'] as $c)
              <div style="padding: 12px 14px; border-radius: 10px; background: #F7F5F1; display: flex; align-items: center; gap: 10px">
                <span style="flex: 1; display: flex; flex-direction: column; gap: 2px"><span style="font-size: 13px; font-weight: 700">{{ $c['label'] }}</span><span style="font-size: 11px; color: #66625B">{{ $c['note'] }}</span></span>
                <span role="switch" aria-checked="{{ $c['on'] ? 'true' : 'false' }}" aria-disabled="true" aria-label="{{ $c['label'] }}" style="{{ $c['track'] }}"><span style="width: 18px; height: 18px; border-radius: 50%; background: #FFFFFF; display: block"></span></span>
              </div>
            @endforeach
          </div>
        </section>

        @if ($sel['open'] && $canDecide)
          <form method="POST" action="{{ $sel['decideAction'] }}" style="display: contents">
          @csrf
          <label style="display: flex; flex-direction: column; gap: 8px; font-size: 14px; font-weight: 700">결정 사유 <span style="font-weight: 400; font-size: 12px; color: #66625B">필수 · 반려 · 보완 사유는 신청자에게 요약해 전달돼요</span>
            <textarea name="reason" required maxlength="1000" rows="2" placeholder="예: 포트폴리오 2점의 본인 작업 여부 확인 필요" style="border-radius: 10px; border: 1px solid #D9D4CB; padding: 12px; font-size: 14px; font-weight: 400; line-height: 1.5; resize: vertical; color: #141413">{{ old('reason') }}</textarea>
          </label>
          <div style="display: flex; flex-wrap: wrap; gap: 8px; justify-content: flex-end">
            <button type="submit" name="decision" value="REJECTED" style="height: 40px; padding: 0 16px; border-radius: 10px; font-size: 14px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; border: 1px solid #D9D4CB; background: #FFFFFF; color: #A3301A">반려</button>
            <button type="submit" name="decision" value="NEEDS_REVISION" style="height: 40px; padding: 0 16px; border-radius: 10px; font-size: 14px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; border: 1px solid #D9D4CB; background: #FFFFFF; color: #141413">보완 요청</button>
            <button type="submit" name="decision" value="APPROVED" style="height: 40px; padding: 0 16px; border-radius: 10px; font-size: 14px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; border: 0; background: #141413; color: #FFFFFF">포트폴리오 작가 승인</button>
          </div>
          </form>
        @elseif ($sel['note'])
          <div style="padding: 14px; border-radius: 10px; background: #F7F5F1; display: flex; flex-direction: column; gap: 6px">
            <span style="font-size: 12px; color: #66625B">결정 사유</span>
            <p style="margin: 0; font-size: 14px; line-height: 1.6; white-space: pre-line">{{ $sel['note'] }}</p>
          </div>
        @endif
      </div>
      @endif
    </section>
  </div>
@endsection
