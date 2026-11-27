@extends('admin.layouts.app')
@section('title', 'AI · 미디어 작업')
@section('content')
@php($isVideo = !empty($video))

  <div style="flex: 999 1 560px; min-width: 0; padding: 28px clamp(16px, 3vw, 40px) 48px; box-sizing: border-box; display: flex; flex-direction: column; gap: 24px">
    <header style="display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 16px">
      <div style="display: flex; flex-direction: column; gap: 6px">
        <h1 style="margin: 0; font-size: 26px; font-weight: 900; letter-spacing: -0.5px">AI · 미디어 작업</h1>
        <p style="margin: 0; font-size: 14px; color: #66625B">{{ $isVideo ? '무빙 영상 변환의 큐 상태 · 단계 · 실패 원인을 보고, 다시 변환해요.' : '생성 작업의 큐 상태와 실패 원인을 보고, 사용 한도를 되돌려요.' }}</p>
        {{-- 종류: AI 생성 · 영상 변환(무빙) --}}
        <nav aria-label="작업 종류" style="display: flex; gap: 6px; margin-top: 6px">
          @foreach ([['AI 생성', route('admin.ai-jobs'), !$isVideo], ['영상 변환', route('admin.ai-jobs.videos'), $isVideo]] as [$label, $href, $on])
            <a href="{{ $href }}" class="btn-a" @if ($on) aria-current="page" @endif style="height: 34px; padding: 0 14px; border-radius: 999px; font-size: 13px; font-weight: 700; display: inline-flex; align-items: center; text-decoration: none; {{ $on ? 'background: #141413; color: #FFFFFF; border: 1px solid #141413' : 'background: #FFFFFF; color: #3A3731; border: 1px solid #D9D4CB' }}">{{ $label }}</a>
          @endforeach
        </nav>
      </div>
      <div style="display: flex; flex-wrap: wrap; gap: 8px"><span style="height: 40px; padding: 0 14px; border-radius: 10px; {{ $queueStyle }}; font-size: 13px; font-weight: 700; display: inline-flex; align-items: center; gap: 8px"><span style="width: 8px; height: 8px; border-radius: 50%; background: {{ $queueDot }}"></span>{{ $queueLabel }}</span></div>
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
    <section style="display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-start">
      <div style="background: #FFFFFF; border: 1px solid #E4E0D8; border-radius: 16px; flex: 3 1 600px; min-width: 0; overflow: hidden">
        <div role="tablist" style="padding: 14px 16px; display: flex; flex-wrap: wrap; gap: 6px; border-bottom: 1px solid #E4E0D8">
          @foreach ($tabs as $t)
            <a href="{{ $t['href'] }}" class="btn-a" role="tab" style="{{ $t['style'] }}">{{ $t['label'] }}</a>
          @endforeach
        </div>
        <div style="overflow-x: auto">
          <table style="width: 100%; min-width: 760px; border-collapse: collapse">
            <thead><tr><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">작업 ID</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">기능</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">회원</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">요청</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">소요</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">상태</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">{{ $isVideo ? '파일 · 길이' : '결과 전달' }}</th></tr></thead>
            <tbody>
              @forelse ($jobs as $j)
              <tr style="{{ $j['rowStyle'] }}">
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap; font-family: ui-monospace, Menlo, monospace; font-size: 13px"><a href="{{ $j['href'] }}" class="btn-a" style="padding: 0; border: 0; background: none; font-size: 14px; font-weight: 700; color: #141413; text-align: left">{{ $j['id'] }}</a></td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap">{{ $j['kind'] }}</td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap"><a href="{{ $j['whoHref'] }}" style="color: #141413; text-decoration: underline; text-underline-offset: 3px">{{ $j['who'] }}</a></td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap; color: #66625B">{{ $j['at'] }}</td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap; font-variant-numeric: tabular-nums">{{ $j['took'] }}</td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap"><span style="{{ $j['pill'] }}">{{ $j['state'] }}</span></td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap"><span style="{{ $j['dPill'] }}">{{ $j['delivery'] }}</span></td>
              </tr>
              @empty
              <tr><td colspan="7" style="padding: 32px 16px; font-size: 14px; color: #66625B; text-align: center">이 탭에 작업이 없어요.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
        <div style="padding: 14px 20px; display: flex; justify-content: flex-end">@include('admin.partials.pages')</div>
      </div>
      @if ($sel)
      <div style="background: #FFFFFF; border: 1px solid #E4E0D8; border-radius: 16px; flex: 2 1 340px; min-width: 0; padding: 20px; display: flex; flex-direction: column; gap: 16px">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 10px">
          <div style="display: flex; flex-direction: column; gap: 4px"><span style="font-size: 12px; color: #66625B; font-family: ui-monospace, Menlo, monospace">{{ $sel['id'] }}</span><h2 style="margin: 0; font-size: 17px; font-weight: 700">{{ $sel['kind'] }} · <a href="{{ $sel['whoHref'] }}" style="color: #141413; text-decoration: underline; text-underline-offset: 3px">{{ $sel['who'] }}</a></h2></div>
          <span style="{{ $sel['pill'] }}">{{ $sel['state'] }}</span>
        </div>
        <ol style="margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column">
          @foreach ($steps as $p)
            <li style="display: flex; gap: 12px; min-height: 44px">
              <span style="display: flex; flex-direction: column; align-items: center; width: 14px"><span style="{{ $p['dot'] }}"></span><span style="{{ $p['line'] }}"></span></span>
              <span style="flex: 1; display: flex; justify-content: space-between; gap: 8px; padding-bottom: 10px"><span style="font-size: 14px; font-weight: {{ $p['weight'] }}">{{ $p['label'] }}</span><span style="font-size: 12px; color: #66625B; font-variant-numeric: tabular-nums">{{ $p['t'] }}</span></span>
            </li>
          @endforeach
        </ol>
        @if ($sel['isFail'])
          <div style="border-radius: 12px; background: #FDE4DE; color: #6E2111; padding: 14px; display: flex; flex-direction: column; gap: 4px">
            <span style="font-size: 12px; font-weight: 700; font-family: ui-monospace, Menlo, monospace">{{ $sel['code'] }}</span>
            <span style="font-size: 14px; line-height: 1.5">{{ $sel['reason'] }}</span>
          </div>
        @endif
        @if ($isVideo)
          {{-- 무빙 영상 변환 상세 --}}
          <dl style="margin: 0; display: grid; grid-template-columns: auto 1fr; gap: 8px 14px; font-size: 13px">
            <dt style="color: #66625B">변환</dt><dd style="margin: 0; font-weight: 500; font-family: ui-monospace, Menlo, monospace">{{ $model }}</dd>
            <dt style="color: #66625B">파일</dt><dd style="margin: 0; font-weight: 500">{{ $sel['file'] }}</dd>
            <dt style="color: #66625B">화질</dt><dd style="margin: 0; font-weight: 500">{{ $sel['renditions'] }}</dd>
            <dt style="color: #66625B">시도</dt><dd style="margin: 0; font-weight: 500">{{ $sel['attempts'] }}</dd>
            <dt style="color: #66625B">원본</dt><dd style="margin: 0; font-weight: 500">{{ $sel['source'] }}</dd>
          </dl>
          <div style="display: flex; flex-wrap: wrap; gap: 8px">
            @if ($sel['playHref'])<a href="{{ $sel['playHref'] }}" target="_blank" rel="noopener" style="height: 40px; padding: 0 16px; border-radius: 10px; font-size: 14px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; border: 1px solid #D9D4CB; background: #FFFFFF; color: #141413; text-decoration: none; flex: 1 1 120px">▶ 영상 보기</a>@endif
            @if ($sel['targetHref'])<a href="{{ $sel['targetHref'] }}" target="_blank" rel="noopener" style="height: 40px; padding: 0 16px; border-radius: 10px; font-size: 14px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; border: 1px solid #D9D4CB; background: #FFFFFF; color: #141413; text-decoration: none; flex: 1 1 120px">게시물 보기</a>@endif
            @if ($sel['canRetry'])
              <button type="submit" form="f-retry" data-confirm="이 영상을 처음부터 다시 변환할까요? 끝나면 회원에게 알림이 가요." style="height: 40px; padding: 0 16px; border-radius: 10px; font-size: 14px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; border: 1px solid #141413; background: #141413; color: #FFFFFF; flex: 1 1 120px">다시 변환</button>
            @endif
          </div>
          @if ($sel['canRetry'])<form id="f-retry" method="POST" action="{{ $sel['retryAction'] }}" hidden>@csrf</form>@endif
          @error('retry')<span style="font-size: 13px; color: #A3301A">{{ $message }}</span>@enderror
          <span style="font-size: 12px; color: #66625B; line-height: 1.5">소요 = 다 올린 뒤부터 변환 끝까지(폰에서 올리는 시간 제외). 원본은 {{ config('starttoo.video_source_keep_days') }}일 보관 후 지워져 그 뒤에는 다시 변환할 수 없어요.</span>
        @else
        <dl style="margin: 0; display: grid; grid-template-columns: auto 1fr; gap: 8px 14px; font-size: 13px">
          <dt style="color: #66625B">모델</dt><dd style="margin: 0; font-weight: 500; font-family: ui-monospace, Menlo, monospace">{{ $model }}</dd>
          <dt style="color: #66625B">사진</dt><dd style="margin: 0; font-weight: 500">{{ $sel['source'] }}</dd>
          <dt style="color: #66625B">한도</dt><dd style="margin: 0; font-weight: 500">{{ $sel['quota'] }}</dd>
          <dt style="color: #66625B">원본 보관</dt><dd style="margin: 0; font-weight: 500">처리 후 바로 삭제 (신체 사진 원본은 보관하지 않아요)</dd>
        </dl>
        @if ($sel['canRestore'])
          <div style="display: flex; flex-wrap: wrap; gap: 8px">
            <button type="submit" form="f-restore" data-confirm="이 회원의 오늘 사용 횟수를 1회 돌려줄까요?" style="height: 40px; padding: 0 16px; border-radius: 10px; font-size: 14px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; gap: 6px; border: 1px solid #D9D4CB; background: #FFFFFF; color: #141413; flex: 1 1 120px">한도 복구</button>
          </div>
          <form id="f-restore" method="POST" action="{{ $sel['restoreAction'] }}" hidden>@csrf</form>
        @endif
        <span style="font-size: 12px; color: #66625B; line-height: 1.5">실패한 작업은 원본 사진을 남기지 않아 다시 실행할 수 없어요. 회원이 앱에서 다시 시도하면 돼요.</span>
        @endif
      </div>
      @endif
    </section>
  </div>
@endsection
