@extends('advertiser.layouts.web')
@section('title', '광고 상품 소개')

@section('content')
<main class="adv-wrap" style="padding-top: 48px; display: flex; flex-direction: column; gap: 32px">
  <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-end; gap: 20px">
    <div style="display: flex; flex-direction: column; gap: 12px; max-width: 640px">
      <span class="adv-display" style="font-size: 12px; letter-spacing: 1px; color: #B93415">AD PRODUCTS</span>
      <h1 style="margin: 0; font-size: clamp(28px, 3.2vw, 40px); font-weight: 900; letter-spacing: -1px; line-height: 1.25">보여진 만큼만 내는<br>CPM 광고</h1>
      <p style="margin: 0; font-size: 15px; color: #55524C; line-height: 1.7">노출 1,000회당 단가로 보장 노출을 정하고, 다 채우면 광고가 끝나요. 모든 광고는 앱에서 ‘광고’ 표시와 함께 나가고, 일별 노출 · 클릭을 성과 보고 링크로 볼 수 있어요.</p>
    </div>
    <a href="{{ route('advertiser.inquiry') }}" class="adv-btn is-accent">광고 문의하기</a>
  </div>

  @if ($stats)
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(200px, 100%), 1fr)); gap: 12px">
      @foreach ($stats as $s)
        <div class="adv-card" style="padding: 18px 20px; display: flex; flex-direction: column; gap: 6px"><strong class="adv-display" style="font-size: 24px">{{ $s['v'] }}</strong><span style="font-size: 13px; color: #66625B">{{ $s['label'] }}</span></div>
      @endforeach
    </div>
  @endif

  @if (! $slots)
    <div class="adv-card" style="padding: 28px; font-size: 15px; color: #55524C">지금은 광고 상품을 정리하고 있어요. 문의는 <a href="mailto:{{ config('advertiser.ops_email') }}">{{ config('advertiser.ops_email') }}</a> 로 보내주세요.</div>
  @else
    <section aria-label="광고 자리" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(340px, 100%), 1fr)); gap: 16px">
      @foreach ($slots as $key => $s)
        <article class="adv-card" style="padding: 24px; display: flex; gap: 20px; align-items: flex-start">
          <span aria-hidden="true" style="position: relative; width: 72px; height: 112px; border-radius: 12px; flex-shrink: 0; background: #F4F2EE; border: 1px solid #E4E0D8">
            <span style="position: absolute; left: 9px; right: 9px; border-radius: 3px; top: {{ round($s['top'] * 2) }}px; height: {{ round($s['h'] * 2) }}px; background: #FF5A36"></span>
          </span>
          <div style="flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 10px">
            <div style="display: flex; flex-direction: column; gap: 4px"><h2 style="margin: 0; font-size: 19px; font-weight: 900">{{ $s['name'] }}</h2><span style="font-size: 13px; color: #66625B; line-height: 1.5">{{ $s['desc'] }}</span></div>
            <dl style="margin: 0; display: grid; grid-template-columns: auto 1fr; gap: 6px 14px; font-size: 14px">
              <dt style="color: #66625B">단가</dt><dd style="margin: 0; font-weight: 700">노출 1,000회당 ₩{{ number_format($s['cpm']) }} <span style="font-weight: 400; color: #77736B">· 부가세 별도</span></dd>
              <dt style="color: #66625B">최소</dt><dd style="margin: 0">보장 노출 {{ number_format($s['min']) }}회 (₩{{ number_format(intdiv($s['min'] * $s['cpm'], 1000)) }}부터)</dd>
              @if ($daily[$key] ?? null)
                <dt style="color: #66625B">하루 평균</dt><dd style="margin: 0">노출 약 {{ number_format($daily[$key]) }}회 <span style="color: #77736B">· 최근 30일</span></dd>
              @endif
              <dt style="color: #66625B">소재</dt><dd style="margin: 0">{{ $s['spec'] }}</dd>
            </dl>
          </div>
        </article>
      @endforeach
    </section>
  @endif

  <section style="padding: 20px 22px; border-radius: 14px; background: #ECEAE5; display: flex; flex-direction: column; gap: 6px; font-size: 13px; color: #55524C; line-height: 1.7">
    <b style="font-size: 14px; color: #141413">진행 방법</b>
    <span>문의를 남기면 영업일 2일 안에 견적서 링크를 메일로 보내드려요 → 견적 수락 · 계좌 입금 · 세금계산서 정보 입력 → 입금 확인 후 노출 시작일부터 광고가 나가고, 성과 보고 링크를 보내드려요.</span>
    <span>이미 견적을 받으셨다면 <a href="{{ route('advertiser.login') }}">광고주 로그인</a>으로 견적 · 성과 보고를 한 곳에서 볼 수 있어요.</span>
  </section>
</main>
@endsection
