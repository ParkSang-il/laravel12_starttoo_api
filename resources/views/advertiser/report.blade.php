@extends('advertiser.layouts.web')
@section('title', '광고 성과 보고 · '.$c->title)
@section('noindex', '1')
@section('top-right')
  @if ($final)<span class="adv-pill gray">종료 · 최종 보고</span>
  @elseif ($c->starts_at->isFuture())<span class="adv-pill blue">{{ $c->starts_at->format('m.d') }} 시작 예정</span>
  @else<span class="adv-pill green">노출 중 · 최대 {{ max(0, $totalDays - $shown) }}일 남음</span>@endif
@endsection

@section('content')
<main class="adv-wrap" style="padding-top: 40px; display: flex; flex-direction: column; gap: 24px">
  <div style="display: flex; flex-direction: column; gap: 6px">
    <span style="font-size: 13px; color: #66625B">광고 성과 보고 · {{ $c->company }}</span>
    <h1 style="margin: 0; font-size: clamp(26px, 3vw, 34px); font-weight: 900; letter-spacing: -0.5px">{{ $c->title }}</h1>
    <span style="font-size: 14px; color: #55524C">{{ $c->starts_at->format('Y.m.d') }} ~ 최대 {{ $c->ends_at->format('m.d') }} · {{ $totalDays }}일 @if ($final) · 최종 보고 @else 중 {{ $shown }}일 반영 · 중간 보고 @endif</span>
  </div>

  @if ($final)
    <div style="border-radius: 14px; background: #141413; color: #F2EFEA; padding: 18px 22px; display: flex; flex-wrap: wrap; align-items: center; gap: 12px 24px">
      <span style="flex: 1 1 320px; font-size: 15px; line-height: 1.6"><b>캠페인이 끝났어요.</b> {{ \App\Services\Starttoo\AdSales::short($totImp) }} 번 보였고 {{ number_format($totClk) }}번 눌렸어요.</span>
      <a href="{{ route('advertiser.inquiry') }}" class="adv-btn is-accent is-sm adv-noprint">다시 집행 문의</a>
    </div>
  @endif

  <section aria-label="요약" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(220px, 100%), 1fr)); gap: 14px">
    @foreach ($kpis as $k)
      <div class="adv-card" style="padding: 20px 22px; display: flex; flex-direction: column; gap: 8px">
        <span style="font-size: 13px; color: #66625B">{{ $k['label'] }}</span>
        <strong class="adv-display adv-num" style="font-size: 28px; letter-spacing: -1px">{{ $k['v'] }}</strong>
        <span style="font-size: 12px; color: #77736B">{{ $k['sub'] }}</span>
        @isset($k['bar'])<span style="height: 6px; border-radius: 3px; background: #ECEAE5; overflow: hidden; display: block" role="progressbar" aria-valuenow="{{ $k['bar'] }}" aria-valuemin="0" aria-valuemax="100" aria-label="{{ $k['label'] }}"><span style="display: block; height: 100%; width: {{ $k['bar'] }}%; background: {{ $k['bar'] >= 100 ? '#1D6B3A' : '#FF5A36' }}"></span></span>@endisset
      </div>
    @endforeach
  </section>

  <section class="adv-card" style="padding: 22px 24px; display: flex; flex-direction: column; gap: 16px">
    <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px">
      <h2 style="margin: 0; font-size: 17px; font-weight: 700">일별 {{ $metricLabel }}</h2>
      <nav aria-label="지표" class="adv-noprint" style="display: flex; gap: 6px">
        @foreach (['imp' => '노출', 'clk' => '클릭', 'ctr' => '클릭률'] as $k => $l)
          <a href="{{ request()->fullUrlWithQuery(['metric' => $k]) }}" @if ($k === $metric) aria-current="true" @endif style="min-height: 36px; padding: 0 14px; border-radius: 10px; font-size: 14px; font-weight: 700; display: inline-flex; align-items: center; text-decoration: none; {{ $k === $metric ? 'background: #141413; color: #FFFFFF; border: 1px solid #141413' : 'background: #FFFFFF; color: #3A3731; border: 1px solid #D9D4CB' }}">{{ $l }}</a>
        @endforeach
      </nav>
    </div>
    <div role="img" aria-label="{{ $chartAria }}" style="height: 220px; display: grid; grid-template-columns: repeat({{ count($bars) }}, minmax(0, 1fr)); gap: {{ count($bars) > 21 ? 3 : 8 }}px; align-items: end; padding-top: 18px">
      @foreach ($bars as $b)
        <div style="height: 100%; display: flex; flex-direction: column; justify-content: flex-end; align-items: center; gap: 6px">
          <span class="adv-num" style="font-size: 11px; color: #55524C; white-space: nowrap">{{ count($bars) > 21 ? '' : $b['v'] }}</span>
          <span style="{{ $b['style'] }}"></span>
          <span style="font-size: 11px; color: #77736B">{{ count($bars) > 31 && $loop->index % 3 ? '' : $b['d'] }}</span>
        </div>
      @endforeach
    </div>
    @unless ($final)<span style="font-size: 12px; color: #77736B">회색 칸은 아직 지나지 않은 날이에요. 보장 노출을 먼저 채우면 그날 광고가 끝나요.</span>@endunless
  </section>

  <section class="adv-card" style="overflow: hidden">
    <div style="padding: 20px 24px 6px"><h2 style="margin: 0; font-size: 17px; font-weight: 700">자리별 성과</h2></div>
    <div style="overflow-x: auto">
      <table style="width: 100%; min-width: 760px; border-collapse: collapse">
        <thead><tr><th class="adv-th" style="padding-left: 24px">자리</th><th class="adv-th">단가 기준</th><th class="adv-th" style="text-align: right">노출</th><th class="adv-th" style="text-align: right">클릭</th><th class="adv-th" style="text-align: right">클릭률</th><th class="adv-th" style="padding-right: 24px">목표 대비</th></tr></thead>
        <tbody>
          @foreach ($placements as $p)
            <tr>
              <td class="adv-td" style="padding-left: 24px"><b>{{ $p['name'] }}</b></td>
              <td class="adv-td">{{ $p['unit'] }}</td>
              <td class="adv-td adv-num" style="text-align: right">{{ number_format($p['imp']) }}</td>
              <td class="adv-td adv-num" style="text-align: right">{{ number_format($p['clk']) }}</td>
              <td class="adv-td adv-num" style="text-align: right">{{ number_format($p['ctr'], 2) }}%</td>
              <td class="adv-td" style="padding-right: 24px; min-width: 180px"><span style="display: flex; flex-direction: column; gap: 6px"><span style="font-size: 13px">{{ $p['goal'] }}</span><span style="height: 6px; border-radius: 3px; background: #ECEAE5; overflow: hidden; display: block"><span style="display: block; height: 100%; width: {{ $p['goalPct'] }}%; background: {{ $p['goalPct'] >= 100 ? '#1D6B3A' : '#141413' }}"></span></span></span></td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </section>

  @if ($creatives->count() > 1)
    <section style="display: flex; flex-direction: column; gap: 12px">
      <h2 style="margin: 0; font-size: 17px; font-weight: 700">소재별 성과</h2>
      <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(min(260px, 100%), 1fr)); gap: 14px">
        @foreach ($creatives as $cr)
          <div class="adv-card" style="padding: 14px; display: flex; gap: 14px; align-items: center">
            <span aria-hidden="true" style="width: 72px; height: 90px; border-radius: 8px; background: #D9CFC4; flex-shrink: 0; position: relative; overflow: hidden">
              @if ($cr['thumb'])<img src="{{ $cr['thumb'] }}" alt="" style="width: 100%; height: 100%; object-fit: cover; display: block">@endif
              <span style="position: absolute; left: 6px; top: 6px; height: 16px; padding: 0 5px; border-radius: 4px; background: #F2EFEA; color: #141413; font-size: 9px; font-weight: 900; display: flex; align-items: center">광고</span>
            </span>
            <span style="flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 4px"><b style="font-size: 14px; overflow-wrap: anywhere">{{ $cr['creative'] }}</b><span style="font-size: 12px; color: #66625B">{{ $slots[$cr['slot']]['name'] ?? $cr['slot'] }}</span><span class="adv-num" style="font-size: 13px">노출 {{ number_format($cr['imp']) }} · 클릭률 <b>{{ $cr['imp'] ? number_format($cr['clk'] / $cr['imp'] * 100, 1) : '0' }}%</b></span></span>
          </div>
        @endforeach
      </div>
    </section>
  @endif

  <section style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-start; gap: 16px; padding: 18px 20px; border-radius: 14px; background: #ECEAE5">
    <div style="flex: 1 1 420px; display: flex; flex-direction: column; gap: 6px; font-size: 12px; color: #55524C; line-height: 1.7">
      <b style="font-size: 13px; color: #141413">집계 기준</b>
      <span>노출 · 클릭은 앱 광고 기록(하루 단위)을 그대로 더한 값이에요 · 전날까지 반영해요 · 보장 노출은 오늘 기록까지 세어 채우는 즉시 광고를 멈춰요</span>
      <span>이 페이지는 광고주 계정으로 로그인해야 열려요. 담당자 외에는 링크를 공유하지 말아주세요.</span>
    </div>
    <div class="adv-noprint" style="display: flex; gap: 8px"><button type="button" onclick="window.print()" class="adv-btn is-ghost is-sm">PDF 저장 · 인쇄</button></div>
  </section>
</main>
@endsection
