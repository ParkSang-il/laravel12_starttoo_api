@extends('advertiser.layouts.web')
@section('title', '광고 문의')
@section('top-right')
  <a href="mailto:{{ config('advertiser.ops_email') }}" style="font-size: 13px; color: #BDB8B0; text-decoration: none">{{ config('advertiser.ops_email') }}</a>
@endsection

@section('content')
@php
  $steps = [
    ['문의 남기기', '자리 · 기간 · 예산만 알려주세요. 정확하지 않아도 돼요.'],
    ['견적서 메일로 받기', '영업일 2일 안에 링크로 보내드려요. 소재 심사 결과도 함께요.'],
    ['입금 · 세금계산서', '계좌이체 후 사업자 정보를 남기면 세금계산서를 발행해요.'],
    ['노출 · 성과 보고', '노출이 시작되면 일별 노출 · 클릭을 볼 수 있는 링크를 드려요.'],
  ];
  $req = '<span style="color: #B93415" aria-hidden="true"> *</span>';
  // 로그인한 광고주는 회사 · 담당자 칸을 미리 채운다
  $pre = fn ($k) => old($k, $me->{$k} ?? '');
@endphp
<main class="adv-wrap" style="padding-top: 48px; display: flex; flex-wrap: wrap; gap: 40px; align-items: flex-start">
  <section class="adv-sticky" style="flex: 1 1 380px; min-width: 0; display: flex; flex-direction: column; gap: 28px">
    <div style="display: flex; flex-direction: column; gap: 14px">
      <span class="adv-display" style="font-size: 12px; letter-spacing: 1px; color: #B93415">ADVERTISE</span>
      <h1 style="margin: 0; font-size: clamp(30px, 3.4vw, 42px); font-weight: 900; letter-spacing: -1px; line-height: 1.2">타투를 고르는 순간에<br>브랜드를 보여주세요</h1>
      <p style="margin: 0; font-size: 16px; color: #55524C; line-height: 1.7">STARTTOO는 작품을 둘러보고, 가상으로 피팅해보고, 작가에게 상담을 요청하는 타투 플랫폼이에요. 애프터케어 · 뷰티 · 패션 브랜드에 잘 맞아요.</p>
    </div>
    @if ($stats)
      <div style="display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px">
        @foreach ($stats as $s)
          <div class="adv-card" style="padding: 16px; display: flex; flex-direction: column; gap: 6px"><strong class="adv-display" style="font-size: 22px">{{ $s['v'] }}</strong><span style="font-size: 12px; color: #66625B; line-height: 1.4">{{ $s['label'] }}</span></div>
        @endforeach
      </div>
      <span style="font-size: 12px; color: #77736B; margin-top: -18px">최근 30일 실제 노출 기록으로 자동 집계</span>
    @endif
    <ol style="margin: 0; padding: 0; list-style: none">
      @foreach ($steps as $i => [$t, $d])
        <li style="display: flex; gap: 14px">
          <span style="display: flex; flex-direction: column; align-items: center"><span style="width: 28px; height: 28px; border-radius: 50%; background: #141413; color: #FFFFFF; font-size: 13px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0">{{ $i + 1 }}</span>@if (! $loop->last)<span style="flex: 1; width: 2px; background: #D9D4CB; margin: 4px 0"></span>@endif</span>
          <span style="padding: 3px 0 18px; display: flex; flex-direction: column; gap: 2px"><span style="font-size: 15px; font-weight: 700">{{ $t }}</span><span style="font-size: 13px; color: #66625B; line-height: 1.5">{{ $d }}</span></span>
        </li>
      @endforeach
    </ol>
  </section>

  <section style="flex: 1.4 1 520px; min-width: 0">
    @if (! $slots)
      <div class="adv-card" style="padding: clamp(20px, 3vw, 36px); display: flex; flex-direction: column; gap: 10px">
        <h2 style="margin: 0; font-size: 22px; font-weight: 900">지금은 광고 문의를 받지 않아요</h2>
        <span style="font-size: 14px; color: #66625B; line-height: 1.6">자리를 정리하고 있어요. 급한 문의는 <a href="mailto:{{ config('advertiser.ops_email') }}">{{ config('advertiser.ops_email') }}</a> 로 보내주세요.</span>
      </div>
    @else
    <form method="POST" action="{{ route('advertiser.inquiry.store') }}" enctype="multipart/form-data" aria-label="광고 문의" class="adv-card" style="padding: clamp(20px, 3vw, 36px); display: flex; flex-direction: column; gap: 26px" novalidate>
      @csrf
      <div style="display: flex; flex-direction: column; gap: 4px"><h2 style="margin: 0; font-size: 22px; font-weight: 900">광고 문의</h2><span style="font-size: 13px; color: #66625B; line-height: 1.6">영업일 2일 안에 견적서를 메일로 보내드려요. <span style="color: #B93415">*</span> 표시는 꼭 적어주세요.</span></div>

      @if ($errors->any())
        <div role="alert" style="border-radius: 12px; background: #FDE4DE; color: #6E2111; padding: 12px 16px; font-size: 14px; line-height: 1.6">
          <b>확인이 필요한 칸이 있어요</b>
          <ul style="margin: 4px 0 0; padding-left: 18px">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
      @endif

      {{-- 봇 방지 숨은 칸 --}}
      <div aria-hidden="true" style="position: absolute; left: -9999px"><label>웹사이트 <input name="website" tabindex="-1" autocomplete="off"></label></div>

      <fieldset style="border: 0; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(min(220px, 100%), 1fr)); gap: 16px">
        <legend style="font-size: 16px; font-weight: 700; margin-bottom: 14px">회사 · 담당자</legend>
        @foreach ([['company', '회사명', 'text', 'organization', ''], ['contact_name', '담당자', 'text', 'name', '이름 · 팀'], ['email', '이메일', 'email', 'email', 'you@company.com'], ['phone', '연락처', 'tel', 'tel', '010-0000-0000']] as [$n, $l, $type, $ac, $ph])
          <label style="display: flex; flex-direction: column; gap: 6px">
            <span class="adv-label">{{ $l }}{!! $req !!}</span>
            <input name="{{ $n }}" type="{{ $type }}" required autocomplete="{{ $ac }}" placeholder="{{ $ph }}" value="{{ $pre($n) }}" class="adv-input @error($n) is-invalid @enderror" @error($n) aria-invalid="true" aria-describedby="{{ $n }}-err" @enderror>
            @if ($n === 'email')<span style="font-size: 12px; color: #77736B">견적서와 성과 보고 링크가 이 주소로 가요</span>@endif
            @error($n)<span id="{{ $n }}-err" class="adv-err">{{ $message }}</span>@enderror
          </label>
        @endforeach
        <label style="display: flex; flex-direction: column; gap: 6px; grid-column: 1 / -1"><span class="adv-label">업종</span>
          <select name="industry" class="adv-input">@foreach ($industries as $o)<option @selected(old('industry') === $o)>{{ $o }}</option>@endforeach</select>
        </label>
      </fieldset>

      <fieldset style="border: 0; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 12px" @error('slots') aria-describedby="slots-err" @enderror>
        <legend style="font-size: 16px; font-weight: 700; margin-bottom: 2px">원하는 자리{!! $req !!} <span style="font-size: 13px; font-weight: 500; color: #66625B">여러 개 골라도 돼요</span></legend>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(240px, 100%), 1fr)); gap: 10px">
          @foreach ($slots as $key => $o)
            <label class="adv-choice adv-slot">
              <input type="checkbox" name="slots[]" value="{{ $key }}" @checked(in_array($key, old('slots', []), true))>
              <span class="adv-face"><span class="adv-thumb"><i style="top: {{ $o['top'] }}px; height: {{ $o['h'] }}px"></i></span><span>{{ $o['name'] }}<small>{{ $o['desc'] }}</small></span></span>
            </label>
          @endforeach
        </div>
        <span style="font-size: 12px; color: #77736B">자리별 단가 · 최소 노출은 <a href="{{ route('advertiser.products') }}">상품 소개</a>에서 볼 수 있어요</span>
        @error('slots')<span id="slots-err" class="adv-err">{{ $message }}</span>@enderror
      </fieldset>

      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(240px, 100%), 1fr)); gap: 16px">
        <label style="display: flex; flex-direction: column; gap: 6px"><span class="adv-label">희망 시작일</span><input type="date" name="wish_start" min="{{ today()->toDateString() }}" value="{{ old('wish_start') }}" class="adv-input">@error('wish_start')<span class="adv-err">{{ $message }}</span>@enderror</label>
        <fieldset style="border: 0; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px"><legend class="adv-label" style="margin-bottom: 6px">기간</legend>
          <div style="display: flex; flex-wrap: wrap; gap: 6px">@foreach ($durations as $d)<label class="adv-choice"><input type="radio" name="duration" value="{{ $d }}" @checked(old('duration', '2주') === $d)><span class="adv-face">{{ $d }}</span></label>@endforeach</div>
        </fieldset>
      </div>

      <fieldset style="border: 0; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px"><legend class="adv-label" style="margin-bottom: 6px">예산 (부가세 별도)</legend>
        <div style="display: flex; flex-wrap: wrap; gap: 6px">@foreach ($budgets as $b)<label class="adv-choice"><input type="radio" name="budget" value="{{ $b }}" @checked(old('budget') === $b)><span class="adv-face">{{ $b }}</span></label>@endforeach</div>
      </fieldset>

      <label style="display: flex; flex-direction: column; gap: 6px"><span class="adv-label">광고를 누르면 열릴 주소</span><input type="url" name="landing_url" inputmode="url" placeholder="https://" value="{{ old('landing_url') }}" class="adv-input @error('landing_url') is-invalid @enderror"><span style="font-size: 12px; color: #77736B">https:// 로 시작하는 주소만 앱에서 열 수 있어요</span>@error('landing_url')<span class="adv-err">{{ $message }}</span>@enderror</label>

      <div style="display: flex; flex-direction: column; gap: 6px">
        <span class="adv-label" id="files-label">광고 소재</span>
        <label class="adv-drop">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#55524C" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V4M7 9l5-5 5 5M4 16v4h16v-4"/></svg>
          <span style="font-size: 14px; font-weight: 700">이미지 · 영상 올리기</span>
          <span style="font-size: 12px; color: #77736B">세로 1080×1350 권장 · JPG · PNG · MP4 · MOV · 영상 50MB · 이미지 20MB · 5개까지 · 여러 번 나눠 골라도 돼요 · 나중에 메일로 보내도 돼요</span>
          <input type="file" name="files[]" multiple accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime,video/x-m4v,.mp4,.mov,.m4v" aria-labelledby="files-label">
        </label>
        {{-- 고른 파일 목록(고를 때마다 쌓이고, 하나씩 뺄 수 있다) --}}
        <ul data-file-list style="list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px"></ul>
        <span data-file-error class="adv-err" role="alert" style="display: none"></span>
        @error('files.*')<span class="adv-err">{{ $message }}</span>@enderror
      </div>

      <label style="display: flex; flex-direction: column; gap: 6px"><span class="adv-label">요청 사항</span><textarea name="memo" rows="3" maxlength="2000" placeholder="타깃 지역, 함께 하고 싶은 작가, 이벤트 내용 등" class="adv-textarea">{{ old('memo') }}</textarea></label>

      <div style="border-radius: 12px; background: #F4F2EE; padding: 16px 18px; display: flex; flex-direction: column; gap: 8px">
        <span style="font-size: 14px; font-weight: 700">이런 광고는 받지 않아요</span>
        <ul style="margin: 0; padding-left: 18px; font-size: 13px; color: #55524C; line-height: 1.7">
          <li>통증 없음 · 흉터 없음처럼 시술 효과를 보장하는 표현, 의료 효과를 내세우는 표현</li>
          <li>면허 없는 시술 · 반영구 시술을 권유하는 광고, 가격 할인만 강조하는 시술 광고</li>
          <li>타투 · 피부에 대한 과장 · 비교 광고, 혐오 표현</li>
        </ul>
        <span style="font-size: 12px; color: #77736B">모든 광고는 앱에서 ‘광고’ 표시와 함께 나가요. 소재는 노출 전 심사해요.</span>
      </div>

      <label class="adv-agree">
        <input type="checkbox" name="privacy" value="1" required @checked(old('privacy')) @error('privacy') aria-invalid="true" @enderror>
        <span style="font-size: 14px; line-height: 1.6"><b>개인정보 수집 · 이용에 동의해요 (필수)</b><br>
          <span style="font-size: 13px; color: #66625B">수집 항목: 회사명, 담당자 이름, 이메일, 연락처 · 목적: 광고 상담, 견적 · 계약 안내 · 보관: 문의일로부터 {{ intdiv($retention['inquiry_days'], 365) }}년, 계약 시 계약 종료 후 {{ $retention['contract_years'] }}년 · 동의하지 않을 수 있지만, 이 경우 문의를 받을 수 없어요.</span></span>
      </label>
      @error('privacy')<span class="adv-err" style="margin-top: -18px">{{ $message }}</span>@enderror

      {{-- 보내는 중: 파일 올리는 진행률(영상은 오래 걸려 화면이 멈춘 것처럼 보이지 않게) --}}
      <div data-upload-status role="status" aria-live="polite" style="border-radius: 12px; background: #F4F2EE; padding: 14px 16px; display: none; flex-direction: column; gap: 8px">
        <span data-upload-text style="font-size: 14px; font-weight: 700">문의를 보내고 있어요</span>
        <span style="height: 6px; border-radius: 3px; background: #E4E0D8; overflow: hidden; display: block"><span data-upload-bar style="display: block; height: 100%; width: 0%; background: #FF5A36; transition: width .2s"></span></span>
        <span style="font-size: 12px; color: #77736B">파일이 크면 몇 분 걸릴 수 있어요. 끝날 때까지 이 페이지를 닫지 말아주세요.</span>
      </div>
      <span data-upload-error class="adv-err" role="alert" style="display: none"></span>
      <button type="submit" data-submit class="adv-btn is-accent" style="height: 56px">문의 보내기</button>
    </form>
    @endif
  </section>
</main>
<script>
  // 광고 소재: 파일을 고를 때마다 목록에 쌓는다(브라우저 기본은 다시 고르면 앞 선택이 바뀐다). 5개까지 · 하나씩 빼기
  var MAX_FILES = 5, MAX_VIDEO = 50 * 1024 * 1024, MAX_IMAGE = 20 * 1024 * 1024;
  function isVideo(f) { return /^video\//.test(f.type) || /\.(mp4|mov|m4v)$/i.test(f.name); }
  function sizeLimit(f) { return isVideo(f) ? MAX_VIDEO : MAX_IMAGE; }
  (function () {
    var inp = document.querySelector('.adv-drop input[type=file]');
    if (!inp) return;
    var list = document.querySelector('[data-file-list]');
    var ferr = document.querySelector('[data-file-error]');
    var picked = [];
    var canSet = typeof DataTransfer !== 'undefined';

    function sync() {
      if (canSet) {
        var dt = new DataTransfer();
        picked.forEach(function (f) { dt.items.add(f); });
        inp.files = dt.files;
      }
      list.innerHTML = '';
      picked.forEach(function (f, i) {
        var li = document.createElement('li');
        li.style.cssText = 'display: flex; align-items: center; gap: 10px; padding: 8px 12px; border-radius: 10px; background: #F4F2EE; font-size: 13px';
        var name = document.createElement('span');
        name.style.cssText = 'flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #3A3731; font-weight: 500';
        name.textContent = (isVideo(f) ? '영상 · ' : '이미지 · ') + f.name + ' · ' + (f.size / 1048576).toFixed(1) + 'MB';
        var del = document.createElement('button');
        del.type = 'button';
        del.textContent = '빼기';
        del.setAttribute('aria-label', f.name + ' 빼기');
        del.style.cssText = 'border: 0; background: none; color: #A3301A; font-size: 13px; font-weight: 700; cursor: pointer; padding: 4px';
        del.addEventListener('click', function () { picked.splice(i, 1); ferr.style.display = 'none'; sync(); });
        li.appendChild(name);
        li.appendChild(del);
        list.appendChild(li);
      });
    }

    inp.addEventListener('change', function () {
      var added = Array.from(inp.files);
      // 오래된 브라우저(목록을 고칠 수 없음): 고른 것만 보여 준다
      if (!canSet) { picked = added; sync(); return; }
      var msgs = [];
      added.forEach(function (f) {
        if (picked.some(function (p) { return p.name === f.name && p.size === f.size; })) return;
        if (f.size > sizeLimit(f)) { msgs.push(f.name + ': ' + (isVideo(f) ? '영상은 50MB' : '이미지는 20MB') + '까지예요'); return; }
        if (picked.length >= MAX_FILES) { msgs.push('파일은 ' + MAX_FILES + '개까지 올릴 수 있어요'); return; }
        picked.push(f);
      });
      ferr.textContent = msgs.filter(function (m, i) { return msgs.indexOf(m) === i; }).join(' · ');
      ferr.style.display = msgs.length ? 'block' : 'none';
      sync();
    });
  })();

  // 보내기: 파일이 있으면 진행률을 보여 주며 올리고, 받은 결과 페이지(완료 · 오류)를 그대로 띄운다
  (function () {
    var form = document.querySelector('form[aria-label="광고 문의"]');
    if (!form) return;
    var btn = form.querySelector('[data-submit]');
    var box = form.querySelector('[data-upload-status]');
    var text = form.querySelector('[data-upload-text]');
    var bar = form.querySelector('[data-upload-bar]');
    var err = form.querySelector('[data-upload-error]');
    var busy = false;
    // 영상 50MB · 이미지 20MB(서버 규칙) · 한 번에 보내는 합계는 서버 요청 한도(160MB) 안쪽
    var MAX_TOTAL = 150 * 1024 * 1024;

    function fail(msg) {
      busy = false;
      box.style.display = 'none';
      btn.disabled = false;
      btn.textContent = '문의 보내기';
      err.textContent = msg;
      err.style.display = 'block';
    }
    function leaveGuard(e) { if (busy) { e.preventDefault(); e.returnValue = ''; } }
    window.addEventListener('beforeunload', leaveGuard);

    // 보내기 전 필수 항목 확인(파일을 다 올린 뒤에 서버가 거절하지 않게). 첫 빈 칸으로 이동해 알려 준다
    var labels = { company: '회사명', contact_name: '담당자', email: '이메일', phone: '연락처' };
    function missing() {
      for (var n in labels) {
        var el = form.querySelector('[name="' + n + '"]');
        if (el && !el.value.trim()) return { el: el, msg: labels[n] + '을(를) 적어주세요.' };
        if (el && n === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(el.value.trim())) return { el: el, msg: '이메일 주소를 확인해주세요.' };
      }
      var slots = form.querySelectorAll('input[name="slots[]"]');
      if (slots.length && !form.querySelector('input[name="slots[]"]:checked')) return { el: slots[0], msg: '원하는 자리를 하나 이상 골라주세요.' };
      var privacy = form.querySelector('input[name="privacy"]');
      if (privacy && !privacy.checked) return { el: privacy, msg: '개인정보 수집 · 이용에 동의해야 문의를 보낼 수 있어요.' };
      return null;
    }

    form.addEventListener('submit', function (e) {
      if (busy) { e.preventDefault(); return; }
      err.style.display = 'none';
      var miss = missing();
      if (miss) {
        e.preventDefault();
        fail(miss.msg);
        miss.el.scrollIntoView({ block: 'center', behavior: 'smooth' });
        miss.el.focus({ preventScroll: true });
        return;
      }
      var input = form.querySelector('input[type=file]');
      var files = input ? Array.from(input.files) : [];
      // 올리기 전에 미리 확인(큰 파일을 다 올린 뒤에 거절되지 않게)
      if (files.length > MAX_FILES) { e.preventDefault(); fail('파일은 ' + MAX_FILES + '개까지 올릴 수 있어요.'); return; }
      var big = files.filter(function (f) { return f.size > sizeLimit(f); })[0];
      if (big) { e.preventDefault(); fail(big.name + ' 파일이 ' + (isVideo(big) ? '50MB' : '20MB') + '를 넘어요. 줄이거나 나중에 메일로 보내주세요.'); return; }
      var sum = files.reduce(function (s, f) { return s + f.size; }, 0);
      if (sum > MAX_TOTAL) { e.preventDefault(); fail('한 번에 150MB까지 보낼 수 있어요(지금 ' + (sum / 1048576).toFixed(1) + 'MB). 일부는 나중에 메일로 보내주세요.'); return; }

      busy = true;
      btn.disabled = true;
      btn.textContent = '보내는 중…';
      box.style.display = 'flex';
      // 파일이 없으면 보통 보내기(금방 끝난다)
      if (!files.length || !window.FormData || !window.XMLHttpRequest) { text.textContent = '문의를 보내고 있어요'; return; }

      e.preventDefault();
      var total = files.reduce(function (s, f) { return s + f.size; }, 0);
      text.textContent = '파일 올리는 중 0% · ' + (total / 1048576).toFixed(1) + 'MB';
      var xhr = new XMLHttpRequest();
      xhr.open('POST', form.action);
      xhr.setRequestHeader('Accept', 'text/html');
      xhr.upload.addEventListener('progress', function (ev) {
        if (!ev.lengthComputable) return;
        var p = Math.min(100, Math.round(ev.loaded / ev.total * 100));
        bar.style.width = p + '%';
        text.textContent = p < 100 ? '파일 올리는 중 ' + p + '% · ' + (total / 1048576).toFixed(1) + 'MB' : '올리기 끝 · 문의를 접수하고 있어요';
      });
      xhr.addEventListener('load', function () {
        if (xhr.status >= 200 && xhr.status < 400 && xhr.responseText) {
          busy = false;
          // 서버가 보낸 결과(접수 완료 또는 입력 오류) 페이지를 그대로 보여 준다
          if (xhr.responseURL) history.replaceState(null, '', xhr.responseURL);
          document.open();
          document.write(xhr.responseText);
          document.close();
          window.scrollTo(0, 0);
        } else if (xhr.status === 413) {
          fail('파일이 너무 커서 보내지 못했어요. 줄이거나 나중에 메일로 보내주세요.');
        } else if (xhr.status === 429) {
          fail('잠시 후 다시 보내주세요. 짧은 시간에 너무 많이 보냈어요.');
        } else if (xhr.status === 419) {
          fail('페이지가 오래 열려 있었어요. 새로고침한 뒤 다시 보내주세요.');
        } else {
          fail('보내지 못했어요. 잠시 후 다시 시도해주세요.');
        }
      });
      xhr.addEventListener('error', function () { fail('네트워크가 끊겨 보내지 못했어요. 연결을 확인하고 다시 보내주세요.'); });
      xhr.send(new FormData(form));
    });
  })();
</script>
@endsection
