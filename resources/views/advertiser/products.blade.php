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
      <p class="pd-lead">STARTTOO는 작품을 둘러보고, 가상으로 피팅해 보고, 작가에게 상담을 요청하는 타투 플랫폼이에요. 애프터케어 · 뷰티 · 패션 브랜드를 관심이 가장 높은 순간에 보여 드려요.</p>
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
      <div><span class="ic"><svg viewBox="0 0 24 24"><path d="M12 21s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 11c0 5.6-7 10-7 10z"/></svg></span><h3>관심이 높은 순간</h3><p>타투 작품을 고르고, 시술 후기를 읽고, 작가에게 상담을 요청하는 사람들에게 보여요. 애프터케어 · 뷰티 · 패션과 잘 맞아요.</p></div>
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
        <p style="margin: 0; font-size: 15px; color: #55524C; line-height: 1.75">메일로 받은 전용 링크에서 광고주 계정으로 로그인하면 오늘 집계 중인 기록까지 볼 수 있어요. PDF로 저장해 내부 보고에 바로 쓸 수 있고, 캠페인이 끝나면 최종 보고를 메일로 보내 드려요.</p>
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
    <details><summary>CPM이 뭔가요?</summary>
      <p>CPM(Cost Per Mille)은 광고가 <b>1,000번 보일 때의 가격</b>이에요. STARTTOO는 기간이 아니라 노출 수로 광고를 팔아요. 견적에서 「보장 노출」을 정하면 그만큼 보일 때까지 광고가 나가요.</p>
      <div class="pd-calc">
        <span>CPM ₩{{ number_format($exCpm) }} × 보장 노출 100,000회</span>
        <span>= 100 × ₩{{ number_format($exCpm) }}</span>
        <b>= ₩{{ number_format($exCpm * 100) }} <small>부가세 별도 · 부가세 포함 ₩{{ number_format((int) round($exCpm * 100 * 1.1)) }}</small></b>
      </div>
      <p>자리마다 단가와 최소 보장 노출이 달라요. 위 「광고 자리」에서 확인할 수 있고, 보장 노출은 1,000회 단위로 정해요. 여러 자리를 함께 집행하면 자리별 금액을 더해 한 장의 견적서로 보내 드려요.</p>
    </details>
    <details><summary>노출은 어떻게 세고, 언제 끝나나요?</summary>
      <ul>
        <li>광고가 앱 화면에 나타날 때마다 1회로 세요. 누른 횟수(클릭)는 따로 세어 성과 보고에 보여 드려요.</li>
        <li>같은 피드를 보는 동안에는 한 사람에게 같은 광고를 두 번 보여 주지 않아요. 새로고침하거나 화면을 다시 열면 다시 나올 수 있어요.</li>
        <li>보장 노출을 다 채우면 <b>그 즉시 광고가 멈추고</b> 캠페인이 끝나요. 그래서 계약한 노출보다 많이 나가거나 더 청구되는 일은 없어요.</li>
        <li>견적서에는 노출 시작일과 「최대 종료일」이 적혀 있어요. 보장 노출을 먼저 채우면 종료일 전에 끝나고, 종료일이 되면 노출을 멈춰요. 종료일까지 다 채우지 못했을 때의 처리는 견적서 「조건」에 적어 드려요.</li>
        <li>예상 소진 기간은 최근 30일 같은 자리의 실제 하루 평균 노출로 계산해 견적서에 적어 드려요. 예상치라 실제와 다를 수 있어요.</li>
      </ul>
    </details>
    <details><summary>결제는 어떻게 하나요?</summary>
      <ol>
        <li><b>견적 수락</b>: 메일로 받은 견적서 링크에서 「견적 수락하기」를 누르면 입금 계좌가 열려요. 견적서는 발행일부터 {{ config('advertiser.quote_valid_days', 7) }}일 동안 유효하고, 기한이 지나면 새 견적을 요청해야 해요.</li>
        <li><b>계좌이체</b>: 견적서 합계(부가세 포함)를 안내된 계좌로 보내 주세요. 입금자명은 회사명으로 해 주세요.</li>
        <li><b>입금 알림 · 세금계산서 정보</b>: 견적서 화면에 사업자등록번호 · 상호와 대표자 · 세금계산서 받을 이메일을 적고 「입금했어요」를 눌러 주세요. 사업자등록증은 올려도 되고 안 올려도 돼요.</li>
        <li><b>계약 완료</b>: 담당자가 입금을 확인하면(보통 영업일 하루 안) 계약 완료 메일과 세금계산서를 보내 드려요.</li>
      </ol>
      <p>카드 결제와 후불 결제는 아직 받지 않아요. 견적 내용을 바꾸고 싶으면 수락 전에 견적서의 「수정 요청」으로 알려 주세요. 새 견적서를 메일로 보내 드려요.</p>
    </details>
    <details><summary>광고 소재는 어떻게 준비하나요?</summary>
      <ul>
        <li><b>추천 피드 카드</b>: 세로 사진 1장이 꼭 필요해요. 1080×1350(4:5) 비율을 권하고, 위쪽은 브랜드 이름이 겹쳐 보이니 중요한 글자나 로고는 가운데 아래쪽에 두세요. 사진 아래에는 광고 문구가 한 줄 이어져요.</li>
        <li><b>커뮤니티 상단</b>: 광고 문구가 크게 두 줄까지 보이는 카드예요. 사진은 넣지 않아도 되고, 넣으면 글자가 잘 읽히게 어둡게 덮어 배경으로 깔아요.</li>
        <li><b>광고 문구</b>: 80자까지 쓸 수 있지만, 화면에서는 앞부분만 보이니 핵심을 앞에 써 주세요.</li>
        <li><b>연결 주소</b>: 광고를 누르면 열릴 브랜드 페이지예요. 앱에서 열려면 https:// 로 시작하는 주소여야 해요.</li>
        <li><b>파일 형식</b>: JPG · PNG · WEBP. 앱 광고 자리에는 지금 사진만 나가요(영상은 아직 지원하지 않아요).</li>
      </ul>
      <p>소재는 문의할 때 올리거나 견적을 받은 뒤 메일로 보내 주셔도 돼요. 모든 소재는 노출 전에 심사하고, 고칠 부분이 있으면 노출 전에 알려 드려요. 소재를 여러 개 준비하면 보장 노출을 나눠 함께 내보내고, 성과 보고에서 소재별 결과를 비교할 수 있어요.</p>
    </details>
    <details><summary>어떤 광고는 받지 않나요?</summary>
      <p>타투를 알아보는 사람들이 보는 자리라, 아래 광고는 받지 않거나 수정을 요청해요.</p>
      <ul>
        <li><b>효과 보장 · 의료 표현</b>: 「통증 없음」 「흉터 없음」 「100% 지워짐」처럼 시술 결과를 보장하거나, 치료 · 의료 효과를 내세우는 표현</li>
        <li><b>불법 · 무면허 시술</b>: 면허 없는 시술이나 반영구 시술을 권유하는 광고, 가격 할인만 강조하는 시술 광고</li>
        <li><b>과장 · 비교 광고</b>: 근거 없는 「최고」 「1위」 표현, 다른 업체를 깎아내리는 비교</li>
        <li><b>혐오 · 선정 표현</b>: 혐오를 부추기는 표현, 지나치게 선정적이거나 불쾌감을 주는 이미지</li>
        <li><b>그 밖에</b>: 법으로 광고가 제한된 상품 · 서비스, 다른 사람의 상표 · 저작물을 허락 없이 쓴 소재</li>
      </ul>
      <p>모든 광고는 앱에서 「광고」 표시와 함께 나가요. 심사에서 진행하기 어렵다고 판단하면 사유를 메일로 알려 드려요.</p>
    </details>
    <details><summary>진행 상황은 어디서 보나요?</summary>
      <ul>
        <li><b>견적서 링크</b>: 견적이 나오면 메일로 보내 드려요. 처음 열 때 비밀번호를 정해 광고주 계정을 만들면 열리고, 수락 · 수정 요청 · 입금 알림을 모두 이 화면에서 해요.</li>
        <li><b>성과 보고 링크</b>: 노출이 시작되는 날 메일로 보내 드려요. 일별 노출 · 클릭 · 클릭률, 자리별 · 소재별 성과, 보장 노출 달성률을 오늘 집계 중인 기록까지 바로 반영해 보여 주고, PDF로 저장할 수 있어요. 캠페인이 끝나면 최종 보고를 다시 보내 드려요.</li>
        <li><b>광고주 계정</b>: 견적서 화면에서 비밀번호만 정하면 계정이 만들어져요. <a href="{{ route('advertiser.login') }}">광고주 로그인</a>으로 그동안의 견적 · 계약 · 성과 보고와 보낸 문의를 한 곳에서 보고, 세금계산서 정보를 저장해 둘 수 있어요. 비밀번호를 잊었으면 <a href="{{ route('advertiser.password.forgot') }}">비밀번호 설정 링크</a>를 메일로 받으면 돼요.</li>
      </ul>
      <p>견적서 · 성과 보고 링크는 받은 분만 열 수 있는 전용 주소예요. 담당자 외에는 공유하지 말아 주시고, 링크가 퍼졌다면 담당자에게 알려 주세요. 새 링크를 보내 드리고 예전 링크는 바로 막아요.</p>
    </details>
    <details><summary>광고 시작일은 정할 수 있나요?</summary>
      <p>네. 문의할 때 희망 시작일을 적어 주시면 견적서에 노출 시작일로 넣어 드려요. 시작일 전까지 입금 확인과 소재 심사가 끝나야 그날부터 광고가 나갈 수 있으니, 시작일 기준 영업일 3~4일 전에는 문의해 주세요.</p>
    </details>
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
