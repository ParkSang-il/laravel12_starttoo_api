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
        @if ($canManage)
          <a href="{{ $newHref }}" class="btn-a" style="height: 40px; padding: 0 16px; border-radius: 10px; font-size: 14px; font-weight: 700; gap: 6px; border: 0; background: #141413; color: #FFFFFF">새 광고</a>
        @endif
      </div>
      <div style="overflow-x: auto">
        <table style="width: 100%; min-width: 900px; border-collapse: collapse">
          <thead><tr><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">노출 위치</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">광고 작가 · 상점</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">기간</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">노출</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">클릭</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">상태</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">노출</th></tr></thead>
          <tbody>
            @forelse ($slots as $s)
              <tr style="{{ $s['rowStyle'] }}">
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap; font-weight: 700">{{ $s['where'] }}<span style="display: block; font-size: 12px; font-weight: 400; color: #66625B">{{ $s['title'] }}@if ($s['editHref']) · <a href="{{ $s['editHref'] }}" style="color: #1F4F99; font-weight: 700">수정</a>@endif</span></td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap">@if ($s['whoHref'])<a href="{{ $s['whoHref'] }}" target="_blank" rel="noopener" title="상점 공개 페이지" style="color: #141413; text-decoration: underline; text-underline-offset: 3px">{{ $s['who'] }}</a>@else{{ $s['who'] }}@endif</td>
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
              <tr><td colspan="7" style="padding: 32px 16px; font-size: 14px; color: #66625B; text-align: center">등록된 광고가 없어요. 「새 광고」로 등록해요.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </section>

    @if ($showForm)
      {{-- 광고 등록 · 수정(공지 편집 패널과 같은 모양) --}}
      <section style="background: #FFFFFF; border: 1px solid #E4E0D8; border-radius: 16px; padding: 20px; display: flex; flex-direction: column; gap: 14px">
        <div style="display: flex; justify-content: space-between; align-items: center"><h2 style="margin: 0; font-size: 17px; font-weight: 700">{{ $form['title'] }}</h2><a href="{{ $closeHref }}" style="font-size: 13px; color: #66625B">닫기</a></div>
        <form method="POST" action="{{ $form['action'] }}" enctype="multipart/form-data" style="display: flex; flex-direction: column; gap: 14px">
          @csrf
          <div style="display: flex; flex-wrap: wrap; gap: 14px">
            <label style="flex: 1 1 200px; display: flex; flex-direction: column; gap: 6px"><span style="font-size: 13px; font-weight: 700; color: #3A3731">노출 위치</span>
              <select name="slot" style="height: 44px; border-radius: 10px; border: 1px solid #D9D4CB; padding: 0 12px; font-size: 14px; color: #141413; background: #FFFFFF; box-sizing: border-box; width: 100%">
                @foreach ($slotOptions as $value => $label)<option value="{{ $value }}" @selected(old('slot', $form['slot']) === $value)>{{ $label }}</option>@endforeach
              </select>
            </label>
            <label style="flex: 1 1 200px; display: flex; flex-direction: column; gap: 6px"><span style="font-size: 13px; font-weight: 700; color: #3A3731">광고주</span><input name="advertiser" required maxlength="60" value="{{ old('advertiser', $form['advertiser']) }}" placeholder="예: 잉크하우스" style="height: 44px; border-radius: 10px; border: 1px solid #D9D4CB; padding: 0 12px; font-size: 14px; color: #141413; background: #FFFFFF; box-sizing: border-box; width: 100%"></label>
          </div>
          <label style="display: flex; flex-direction: column; gap: 6px"><span style="font-size: 13px; font-weight: 700; color: #3A3731">광고 문구</span><input name="title" required maxlength="80" value="{{ old('title', $form['text']) }}" placeholder="예: 12월 예약 오픈 · 첫 방문 10% 할인" style="height: 44px; border-radius: 10px; border: 1px solid #D9D4CB; padding: 0 12px; font-size: 14px; color: #141413; background: #FFFFFF; box-sizing: border-box; width: 100%"></label>
          <div style="display: flex; flex-wrap: wrap; gap: 14px">
            <label style="flex: 1 1 200px; display: flex; flex-direction: column; gap: 6px"><span style="font-size: 13px; font-weight: 700; color: #3A3731">누르면 이동 · 작가 회원 번호</span><input name="artist" maxlength="14" value="{{ old('artist', $form['artist']) }}" placeholder="예: U-12 → 그 작가 상점" style="height: 44px; border-radius: 10px; border: 1px solid #D9D4CB; padding: 0 12px; font-size: 14px; color: #141413; background: #FFFFFF; box-sizing: border-box; width: 100%"></label>
            <label style="flex: 1 1 200px; display: flex; flex-direction: column; gap: 6px"><span style="font-size: 13px; font-weight: 700; color: #3A3731">또는 링크</span><input name="link" maxlength="200" value="{{ old('link', $form['link']) }}" placeholder="/community/post?id=3 또는 https://" style="height: 44px; border-radius: 10px; border: 1px solid #D9D4CB; padding: 0 12px; font-size: 14px; color: #141413; background: #FFFFFF; box-sizing: border-box; width: 100%"></label>
          </div>
          <div style="display: flex; flex-wrap: wrap; gap: 14px">
            <label style="flex: 1 1 160px; display: flex; flex-direction: column; gap: 6px"><span style="font-size: 13px; font-weight: 700; color: #3A3731">시작일 <span style="font-weight: 400; color: #66625B">· 비우면 바로</span></span><input type="date" name="from" value="{{ old('from', $form['from']) }}" style="height: 44px; border-radius: 10px; border: 1px solid #D9D4CB; padding: 0 12px; font-size: 14px; color: #141413; background: #FFFFFF; box-sizing: border-box; width: 100%"></label>
            <label style="flex: 1 1 160px; display: flex; flex-direction: column; gap: 6px"><span style="font-size: 13px; font-weight: 700; color: #3A3731">종료일 <span style="font-weight: 400; color: #66625B">· 비우면 계속</span></span><input type="date" name="until" value="{{ old('until', $form['until']) }}" style="height: 44px; border-radius: 10px; border: 1px solid #D9D4CB; padding: 0 12px; font-size: 14px; color: #141413; background: #FFFFFF; box-sizing: border-box; width: 100%"></label>
          </div>
          <div style="display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-end">
            @if ($form['image'])
              <img src="{{ $form['image'] }}" alt="지금 광고 이미지" style="width: 200px; border-radius: 10px; border: 1px solid #E4E0D8">
            @endif
            <label style="flex: 1 1 240px; display: flex; flex-direction: column; gap: 6px"><span style="font-size: 13px; font-weight: 700; color: #3A3731">광고 이미지 <span style="font-weight: 400; color: #66625B">· JPG · PNG · WEBP, 8MB까지, 가로로 긴 사진(1200px 정도)</span></span><input type="file" name="image" accept="image/jpeg,image/png,image/webp" style="font-size: 13px"></label>
            @if ($form['image'])
              <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 700"><input type="hidden" name="remove_image" value="0"><input type="checkbox" name="remove_image" value="1" style="width: 18px; height: 18px; margin: 0; accent-color: #141413">이미지 빼기</label>
            @endif
          </div>
          <div style="display: flex; gap: 8px; align-items: center">
            <span style="flex: 1; font-size: 12px; color: #66625B; line-height: 1.5">작가 번호를 넣으면 링크는 쓰지 않아요. 「홈 피드」 광고는 사진 카드라 이미지가 꼭 필요해요. 등록하면 바로 노출되고(시작일이 있으면 그날부터), 목록의 스위치로 끌 수 있어요.</span>
            <button type="submit" style="height: 40px; padding: 0 20px; border-radius: 10px; font-size: 14px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; border: 0; background: #141413; color: #FFFFFF">{{ $form['save'] }}</button>
          </div>
        </form>
      </section>
    @endif

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
