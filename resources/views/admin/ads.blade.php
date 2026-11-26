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
          <thead><tr><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">노출 위치</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">광고 작가 · 상점</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">기간</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap" title="볼 때마다 셈(같은 회원이 여러 번 보면 여러 번)">전체 노출</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap" title="처음 본 회원만 셈(이미 본 회원은 세지 않음)">신규 노출</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">클릭 · 클릭률</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">상태</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">노출</th></tr></thead>
          <tbody>
            @forelse ($slots as $s)
              <tr style="{{ $s['rowStyle'] }}">
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap; font-weight: 700">{{ $s['where'] }}<span style="display: block; font-size: 12px; font-weight: 400; color: #66625B">{{ $s['title'] }}@if ($s['editHref']) · <a href="{{ $s['editHref'] }}" style="color: #1F4F99; font-weight: 700">수정</a>@endif</span></td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap">@if ($s['whoHref'])<a href="{{ $s['whoHref'] }}" target="_blank" rel="noopener" title="상점 공개 페이지" style="color: #141413; text-decoration: underline; text-underline-offset: 3px">{{ $s['who'] }}</a>@else{{ $s['who'] }}@endif</td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap; color: #66625B">{{ $s['period'] }}</td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap; font-variant-numeric: tabular-nums">{{ $s['imp'] }}</td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap; font-variant-numeric: tabular-nums; font-weight: 700">{{ $s['reach'] }}</td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap; font-variant-numeric: tabular-nums">{{ $s['clk'] }} <span style="color: #66625B">· {{ $s['ctr'] }}</span></td>
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
              <tr><td colspan="8" style="padding: 32px 16px; font-size: 14px; color: #66625B; text-align: center">등록된 광고가 없어요. 「새 광고」로 등록해요.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </section>

    {{-- 작가 광고 심사: 앱 「스튜디오 → 광고」에서 작가가 신청 · 변경 요청한 광고. 승인하면 바로 노출(결제는 Phase 6) --}}
    <section style="background: #FFFFFF; border: 1px solid #E4E0D8; border-radius: 16px; overflow: hidden">
      <div style="padding: 18px 20px; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 10px">
        <div style="display: flex; flex-direction: column; gap: 4px">
          <h2 style="margin: 0; font-size: 17px; font-weight: 700">작가 광고 심사</h2>
          <span style="font-size: 13px; color: #66625B">승인하면 바로 노출돼요(한 달 상품은 다음 달 1일부터). 가격 할인 · 시술 효과 보장 표현은 반려해요. 결과는 작가에게 알림으로 가요.</span>
        </div>
        <span style="{{ \App\Support\AdminUi::pill(count($queue) ? 'blue' : 'gray') }}">대기 {{ count($queue) }}건</span>
      </div>
      @forelse ($queue as $q)
        <article style="padding: 16px 20px; border-top: 1px solid #F0EDE7; display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-start">
          @if ($q['image'])
            {{-- 직접 올린 사진은 크게 열어 심사할 수 있게 링크 + 표시 --}}
            <a href="{{ $q['image'] }}" target="_blank" rel="noopener" style="flex: none; display: flex; flex-direction: column; gap: 4px; align-items: center; text-decoration: none">
              <img src="{{ $q['image'] }}" alt="{{ ($q['own_image'] ?? false) ? '직접 올린 광고 사진' : '광고 작품' }}" style="width: 72px; height: 90px; object-fit: cover; border-radius: 8px; border: 1px solid #E4E0D8">
              @if ($q['own_image'] ?? false)<span style="{{ \App\Support\AdminUi::pill('amber') }}">직접 올린 사진</span>@endif
            </a>
          @else
            <div style="width: 72px; height: 90px; border-radius: 8px; background: #F0EDE7; flex: none"></div>
          @endif
          <div style="flex: 1 1 260px; min-width: 0; display: flex; flex-direction: column; gap: 6px">
            <div style="display: flex; flex-wrap: wrap; gap: 6px; align-items: center">
              <span style="{{ \App\Support\AdminUi::pill($q['kind'] === 'change' ? 'amber' : 'blue') }}">{{ $q['kind'] === 'change' ? '문구 · 작품 변경' : '새 신청' }}</span>
              <strong style="font-size: 14px">{{ $q['product'] }}</strong>
              <span style="font-size: 12px; color: #66625B">#{{ $q['id'] }} · {{ $q['when'] }} 신청</span>
            </div>
            <div style="font-size: 15px; font-weight: 700">“{{ $q['title'] }}”</div>
            @if ($q['before'])
              <div style="font-size: 12px; color: #66625B">지금 문구: “{{ $q['before'] }}”</div>
            @endif
            <div style="font-size: 13px; color: #3A3731">
              @if ($q['shopHref'])<a href="{{ $q['shopHref'] }}" target="_blank" rel="noopener" style="color: #141413; text-decoration: underline; text-underline-offset: 3px">{{ $q['advertiser'] }}</a>@else{{ $q['advertiser'] }}@endif
              · {{ $q['artist'] }} · {{ $q['period'] }} · 예상 광고비 ₩{{ number_format($q['amount']) }}
              @if ($q['keywords']) · 키워드 {{ collect($q['keywords'])->map(fn ($k) => '#' . $k)->join(' ') }}@endif
            </div>
          </div>
          @if ($canReview)
            <div style="flex: 1 1 280px; display: flex; flex-direction: column; gap: 8px">
              <form method="POST" action="{{ $q['approve'] }}" style="display: contents">@csrf
                <button type="submit" style="height: 40px; border-radius: 10px; font-size: 14px; font-weight: 700; border: 0; background: #141413; color: #FFFFFF">{{ $q['kind'] === 'change' ? '변경 승인' : '승인 · 노출 시작' }}</button>
              </form>
              <form method="POST" action="{{ $q['reject'] }}" style="display: flex; gap: 8px">@csrf
                <input name="reason" required maxlength="300" placeholder="반려 사유(작가에게 보여요)" aria-label="광고 #{{ $q['id'] }} 반려 사유" style="flex: 1; min-width: 0; height: 40px; border-radius: 10px; border: 1px solid #D9D4CB; padding: 0 12px; font-size: 13px; color: #141413; background: #FFFFFF; box-sizing: border-box">
                <button type="submit" style="height: 40px; padding: 0 14px; border-radius: 10px; font-size: 14px; font-weight: 700; border: 1px solid #E8C2B8; background: #FFFFFF; color: #B3261E">반려</button>
              </form>
            </div>
          @endif
        </article>
      @empty
        <div style="padding: 24px 20px; border-top: 1px solid #F0EDE7; font-size: 14px; color: #66625B; text-align: center">심사할 작가 광고가 없어요.</div>
      @endforelse
    </section>

    {{-- 작가 광고 상품: 앱 「광고 만들기 1/3」에 보이는 위치 · 단가 · 자리 수 --}}
    <section style="background: #FFFFFF; border: 1px solid #E4E0D8; border-radius: 16px; padding: 20px; display: flex; flex-direction: column; gap: 14px">
      <div style="display: flex; flex-direction: column; gap: 4px">
        <h2 style="margin: 0; font-size: 17px; font-weight: 700">작가 광고 상품</h2>
        <span style="font-size: 13px; color: #66625B">단가는 부가세 별도이고 지금은 앱에 「예상 광고비」로만 안내해요(결제는 Phase 6). 자리 수 0은 제한 없음, 한 달 상품은 한 달에 받을 수 있는 광고 수예요.</span>
      </div>
      <form method="POST" action="{{ $productsAction }}" style="display: flex; flex-direction: column; gap: 10px">
        @csrf
        @foreach ($products as $id => $p)
          <div style="padding: 14px; border-radius: 12px; background: #F7F5F1; display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end">
            <label style="flex: 1 1 200px; display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 700; min-height: 40px"><input type="hidden" name="products[{{ $id }}][on]" value="0"><input type="checkbox" name="products[{{ $id }}][on]" value="1" @checked(old("products.$id.on", $p['on'])) @disabled(! $canReview) style="width: 18px; height: 18px; margin: 0; accent-color: #141413">{{ $p['name'] }} <span style="font-weight: 400; color: #66625B">· {{ $p['unit'] === 'month' ? '한 달' : '하루' }} 단위</span></label>
            <label style="flex: 0 1 160px; display: flex; flex-direction: column; gap: 6px"><span style="font-size: 12px; font-weight: 700; color: #3A3731">{{ $p['unit'] === 'month' ? '한 달' : '하루' }} 단가(원)</span><input type="number" name="products[{{ $id }}][price]" min="0" max="100000000" step="1000" value="{{ old("products.$id.price", $p['price']) }}" @disabled(! $canReview) style="height: 40px; border-radius: 10px; border: 1px solid #D9D4CB; padding: 0 12px; font-size: 14px; color: #141413; background: #FFFFFF; box-sizing: border-box; width: 100%"></label>
            <label style="flex: 0 1 120px; display: flex; flex-direction: column; gap: 6px"><span style="font-size: 12px; font-weight: 700; color: #3A3731">자리 수</span><input type="number" name="products[{{ $id }}][capacity]" min="0" max="1000" value="{{ old("products.$id.capacity", $p['capacity']) }}" @disabled(! $canReview) style="height: 40px; border-radius: 10px; border: 1px solid #D9D4CB; padding: 0 12px; font-size: 14px; color: #141413; background: #FFFFFF; box-sizing: border-box; width: 100%"></label>
            @if ($id === $regionPriced)
              {{-- 지역별 단가: 작가 상점 지역 기준. 비우면 위의 기본 단가 --}}
              <details style="flex: 1 1 100%" @if (count($p['region_prices'])) open @endif>
                <summary style="cursor: pointer; font-size: 13px; font-weight: 700; color: #3A3731">지역별 단가 <span style="font-weight: 400; color: #66625B">· 작가 상점 지역 기준, 비우면 기본 단가 · 설정 {{ count($p['region_prices']) }}곳</span></summary>
                <div style="margin-top: 10px; display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 8px">
                  @foreach ($regionOptions as $r)
                    <label style="display: flex; flex-direction: column; gap: 4px"><span style="font-size: 12px; color: #3A3731">{{ $r['label'] }}</span><input type="number" name="products[{{ $id }}][region_prices][{{ $r['id'] }}]" min="0" max="100000000" step="1000" value="{{ old("products.$id.region_prices.{$r['id']}", $p['region_prices'][$r['id']] ?? '') }}" placeholder="{{ number_format($p['price']) }}" @disabled(! $canReview) style="height: 36px; border-radius: 8px; border: 1px solid #D9D4CB; padding: 0 10px; font-size: 13px; color: #141413; background: #FFFFFF; box-sizing: border-box; width: 100%"></label>
                  @endforeach
                </div>
              </details>
            @endif
          </div>
        @endforeach
        @if ($canReview)
          <div style="display: flex; justify-content: flex-end"><button type="submit" style="height: 40px; padding: 0 20px; border-radius: 10px; font-size: 14px; font-weight: 700; border: 0; background: #141413; color: #FFFFFF">저장</button></div>
        @endif
      </form>
    </section>

    {{-- 앱 「광고 안내」 페이지: 광고 만들기 · 광고 관리에서 여는 안내. 섹션(제목 + 본문)별로 고친다 --}}
    @php
      $guideRows = old('sections', array_merge(
        array_map(fn ($s, $i) => $s + ['order' => $i + 1], $guide['sections'], array_keys($guide['sections'])),
        [['title' => '', 'body' => '', 'order' => count($guide['sections']) + 1], ['title' => '', 'body' => '', 'order' => count($guide['sections']) + 2]]
      ));
    @endphp
    <section style="background: #FFFFFF; border: 1px solid #E4E0D8; border-radius: 16px; padding: 20px; display: flex; flex-direction: column; gap: 14px">
      <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 10px">
        <div style="display: flex; flex-direction: column; gap: 4px">
          <h2 style="margin: 0; font-size: 17px; font-weight: 700">광고 안내 페이지</h2>
          <span style="font-size: 13px; color: #66625B">앱 「광고 만들기」 · 「광고 관리」의 광고 안내에 보이는 내용이에요. 제목과 본문을 모두 비우면 그 섹션은 빠지고, 순서 숫자가 작은 섹션이 위로 가요. 본문 줄바꿈은 그대로 보여요.</span>
        </div>
        <span style="font-size: 12px; color: #66625B">{{ $guide['updated_at'] ? '마지막 저장 ' . \App\Support\AdminUi::date($guide['updated_at'], 'Y.m.d H:i') : '기본 안내 사용 중' }}</span>
      </div>
      <form method="POST" action="{{ $guideAction }}" style="display: flex; flex-direction: column; gap: 10px">
        @csrf
        @foreach ($guideRows as $i => $row)
          <div style="padding: 14px; border-radius: 12px; background: #F7F5F1; display: flex; flex-direction: column; gap: 8px">
            <div style="display: flex; gap: 8px">
              <label style="flex: 0 0 72px; display: flex; flex-direction: column; gap: 6px"><span style="font-size: 12px; font-weight: 700; color: #3A3731">순서</span><input type="number" name="sections[{{ $i }}][order]" min="0" max="999" value="{{ $row['order'] ?? $i + 1 }}" @disabled(! $canReview) style="height: 40px; border-radius: 10px; border: 1px solid #D9D4CB; padding: 0 10px; font-size: 14px; color: #141413; background: #FFFFFF; box-sizing: border-box; width: 100%"></label>
              <label style="flex: 1; display: flex; flex-direction: column; gap: 6px"><span style="font-size: 12px; font-weight: 700; color: #3A3731">제목 <span style="font-weight: 400; color: #66625B">· {{ $guideLimits['title'] }}자까지</span></span><input name="sections[{{ $i }}][title]" maxlength="{{ $guideLimits['title'] }}" value="{{ $row['title'] ?? '' }}" placeholder="{{ ($row['title'] ?? '') === '' ? '새 섹션 제목(비우면 추가하지 않아요)' : '' }}" @disabled(! $canReview) style="height: 40px; border-radius: 10px; border: 1px solid #D9D4CB; padding: 0 12px; font-size: 14px; font-weight: 700; color: #141413; background: #FFFFFF; box-sizing: border-box; width: 100%"></label>
            </div>
            <label style="display: flex; flex-direction: column; gap: 6px"><span style="font-size: 12px; font-weight: 700; color: #3A3731">본문 <span style="font-weight: 400; color: #66625B">· {{ number_format($guideLimits['body']) }}자까지</span></span><textarea name="sections[{{ $i }}][body]" maxlength="{{ $guideLimits['body'] }}" rows="5" @disabled(! $canReview) style="border-radius: 10px; border: 1px solid #D9D4CB; padding: 10px 12px; font-size: 14px; line-height: 1.6; color: #141413; background: #FFFFFF; box-sizing: border-box; width: 100%; resize: vertical; font-family: inherit">{{ $row['body'] ?? '' }}</textarea></label>
          </div>
        @endforeach
        @if ($canReview)
          <div style="display: flex; gap: 8px; align-items: center">
            <span style="flex: 1; font-size: 12px; color: #66625B; line-height: 1.5">섹션은 {{ $guideLimits['sections'] }}개까지예요. 더 추가하려면 저장한 뒤 다시 열면 빈 칸 2개가 새로 생겨요.</span>
            <button type="submit" style="height: 40px; padding: 0 20px; border-radius: 10px; font-size: 14px; font-weight: 700; border: 0; background: #141413; color: #FFFFFF">저장</button>
          </div>
        @endif
      </form>
    </section>

    {{-- 광고 운영 정책: 작가가 앱에서 심사를 요청할 때 동의하는 문서. 저장 시각이 새 버전이 된다 --}}
    @php
      $policyRows = old('policy', array_merge(
        array_map(fn ($s, $i) => $s + ['order' => $i + 1], $policy['sections'], array_keys($policy['sections'])),
        [['title' => '', 'body' => '', 'order' => count($policy['sections']) + 1], ['title' => '', 'body' => '', 'order' => count($policy['sections']) + 2]]
      ));
    @endphp
    <section style="background: #FFFFFF; border: 1px solid #E4E0D8; border-radius: 16px; padding: 20px; display: flex; flex-direction: column; gap: 14px">
      <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 10px">
        <div style="display: flex; flex-direction: column; gap: 4px">
          <h2 style="margin: 0; font-size: 17px; font-weight: 700">광고 운영 정책</h2>
          <span style="font-size: 13px; color: #66625B">작가가 광고 심사를 요청할 때 동의하는 정책이에요(앱 「광고 만들기 → 심사 요청」에서 열어 봐요). 저장하면 새 버전이 되고, 다음 심사 요청부터 새 정책에 동의해요. 동의한 버전은 광고마다 남아요.</span>
        </div>
        <span style="font-size: 12px; color: #66625B">{{ $policy['updated_at'] ? '현재 버전 ' . $policy['version'] : '기본안(개발용 초안) 사용 중 · 정식 운영 전 검토 필요' }}</span>
      </div>
      @error('policy')<div style="font-size: 13px; color: #B3261E">{{ $message }}</div>@enderror
      <form method="POST" action="{{ $policyAction }}" style="display: flex; flex-direction: column; gap: 10px">
        @csrf
        @foreach ($policyRows as $i => $row)
          <div style="padding: 14px; border-radius: 12px; background: #F7F5F1; display: flex; flex-direction: column; gap: 8px">
            <div style="display: flex; gap: 8px">
              <label style="flex: 0 0 72px; display: flex; flex-direction: column; gap: 6px"><span style="font-size: 12px; font-weight: 700; color: #3A3731">순서</span><input type="number" name="policy[{{ $i }}][order]" min="0" max="999" value="{{ $row['order'] ?? $i + 1 }}" @disabled(! $canReview) style="height: 40px; border-radius: 10px; border: 1px solid #D9D4CB; padding: 0 10px; font-size: 14px; color: #141413; background: #FFFFFF; box-sizing: border-box; width: 100%"></label>
              <label style="flex: 1; display: flex; flex-direction: column; gap: 6px"><span style="font-size: 12px; font-weight: 700; color: #3A3731">제목 <span style="font-weight: 400; color: #66625B">· {{ $guideLimits['title'] }}자까지</span></span><input name="policy[{{ $i }}][title]" maxlength="{{ $guideLimits['title'] }}" value="{{ $row['title'] ?? '' }}" placeholder="{{ ($row['title'] ?? '') === '' ? '새 조항 제목(비우면 추가하지 않아요)' : '' }}" @disabled(! $canReview) style="height: 40px; border-radius: 10px; border: 1px solid #D9D4CB; padding: 0 12px; font-size: 14px; font-weight: 700; color: #141413; background: #FFFFFF; box-sizing: border-box; width: 100%"></label>
            </div>
            <label style="display: flex; flex-direction: column; gap: 6px"><span style="font-size: 12px; font-weight: 700; color: #3A3731">본문 <span style="font-weight: 400; color: #66625B">· {{ number_format($guideLimits['body']) }}자까지</span></span><textarea name="policy[{{ $i }}][body]" maxlength="{{ $guideLimits['body'] }}" rows="5" @disabled(! $canReview) style="border-radius: 10px; border: 1px solid #D9D4CB; padding: 10px 12px; font-size: 14px; line-height: 1.6; color: #141413; background: #FFFFFF; box-sizing: border-box; width: 100%; resize: vertical; font-family: inherit">{{ $row['body'] ?? '' }}</textarea></label>
          </div>
        @endforeach
        @if ($canReview)
          <div style="display: flex; gap: 8px; align-items: center">
            <span style="flex: 1; font-size: 12px; color: #66625B; line-height: 1.5">조항은 {{ $guideLimits['sections'] }}개까지예요. 더 추가하려면 저장한 뒤 다시 열면 빈 칸 2개가 새로 생겨요.</span>
            <button type="submit" data-confirm="광고 운영 정책을 새 버전으로 저장할까요? 다음 심사 요청부터 작가가 새 정책에 동의해요." style="height: 40px; padding: 0 20px; border-radius: 10px; font-size: 14px; font-weight: 700; border: 0; background: #141413; color: #FFFFFF">저장</button>
          </div>
        @endif
      </form>
    </section>

    {{-- 구글 광고(AdMob): 자체 광고가 없을 때 같은 자리를 채운다 --}}
    <section style="background: #FFFFFF; border: 1px solid #E4E0D8; border-radius: 16px; padding: 20px; display: flex; flex-direction: column; gap: 14px">
      <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 10px">
        <div style="display: flex; flex-direction: column; gap: 4px">
          <h2 style="margin: 0; font-size: 17px; font-weight: 700">구글 광고(AdMob)</h2>
          <span style="font-size: 13px; color: #66625B">자체 광고가 없을 때 같은 자리를 구글 네이티브 광고로 채워요. 저장하면 앱이 다음에 광고를 받을 때부터 적용돼요.</span>
        </div>
        <span style="{{ \App\Support\AdminUi::pill($admobOn ? 'green' : 'gray') }}">{{ $admobOn ? '켜짐' : '꺼짐' }}</span>
      </div>
      <form method="POST" action="{{ $admobAction }}" style="display: flex; flex-direction: column; gap: 14px">
        @csrf
        <label style="display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 700"><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $admobOn)) @disabled(! $canManage) style="width: 18px; height: 18px; margin: 0; accent-color: #141413">구글 광고 사용</label>
        @foreach ($slotOptions as $slot => $label)
          @php $s = $admob['slots'][$slot] ?? ['on' => false, 'android' => '', 'ios' => '', 'share' => 50]; @endphp
          <div style="padding: 14px; border-radius: 12px; background: #F7F5F1; display: flex; flex-direction: column; gap: 10px">
            <label style="display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 700"><input type="hidden" name="slots[{{ $slot }}][on]" value="0"><input type="checkbox" name="slots[{{ $slot }}][on]" value="1" @checked(old("slots.$slot.on", $s['on'])) @disabled(! $canManage) style="width: 18px; height: 18px; margin: 0; accent-color: #141413">{{ $label }}</label>
            <div style="display: flex; flex-wrap: wrap; gap: 10px">
              <label style="flex: 1 1 260px; display: flex; flex-direction: column; gap: 6px"><span style="font-size: 12px; font-weight: 700; color: #3A3731">Android 광고 단위 ID</span><input name="slots[{{ $slot }}][android]" value="{{ old("slots.$slot.android", $s['android']) }}" placeholder="ca-app-pub-0000000000000000/0000000000" @disabled(! $canManage) style="height: 40px; border-radius: 10px; border: 1px solid #D9D4CB; padding: 0 12px; font-size: 13px; font-family: ui-monospace, Menlo, monospace; color: #141413; background: #FFFFFF; box-sizing: border-box; width: 100%"></label>
              <label style="flex: 1 1 260px; display: flex; flex-direction: column; gap: 6px"><span style="font-size: 12px; font-weight: 700; color: #3A3731">iOS 광고 단위 ID</span><input name="slots[{{ $slot }}][ios]" value="{{ old("slots.$slot.ios", $s['ios']) }}" placeholder="ca-app-pub-0000000000000000/0000000000" @disabled(! $canManage) style="height: 40px; border-radius: 10px; border: 1px solid #D9D4CB; padding: 0 12px; font-size: 13px; font-family: ui-monospace, Menlo, monospace; color: #141413; background: #FFFFFF; box-sizing: border-box; width: 100%"></label>
              <label style="flex: 0 0 140px; display: flex; flex-direction: column; gap: 6px"><span style="font-size: 12px; font-weight: 700; color: #3A3731">구글 광고 비율(%)</span><input type="number" name="slots[{{ $slot }}][share]" value="{{ old("slots.$slot.share", $s['share']) }}" min="0" max="100" step="5" @disabled(! $canManage) style="height: 40px; border-radius: 10px; border: 1px solid #D9D4CB; padding: 0 12px; font-size: 13px; color: #141413; background: #FFFFFF; box-sizing: border-box; width: 100%"></label>
            </div>
            <span style="font-size: 12px; color: #66625B">광고 칸마다 이 비율만큼 구글 광고, 나머지는 자체 광고를 무작위로 보여 줘요. 자체 광고가 없으면 항상 구글 광고예요(0 = 자체 광고 우선, 100 = 구글 광고만).</span>
          </div>
        @endforeach
        <div style="display: flex; gap: 8px; align-items: center">
          <span style="flex: 1; font-size: 12px; color: #66625B; line-height: 1.5">광고 단위는 AdMob 사이트 「광고 단위 → 네이티브 고급형」에서 만들어요. 앱 ID(ca-app-pub-…~…)는 앱 설치 파일에 들어가서 여기서 바꿀 수 없어요. 노출 · 수익은 AdMob 사이트 「보고서」에서 봐요.</span>
          @if ($canManage)
            <button type="submit" style="height: 40px; padding: 0 20px; border-radius: 10px; font-size: 14px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; border: 0; background: #141413; color: #FFFFFF">저장</button>
          @endif
        </div>
      </form>
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
