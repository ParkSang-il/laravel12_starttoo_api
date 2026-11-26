@extends('admin.layouts.app')
@section('title', '신고 · 차단 관리')
@section('content')

  <div style="flex: 999 1 560px; min-width: 0; padding: 28px clamp(16px, 3vw, 40px) 48px; box-sizing: border-box; display: flex; flex-direction: column; gap: 24px">
    <header style="display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 16px">
      <div style="display: flex; flex-direction: column; gap: 6px">
        <h1 style="margin: 0; font-size: 26px; font-weight: 900; letter-spacing: -0.5px">신고 · 차단 관리</h1>
        <p style="margin: 0; font-size: 14px; color: #66625B">접수된 신고를 검토하고 콘텐츠 숨김과 회원 제재를 처리해요.</p>
      </div>
      @if (request('target'))
        <div style="display: flex; flex-wrap: wrap; gap: 8px"><a href="{{ route('admin.reports') }}" class="btn-a" style="height: 40px; padding: 0 16px; border-radius: 10px; font-size: 14px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; border: 1px solid #D9D4CB; background: #FFFFFF; color: #141413">전체 신고 보기</a></div>
      @endif
    </header>
    <section style="display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-start">
      <div style="background: #FFFFFF; border: 1px solid #E4E0D8; border-radius: 16px; flex: 3 1 560px; min-width: 0; overflow: hidden">
        <div style="padding: 14px 16px; display: flex; flex-wrap: wrap; gap: 6px; border-bottom: 1px solid #E4E0D8">
          @foreach ($tabs as $t)
            <a href="{{ $t['href'] }}" class="btn-a" style="{{ $t['style'] }}">{{ $t['label'] }}</a>
          @endforeach
        </div>
        <div style="overflow-x: auto">
          <table style="width: 100%; min-width: 720px; border-collapse: collapse">
            <thead><tr><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">접수</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">유형</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">사유</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">대상</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">누적</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">상태</th></tr></thead>
            <tbody>
              @forelse ($rows as $r)
                <tr style="{{ $r['rowStyle'] }}">
                  <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap; color: #66625B">{{ $r['date'] }}</td>
                  <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap">{{ $r['type'] }}</td>
                  <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap; font-weight: 700"><a href="{{ $r['href'] }}" class="btn-a" style="padding: 0; border: 0; background: none; font-size: 14px; font-weight: 700; color: #141413; text-align: left">{{ $r['reason'] }}</a></td>
                  <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap">{{ $r['target'] }}</td>
                  <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap">{{ $r['count'] }}회</td>
                  <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap"><span style="{{ $r['pill'] }}">{{ $r['state'] }}</span></td>
                </tr>
              @empty
                <tr><td colspan="6" style="padding: 32px 16px; font-size: 14px; color: #66625B; text-align: center">이 탭에 신고가 없어요.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
        <div style="padding: 14px 20px; display: flex; justify-content: flex-end">@include('admin.partials.pages')</div>
      </div>

      @if ($sel)
      <aside style="background: #FFFFFF; border: 1px solid #E4E0D8; border-radius: 16px; flex: 2 1 340px; min-width: 0; padding: 22px; display: flex; flex-direction: column; gap: 18px">
        <div style="display: flex; justify-content: space-between; align-items: center">
          <h2 style="margin: 0; font-size: 17px; font-weight: 700">신고 상세</h2>
          <span style="{{ $sel['pill'] }}">{{ $sel['state'] }}</span>
        </div>
        <dl style="margin: 0; display: grid; grid-template-columns: auto 1fr; gap: 10px 16px; font-size: 14px">
          <dt style="color: #66625B">사유</dt><dd style="margin: 0; font-weight: 700">{{ $sel['reason'] }}</dd>
          <dt style="color: #66625B">대상</dt><dd style="margin: 0">{{ $sel['target'] }}</dd>
          <dt style="color: #66625B">작성자</dt><dd style="margin: 0">{{ $sel['author'] }}</dd>
          <dt style="color: #66625B">누적 신고</dt><dd style="margin: 0">{{ $sel['count'] }}회 · 신고자 {{ $sel['reporters'] }}명</dd>
        </dl>
        <div style="padding: 14px; border-radius: 10px; background: #F7F5F1; display: flex; flex-direction: column; gap: 6px">
          <span style="font-size: 12px; color: #66625B">신고된 내용</span>
          <p style="margin: 0; font-size: 14px; line-height: 1.6; white-space: pre-line; overflow-wrap: anywhere">{{ $sel['excerpt'] }}</p>
          @if ($sel['details'] !== '')
            <span style="font-size: 12px; color: #66625B; margin-top: 6px">신고자 메모</span>
            <p style="margin: 0; font-size: 14px; line-height: 1.6; white-space: pre-line; overflow-wrap: anywhere">{{ $sel['details'] }}</p>
          @endif
        </div>
        @if ($sel['open'] && ($canDismiss || $canResolve))
          <form method="POST" action="{{ $sel['resolveAction'] }}" style="display: contents">
          @csrf
          <input type="hidden" name="penalty" value="{{ $pen }}">
          <fieldset style="border: 0; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 8px">
            <legend style="font-size: 14px; font-weight: 700; padding: 0; margin-bottom: 8px">작성자 제재</legend>
            <div style="display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 6px">
              @foreach ($penalties as $p)
                @if ($p['locked'])
                  <span class="btn-a" aria-disabled="true" title="운영자 이상만 정지할 수 있어요" style="{{ $p['style'] }}">{{ $p['label'] }}</span>
                @else
                  <a href="{{ $p['href'] }}" class="btn-a" aria-current="{{ $p['on'] ? 'true' : 'false' }}" style="{{ $p['style'] }}">{{ $p['label'] }}</a>
                @endif
              @endforeach
            </div>
          </fieldset>
          <div style="display: flex; gap: 8px">
            @if ($canDismiss)
              <button type="submit" name="result" value="반려" style="height: 40px; padding: 0 16px; border-radius: 10px; font-size: 14px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; border: 1px solid #D9D4CB; background: #FFFFFF; color: #141413; flex: 1; justify-content: center">신고 반려</button>
            @endif
            @if ($canResolve)
              <button type="submit" name="result" value="처리 완료" data-confirm="같은 대상의 미처리 신고를 모두 처리하고, 작성자에게 「{{ $pen }}」을(를) 적용할까요?" style="height: 40px; padding: 0 16px; border-radius: 10px; font-size: 14px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; border: 0; background: #141413; color: #FFFFFF; flex: 1; justify-content: center">숨김 + 제재 적용</button>
            @endif
          </div>
          </form>
          <span style="font-size: 12px; color: #66625B; line-height: 1.5">처리 결과는 신고자에게 알림으로 전달되고 감사 로그에 남아요. 정지된 회원은 앱에 로그인하면 정지 기간이 안내돼요.</span>
        @endif
      </aside>
      @endif
    </section>
  </div>
@endsection
