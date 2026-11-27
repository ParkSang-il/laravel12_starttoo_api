@extends('advertiser.layouts.web')
@section('title', '견적서 '.$c->quote_no)
@section('noindex', '1')
@php
  $status = $c->status === 'cancelled' ? ['취소된 견적', 'gray'] : ($expired ? ['유효 기한 지남', 'gray'] : [['견적 도착', 'amber'], ['입금 대기', 'blue'], ['입금 확인 중', 'blue'], ['계약 완료', 'green']][$stage]);
  $reached = [1, 2, 3, 4][$stage];
@endphp
@section('top-right')
  <span class="adv-pill {{ $status[1] }}">{{ $status[0] }}</span>
@endsection

@section('content')
<main class="adv-wrap" style="padding-top: 40px; display: flex; flex-wrap: wrap; gap: 28px; align-items: flex-start">
  <article aria-label="견적서" class="adv-card" style="flex: 2 1 640px; min-width: 0; padding: clamp(20px, 4vw, 48px); display: flex; flex-direction: column; gap: 28px">
    <div style="display: flex; flex-wrap: wrap; justify-content: space-between; gap: 16px; align-items: flex-start">
      <div style="display: flex; flex-direction: column; gap: 6px"><span style="font-size: 13px; color: #66625B">견적서 · {{ $c->quote_no }}</span><h1 style="margin: 0; font-size: 30px; font-weight: 900; letter-spacing: -0.5px">{{ $c->title }}</h1></div>
      <dl style="margin: 0; display: grid; grid-template-columns: auto auto; gap: 4px 16px; font-size: 13px"><dt style="color: #66625B">발행일</dt><dd style="margin: 0">{{ $c->created_at->format('Y.m.d') }}</dd><dt style="color: #66625B">유효 기한</dt><dd style="margin: 0; font-weight: 700">{{ $c->valid_until->format('Y.m.d') }} 까지</dd></dl>
    </div>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(240px, 100%), 1fr)); gap: 12px">
      <div style="padding: 16px 18px; border-radius: 12px; background: #F4F2EE; display: flex; flex-direction: column; gap: 4px; font-size: 14px"><span style="font-size: 12px; color: #66625B">받는 분</span><b>{{ $c->company }}</b><span>{{ $c->contact_name }} 님 · {{ $c->email }}</span></div>
      <div style="padding: 16px 18px; border-radius: 12px; background: #F4F2EE; display: flex; flex-direction: column; gap: 4px; font-size: 14px"><span style="font-size: 12px; color: #66625B">보내는 곳</span><b>{{ $company['name'] }}</b><span>사업자등록번호 {{ $company['biz_no'] }}@if ($c->manager) · 담당 {{ $c->manager }}@endif</span></div>
    </div>

    <div style="overflow-x: auto">
      <table style="width: 100%; min-width: 620px; border-collapse: collapse">
        <thead><tr><th class="adv-th">광고 자리</th><th class="adv-th">기간 · 수량</th><th class="adv-th">단가 기준</th><th class="adv-th" style="text-align: right">금액</th></tr></thead>
        <tbody>
          @foreach ($lines as $l)
            <tr>
              <td class="adv-td"><span style="display: flex; gap: 12px; align-items: flex-start"><span aria-hidden="true" style="position: relative; width: 44px; height: 56px; border-radius: 6px; flex-shrink: 0; background: #F4F2EE; border: 1px solid #E4E0D8"><span style="position: absolute; left: 6px; right: 6px; border-radius: 2px; top: {{ $l['top'] }}px; height: {{ $l['h'] }}px; background: #FF5A36"></span></span><span style="display: flex; flex-direction: column; gap: 3px"><b>{{ $l['name'] }}</b><span style="font-size: 12px; color: #66625B">{{ $l['desc'] }}</span></span></span></td>
              <td class="adv-td">{{ $l['qty'] }}<br><span style="font-size: 12px; color: #66625B">{{ $l['period'] }}</span></td>
              <td class="adv-td">{{ $l['unit'] }}<br><span style="font-size: 12px; color: #66625B">{{ $l['unit_note'] }}</span></td>
              <td class="adv-td adv-num" style="text-align: right; font-weight: 700; white-space: nowrap">₩{{ number_format($l['amount']) }}</td>
            </tr>
          @endforeach
          @if ($c->discount > 0)
            <tr><td class="adv-td" colspan="3">{{ $c->discount_label ?? '할인' }}</td><td class="adv-td adv-num" style="text-align: right; color: #1D6B3A; white-space: nowrap">−₩{{ number_format($c->discount) }}</td></tr>
          @endif
        </tbody>
      </table>
    </div>
    <dl class="adv-num" style="margin: 0 0 0 auto; width: min(340px, 100%); display: grid; grid-template-columns: 1fr auto; gap: 8px 16px; font-size: 14px">
      <dt style="color: #66625B">공급가액</dt><dd style="margin: 0; text-align: right">₩{{ number_format($c->supply) }}</dd>
      <dt style="color: #66625B">부가세 10%</dt><dd style="margin: 0; text-align: right">₩{{ number_format($c->vat) }}</dd>
      <dt style="font-size: 16px; font-weight: 700; padding-top: 10px; border-top: 1px solid #E4E0D8">합계</dt><dd class="adv-display" style="margin: 0; text-align: right; font-size: 22px; padding-top: 8px; border-top: 1px solid #E4E0D8">₩{{ number_format($c->total) }}</dd>
    </dl>

    <section style="border-radius: 14px; border: 1px solid #E4E0D8; padding: 18px 20px; display: flex; flex-direction: column; gap: 10px">
      <h2 style="margin: 0; font-size: 16px; font-weight: 700">예상 성과</h2>
      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(160px, 100%), 1fr)); gap: 12px">
        @foreach ($expected as $e)<div><strong class="adv-display" style="font-size: 20px">{{ $e['value'] }}</strong><br><span style="font-size: 12px; color: #66625B">{{ $e['label'] }}</span></div>@endforeach
      </div>
      <span style="font-size: 12px; color: #77736B">예상 소진은 견적 때 최근 30일 같은 자리의 실제 하루 평균 노출로 계산했어요. 예상치라 실제와 다를 수 있고, 보장 노출만 약속해요. 보장 노출을 채우면 그날 광고가 끝나요.</span>
    </section>

    @if ($c->terms)
      <section style="display: flex; flex-direction: column; gap: 10px">
        <h2 style="margin: 0; font-size: 16px; font-weight: 700">조건</h2>
        <ul style="margin: 0; padding-left: 18px; font-size: 14px; color: #3A3731; line-height: 1.8">@foreach ($c->terms as $t)<li>{{ $t }}</li>@endforeach</ul>
      </section>
    @endif
  </article>

  <aside aria-label="다음 단계" class="adv-sticky adv-noprint" style="flex: 1 1 340px; min-width: 0; display: flex; flex-direction: column; gap: 14px">
    <div class="adv-card" style="padding: 22px; display: flex; flex-direction: column; gap: 18px">
      <ol aria-label="진행 단계" style="margin: 0; padding: 0; list-style: none; display: flex; gap: 6px">
        @foreach (['견적 확인', '수락', '입금', '계약 완료'] as $i => $l)
          <li @if ($i === $reached - 1) aria-current="step" @endif style="flex: 1; display: flex; flex-direction: column; gap: 6px"><span style="display: block; height: 4px; border-radius: 2px; background: {{ $i < $reached ? '#141413' : '#E4E0D8' }}"></span><span style="font-size: 12px; {{ $i < $reached ? 'color: #141413; font-weight: 700' : 'color: #8A867F' }}">{{ $l }}</span></li>
        @endforeach
      </ol>

      @if ($errors->hasAny(['quote', 'change_request', 'biz_no', 'biz_name', 'tax_email', 'biz_cert']))
        <div role="alert" style="border-radius: 10px; background: #FDE4DE; color: #6E2111; padding: 10px 14px; font-size: 13px">{{ $errors->first() }}</div>
      @endif

      @if ($c->status === 'cancelled')
        <div style="display: flex; flex-direction: column; gap: 10px">
          <h2 style="margin: 0; font-size: 18px; font-weight: 900">취소된 견적이에요</h2>
          <span style="font-size: 13px; color: #66625B; line-height: 1.6">궁금한 점은 담당자에게 문의해주세요.</span>
        </div>
      @elseif ($expired)
        <div style="display: flex; flex-direction: column; gap: 10px">
          <h2 style="margin: 0; font-size: 18px; font-weight: 900">유효 기한이 지났어요</h2>
          <span style="font-size: 13px; color: #66625B; line-height: 1.6">자리와 단가가 바뀌었을 수 있어요. 담당자에게 새 견적을 요청해주세요.</span>
          <a href="mailto:{{ config('advertiser.ops_email') }}?subject={{ rawurlencode('새 견적 요청 '.$c->quote_no) }}" class="adv-btn" style="width: 100%">새 견적 요청</a>
        </div>
      @elseif ($stage === 0)
        <div style="display: flex; flex-direction: column; gap: 12px">
          <h2 style="margin: 0; font-size: 18px; font-weight: 900">견적이 괜찮으신가요?</h2>
          <span style="font-size: 13px; color: #66625B; line-height: 1.6">수락하면 입금 계좌와 세금계산서 정보 입력 칸이 열려요.</span>
          @if ($c->change_requested_at)
            <span role="status" style="font-size: 13px; line-height: 1.6; padding: 10px 12px; border-radius: 10px; background: #FFF1D6; color: #7A4F00">{{ $c->change_requested_at->format('m.d H:i') }}에 수정 요청을 보냈어요. 새 견적을 메일로 보내드릴게요.</span>
          @endif
          <form method="POST" action="{{ route('advertiser.quote.accept', $c->quote_token) }}">@csrf<button type="submit" class="adv-btn is-accent" style="width: 100%">견적 수락하기</button></form>
          <details @if ($errors->has('change_request')) open @endif>
            <summary class="adv-btn is-ghost" style="width: 100%; list-style: none">수정 요청</summary>
            <form method="POST" action="{{ route('advertiser.quote.change', $c->quote_token) }}" style="display: flex; flex-direction: column; gap: 6px; margin-top: 12px">@csrf
              <label for="change_request" class="adv-label">어떤 부분을 바꿀까요?</label>
              <textarea id="change_request" name="change_request" rows="3" required maxlength="2000" placeholder="예: 보장 노출을 15만 회로, 커뮤니티 상단도 추가" class="adv-textarea" style="font-size: 14px">{{ old('change_request', $c->change_request) }}</textarea>
              <button type="submit" class="adv-btn is-ghost is-sm">담당자에게 보내기</button>
            </form>
          </details>
        </div>
      @elseif ($stage === 1)
        <form method="POST" action="{{ route('advertiser.quote.paid', $c->quote_token) }}" enctype="multipart/form-data" style="display: flex; flex-direction: column; gap: 14px">@csrf
          <h2 style="margin: 0; font-size: 18px; font-weight: 900">입금 · 세금계산서</h2>
          <div style="border-radius: 12px; background: #141413; color: #F2EFEA; padding: 16px 18px; display: flex; flex-direction: column; gap: 6px">
            <span style="font-size: 12px; color: #A19D96">입금 계좌</span>
            <span style="font-size: 16px; font-weight: 700">{{ $bank['name'] }} {{ $bank['account'] }}</span>
            <span style="font-size: 13px; color: #CFCAC1">예금주 {{ $bank['holder'] }} · <b style="color: #FFFFFF">₩{{ number_format($c->total) }}</b></span>
            <span style="font-size: 12px; color: #A19D96">입금자명은 회사명으로 해주세요</span>
          </div>
          <fieldset style="border: 0; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px">
            <legend style="font-size: 14px; font-weight: 700; margin-bottom: 8px">세금계산서 받을 정보</legend>
            <label style="display: flex; flex-direction: column; gap: 4px"><span style="font-size: 13px; color: #3A3731">사업자등록번호 <span style="color: #B93415" aria-hidden="true">*</span></span><input name="biz_no" required inputmode="numeric" placeholder="000-00-00000" value="{{ old('biz_no', $c->biz_no ?? $taxPrefill?->biz_no) }}" class="adv-input" style="height: 44px">@error('biz_no')<span class="adv-err">{{ $message }}</span>@enderror</label>
            <label style="display: flex; flex-direction: column; gap: 4px"><span style="font-size: 13px; color: #3A3731">상호 · 대표자 <span style="color: #B93415" aria-hidden="true">*</span></span><input name="biz_name" required value="{{ old('biz_name', $c->biz_name ?? $taxPrefill?->biz_name) }}" class="adv-input" style="height: 44px">@error('biz_name')<span class="adv-err">{{ $message }}</span>@enderror</label>
            <label style="display: flex; flex-direction: column; gap: 4px"><span style="font-size: 13px; color: #3A3731">받을 이메일 <span style="color: #B93415" aria-hidden="true">*</span></span><input name="tax_email" type="email" required value="{{ old('tax_email', $c->tax_email ?? $taxPrefill?->tax_email ?? $c->email) }}" class="adv-input" style="height: 44px">@error('tax_email')<span class="adv-err">{{ $message }}</span>@enderror</label>
            <label style="display: flex; flex-direction: column; gap: 4px"><span style="font-size: 13px; color: #3A3731">사업자등록증 (선택)</span><input type="file" name="biz_cert" accept="application/pdf,image/jpeg,image/png" style="font-size: 13px; min-height: 44px">@error('biz_cert')<span class="adv-err">{{ $message }}</span>@enderror</label>
          </fieldset>
          <button type="submit" class="adv-btn" style="width: 100%">입금했어요</button>
          <span style="font-size: 12px; color: #77736B; line-height: 1.5">입금이 확인되면 계약 완료 메일과 세금계산서를 보내드려요. 보통 영업일 하루 안에 확인돼요.</span>
        </form>
      @elseif ($stage === 2)
        <div role="status" style="display: flex; flex-direction: column; gap: 10px">
          <h2 style="margin: 0; font-size: 18px; font-weight: 900">입금 확인 중이에요</h2>
          <span style="font-size: 13px; color: #66625B; line-height: 1.6">운영자가 확인하면 계약 완료 메일을 보내드려요. 노출이 시작되는 날 성과 보고 링크도 함께 가요.</span>
          <dl style="margin: 0; display: grid; grid-template-columns: auto 1fr; gap: 6px 14px; font-size: 13px; padding: 12px 14px; border-radius: 10px; background: #F4F2EE"><dt style="color: #66625B">입금 알림</dt><dd style="margin: 0">{{ $c->payment_reported_at?->format('m.d H:i') }}</dd><dt style="color: #66625B">노출 시작</dt><dd style="margin: 0">{{ $c->starts_at->format('m.d') }}</dd></dl>
        </div>
      @else
        <div role="status" style="display: flex; flex-direction: column; gap: 10px">
          <h2 style="margin: 0; font-size: 18px; font-weight: 900">{{ $c->status === 'ended' ? '광고가 끝났어요' : '계약이 완료됐어요' }}</h2>
          <span style="font-size: 13px; color: #66625B; line-height: 1.6">입금 확인 {{ $c->paid_at?->format('m.d') }}@if ($c->tax_invoice_issued_at) · 세금계산서 발행 {{ $c->tax_invoice_issued_at->format('m.d') }}@endif</span>
          @if ($c->report_token)<a href="{{ route('advertiser.report', $c->report_token) }}" class="adv-btn" style="width: 100%">성과 보고 보기</a>
          @else<span style="font-size: 13px; color: #66625B">노출이 시작되는 날 성과 보고 링크를 메일로 보내드려요.</span>@endif
        </div>
      @endif
    </div>

    {{-- 광고주 계정: 견적 메일을 받은 사람만 이 화면을 열 수 있어 여기서 비밀번호를 만든다 --}}
    @if ($account === 'create')
      <form method="POST" action="{{ route('advertiser.quote.account', $c->quote_token) }}" class="adv-card" style="padding: 20px 22px; display: flex; flex-direction: column; gap: 10px">@csrf
        <h2 style="margin: 0; font-size: 16px; font-weight: 900">광고주 계정 만들기</h2>
        <span style="font-size: 13px; color: #66625B; line-height: 1.6">비밀번호만 정하면 <b style="color: #141413">{{ $ownerEmail }}</b> 로 로그인해 견적 · 계약 · 성과 보고를 한 곳에서 볼 수 있어요.</span>
        @error('account')<span class="adv-err">{{ $message }}</span>@enderror
        <input type="hidden" name="email" value="{{ $ownerEmail }}" autocomplete="username">
        <label style="display: flex; flex-direction: column; gap: 4px"><span style="font-size: 13px; color: #3A3731">비밀번호 <span style="color: #77736B">· 영문 · 숫자 포함 8자 이상</span></span><input type="password" name="password" required minlength="8" maxlength="72" autocomplete="new-password" class="adv-input" style="height: 44px">@error('password')<span class="adv-err">{{ $message }}</span>@enderror</label>
        <label style="display: flex; flex-direction: column; gap: 4px"><span style="font-size: 13px; color: #3A3731">비밀번호 확인</span><input type="password" name="password_confirmation" required maxlength="72" autocomplete="new-password" class="adv-input" style="height: 44px"></label>
        <button type="submit" class="adv-btn is-ghost is-sm">계정 만들기</button>
      </form>
    @elseif ($account === 'login')
      <div class="adv-card" style="padding: 16px 20px; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px">
        <span style="font-size: 13px; color: #66625B">광고주 계정이 있어요</span>
        <a href="{{ route('advertiser.login') }}" class="adv-btn is-ghost is-sm">로그인해 내 광고 보기</a>
      </div>
    @elseif ($account === 'mine')
      <a href="{{ route('advertiser.account') }}" class="adv-btn is-ghost is-sm">내 광고 전체 보기</a>
    @endif

    <div style="display: flex; gap: 8px"><button type="button" onclick="window.print()" class="adv-btn is-ghost is-sm" style="flex: 1">PDF 저장 · 인쇄</button></div>
    <span style="font-size: 12px; color: #77736B; line-height: 1.6">이 견적서는 받은 이메일을 확인한 뒤에만 열리는 전용 링크예요. 담당자 외에는 공유하지 말아주세요. 문의: {{ $c->manager ? $c->manager.' · ' : '' }}{{ config('advertiser.ops_email') }}</span>
  </aside>
</main>
@endsection
