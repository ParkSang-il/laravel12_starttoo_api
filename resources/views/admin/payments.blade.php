@extends('admin.layouts.app')
@section('title', '예약 · 결제 모니터링')
@section('content')

  <div style="flex: 999 1 560px; min-width: 0; padding: 28px clamp(16px, 3vw, 40px) 48px; box-sizing: border-box; display: flex; flex-direction: column; gap: 24px">
    <header style="display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 16px">
      <div style="display: flex; flex-direction: column; gap: 6px">
        <h1 style="margin: 0; font-size: 26px; font-weight: 900; letter-spacing: -0.5px">예약 · 결제 모니터링</h1>
        <p style="margin: 0; font-size: 14px; color: #66625B">예약 상태 흐름과 변경 · 취소 · 분쟁을 모니터링해요.</p>
      </div>
      @if ($canExport)
        <div style="display: flex; flex-wrap: wrap; gap: 8px"><a href="{{ route('admin.payments.export', request()->only('tab')) }}" class="btn-a" style="height: 40px; padding: 0 16px; border-radius: 10px; font-size: 14px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; border: 1px solid #D9D4CB; background: #FFFFFF; color: #141413">엑셀 내보내기</a></div>
      @endif
    </header>
    <section style="padding: 14px 18px; border-radius: 12px; background: #FFF1D6; color: #5C3B00; display: flex; flex-wrap: wrap; gap: 12px; align-items: center">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.5v.01"/></svg>
      <span style="flex: 1 1 360px; font-size: 14px; line-height: 1.5"><strong>예약금 결제 비활성</strong> — 문신사법 · PG 검토가 끝나면 결제 · 환불 · 정산 지표가 이 화면에 함께 표시돼요.</span>
    </section>

    <section style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(220px, 100%), 1fr)); gap: 14px">
      @foreach ($kpis as $k)
        <div style="background: #FFFFFF; border: 1px solid #E4E0D8; border-radius: 16px; padding: 18px 20px; display: flex; flex-direction: column; gap: 8px">
          <span style="font-size: 13px; color: #66625B; font-weight: 500">{{ $k['label'] }}</span>
          <strong style="font-family: Unbounded, 'Arial Black', sans-serif; font-size: 26px; font-weight: 700; letter-spacing: -1px">{{ $k['value'] }}</strong>
          <span style="font-size: 12px; color: #66625B">{{ $k['sub'] }}</span>
        </div>
      @endforeach
    </section>

    <section style="background: #FFFFFF; border: 1px solid #E4E0D8; border-radius: 16px; overflow: hidden">
      <div style="padding: 14px 16px; display: flex; flex-wrap: wrap; gap: 6px; border-bottom: 1px solid #E4E0D8">
        @foreach ($tabs as $t)
          <a href="{{ $t['href'] }}" class="btn-a" style="{{ $t['style'] }}">{{ $t['label'] }}</a>
        @endforeach
      </div>
      <div style="overflow-x: auto">
        <table style="width: 100%; min-width: 1000px; border-collapse: collapse">
          <thead><tr><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">예약번호</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">고객</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">작가</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">시술 일시</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">견적</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">예약금</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">상태</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">최근 변경</th></tr></thead>
          <tbody>
            @forelse ($rows as $r)
              <tr>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap; font-variant-numeric: tabular-nums; font-weight: 700">{{ $r['id'] }}</td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap">{{ $r['customer'] }}</td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap">{{ $r['artist'] }}</td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap">{{ $r['when'] }}</td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap; font-variant-numeric: tabular-nums">{{ $r['quote'] }}</td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap; color: #66625B">결제 미사용</td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap"><span style="{{ $r['pill'] }}">{{ $r['state'] }}</span></td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap; color: #66625B">{{ $r['log'] }}</td>
              </tr>
            @empty
              <tr><td colspan="8" style="padding: 32px 16px; font-size: 14px; color: #66625B; text-align: center">이 탭에 예약이 없어요.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div style="padding: 14px 20px; display: flex; justify-content: flex-end">@include('admin.partials.pages')</div>
    </section>
  </div>
@endsection
