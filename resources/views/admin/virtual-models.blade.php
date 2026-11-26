@extends('admin.layouts.app')
@section('title', '가상 모델 라이브러리')
@section('content')

  <div style="flex: 999 1 560px; min-width: 0; padding: 28px clamp(16px, 3vw, 40px) 48px; box-sizing: border-box; display: flex; flex-direction: column; gap: 24px">
    <header style="display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 16px">
      <div style="display: flex; flex-direction: column; gap: 6px">
        <h1 style="margin: 0; font-size: 26px; font-weight: 900; letter-spacing: -0.5px">가상 모델 라이브러리</h1>
        <p style="margin: 0; font-size: 14px; color: #66625B">사진 없이 피팅할 때 쓰는 부위별 모델 사진을 관리해요.</p>
      </div>
    </header>
    <section style="display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-start">
      <div style="background: #FFFFFF; border: 1px solid #E4E0D8; border-radius: 16px; flex: 3 1 600px; min-width: 0; overflow: hidden">
        <div role="tablist" style="padding: 14px 16px; display: flex; flex-wrap: wrap; gap: 6px; border-bottom: 1px solid #E4E0D8">
          @foreach ($parts as $t)
            <a href="{{ $t['href'] }}" class="btn-a" role="tab" style="{{ $t['style'] }}">{{ $t['label'] }}</a>
          @endforeach
        </div>
        <div style="padding: 12px 16px; display: flex; flex-wrap: wrap; align-items: center; gap: 6px; border-bottom: 1px solid #E4E0D8">
          <span style="font-size: 12px; font-weight: 700; color: #66625B; margin-right: 4px">성별</span>
          @foreach ($genders as $t)<a href="{{ $t['href'] }}" class="btn-a" style="{{ $t['style'] }}">{{ $t['label'] }}</a>@endforeach
          <span style="font-size: 12px; font-weight: 700; color: #66625B; margin: 0 4px 0 10px">피부 톤</span>
          @foreach ($tones as $t)<a href="{{ $t['href'] }}" class="btn-a" style="{{ $t['style'] }}">{{ $t['label'] }}</a>@endforeach
          <span style="margin-left: auto; font-size: 13px; color: #66625B">{{ $countLabel }}</span>
        </div>
        <div style="padding: 16px; display: grid; grid-template-columns: repeat(auto-fill, minmax(min(170px, 100%), 1fr)); gap: 12px">
          @forelse ($models as $m)
            <div style="{{ $m['cardStyle'] }}">
              <a href="{{ $m['href'] }}" class="btn-a" aria-label="{{ $m['code'] }} 자세히" style="padding: 0; border: 0; display: block; width: 100%; {{ $m['thumb'] }}">
                @if ($m['image'])<img src="{{ $m['image'] }}" alt="" loading="lazy" style="position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover">@endif
                <span style="position: absolute; left: 8px; bottom: 8px; {{ $m['lmPill'] }}">{{ $m['lm'] }}</span>
              </a>
              <div style="padding: 10px 12px; display: flex; align-items: center; gap: 8px">
                <span style="flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px"><span style="font-size: 13px; font-weight: 700; font-family: ui-monospace, Menlo, monospace">{{ $m['code'] }}</span><span style="font-size: 12px; color: #66625B">{{ $m['face'] }} · 사용 {{ $m['uses'] }}</span></span>
                @if ($m['canToggle'])
                  <form method="POST" action="{{ $m['action'] }}" style="display: contents">@csrf<button type="submit" role="switch" aria-checked="{{ $m['on'] ? 'true' : 'false' }}" aria-label="{{ $m['aria'] }}" style="{{ $m['track'] }}"><span style="{{ $knob }}"></span></button></form>
                @else
                  <span role="switch" aria-checked="{{ $m['on'] ? 'true' : 'false' }}" aria-disabled="true" aria-label="{{ $m['aria'] }}" style="{{ $m['track'] }}"><span style="{{ $knob }}"></span></span>
                @endif
              </div>
            </div>
          @empty
            <p style="grid-column: 1 / -1; margin: 0; padding: 32px 16px; font-size: 14px; color: #66625B; text-align: center">이 조건의 모델 사진이 없어요.</p>
          @endforelse
        </div>
      </div>
      @if ($sel)
      <div style="background: #FFFFFF; border: 1px solid #E4E0D8; border-radius: 16px; flex: 2 1 320px; min-width: 0; padding: 20px; display: flex; flex-direction: column; gap: 16px">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px">
          <div style="display: flex; flex-direction: column; gap: 4px"><span style="font-size: 13px; color: #66625B">{{ $sel['partLabel'] }} · {{ $sel['toneLabel'] }}</span><h2 style="margin: 0; font-size: 17px; font-weight: 700; font-family: ui-monospace, Menlo, monospace">{{ $sel['code'] }}</h2></div>
          <span style="{{ $sel['pill'] }}">{{ $sel['state'] }}</span>
        </div>
        <span style="{{ $sel['big'] }}">@if ($sel['image'])<img src="{{ $sel['image'] }}" alt="{{ $sel['code'] }}" style="position: absolute; inset: 0; width: 100%; height: 100%; object-fit: contain">@endif</span>
        <dl style="margin: 0; display: grid; grid-template-columns: auto 1fr; gap: 8px 14px; font-size: 13px; line-height: 1.5">
          <dt style="color: #66625B">피부 영역</dt><dd style="margin: 0; font-weight: 700">{{ $sel['skin'] }}</dd>
          <dt style="color: #66625B">사진 출처</dt><dd style="margin: 0">{{ $sel['source'] }}</dd>
          <dt style="color: #66625B">촬영</dt><dd style="margin: 0">{{ $sel['shot'] }}</dd>
          <dt style="color: #66625B">노출 순서</dt><dd style="margin: 0">{{ $sel['order'] }}</dd>
        </dl>
        @if ($sel['canRedo'])
          <div style="display: flex; flex-wrap: wrap; gap: 8px">
            <form method="POST" action="{{ $sel['redoAction'] }}" style="display: contents">@csrf<button type="submit" data-confirm="같은 조건으로 AI 사진을 새로 만들까요? 지금 사진은 바뀌고 AI 비용이 1회 들어요." style="height: 40px; padding: 0 16px; border-radius: 10px; font-size: 14px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; gap: 6px; border: 1px solid #D9D4CB; background: #FFFFFF; color: #141413; flex: 1 1 120px">사진 교체</button></form>
          </div>
        @endif
      </div>
      @endif
    </section>
  </div>
@endsection
