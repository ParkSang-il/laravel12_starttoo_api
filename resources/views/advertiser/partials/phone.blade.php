{{--
  앱 화면 모형(상품 소개). 앱 위젯(FeedAdvertisement · CommunityAdCard · FeedHeader · StBottomNav)과 같은 색 · 배치로 그린다.
  $kind = feed | community
  $shot = 실제 앱 광고 카드 캡처 주소(public/advertiser/screens/{slot}.jpg). 있으면 모형 안의 광고 카드 자리를 그 캡처로 채운다.
  캡처가 없으면 광고 카드를 예시 글자로 그린다. 다른 게시물은 회색 막대로만 그린다(가짜 회원 · 수치 없음).
--}}
@php $tab = $kind === 'community' ? 2 : 0; @endphp
<div class="pp-phone {{ $class ?? '' }}" role="img" aria-label="{{ $kind === 'community' ? '커뮤니티 피드 맨 위 광고 카드 화면' : '홈 추천 피드 광고 카드 화면' }}">
  <div class="pp-screen">
    <div class="pp-status"><span>9:41</span><span class="pp-island"></span><span class="pp-status-r"><i></i><i></i><b></b></span></div>
    <div class="pp-tabs">
      @foreach (['추천', '팔로잉', '커뮤니티'] as $i => $t)<span class="{{ $i === $tab ? 'on' : '' }}">{{ $t }}</span>@endforeach
      <span class="pp-tab-icons"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4 4"/></svg><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 16v-5a6 6 0 1 1 12 0v5l2 2H4zM10 21h4"/></svg></span>
    </div>

    @if ($kind === 'community')
      <div class="pp-chips"><span class="on">전체</span><span>질문</span><span>후기</span><span>자유</span></div>
      <div class="pp-body">
        @if ($shot)
          <div class="pp-ad pp-shot-card"><img src="{{ $shot }}" alt="" loading="lazy"><span class="pp-ring" aria-hidden="true"></span></div>
        @else
          <div class="pp-cad pp-ad">
            <div class="pp-cad-img"></div>
            <div class="pp-cad-txt">
              <span class="pp-cad-badge">광고</span>
              <b>광고 문구가 두 줄까지<br>이렇게 크게 보여요</b>
              <span class="pp-cad-brand">브랜드 이름</span>
            </div>
            <span class="pp-ring" aria-hidden="true"></span>
          </div>
        @endif
        <div class="pp-dots"><i class="on"></i><i></i></div>
        @for ($i = 0; $i < 5; $i++)
          <div class="pp-post">
            <span class="pp-tag"></span>
            <span class="pp-bar" style="width: {{ [82, 64, 74, 58, 70][$i] }}%"></span>
            <span class="pp-bar sm" style="width: {{ [46, 52, 38, 44, 50][$i] }}%"></span>
          </div>
        @endfor
      </div>
    @else
      <div class="pp-chips"><span class="on">전체</span><span class="pp-ghost"></span><span class="pp-ghost"></span><span class="pp-ghost"></span></div>
      <div class="pp-body flush">
        @if ($shot)
          <div class="pp-ad pp-shot-feed"><img src="{{ $shot }}" alt="" loading="lazy"><span class="pp-ring" aria-hidden="true"></span></div>
        @else
          <div class="pp-ad">
            <div class="pp-feed-img">
              <div class="pp-product"><i></i><b></b></div>
              <div class="pp-author">
                <span class="pp-avatar"></span>
                <span class="pp-author-t"><b>브랜드 이름</b><small>광고</small></span>
                <span class="pp-more">···</span>
              </div>
            </div>
            <div class="pp-cta"><span>자세히 보기</span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg></div>
            <div class="pp-cap"><b>브랜드 이름</b> 광고 문구가 사진 아래에 이렇게 이어져요</div>
            <span class="pp-ring" aria-hidden="true"></span>
          </div>
        @endif
        <div class="pp-next"></div>
      </div>
    @endif

    <div class="pp-nav">
      @foreach (['홈', '발견', '등록', '채팅', 'MY'] as $i => $n)
        <span class="{{ $i === 0 ? 'on' : '' }}"><i></i>{{ $n }}</span>
      @endforeach
    </div>
  </div>
</div>
