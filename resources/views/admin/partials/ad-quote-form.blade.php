{{-- 외부 광고 견적(CPM) 입력. $action, $d = AdminAdSalesController::quoteDefaults(), $rates --}}
@php
  $input = 'height: 40px; border-radius: 10px; border: 1px solid #D9D4CB; padding: 0 12px; font-size: 14px; color: #141413; background: #FFFFFF; box-sizing: border-box; width: 100%; font-family: inherit';
  $area = 'border-radius: 10px; border: 1px solid #D9D4CB; padding: 10px 12px; font-size: 13px; line-height: 1.6; color: #141413; background: #FFFFFF; box-sizing: border-box; width: 100%; resize: vertical; font-family: inherit';
  $label = 'font-size: 12px; font-weight: 700; color: #3A3731';
  $lines = old('lines', $d['lines']);
  $max = \App\Services\Starttoo\AdSales::MAX_LINES;
@endphp
<form method="POST" action="{{ $action }}" data-ad-quote style="display: flex; flex-direction: column; gap: 12px">
  @csrf
  @error('quote')<div role="alert" style="font-size: 13px; color: #B3261E">{{ $message }}</div>@enderror
  @if ($errors->hasAny(['title', 'company', 'contact_name', 'email', 'valid_until', 'starts_at', 'ends_at']))
    <div role="alert" style="font-size: 13px; color: #B3261E">{{ $errors->first() }}</div>
  @endif
  <label style="display: flex; flex-direction: column; gap: 4px"><span style="{{ $label }}">견적 제목 <span style="font-weight: 400; color: #66625B">· 광고주 견적서 맨 위</span></span><input name="title" required maxlength="120" value="{{ old('title', $d['title']) }}" placeholder="예: 오로라 스킨랩 12월 출시 캠페인" style="{{ $input }}"></label>
  <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(160px, 100%), 1fr)); gap: 8px">
    <label style="display: flex; flex-direction: column; gap: 4px"><span style="{{ $label }}">회사명</span><input name="company" required maxlength="100" value="{{ old('company', $d['company']) }}" style="{{ $input }}"></label>
    <label style="display: flex; flex-direction: column; gap: 4px"><span style="{{ $label }}">담당자</span><input name="contact_name" required maxlength="60" value="{{ old('contact_name', $d['contact_name']) }}" style="{{ $input }}"></label>
    <label style="display: flex; flex-direction: column; gap: 4px"><span style="{{ $label }}">받는 이메일</span><input type="email" name="email" required maxlength="150" value="{{ old('email', $d['email']) }}" style="{{ $input }}"></label>
    <label style="display: flex; flex-direction: column; gap: 4px"><span style="{{ $label }}">운영 담당 <span style="font-weight: 400; color: #66625B">· 선택</span></span><input name="manager" maxlength="60" value="{{ old('manager') }}" style="{{ $input }}"></label>
  </div>
  <label style="display: flex; flex-direction: column; gap: 4px"><span style="{{ $label }}">연결 주소 <span style="font-weight: 400; color: #66625B">· https:// · 광고를 누르면 열려요</span></span><input name="landing_url" maxlength="500" value="{{ old('landing_url', $d['landing_url']) }}" placeholder="https://" style="{{ $input }}"></label>

  <fieldset style="border: 0; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px">
    <legend style="{{ $label }}; margin-bottom: 6px">자리 · 보장 노출 · CPM <span style="font-weight: 400; color: #66625B">· 금액 = 보장 노출 ÷ 1,000 × CPM (부가세 별도)</span></legend>
    @for ($i = 0; $i < $max; $i++)
      @php $l = $lines[$i] ?? ['slot' => '', 'imps' => '', 'cpm' => '']; @endphp
      <div data-line style="display: grid; grid-template-columns: minmax(120px, 1.4fr) minmax(90px, 1fr) minmax(80px, 0.8fr) minmax(90px, auto); gap: 6px; align-items: center">
        <select name="lines[{{ $i }}][slot]" aria-label="자리 {{ $i + 1 }}" style="{{ $input }}">
          <option value="">{{ $i === 0 ? '자리 고르기' : '— 줄 추가 —' }}</option>
          @foreach ($rates as $slot => $r)
            <option value="{{ $slot }}" data-cpm="{{ $r['cpm'] }}" data-min="{{ $r['min'] }}" @selected(($l['slot'] ?? '') === $slot)>{{ $r['name'] }}{{ $r['on'] ? '' : ' (문의 받기 꺼짐)' }}</option>
          @endforeach
        </select>
        <input type="number" name="lines[{{ $i }}][imps]" min="0" step="1000" value="{{ $l['imps'] ?? '' }}" placeholder="보장 노출" aria-label="보장 노출 {{ $i + 1 }}" style="{{ $input }}">
        <input type="number" name="lines[{{ $i }}][cpm]" min="0" step="100" value="{{ $l['cpm'] ?? '' }}" placeholder="CPM" aria-label="CPM {{ $i + 1 }}" style="{{ $input }}">
        <span data-amount style="font-size: 13px; text-align: right; white-space: nowrap; color: #3A3731"></span>
      </div>
    @endfor
  </fieldset>
  <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(160px, 100%), 1fr)); gap: 8px">
    <label style="display: flex; flex-direction: column; gap: 4px"><span style="{{ $label }}">할인 이름 <span style="font-weight: 400; color: #66625B">· 선택</span></span><input name="discount_label" maxlength="60" value="{{ old('discount_label') }}" placeholder="첫 집행 할인" style="{{ $input }}"></label>
    <label style="display: flex; flex-direction: column; gap: 4px"><span style="{{ $label }}">할인 금액(원)</span><input type="number" name="discount" min="0" step="1000" value="{{ old('discount', 0) }}" style="{{ $input }}"></label>
  </div>
  <div data-total style="padding: 10px 12px; border-radius: 10px; background: #F4F2EE; font-size: 13px; line-height: 1.7"></div>

  <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(140px, 100%), 1fr)); gap: 8px">
    <label style="display: flex; flex-direction: column; gap: 4px"><span style="{{ $label }}">견적 유효 기한</span><input type="date" name="valid_until" required min="{{ today()->toDateString() }}" value="{{ old('valid_until', $d['valid_until']) }}" style="{{ $input }}"></label>
    <label style="display: flex; flex-direction: column; gap: 4px"><span style="{{ $label }}">노출 시작일</span><input type="date" name="starts_at" required value="{{ old('starts_at', $d['starts_at']) }}" style="{{ $input }}"></label>
    <label style="display: flex; flex-direction: column; gap: 4px"><span style="{{ $label }}">노출 종료일(최대)</span><input type="date" name="ends_at" required value="{{ old('ends_at', $d['ends_at']) }}" style="{{ $input }}"></label>
  </div>
  <label style="display: flex; flex-direction: column; gap: 4px"><span style="{{ $label }}">조건 <span style="font-weight: 400; color: #66625B">· 한 줄에 하나, 견적서에 그대로 보여요</span></span><textarea name="terms" rows="4" maxlength="3000" style="{{ $area }}">{{ old('terms', $d['terms']) }}</textarea></label>
  <label style="display: flex; flex-direction: column; gap: 4px"><span style="{{ $label }}">운영 메모 <span style="font-weight: 400; color: #66625B">· 광고주에게 안 보여요</span></span><textarea name="memo" rows="2" maxlength="2000" style="{{ $area }}">{{ old('memo') }}</textarea></label>
  <div><button type="submit" data-confirm="이 견적을 광고주 이메일로 보낼까요?" style="height: 40px; padding: 0 20px; border-radius: 10px; font-size: 14px; font-weight: 700; border: 0; background: #141413; color: #FFFFFF; cursor: pointer; font-family: inherit">견적 만들고 메일 보내기</button></div>
</form>
<script>
  (function () {
    var form = document.currentScript.previousElementSibling;
    var won = function (n) { return '₩' + Math.round(n).toLocaleString('ko-KR'); };
    function calc() {
      var sum = 0;
      form.querySelectorAll('[data-line]').forEach(function (row) {
        var sel = row.querySelector('select'), imps = +row.querySelector('[name$="[imps]"]').value || 0, cpm = +row.querySelector('[name$="[cpm]"]').value || 0;
        var out = row.querySelector('[data-amount]');
        if (!sel.value || !imps) { out.textContent = ''; return; }
        var amt = Math.floor(imps * cpm / 1000);
        var min = +(sel.selectedOptions[0].dataset.min || 0);
        sum += amt;
        out.textContent = won(amt) + (imps < min ? ' · 최소 ' + min.toLocaleString('ko-KR') + '회' : '');
        out.style.color = imps < min || imps % 1000 ? '#B3261E' : '#3A3731';
      });
      var disc = +form.querySelector('[name="discount"]').value || 0;
      var supply = Math.max(0, sum - disc), vat = Math.round(supply * 0.1);
      form.querySelector('[data-total]').innerHTML = '공급가액 <b>' + won(supply) + '</b> · 부가세 ' + won(vat) + ' · 합계 <b>' + won(supply + vat) + '</b>';
    }
    // 자리를 고르면 비어 있는 칸에 단가표 CPM · 최소 노출을 채운다
    form.querySelectorAll('[data-line] select').forEach(function (sel) {
      sel.addEventListener('change', function () {
        var row = sel.closest('[data-line]'), o = sel.selectedOptions[0];
        if (o && o.dataset.cpm) {
          var cpm = row.querySelector('[name$="[cpm]"]'), imps = row.querySelector('[name$="[imps]"]');
          if (!cpm.value) cpm.value = o.dataset.cpm;
          if (!imps.value) imps.value = o.dataset.min;
        }
        calc();
      });
    });
    form.addEventListener('input', calc);
    calc();
  })();
</script>
