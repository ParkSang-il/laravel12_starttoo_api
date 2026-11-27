@extends('advertiser.layouts.web')
@section('title', '광고 상품 소개')

@section('content')
@php
  $kind = fn ($slot) => $slot === 'community_top' ? 'community' : 'feed';
  // FAQ 계산 예시에 쓸 단가(받는 자리가 없으면 단가표 기본값)
  $exCpm = (int) (array_values($slots)[0]['cpm'] ?? \App\Services\Starttoo\AdSales::SLOTS['feed_inline']['cpm']);
  $bullets = [
    'feed_inline' => ['작품을 넘겨 보는 홈 피드 한가운데, 게시물과 같은 크기의 세로 사진 카드', '사진 아래 「자세히 보기」 줄을 누르면 브랜드 페이지가 바로 열려요', '같은 피드에서는 한 번 본 광고를 다시 보여 주지 않아 피로감이 적어요'],
    'community_top' => ['후기 · 질문 게시판을 여는 순간 가장 먼저 보이는 맨 위 카드', '실시간 인기글과 나란히 좌우로 넘기는 자리라 자연스럽게 눈에 들어와요', '문구 중심 카드라 사진이 없어도 진행할 수 있어요'],
  ];
@endphp

{{-- ── 히어로 ── --}}
<section class="pd-hero">
  <div class="adv-wrap pd-hero-in">
    <div style="flex: 1 1 440px; min-width: 0; display: flex; flex-direction: column; gap: 22px">
      <span class="pd-kicker">STARTTOO ADS</span>
      <h1 class="pd-h1">타투를 고르는 순간,<br><em>브랜드가 보입니다</em></h1>
      <p class="pd-lead">STARTTOO는 작품을 둘러보고, 가상으로 피팅해 보고, 작가에게 상담을 요청하는 타투 플랫폼이에요. 아프터케어 · 뷰티 · 패션 브랜드를 관심이 가장 높은 순간에 보여 드려요.</p>
      <div style="display: flex; flex-wrap: wrap; gap: 10px">
        <a href="{{ route('advertiser.inquiry') }}" class="adv-btn is-accent">광고 문의하기</a>
        <a href="#slots" class="pd-btn-line">광고 자리 보기</a>
      </div>
      @if ($stats)
        <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-top: 6px">
          @foreach ($stats as $s)<div class="pd-stat"><strong>{{ $s['v'] }}</strong><span>{{ $s['label'] }}</span></div>@endforeach
        </div>
        <span style="font-size: 12px; color: #77736B; margin-top: -10px">최근 30일 실제 광고 기록으로 자동 집계</span>
      @endif
    </div>
    <div class="pd-phones" aria-hidden="true">
      @include('advertiser.partials.phone', ['kind' => 'feed', 'shot' => $shots['feed_inline'] ?? null, 'class' => 'a'])
      @include('advertiser.partials.phone', ['kind' => 'community', 'shot' => $shots['community_top'] ?? null, 'class' => 'b'])
    </div>
  </div>
</section>

<main class="adv-wrap">
  {{-- ── 자리별 ── --}}
  <section id="slots" class="pd-sec" aria-label="광고 자리">
    <div class="pd-sec-head">
      <span class="pd-kicker">PLACEMENTS</span>
      <h2 class="pd-h2">앱에서 광고는 이렇게 보여요</h2>
      <p style="margin: 0; font-size: 15px; color: #55524C; line-height: 1.7">주홍 테두리가 광고 자리예요. 모든 광고는 「광고」 표시와 함께 앱 화면과 같은 모양으로 나가요.</p>
    </div>

    @forelse ($slots as $key => $s)
      <article class="pd-slot {{ $loop->odd ? '' : 'rev' }}">
        <div class="pd-slot-stage">
          @include('advertiser.partials.phone', ['kind' => $kind($key), 'shot' => $shots[$key] ?? null])
        </div>
        <div class="pd-slot-info">
          <span class="adv-pill gray" style="align-self: flex-start">{{ $loop->iteration < 10 ? '0' . $loop->iteration : $loop->iteration }} · {{ $key === 'community_top' ? '커뮤니티' : '홈' }}</span>
          <div style="display: flex; flex-direction: column; gap: 6px">
            <h3 style="margin: 0; font-size: clamp(24px, 2.6vw, 32px); font-weight: 900; letter-spacing: -0.5px">{{ $s['name'] }}</h3>
            <span style="font-size: 15px; color: #55524C; line-height: 1.6">{{ $s['desc'] }}</span>
          </div>
          <ul class="pd-list">@foreach ($bullets[$key] ?? [] as $b)<li>{{ $b }}</li>@endforeach</ul>
          <div class="pd-price"><span style="font-size: 14px; color: #66625B">노출 1,000회당</span><strong>₩{{ number_format($s['cpm']) }}</strong><span style="font-size: 13px; color: #77736B">부가세 별도</span></div>
          <div class="pd-facts">
            <div class="pd-fact"><span>최소 보장 노출</span><b>{{ number_format($s['min']) }}회</b></div>
            <div class="pd-fact"><span>최소 금액</span><b>₩{{ number_format(intdiv($s['min'] * $s['cpm'], 1000)) }}</b></div>
            @if ($daily[$key] ?? null)<div class="pd-fact"><span>하루 평균 노출 · 최근 30일</span><b>약 {{ number_format($daily[$key]) }}회</b></div>@endif
            <div class="pd-fact"><span>소재</span><b style="font-size: 14px">{{ $s['spec'] }}</b></div>
          </div>
          <div><a href="{{ route('advertiser.inquiry') }}" class="adv-btn is-sm">이 자리로 문의하기</a></div>
        </div>
      </article>
    @empty
      <div class="adv-card" style="padding: 28px; font-size: 15px; color: #55524C">지금은 광고 상품을 정리하고 있어요. 문의는 <a href="mailto:{{ config('advertiser.ops_email') }}">{{ config('advertiser.ops_email') }}</a> 로 보내주세요.</div>
    @endforelse
  </section>

  {{-- ── 왜 ── --}}
  <section class="pd-sec" aria-label="STARTTOO 광고의 장점">
    <div class="pd-sec-head"><span class="pd-kicker">WHY STARTTOO</span><h2 class="pd-h2">보여진 만큼만 내는 광고</h2></div>
    <div class="pd-why">
      <div><span class="ic"><svg viewBox="0 0 24 24"><path d="M12 21s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 11c0 5.6-7 10-7 10z"/></svg></span><h3>관심이 높은 순간</h3><p>타투 작품을 고르고, 시술 후기를 읽고, 작가에게 상담을 요청하는 사람들에게 보여요. 아프터케어 · 뷰티 · 패션과 잘 맞아요.</p></div>
      <div><span class="ic"><svg viewBox="0 0 24 24"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg></span><h3>CPM · 보장 노출</h3><p>노출 1,000회당 단가로 보장 노출을 정해요. 보장 노출을 다 채우면 그날 광고가 끝나서, 계약한 만큼만 나가요.</p></div>
      <div><span class="ic"><svg viewBox="0 0 24 24"><path d="M4 4h16v12H8l-4 4zM8 9h8M8 12h5"/></svg></span><h3>투명한 성과 보고</h3><p>일별 노출 · 클릭 · 클릭률과 보장 노출 달성률을 전용 링크와 광고주 계정에서 언제든 확인할 수 있어요.</p></div>
    </div>
  </section>

  {{-- ── 성과 보고 미리보기 ── --}}
  <section class="pd-sec" aria-label="성과 보고 화면">
    <div class="pd-report">
      <div style="flex: 1 1 320px; display: flex; flex-direction: column; gap: 14px">
        <span class="pd-kicker">REPORT</span>
        <h2 class="pd-h2">노출이 시작되면<br>성과 보고 링크를 드려요</h2>
        <p style="margin: 0; font-size: 15px; color: #55524C; line-height: 1.75">로그인 없이 열리는 전용 링크로 매일 전날까지의 기록을 볼 수 있어요. PDF로 저장해 내부 보고에 바로 쓸 수 있고, 캠페인이 끝나면 최종 보고를 메일로 보내 드려요.</p>
        <ul class="pd-list"><li>일별 노출 · 클릭 · 클릭률 그래프</li><li>자리별 · 소재별 성과</li><li>보장 노출 달성률</li></ul>
      </div>
      <div class="pd-report-card" aria-hidden="true">
        <div style="display: flex; justify-content: space-between; align-items: center; gap: 10px"><b style="font-size: 15px">일별 노출</b><span class="adv-pill gray">예시 화면</span></div>
        <div class="pd-bars">@foreach ([38, 52, 47, 61, 58, 72, 66, 80, 74, 88, 83, 95, 0, 0] as $h)<i class="{{ $h ? '' : 'off' }}" style="height: {{ $h ?: 4 }}%"></i>@endforeach</div>
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px">
          @foreach (['노출', '클릭', '보장 노출 달성'] as $l)<div style="padding: 12px; border-radius: 12px; background: #F4F2EE; display: flex; flex-direction: column; gap: 8px"><span style="font-size: 12px; color: #66625B">{{ $l }}</span><span style="height: 12px; width: 70%; border-radius: 4px; background: #D9D4CB"></span></div>@endforeach
        </div>
      </div>
    </div>
  </section>

  {{-- ── 진행 방법 ── --}}
  <section class="pd-sec" aria-label="진행 방법">
    <div class="pd-sec-head"><span class="pd-kicker">PROCESS</span><h2 class="pd-h2">문의부터 노출까지 4단계</h2></div>
    <div class="pd-steps">
      <div><b>문의 남기기</b><span>원하는 자리 · 기간 · 예산만 알려주세요. 정확하지 않아도 돼요.</span></div>
      <div><b>견적서 받기</b><span>영업일 2일 안에 견적서 링크를 메일로 보내 드려요. 소재 심사 결과도 함께요.</span></div>
      <div><b>입금 · 세금계산서</b><span>견적을 수락하고 계좌로 입금한 뒤 사업자 정보를 남기면 세금계산서를 발행해요.</span></div>
      <div style="border-right: 0"><b>노출 · 성과 보고</b><span>시작일부터 광고가 나가고, 성과 보고 링크로 매일 결과를 볼 수 있어요.</span></div>
    </div>
  </section>

  {{-- ── 자주 묻는 질문 ── --}}
  <section class="pd-sec pd-faq" aria-label="자주 묻는 질문">
    <div class="pd-sec-head"><span class="pd-kicker">FAQ</span><h2 class="pd-h2">자주 묻는 질문</h2></div>
    <details><summary>CPM이 뭔가요?</summary><p>광고가 1,000번 보일 때의 가격이에요. 예를 들어 CPM ₩{{ number_format($exCpm) }}에 보장 노출 100,000회라면 100 × ₩{{ number_format($exCpm) }} = ₩{{ number_format($exCpm * 100) }}(부가세 별도)이에요.</p></details>
    <details><summary>결제는 어떻게 하나요?</summary><p>견적서에서 수락하면 입금 계좌가 열려요. 계좌이체로 입금하고 사업자등록번호 · 상호 · 받을 이메일을 남기면 세금계산서를 발행해 드려요. 카드 결제는 아직 받지 않아요.</p></details>
    <details><summary>광고 소재는 어떻게 준비하나요?</summary><p>추천 피드 카드는 세로 사진(1080×1350 권장)과 짧은 문구가 필요하고, 커뮤니티 상단은 문구 중심이라 사진이 없어도 돼요. 문의할 때 올리거나 나중에 메일로 보내도 돼요. 모든 소재는 노출 전 심사해요.</p></details>
    <details><summary>어떤 광고는 받지 않나요?</summary><p>통증 없음 · 흉터 없음처럼 시술 효과를 보장하거나 의료 효과를 내세우는 표현, 면허 없는 시술 · 반영구 시술 권유, 과장 · 비교 광고와 혐오 표현은 받지 않아요.</p></details>
    <details><summary>진행 상황은 어디서 보나요?</summary><p>견적서 · 성과 보고는 메일로 받은 전용 링크에서 볼 수 있어요. 견적서 화면에서 광고주 계정을 만들면 <a href="{{ route('advertiser.login') }}">광고주 로그인</a>으로 모든 견적 · 계약 · 성과 보고를 한 곳에서 볼 수 있어요.</p></details>
  </section>

  {{-- ── 마무리 ── --}}
  <section class="pd-final">
    <div style="display: flex; flex-direction: column; gap: 10px; max-width: 560px">
      <span class="pd-kicker">GET STARTED</span>
      <h2 class="pd-h2">브랜드에 맞는 자리를 같이 찾아 드릴게요</h2>
      <span style="font-size: 15px; color: #BDB8B0; line-height: 1.7">문의를 남기면 영업일 2일 안에 견적서를 보내 드려요.</span>
    </div>
    <div style="display: flex; flex-wrap: wrap; gap: 10px">
      <a href="{{ route('advertiser.inquiry') }}" class="adv-btn is-accent">광고 문의하기</a>
      <a href="mailto:{{ config('advertiser.ops_email') }}" class="pd-btn-line">{{ config('advertiser.ops_email') }}</a>
    </div>
  </section>
</main>
@endsection
