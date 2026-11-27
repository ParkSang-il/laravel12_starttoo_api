@extends('admin.layouts.app')
@section('title', '광고 문의 · 계약')
@section('content')
@php
  $card = 'background: #FFFFFF; border: 1px solid #E4E0D8; border-radius: 16px';
  $th = 'text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap';
  $td = 'padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; vertical-align: top';
  $input = 'height: 40px; border-radius: 10px; border: 1px solid #D9D4CB; padding: 0 12px; font-size: 14px; color: #141413; background: #FFFFFF; box-sizing: border-box; width: 100%; font-family: inherit';
  $area = 'border-radius: 10px; border: 1px solid #D9D4CB; padding: 10px 12px; font-size: 14px; line-height: 1.6; color: #141413; background: #FFFFFF; box-sizing: border-box; width: 100%; resize: vertical; font-family: inherit';
  $label = 'font-size: 12px; font-weight: 700; color: #3A3731';
  $btn = 'height: 40px; padding: 0 16px; border-radius: 10px; font-size: 14px; font-weight: 700; border: 0; background: #141413; color: #FFFFFF; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; font-family: inherit';
  $ghost = 'height: 36px; padding: 0 14px; border-radius: 10px; font-size: 13px; font-weight: 700; border: 1px solid #D9D4CB; background: #FFFFFF; color: #141413; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; font-family: inherit';
  $muted = 'font-size: 12px; color: #66625B; line-height: 1.6';
  $dl = 'margin: 0; display: grid; grid-template-columns: auto 1fr; gap: 6px 16px; font-size: 13px';
@endphp

  <div style="flex: 999 1 560px; min-width: 0; padding: 28px clamp(16px, 3vw, 40px) 48px; box-sizing: border-box; display: flex; flex-direction: column; gap: 20px">
    <header style="display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 16px">
      <div style="display: flex; flex-direction: column; gap: 6px">
        <h1 style="margin: 0; font-size: 26px; font-weight: 900; letter-spacing: -0.5px">광고 문의 · 계약</h1>
        <p style="margin: 0; font-size: 14px; color: #66625B; line-height: 1.6">외부 광고주가 <a href="{{ route('advertiser.inquiry') }}" target="_blank" rel="noopener" style="color: #141413">광고 문의 페이지</a>에 남긴 문의에 CPM 견적을 보내고, 입금을 확인하면 광고를 만들어요. 광고주는 견적서 · 성과 보고 링크와 <a href="{{ route('advertiser.login') }}" target="_blank" rel="noopener" style="color: #141413">광고주 계정</a>으로 진행 상황을 봐요.</p>
      </div>
      @if ($canManage)
        <a href="{{ route('admin.ad-sales', ['tab' => '계약', 'sel' => 'new']) }}" style="{{ $btn }}">문의 없이 새 견적</a>
      @endif
    </header>

    <div role="tablist" style="display: flex; flex-wrap: wrap; gap: 6px">
      @foreach ($tabs as $t)<a href="{{ $t['href'] }}" class="btn-a" role="tab" style="{{ $t['style'] }}">{{ $t['label'] }}</a>@endforeach
    </div>

    @if ($tab === '단가표')
      {{-- ── 단가표 ── --}}
      <form method="POST" action="{{ route('admin.ad-sales.rates') }}" style="{{ $card }}; padding: 20px 22px; display: flex; flex-direction: column; gap: 16px; max-width: 920px">
        @csrf
        <div style="display: flex; flex-direction: column; gap: 4px">
          <h2 style="margin: 0; font-size: 17px; font-weight: 700">외부 광고 단가표 (CPM)</h2>
          <span style="{{ $muted }}">노출 1,000회당 단가(부가세 별도)와 최소 보장 노출이에요. 상품 소개 · 문의 화면과 새 견적의 기본값에 쓰여요. 이미 보낸 견적 금액은 바뀌지 않아요. 작가 광고 단가(광고 · 프리미엄)와는 따로예요.</span>
        </div>
        @error('rates')<div style="font-size: 13px; color: #B3261E">{{ $message }}</div>@enderror
        <div style="overflow-x: auto">
          <table style="width: 100%; min-width: 640px; border-collapse: collapse">
            <thead><tr><th style="{{ $th }}">자리</th><th style="{{ $th }}">문의 받기</th><th style="{{ $th }}">CPM 단가(원)</th><th style="{{ $th }}">최소 보장 노출(회)</th><th style="{{ $th }}">최근 30일 하루 평균</th></tr></thead>
            <tbody>
              @foreach ($rates as $slot => $r)
                <tr>
                  <td style="{{ $td }}"><b>{{ $r['name'] }}</b><br><span style="{{ $muted }}">{{ $slot }} · {{ $r['desc'] }}</span></td>
                  <td style="{{ $td }}"><input type="hidden" name="rates[{{ $slot }}][on]" value="0"><label style="display: inline-flex; gap: 6px; align-items: center; font-size: 13px"><input type="checkbox" name="rates[{{ $slot }}][on]" value="1" @checked(old("rates.$slot.on", $r['on'])) @disabled(! $canManage)> 받기</label></td>
                  <td style="{{ $td }}"><input type="number" name="rates[{{ $slot }}][cpm]" min="0" step="100" required value="{{ old("rates.$slot.cpm", $r['cpm']) }}" style="{{ $input }}; max-width: 140px" @disabled(! $canManage)></td>
                  <td style="{{ $td }}"><input type="number" name="rates[{{ $slot }}][min]" min="1000" step="1000" required value="{{ old("rates.$slot.min", $r['min']) }}" style="{{ $input }}; max-width: 160px" @disabled(! $canManage)><br><span style="{{ $muted }}">최소 금액 {{ \App\Support\AdminUi::won(intdiv($r['min'] * $r['cpm'], 1000)) }}</span></td>
                  <td style="{{ $td }}; white-space: nowrap">{{ ($slotDaily[$slot] ?? null) ? number_format($slotDaily[$slot]) . '회' : '기록 없음' }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
        @if ($canManage)<div><button type="submit" style="{{ $btn }}">단가표 저장</button></div>
        @else<span style="{{ $muted }}">단가표는 운영 담당 이상만 바꿀 수 있어요.</span>@endif
      </form>

    @elseif ($tab === '광고주')
      {{-- ── 광고주 ── --}}
      <section style="{{ $card }}; overflow: hidden">
        <form method="GET" action="{{ route('admin.ad-sales') }}" style="padding: 14px 16px; display: flex; gap: 8px; border-bottom: 1px solid #E4E0D8">
          <input type="hidden" name="tab" value="광고주">
          <input name="q" value="{{ $search }}" placeholder="회사명 · 이메일" style="{{ $input }}; max-width: 320px">
          <button type="submit" style="{{ $ghost }}; height: 40px">찾기</button>
        </form>
        <div style="overflow-x: auto">
          <table style="width: 100%; min-width: 820px; border-collapse: collapse">
            <thead><tr><th style="{{ $th }}">광고주</th><th style="{{ $th }}">담당자</th><th style="{{ $th }}">계정</th><th style="{{ $th }}">마지막 로그인</th><th style="{{ $th }}">계약</th><th style="{{ $th }}">입금 합계</th><th style="{{ $th }}"></th></tr></thead>
            <tbody>
              @forelse ($advertisers as $a)
                <tr>
                  <td style="{{ $td }}"><b>{{ $a['company'] }}</b><br><span style="{{ $muted }}">{{ $a['email'] }}</span></td>
                  <td style="{{ $td }}">{{ $a['contact'] }}</td>
                  <td style="{{ $td }}"><span style="{{ $a['accountPill'] }}">{{ $a['account'] }}</span></td>
                  <td style="{{ $td }}; white-space: nowrap; color: #66625B">{{ $a['login'] }}</td>
                  <td style="{{ $td }}">{{ $a['contracts'] }}건</td>
                  <td style="{{ $td }}; white-space: nowrap">{{ $a['paid'] }}</td>
                  <td style="{{ $td }}">@if ($canManage)<form method="POST" action="{{ $a['linkAction'] }}">@csrf<button type="submit" data-confirm="{{ $a['email'] }} 로 비밀번호 설정 링크를 보낼까요?" style="{{ $ghost }}">비밀번호 설정 링크 보내기</button></form>@endif</td>
                </tr>
              @empty
                <tr><td colspan="7" style="padding: 32px 16px; font-size: 14px; color: #66625B; text-align: center">광고주가 없어요. 견적을 보내면 견적 이메일로 광고주가 생겨요.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
        <div style="padding: 14px 20px; display: flex; justify-content: flex-end">@include('admin.partials.pages')</div>
      </section>

    @else
      {{-- ── 문의 · 계약 목록 + 상세 ── --}}
      <section style="display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-start">
        <div style="{{ $card }}; flex: 3 1 560px; min-width: 0; overflow: hidden">
          <div style="padding: 14px 16px; display: flex; flex-wrap: wrap; gap: 6px; border-bottom: 1px solid #E4E0D8">
            @foreach ($filters as $f)<a href="{{ $f['href'] }}" class="btn-a" style="{{ $f['style'] }}">{{ $f['label'] }}</a>@endforeach
          </div>
          <div style="overflow-x: auto">
            <table style="width: 100%; min-width: 720px; border-collapse: collapse">
              <thead><tr><th style="{{ $th }}">{{ $tab === '문의' ? '접수 번호' : '견적' }}</th><th style="{{ $th }}">광고주</th><th style="{{ $th }}">자리</th><th style="{{ $th }}">{{ $tab === '문의' ? '예산' : '합계' }}</th><th style="{{ $th }}">{{ $tab === '문의' ? '접수' : '기간' }}</th><th style="{{ $th }}">상태</th></tr></thead>
              <tbody>
                @forelse ($list as $r)
                  <tr style="{{ $r['rowStyle'] }}">
                    <td style="{{ $td }}; white-space: nowrap; font-family: ui-monospace, Menlo, monospace; font-size: 13px"><a href="{{ $r['href'] }}" style="color: #141413; font-weight: 700">{{ $r['code'] }}</a></td>
                    <td style="{{ $td }}; max-width: 260px"><a href="{{ $r['href'] }}" style="color: #141413; font-weight: 700">{{ $r['company'] }}</a><br><span style="{{ $muted }}; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{ $r['who'] }}</span></td>
                    <td style="{{ $td }}">{{ $r['slots'] }}</td>
                    <td style="{{ $td }}; white-space: nowrap">{{ $r['budget'] }}</td>
                    <td style="{{ $td }}; white-space: nowrap; color: #66625B">{{ $r['when'] }}</td>
                    <td style="{{ $td }}; white-space: nowrap"><span style="{{ $r['pill'] }}">{{ $r['state'] }}</span></td>
                  </tr>
                @empty
                  <tr><td colspan="6" style="padding: 32px 16px; font-size: 14px; color: #66625B; text-align: center">{{ $tab === '문의' ? '문의가 없어요.' : '계약이 없어요.' }}</td></tr>
                @endforelse
              </tbody>
            </table>
          </div>
          <div style="padding: 14px 20px; display: flex; justify-content: flex-end">@include('admin.partials.pages')</div>
        </div>

        @if ($inquiry)
          {{-- 문의 상세 --}}
          @php $ist = \App\Services\Starttoo\AdSales::INQUIRY_STATUS[$inquiry->status] ?? [$inquiry->status, 'gray']; @endphp
          <div style="{{ $card }}; flex: 2 1 420px; min-width: 0; overflow: hidden; display: flex; flex-direction: column">
            <div style="padding: 18px 20px; border-bottom: 1px solid #E4E0D8; display: flex; justify-content: space-between; align-items: flex-start; gap: 8px">
              <div style="display: flex; flex-direction: column; gap: 4px; min-width: 0">
                <span style="font-size: 13px; color: #66625B"><span style="font-family: ui-monospace, Menlo, monospace">{{ $inquiry->code }}</span> · {{ \App\Support\AdminUi::date($inquiry->created_at, 'Y.m.d H:i') }}</span>
                <h2 style="margin: 0; font-size: 17px; font-weight: 700; overflow-wrap: anywhere">{{ $inquiry->company }}</h2>
              </div>
              <span style="{{ \App\Support\AdminUi::pill($ist[1]) }}">{{ $ist[0] }}</span>
            </div>
            <div style="padding: 16px 20px; display: flex; flex-direction: column; gap: 14px">
              <dl style="{{ $dl }}">
                <dt style="color: #66625B">담당자</dt><dd style="margin: 0">{{ $inquiry->contact_name }} · <a href="mailto:{{ $inquiry->email }}" style="color: #141413">{{ $inquiry->email }}</a> · {{ $inquiry->phone }}</dd>
                <dt style="color: #66625B">업종</dt><dd style="margin: 0">{{ $inquiry->industry ?? '-' }}</dd>
                <dt style="color: #66625B">원하는 자리</dt><dd style="margin: 0">{{ implode(' · ', app(\App\Services\Starttoo\AdSales::class)->slotNames($inquiry->slots)) }}</dd>
                <dt style="color: #66625B">시작 · 기간</dt><dd style="margin: 0">{{ $inquiry->wish_start ?? '미정' }} · {{ $inquiry->duration ?? '-' }}</dd>
                <dt style="color: #66625B">예산</dt><dd style="margin: 0">{{ $inquiry->budget ?? '-' }} (부가세 별도)</dd>
                <dt style="color: #66625B">연결 주소</dt><dd style="margin: 0; overflow-wrap: anywhere">@if ($inquiry->landing_url)<a href="{{ $inquiry->landing_url }}" target="_blank" rel="noopener noreferrer" style="color: #141413">{{ $inquiry->landing_url }}</a>@else-@endif</dd>
                <dt style="color: #66625B">소재</dt><dd style="margin: 0; display: flex; flex-direction: column; gap: 2px">
                  @forelse ($inquiry->files as $n => $f)
                    <a href="{{ route('admin.ad-sales.file', [$inquiry->id, $n]) }}" style="color: #141413">{{ $f['name'] }} <span style="color: #66625B">· {{ number_format($f['size'] / 1048576, 1) }}MB</span></a>
                  @empty - @endforelse
                </dd>
                @if ($inquiry->reject_reason)<dt style="color: #66625B">반려 사유</dt><dd style="margin: 0">{{ $inquiry->reject_reason }}</dd>@endif
              </dl>
              @if ($inquiry->memo)<div style="padding: 12px 14px; border-radius: 10px; background: #F4F2EE; font-size: 13px; line-height: 1.6; white-space: pre-line; overflow-wrap: anywhere">{{ $inquiry->memo }}</div>@endif
              @if ($inquiryContracts)
                <div style="display: flex; flex-direction: column; gap: 6px">
                  <span style="{{ $label }}">이 문의로 만든 견적</span>
                  @foreach ($inquiryContracts as $ic)
                    <a href="{{ route('admin.ad-sales', ['tab' => '계약', 'f' => '전체', 'sel' => 'c' . $ic->id]) }}" style="font-size: 13px; color: #141413">{{ $ic->quote_no }} · {{ \App\Support\AdminUi::won($ic->total) }} · {{ \App\Services\Starttoo\AdSales::STATUS[$ic->status][0] }}</a>
                  @endforeach
                </div>
              @endif
            </div>
            @if ($canManage && in_array($inquiry->status, ['new', 'quoted'], true))
              <div style="padding: 16px 20px; border-top: 1px solid #E4E0D8; display: flex; flex-direction: column; gap: 12px">
                <h3 style="margin: 0; font-size: 15px; font-weight: 700">{{ $inquiry->status === 'quoted' ? '새 견적 보내기' : '견적 보내기' }}</h3>
                @include('admin.partials.ad-quote-form', ['action' => route('admin.ad-sales.quote', $inquiry->id), 'd' => $quoteDefaults])
              </div>
              <div style="padding: 14px 20px; border-top: 1px solid #E4E0D8; display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-start">
                <details style="flex: 1 1 260px">
                  <summary style="{{ $ghost }}; list-style: none">반려하기</summary>
                  <form method="POST" action="{{ route('admin.ad-sales.reject', $inquiry->id) }}" style="margin-top: 10px; display: flex; flex-direction: column; gap: 8px">@csrf
                    @error('reason')<div style="font-size: 13px; color: #B3261E">{{ $message }}</div>@enderror
                    <textarea name="reason" required maxlength="500" rows="3" placeholder="광고주에게 메일로 가요. 예: 시술 효과를 보장하는 표현이 있어 진행하기 어려워요." style="{{ $area }}">{{ old('reason') }}</textarea>
                    <div><button type="submit" data-confirm="반려하고 사유를 광고주에게 메일로 보낼까요?" style="{{ $btn }}; background: #A3301A">반려하고 메일 보내기</button></div>
                  </form>
                </details>
                <form method="POST" action="{{ route('admin.ad-sales.close', $inquiry->id) }}">@csrf<button type="submit" data-confirm="메일 없이 문의를 닫을까요? (스팸 · 중복 문의)" style="{{ $ghost }}">메일 없이 닫기</button></form>
              </div>
            @endif
          </div>

        @elseif ($newQuote)
          <div style="{{ $card }}; flex: 2 1 420px; min-width: 0; padding: 18px 20px; display: flex; flex-direction: column; gap: 12px">
            <h2 style="margin: 0; font-size: 17px; font-weight: 700">문의 없이 새 견적</h2>
            <span style="{{ $muted }}">메일 · 전화로 받은 문의에 바로 견적을 보낼 때 써요. 받는 분 이메일로 광고주가 생겨요.</span>
            @if ($canManage)
              @include('admin.partials.ad-quote-form', ['action' => route('admin.ad-sales.quote.new'), 'd' => $quoteDefaults])
            @else<span style="{{ $muted }}">견적은 운영 담당 이상만 보낼 수 있어요.</span>@endif
          </div>

        @elseif ($contract)
          {{-- 계약 상세 --}}
          @php
            $c = $contract;
            $cst = $c->expired ? ['기한 지남', 'gray'] : \App\Services\Starttoo\AdSales::STATUS[$c->status];
            $paidStage = in_array($c->status, ['contracted', 'live', 'ended'], true);
          @endphp
          <div style="{{ $card }}; flex: 2 1 420px; min-width: 0; overflow: hidden; display: flex; flex-direction: column">
            <div style="padding: 18px 20px; border-bottom: 1px solid #E4E0D8; display: flex; justify-content: space-between; align-items: flex-start; gap: 8px">
              <div style="display: flex; flex-direction: column; gap: 4px; min-width: 0">
                <span style="font-size: 13px; color: #66625B"><span style="font-family: ui-monospace, Menlo, monospace">{{ $c->quote_no }}</span> · {{ $c->created_at->format('Y.m.d') }} 발행@if ($c->manager) · 담당 {{ $c->manager }}@endif</span>
                <h2 style="margin: 0; font-size: 17px; font-weight: 700; overflow-wrap: anywhere">{{ $c->title }}</h2>
                <span style="font-size: 13px">{{ $c->company }} · {{ $c->contact_name }} · <a href="mailto:{{ $c->email }}" style="color: #141413">{{ $c->email }}</a></span>
              </div>
              <span style="{{ \App\Support\AdminUi::pill($cst[1]) }}">{{ $cst[0] }}</span>
            </div>
            <div style="padding: 16px 20px; display: flex; flex-direction: column; gap: 14px">
              @error('contract')<div role="alert" style="font-size: 13px; color: #B3261E">{{ $message }}</div>@enderror

              <div style="display: flex; flex-direction: column; gap: 6px">
                @foreach ($contractLines as $i => $l)
                  <div style="display: flex; justify-content: space-between; gap: 10px; font-size: 13px"><span><b>{{ $l['name'] }}</b> · {{ $l['qty'] }} · {{ $l['unit'] }}</span><span style="white-space: nowrap">{{ \App\Support\AdminUi::won($l['amount']) }}</span></div>
                @endforeach
                @if ($c->discount)<div style="display: flex; justify-content: space-between; font-size: 13px; color: #1D6B3A"><span>{{ $c->discount_label ?? '할인' }}</span><span>−{{ \App\Support\AdminUi::won($c->discount) }}</span></div>@endif
                <div style="display: flex; justify-content: space-between; font-size: 14px; font-weight: 700; padding-top: 6px; border-top: 1px solid #F0EDE7"><span>합계 (부가세 포함)</span><span>{{ \App\Support\AdminUi::won($c->total) }}</span></div>
              </div>
              <dl style="{{ $dl }}">
                <dt style="color: #66625B">노출 기간</dt><dd style="margin: 0">{{ $c->starts_at->format('Y.m.d') }} ~ {{ $c->ends_at->format('Y.m.d') }} (보장 노출을 채우면 먼저 끝)</dd>
                <dt style="color: #66625B">유효 기한</dt><dd style="margin: 0">{{ $c->valid_until->format('Y.m.d') }}</dd>
                <dt style="color: #66625B">연결 주소</dt><dd style="margin: 0; overflow-wrap: anywhere">{{ $c->landing_url ?? '-' }}</dd>
                <dt style="color: #66625B">진행</dt><dd style="margin: 0">수락 {{ $c->accepted_at?->format('m.d H:i') ?? '-' }} · 입금 알림 {{ $c->payment_reported_at?->format('m.d H:i') ?? '-' }} · 입금 확인 {{ $c->paid_at?->format('m.d H:i') ?? '-' }}</dd>
                <dt style="color: #66625B">광고주 계정</dt><dd style="margin: 0">{{ $owner ? ($owner->password ? '계정 있음 · 마지막 로그인 ' . ($owner->last_login_at ? \App\Support\AdminUi::ago($owner->last_login_at) : '-') : '비밀번호 없음(견적서 화면에서 만들 수 있어요)') : '-' }}</dd>
                @if ($c->biz_no)
                  <dt style="color: #66625B">세금계산서</dt><dd style="margin: 0">{{ $c->biz_no }} · {{ $c->biz_name }} · {{ $c->tax_email }}@if ($c->biz_cert_path) · <a href="{{ route('admin.ad-sales.cert', $c->id) }}" style="color: #141413">사업자등록증</a>@endif</dd>
                @endif
              </dl>

              @if ($c->change_request && $c->status === 'quote_sent')
                <div style="padding: 12px 14px; border-radius: 10px; background: #FFF1D6; color: #5C3B00; font-size: 13px; line-height: 1.6; white-space: pre-line; overflow-wrap: anywhere"><b>수정 요청 · {{ $c->change_requested_at?->format('m.d H:i') }}</b>
{{ $c->change_request }}
<span style="font-size: 12px">→ 이 견적을 취소하고 문의에서 새 견적을 보내주세요.</span></div>
              @endif

              {{-- 링크 --}}
              <div style="display: flex; flex-direction: column; gap: 8px">
                <label style="display: flex; flex-direction: column; gap: 4px"><span style="{{ $label }}">견적서 링크 <span style="font-weight: 400; color: #66625B">· 로그인 없이 열려요</span></span>
                  <input readonly value="{{ $quoteUrl }}" onclick="this.select()" style="{{ $input }}; font-family: ui-monospace, Menlo, monospace; font-size: 12px"></label>
                @if ($reportUrl)
                  <label style="display: flex; flex-direction: column; gap: 4px"><span style="{{ $label }}">성과 보고 링크 @if ($c->report_mailed_at)<span style="font-weight: 400; color: #66625B">· {{ $c->report_mailed_at->format('m.d H:i') }} 메일 보냄</span>@endif</span>
                    <input readonly value="{{ $reportUrl }}" onclick="this.select()" style="{{ $input }}; font-family: ui-monospace, Menlo, monospace; font-size: 12px"></label>
                @endif
              </div>

              @if ($canManage)
                <div style="display: flex; flex-wrap: wrap; gap: 6px">
                  @if ($c->status === 'quote_sent')
                    <form method="POST" action="{{ route('admin.ad-sales.resend', $c->id) }}">@csrf<button type="submit" style="{{ $ghost }}">견적 메일 다시 보내기</button></form>
                  @endif
                  @if (in_array($c->status, ['quote_sent', 'awaiting_payment', 'payment_reported'], true))
                    <form method="POST" action="{{ route('admin.ad-sales.paid', $c->id) }}">@csrf<button type="submit" data-confirm="{{ \App\Support\AdminUi::won($c->total) }} 입금을 통장에서 확인했나요? 계약 완료 메일이 광고주에게 가요." style="{{ $btn }}; height: 36px; font-size: 13px; {{ $c->status === 'payment_reported' ? 'background: #FF5A36; color: #141413' : '' }}">입금 확인</button></form>
                  @endif
                  @if ($paidStage)
                    <form method="POST" action="{{ route('admin.ad-sales.tax', $c->id) }}">@csrf<input type="hidden" name="issued" value="{{ $c->tax_invoice_issued_at ? 0 : 1 }}"><button type="submit" style="{{ $ghost }}">{{ $c->tax_invoice_issued_at ? '세금계산서 발행 ' . $c->tax_invoice_issued_at->format('m.d') . ' · 표시 지우기' : '세금계산서 발행했어요' }}</button></form>
                    <form method="POST" action="{{ route('admin.ad-sales.report-mail', $c->id) }}">@csrf<button type="submit" style="{{ $ghost }}">성과 보고 메일 보내기</button></form>
                  @endif
                  <form method="POST" action="{{ route('admin.ad-sales.rotate', [$c->id, 'quote']) }}">@csrf<button type="submit" data-confirm="견적서 링크를 새로 만들까요? 이전 링크는 바로 막혀요." style="{{ $ghost }}">견적서 링크 다시 발급</button></form>
                  @if ($reportUrl)
                    <form method="POST" action="{{ route('admin.ad-sales.rotate', [$c->id, 'report']) }}">@csrf<button type="submit" data-confirm="성과 보고 링크를 새로 만들까요? 이전 링크는 바로 막혀요." style="{{ $ghost }}">성과 보고 링크 다시 발급</button></form>
                  @endif
                </div>
              @endif

              {{-- 광고 만들기 · 연결된 광고 --}}
              @if ($paidStage || $contractAds)
                <div style="display: flex; flex-direction: column; gap: 10px; padding-top: 12px; border-top: 1px solid #F0EDE7">
                  <h3 style="margin: 0; font-size: 15px; font-weight: 700">앱 광고</h3>
                  @forelse ($contractAds as $a)
                    <div style="display: flex; flex-direction: column; gap: 4px; font-size: 13px">
                      <span><a href="{{ $a['href'] }}" style="color: #141413; font-weight: 700">#{{ $a['id'] }} {{ $a['title'] }}</a> · {{ $a['slot'] }} @unless ($a['active'])<span style="{{ \App\Support\AdminUi::pill('gray') }}">꺼짐</span>@endunless</span>
                      <span style="height: 6px; border-radius: 3px; background: #ECEAE5; overflow: hidden; display: block"><span style="display: block; height: 100%; width: {{ $a['cap'] ? min(100, floor($a['imp'] / $a['cap'] * 100)) : 0 }}%; background: #141413"></span></span>
                      <span style="{{ $muted }}">노출 {{ number_format($a['imp']) }} / {{ number_format($a['cap']) }}회 · 클릭 {{ number_format($a['clicks']) }}</span>
                    </div>
                  @empty
                    <span style="{{ $muted }}">아직 만든 광고가 없어요. 아래에서 견적 줄마다 광고를 만들어주세요. 광고가 있어야 시작일에 노출 · 성과 보고 메일이 시작돼요.</span>
                  @endforelse
                  @if ($canManage && in_array($c->status, ['contracted', 'live'], true))
                    @foreach ($c->lines as $i => $l)
                      @if ($lineLeft[$i] > 0)
                        <details @if ((string) old('line') === (string) $i) open @endif>
                          <summary style="{{ $ghost }}; list-style: none">{{ $contractLines[$i]['name'] }} 광고 만들기 · 남은 보장 {{ number_format($lineLeft[$i]) }}회</summary>
                          <form method="POST" action="{{ route('admin.ad-sales.ad', $c->id) }}" enctype="multipart/form-data" style="margin-top: 10px; display: flex; flex-direction: column; gap: 8px">@csrf
                            <input type="hidden" name="line" value="{{ $i }}">
                            <label style="display: flex; flex-direction: column; gap: 4px"><span style="{{ $label }}">광고 문구 <span style="font-weight: 400; color: #66625B">· 80자까지, 앱 카드에 그대로 보여요</span></span><input name="title" required maxlength="80" value="{{ old('title') }}" style="{{ $input }}"></label>
                            <label style="display: flex; flex-direction: column; gap: 4px"><span style="{{ $label }}">사진 <span style="font-weight: 400; color: #66625B">· {{ \App\Services\Starttoo\AdSales::SLOTS[$l['slot']]['spec'] ?? '' }}</span></span><input type="file" name="image" accept="image/jpeg,image/png,image/webp" style="font-size: 13px"></label>
                            <label style="display: flex; flex-direction: column; gap: 4px"><span style="{{ $label }}">누르면 열 주소 <span style="font-weight: 400; color: #66625B">· 비우면 계약 연결 주소</span></span><input name="link" maxlength="200" placeholder="{{ $c->landing_url ?? 'https://' }}" value="{{ old('link') }}" style="{{ $input }}"></label>
                            <label style="display: flex; flex-direction: column; gap: 4px"><span style="{{ $label }}">이 광고 보장 노출 <span style="font-weight: 400; color: #66625B">· 소재를 나눌 때만 줄여 적어요</span></span><input type="number" name="cap" min="1000" step="1000" max="{{ $lineLeft[$i] }}" placeholder="{{ $lineLeft[$i] }}" value="{{ old('cap') }}" style="{{ $input }}; max-width: 200px"></label>
                            <span style="{{ $muted }}">노출 기간은 계약 기간({{ $c->starts_at->format('m.d') }} ~ {{ $c->ends_at->format('m.d') }})이고, 보장 노출을 채우면 그만 나가요. 문구 · 사진은 「광고 · 프리미엄」에서 고칠 수 있어요.</span>
                            <div><button type="submit" style="{{ $btn }}">광고 만들기</button></div>
                          </form>
                        </details>
                      @endif
                    @endforeach
                  @endif
                </div>
              @endif

              @if ($canManage)
                <form method="POST" action="{{ route('admin.ad-sales.memo', $c->id) }}" style="display: flex; flex-direction: column; gap: 6px; padding-top: 12px; border-top: 1px solid #F0EDE7">@csrf
                  <span style="{{ $label }}">운영 메모 <span style="font-weight: 400; color: #66625B">· 광고주에게 안 보여요</span></span>
                  <textarea name="memo" rows="2" maxlength="2000" style="{{ $area }}">{{ old('memo', $c->memo) }}</textarea>
                  <div><button type="submit" style="{{ $ghost }}">메모 저장</button></div>
                </form>
                <div style="display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-start">
                  @if (in_array($c->status, ['contracted', 'live'], true))
                    <form method="POST" action="{{ route('admin.ad-sales.end', $c->id) }}">@csrf<button type="submit" data-confirm="지금 종료할까요? 연결된 광고를 끄고 최종 보고 메일을 보내요." style="{{ $ghost }}">지금 종료</button></form>
                  @endif
                  @unless (in_array($c->status, ['ended', 'cancelled'], true))
                    <details style="flex: 1 1 240px">
                      <summary style="{{ $ghost }}; list-style: none; color: #A3301A">계약 취소</summary>
                      <form method="POST" action="{{ route('admin.ad-sales.cancel', $c->id) }}" style="margin-top: 8px; display: flex; flex-direction: column; gap: 6px">@csrf
                        <textarea name="reason" required maxlength="300" rows="2" placeholder="취소 사유(운영 메모에 남아요 · 광고주에게 메일은 안 가요)" style="{{ $area }}"></textarea>
                        <div><button type="submit" data-confirm="이 견적 · 계약을 취소할까요? 견적서 링크에 '취소된 견적'으로 보이고, 연결된 광고는 꺼져요." style="{{ $btn }}; background: #A3301A">취소하기</button></div>
                      </form>
                    </details>
                  @endunless
                </div>
              @endif
            </div>
          </div>
        @endif
      </section>
    @endif
  </div>
@endsection
