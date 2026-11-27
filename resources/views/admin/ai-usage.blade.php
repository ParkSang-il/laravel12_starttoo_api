@extends('admin.layouts.app')
@section('title', 'AI 사용량 · 비용')
@section('content')

  <div style="flex: 999 1 560px; min-width: 0; padding: 28px clamp(16px, 3vw, 40px) 48px; box-sizing: border-box; display: flex; flex-direction: column; gap: 24px">
    <header style="display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 16px">
      <div style="display: flex; flex-direction: column; gap: 6px">
        <h1 style="margin: 0; font-size: 26px; font-weight: 900; letter-spacing: -0.5px">AI 사용량 · 비용</h1>
        <p style="margin: 0; font-size: 14px; color: #66625B">Nano Banana 호출량과 비용을 보고, 회원별 하루 사용 한도를 정해요.</p>
      </div>
      <div style="display: flex; flex-wrap: wrap; gap: 8px"><span style="height: 40px; padding: 0 14px; border-radius: 10px; background: #FFFFFF; border: 1px solid #E4E0D8; font-size: 13px; font-weight: 500; display: inline-flex; align-items: center; color: #55524C">{{ $today }}</span></div>
    </header>
    <section style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(200px, 100%), 1fr)); gap: 14px">
      @foreach ($kpis as $k)
        <div style="background: #FFFFFF; border: 1px solid #E4E0D8; border-radius: 16px; padding: 18px 20px; display: flex; flex-direction: column; gap: 10px">
          <span style="font-size: 13px; color: #66625B; font-weight: 500">{{ $k['label'] }}</span>
          <strong style="font-family: Unbounded, 'Arial Black', sans-serif; font-size: 26px; font-weight: 700; letter-spacing: -1px">{{ $k['value'] }}</strong>
          <span style="{{ $k['subStyle'] }}">{{ $k['sub'] }}</span>
        </div>
      @endforeach
    </section>

    @include('admin.partials.sections', ['sectionsLabel' => 'AI 사용량 항목'])

    <section style="display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-start">
      @if ($section === '사용량')
      <div style="background: #FFFFFF; border: 1px solid #E4E0D8; border-radius: 16px; flex: 3 1 560px; min-width: 0; overflow: hidden">
        <div style="padding: 18px 20px 6px; display: flex; flex-direction: column; gap: 4px">
          <h2 style="margin: 0; font-size: 17px; font-weight: 700">기능별 사용량 · 오늘</h2>
          <span style="font-size: 13px; color: #66625B">타투 필러 3안은 한 번의 요청으로 계산해요(AI 3번이라 비용은 3배). 기기에서 만든 결과는 세지 않아요.</span>
        </div>
        <div style="overflow-x: auto">
          <table style="width: 100%; min-width: 640px; border-collapse: collapse">
            <thead><tr><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">기능</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">요청</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">성공률</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">1회 비용</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">오늘 비용</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">한도 차감</th></tr></thead>
            <tbody>
              @foreach ($features as $f)
              <tr>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap; font-weight: 700">{{ $f['name'] }}</td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap; font-variant-numeric: tabular-nums">{{ $f['req'] }}</td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap; font-variant-numeric: tabular-nums">{{ $f['ok'] }}</td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap; color: #66625B">{{ $f['unit'] }}</td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap; font-variant-numeric: tabular-nums; font-weight: 700">{{ $f['cost'] }}</td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap"><span style="{{ $f['pill'] }}">{{ $f['quota'] }}</span></td>
              </tr>
              @endforeach
            </tbody>
          </table>
        </div>
        <div style="padding: 18px 20px; border-top: 1px solid #E4E0D8; display: flex; flex-direction: column; gap: 14px">
          <div style="display: flex; justify-content: space-between; align-items: baseline"><h2 style="margin: 0; font-size: 15px; font-weight: 700">{{ $barsTitle }}</h2><span style="font-size: 13px; color: #66625B">최근 7일@if ($hasBudget) · 이번 달 예산 대비 <b style="color: #141413">{{ $budgetPct }}</b>@endif</span></div>
          <div style="height: 150px; display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 14px; align-items: end">
            @foreach ($bars as $b)
              <div style="display: flex; flex-direction: column; align-items: center; gap: 6px; height: 100%; justify-content: flex-end">
                <span style="font-size: 11px; font-weight: 700">{{ $b['v'] }}</span>
                <span style="{{ $b['style'] }}"></span>
                <span style="font-size: 12px; color: #66625B">{{ $b['d'] }}</span>
              </div>
            @endforeach
          </div>
          @if ($hasBudget)
            <div style="height: 10px; border-radius: 5px; background: #ECEAE5; overflow: hidden"><span style="{{ $budgetBar }}"></span></div>
            <span style="font-size: 13px; color: #66625B">이번 달 누적 {{ $monthCost }} / 예산 {{ $budget }} · {{ $budgetNote }}</span>
          @else
            <span style="font-size: 13px; color: #66625B">월 예산이 없어 예산 대비는 보여 주지 않아요(.env STARTTOO_AI_MONTHLY_BUDGET).</span>
          @endif
        </div>
      </div>
      @endif
      @if ($section === '사용 한도 정책')
      <div style="flex: 1 1 340px; min-width: 0; display: flex; flex-direction: column; gap: 14px">
        <div style="background: #FFFFFF; border: 1px solid #E4E0D8; border-radius: 16px; padding: 20px; display: flex; flex-direction: column; gap: 16px">
          <h2 style="margin: 0; font-size: 17px; font-weight: 700">사용 한도 정책</h2>
          @foreach ($limits as $l)
            <div style="display: flex; align-items: center; gap: 12px">
              <span style="flex: 1; display: flex; flex-direction: column; gap: 2px"><span style="font-size: 14px; font-weight: 700">{{ $l['label'] }}</span><span style="font-size: 12px; color: #66625B">{{ $l['note'] }}</span></span>
              <span style="display: inline-flex; align-items: center; border: 1px solid #D9D4CB; border-radius: 10px; overflow: hidden">
                @if ($canManage)<form method="POST" action="{{ $l['decAction'] }}" style="display: contents">@csrf<button type="submit" aria-label="{{ $l['decAria'] }}" style="width: 36px; height: 36px; border: 0; background: #FFFFFF; font-size: 18px">−</button></form>@endif
                <span style="min-width: 52px; text-align: center; font-size: 14px; font-weight: 700; font-variant-numeric: tabular-nums; {{ $canManage ? '' : 'padding: 8px 10px' }}">{{ $l['value'] }}</span>
                @if ($canManage)<form method="POST" action="{{ $l['incAction'] }}" style="display: contents">@csrf<button type="submit" aria-label="{{ $l['incAria'] }}" style="width: 36px; height: 36px; border: 0; background: #FFFFFF; font-size: 18px">+</button></form>@endif
              </span>
            </div>
          @endforeach
          <div style="height: 1px; background: #F0EDE7"></div>
          @foreach ($guards as $g)
            <div style="display: flex; align-items: center; gap: 12px">
              <span style="flex: 1; display: flex; flex-direction: column; gap: 2px"><span style="font-size: 14px; font-weight: 700">{{ $g['label'] }}</span><span style="font-size: 12px; color: #66625B">{{ $g['note'] }}</span></span>
              @if (! $g['locked'])
                <form method="POST" action="{{ $g['action'] }}" style="display: contents">@csrf<button type="submit" role="switch" aria-checked="{{ $g['on'] ? 'true' : 'false' }}" aria-label="{{ $g['aria'] }}" style="{{ $g['track'] }}"><span style="{{ $knob }}"></span></button></form>
              @else
                <span role="switch" aria-checked="{{ $g['on'] ? 'true' : 'false' }}" aria-disabled="true" aria-label="{{ $g['aria'] }}" style="{{ $g['track'] }}"><span style="{{ $knob }}"></span></span>
              @endif
            </div>
          @endforeach
          <div style="height: 1px; background: #F0EDE7"></div>
          <div style="display: flex; flex-direction: column; gap: 8px">
            <span style="font-size: 13px; font-weight: 700; color: #3A3731">생성 모델</span>
            <span style="font-size: 12px; color: #66625B; font-family: ui-monospace, Menlo, monospace">{{ $modelCode }}</span>
            <span style="font-size: 12px; color: #66625B">바꾸려면 .env STARTTOO_GEMINI_BLEND_MODEL</span>
          </div>
        </div>
      </div>
      @endif
    </section>
  </div>
@endsection
