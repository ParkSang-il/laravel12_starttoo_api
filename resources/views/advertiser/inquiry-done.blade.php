@extends('advertiser.layouts.web')
@section('title', '문의 접수 완료')

@section('content')
<main class="adv-wrap" style="padding-top: 48px; max-width: 760px">
  <div role="status" class="adv-card" style="padding: clamp(24px, 4vw, 48px); display: flex; flex-direction: column; gap: 18px">
    <span style="width: 56px; height: 56px; border-radius: 50%; background: #E3F4E9; color: #1D6B3A; display: flex; align-items: center; justify-content: center"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12l5 5 9-10"/></svg></span>
    <h1 style="margin: 0; font-size: 26px; font-weight: 900">문의가 접수됐어요</h1>
    <p style="margin: 0; font-size: 15px; color: #55524C; line-height: 1.7">영업일 2일 안에 <b style="color: #141413">{{ $inq->email }}</b> 으로 견적서 링크를 보내드려요. 소재 심사 결과도 함께 알려드려요.</p>
    <dl style="margin: 0; display: grid; grid-template-columns: auto 1fr; gap: 8px 20px; font-size: 14px; padding: 16px 18px; border-radius: 12px; background: #F4F2EE">
      <dt style="color: #66625B">접수 번호</dt><dd style="margin: 0; font-weight: 700; font-family: ui-monospace, Menlo, monospace">{{ $inq->code }}</dd>
      <dt style="color: #66625B">원하는 자리</dt><dd style="margin: 0">{{ implode(' · ', $slotNames) }}</dd>
      <dt style="color: #66625B">기간 · 예산</dt><dd style="margin: 0">{{ $inq->duration ?? '-' }} · {{ $inq->budget ?? '미정' }}</dd>
    </dl>
    @if ($me)
      <span style="font-size: 13px; color: #66625B; line-height: 1.6">이 문의는 <a href="{{ route('advertiser.account') }}">내 광고</a>에서도 볼 수 있어요.</span>
    @else
      <span style="font-size: 13px; color: #66625B; line-height: 1.6">견적서 메일을 받으면 견적서 화면에서 광고주 계정을 만들 수 있어요. 계정이 있으면 견적 · 성과 보고를 한 곳에서 볼 수 있어요.</span>
    @endif
    <div><a href="{{ route('advertiser.inquiry') }}" class="adv-btn is-ghost">다른 문의 쓰기</a></div>
  </div>
</main>
@endsection
