@extends('admin.layouts.app')
@section('title', '앱 버전 · 강제 업데이트')
@section('content')

  <div style="flex: 999 1 560px; min-width: 0; padding: 28px clamp(16px, 3vw, 40px) 48px; box-sizing: border-box; display: flex; flex-direction: column; gap: 24px">
    <header style="display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 16px">
      <div style="display: flex; flex-direction: column; gap: 6px">
        <h1 style="margin: 0; font-size: 26px; font-weight: 900; letter-spacing: -0.5px">앱 버전 · 강제 업데이트</h1>
        <p style="margin: 0; font-size: 14px; color: #66625B">최소 지원 버전보다 낮은 앱은 업데이트 전까지 쓸 수 없고, 권장 버전보다 낮으면 업데이트 안내만 떠요.</p>
      </div>
      <div style="display: flex; flex-wrap: wrap; gap: 8px"><span style="{{ $liveStyle }}">{{ $liveLabel }}</span></div>
    </header>

    @include('admin.partials.sections', ['sectionsLabel' => '앱 버전 항목'])

    @if ($section === '업데이트 설정')
    <section style="display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-start">
      <div style="background: #FFFFFF; border: 1px solid #E4E0D8; border-radius: 16px; flex: 3 1 600px; min-width: 0; overflow: hidden">
        <div role="tablist" style="padding: 14px 16px; display: flex; flex-wrap: wrap; gap: 6px; border-bottom: 1px solid #E4E0D8">
          @foreach ($platforms as $t)
            <a href="{{ $t['href'] }}" class="btn-a" role="tab" style="{{ $t['style'] }}">{{ $t['label'] }}</a>
          @endforeach
        </div>
        <div style="padding: 20px; display: flex; flex-direction: column; gap: 18px">
          <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(160px, 100%), 1fr)); gap: 10px">
            @foreach ($vers as $v)
              <div style="{{ $v['box'] }}">
                <span style="font-size: 12px; font-weight: 700; color: #66625B">{{ $v['label'] }}</span>
                <span style="display: flex; align-items: center; gap: 6px">
                  @if ($canSave)<form method="POST" action="{{ $v['decAction'] }}" style="display: contents">@csrf<button type="submit" aria-label="{{ $v['decAria'] }}" style="{{ $v['stepBtn'] }}">−</button></form>@endif
                  <strong style="flex: 1; text-align: center; font-family: Unbounded, 'Arial Black', sans-serif; font-size: 22px; font-weight: 700; letter-spacing: -0.5px">{{ $v['value'] }}</strong>
                  @if ($canSave)<form method="POST" action="{{ $v['incAction'] }}" style="display: contents">@csrf<button type="submit" aria-label="{{ $v['incAria'] }}" style="{{ $v['stepBtn'] }}">+</button></form>@endif
                </span>
                <span style="font-size: 12px; color: #66625B; line-height: 1.4">{{ $v['note'] }}</span>
              </div>
            @endforeach
          </div>
          @if ($invalid)
            <div style="border-radius: 12px; background: #FDE4DE; color: #6E2111; padding: 12px 14px; font-size: 13px; line-height: 1.5">최소 지원 버전 ≤ 권장 버전 ≤ 스토어 최신 버전 순서여야 저장할 수 있어요.</div>
          @endif
          <div style="display: flex; flex-direction: column; gap: 10px">
            <div style="display: flex; justify-content: space-between; align-items: baseline"><h2 style="margin: 0; font-size: 15px; font-weight: 700">사용 중인 버전 분포 · 최근 7일 앱 실행</h2><span style="font-size: 13px; color: #66625B">{{ $impact }}</span></div>
            @forelse ($dist as $d)
              <div style="display: flex; align-items: center; gap: 10px">
                <span style="width: 56px; font-size: 13px; font-weight: 700; font-variant-numeric: tabular-nums">{{ $d['ver'] }}</span>
                <span style="flex: 1; height: 22px; border-radius: 6px; background: #F4F2EE; overflow: hidden"><span style="{{ $d['bar'] }}"></span></span>
                <span style="width: 64px; text-align: right; font-size: 12px; color: #66625B; font-variant-numeric: tabular-nums">{{ $d['users'] }}</span>
                <span style="width: 84px; text-align: right"><span style="{{ $d['pill'] }}">{{ $d['state'] }}</span></span>
              </div>
            @empty
              <span style="font-size: 13px; color: #66625B">새 앱 버전이 버전 확인을 부르면 여기에 쌓여요.</span>
            @endforelse
          </div>
          <div style="display: flex; flex-wrap: wrap; gap: 14px">
            <label style="flex: 1 1 260px; display: flex; flex-direction: column; gap: 6px"><span style="font-size: 13px; font-weight: 700; color: #3A3731">강제 업데이트 문구</span><textarea name="force_message" form="f-save" required maxlength="300" rows="3" @disabled(! $canSave) style="border-radius: 10px; border: 1px solid #D9D4CB; padding: 12px; font-size: 14px; line-height: 1.5; resize: vertical; color: #141413; box-sizing: border-box; width: 100%">{{ $forceMessage }}</textarea></label>
            <label style="flex: 1 1 260px; display: flex; flex-direction: column; gap: 6px"><span style="font-size: 13px; font-weight: 700; color: #3A3731">권장 업데이트 문구</span><textarea name="soft_message" form="f-save" required maxlength="300" rows="3" @disabled(! $canSave) style="border-radius: 10px; border: 1px solid #D9D4CB; padding: 12px; font-size: 14px; line-height: 1.5; resize: vertical; color: #141413; box-sizing: border-box; width: 100%">{{ $softMessage }}</textarea></label>
          </div>
          <label style="display: flex; flex-direction: column; gap: 6px"><span style="font-size: 13px; font-weight: 700; color: #3A3731">스토어 주소</span><input name="store_url" form="f-save" type="url" value="{{ $storeUrl }}" placeholder="https://" @disabled(! $canSave) style="height: 44px; border-radius: 10px; border: 1px solid #D9D4CB; padding: 0 12px; font-size: 14px; color: #141413; background: #FFFFFF; box-sizing: border-box; width: 100%; font-family: ui-monospace, Menlo, monospace; font-size: 13px"></label>
          <div style="display: flex; flex-direction: column; gap: 12px">
            @foreach ($opts as $o)
              <div style="display: flex; align-items: center; gap: 12px">
                <span style="flex: 1; display: flex; flex-direction: column; gap: 2px"><span style="font-size: 14px; font-weight: 700">{{ $o['label'] }}</span><span style="font-size: 12px; color: #66625B">{{ $o['note'] }}</span></span>
                @if ($canSave)
                  <form method="POST" action="{{ $o['action'] }}" style="display: contents">@csrf<button type="submit" role="switch" aria-checked="{{ $o['on'] ? 'true' : 'false' }}" aria-label="{{ $o['aria'] }}" style="{{ $o['track'] }}"><span style="{{ $knob }}"></span></button></form>
                @else
                  <span role="switch" aria-checked="{{ $o['on'] ? 'true' : 'false' }}" aria-disabled="true" aria-label="{{ $o['aria'] }}" style="{{ $o['track'] }}"><span style="{{ $knob }}"></span></span>
                @endif
              </div>
            @endforeach
          </div>
          <div style="display: flex; flex-wrap: wrap; gap: 8px; align-items: center">
            <span style="flex: 1 1 240px; font-size: 12px; color: #66625B; line-height: 1.5">저장하면 앱이 다음 실행 · 앞으로 올 때 <code style="font-family: ui-monospace, Menlo, monospace">GET /api/v1/projects/starttoo/app/version</code>으로 확인해요. 설정 변경은 감사 로그에 남아요.@if (! $canSave) 저장은 최고 관리자만 할 수 있어요.@endif</span>
            @if ($canSave)
              <button type="submit" form="f-save" @disabled($invalid) data-confirm="이 설정을 앱에 바로 적용할까요? 최소 버전 · 점검 모드는 회원 앱을 막아요." style="height: 40px; padding: 0 16px; border-radius: 10px; font-size: 14px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; gap: 6px; border: 0; background: #141413; color: #FFFFFF">{{ $saveLabel }}</button>
            @endif
          </div>
        </div>
      </div>
      <div style="flex: 2 1 320px; min-width: 0; display: flex; flex-direction: column; gap: 14px">
        <div style="background: #FFFFFF; border: 1px solid #E4E0D8; border-radius: 16px; padding: 18px; display: flex; flex-direction: column; gap: 12px">
          <div style="display: flex; justify-content: space-between; align-items: center"><h2 style="margin: 0; font-size: 17px; font-weight: 700">앱 화면 미리보기</h2><div style="display: flex; gap: 6px">@foreach ($previews as $p)<a href="{{ $p['href'] }}" class="btn-a" style="{{ $p['style'] }}">{{ $p['label'] }}</a>@endforeach</div></div>
          <div style="border-radius: 28px; background: #2A2724; padding: 14px; aspect-ratio: 9 / 15; box-sizing: border-box; display: flex; flex-direction: column; justify-content: flex-end">
            <div style="{{ $sheet }}">
              <span style="width: 48px; height: 48px; border-radius: 12px; background: #0E0E0F; color: #FFFFFF; font-family: Unbounded, 'Arial Black', sans-serif; font-size: 14px; font-weight: 800; display: flex; align-items: center; justify-content: center">ST.</span>
              <strong style="font-size: 17px; font-weight: 900">{{ $pvTitle }}</strong>
              <span style="font-size: 13px; color: #55524C; line-height: 1.5">{{ $pvBody }}</span>
              <span style="font-size: 12px; color: #77736B">현재 {{ $pvCur }} → 최신 {{ $latest }}</span>
              <span style="height: 48px; border-radius: 12px; background: #FF5A36; color: #FFFFFF; font-size: 15px; font-weight: 700; display: flex; align-items: center; justify-content: center; align-self: stretch">업데이트</span>
              @if ($pvSoft)<span style="height: 40px; font-size: 14px; font-weight: 700; color: #55524C; display: flex; align-items: center; justify-content: center; align-self: stretch">나중에</span>@endif
            </div>
          </div>
          <span style="font-size: 12px; color: #66625B; line-height: 1.5">{{ $pvNote }}</span>
        </div>
      </div>
    </section>
    @endif

    @if ($section === '변경 이력')
    <section style="background: #FFFFFF; border: 1px solid #E4E0D8; border-radius: 16px; overflow: hidden">
      <div style="padding: 14px 16px; display: flex; flex-wrap: wrap; align-items: center; gap: 10px; border-bottom: 1px solid #E4E0D8">
        <h2 style="margin: 0; font-size: 17px; font-weight: 700; flex: 1">변경 이력</h2>
        <div role="tablist" style="display: flex; flex-wrap: wrap; gap: 6px">
          @foreach ($platforms as $t)
            <a href="{{ $t['href'] }}" class="btn-a" role="tab" style="{{ $t['style'] }}">{{ $t['label'] }}</a>
          @endforeach
        </div>
      </div>
      @forelse ($history as $h)
        <div style="padding: 10px 20px; border-top: 1px solid #F0EDE7; display: flex; flex-direction: column; gap: 2px">
          <span style="display: flex; justify-content: space-between; gap: 8px"><span style="font-size: 13px; font-weight: 700">{{ $h['what'] }}</span><span style="font-size: 12px; color: #66625B">{{ $h['t'] }}</span></span>
          <span style="font-size: 12px; color: #66625B">{{ $h['who'] }} · {{ $h['why'] }}</span>
        </div>
      @empty
        <p style="margin: 0; padding: 14px 20px 18px; font-size: 13px; color: #66625B">아직 바꾼 적이 없어요. 지금 값은 서버 .env 기본값이에요.</p>
      @endforelse
    </section>
    @endif
  </div>
    <form id="f-save" method="POST" action="{{ $saveAction }}" hidden>@csrf</form>
@endsection
