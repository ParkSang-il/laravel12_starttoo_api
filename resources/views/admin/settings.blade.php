@extends('admin.layouts.app')
@section('title', '운영 설정 · 감사 로그')
@section('content')

  <div style="flex: 999 1 560px; min-width: 0; padding: 28px clamp(16px, 3vw, 40px) 48px; box-sizing: border-box; display: flex; flex-direction: column; gap: 24px">
    <header style="display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 16px">
      <div style="display: flex; flex-direction: column; gap: 6px">
        <h1 style="margin: 0; font-size: 26px; font-weight: 900; letter-spacing: -0.5px">운영 설정 · 감사 로그</h1>
        <p style="margin: 0; font-size: 14px; color: #66625B">관리자 권한과 운영 정책을 설정하고, 모든 관리자 작업 이력을 확인해요.</p>
      </div>
      @if ($canExport && $section === '감사 로그')
        <div style="display: flex; flex-wrap: wrap; gap: 8px"><a href="{{ route('admin.settings.logs.export', request()->only('tab')) }}" class="btn-a" style="height: 40px; padding: 0 16px; border-radius: 10px; font-size: 14px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; border: 1px solid #D9D4CB; background: #FFFFFF; color: #141413">로그 내보내기</a></div>
      @endif
    </header>

    @include('admin.partials.sections', ['sectionsLabel' => '운영 설정 항목'])

    @if ($section === '관리자 계정 · 권한')
      <div style="background: #FFFFFF; border: 1px solid #E4E0D8; border-radius: 16px; min-width: 0; overflow: hidden">
        <div style="padding: 18px 20px; display: flex; justify-content: space-between; align-items: center">
          <h2 style="margin: 0; font-size: 17px; font-weight: 700">관리자 계정 · 권한</h2>
        </div>
        <div style="overflow-x: auto">
          <table style="width: 100%; min-width: 440px; border-collapse: collapse">
            <thead><tr><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">이름</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">역할</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">2단계 인증</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">최근 접속</th></tr></thead>
            <tbody>
              @foreach ($admins as $a)
                <tr><td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap; font-weight: 700">{{ $a['name'] }}</td><td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap"><span style="{{ $a['rolePill'] }}">{{ $a['role'] }}</span></td><td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap"><span style="{{ $a['mfaPill'] }}">{{ $a['mfa'] }}</span></td><td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap; color: #66625B">{{ $a['last'] }}</td></tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </div>
    @endif

    @if ($section === '운영 정책')
      <div style="background: #FFFFFF; border: 1px solid #E4E0D8; border-radius: 16px; min-width: 0; padding: 20px; display: flex; flex-direction: column; gap: 4px">
        <h2 style="margin: 0 0 10px; font-size: 17px; font-weight: 700">운영 정책</h2>
        @foreach ($policies as $p)
          <div style="min-height: 60px; display: flex; align-items: center; gap: 12px; border-bottom: 1px solid #F0EDE7">
            <span style="flex: 1; display: flex; flex-direction: column; gap: 2px"><span style="font-size: 14px; font-weight: 700">{{ $p['label'] }}</span><span style="font-size: 12px; color: #66625B">{{ $p['desc'] }}</span></span>
            @if ($p['canToggle'])
              <form method="POST" action="{{ $p['action'] }}" style="display: contents">@csrf<button type="submit" role="switch" aria-checked="{{ $p['on'] ? 'true' : 'false' }}" aria-label="{{ $p['label'] }}" style="{{ $p['track'] }}"><span style="width: 20px; height: 20px; border-radius: 50%; background: #FFFFFF; display: block"></span></button></form>
            @else
              <span role="switch" aria-checked="{{ $p['on'] ? 'true' : 'false' }}" aria-disabled="true" aria-label="{{ $p['label'] }}" style="{{ $p['track'] }}"><span style="width: 20px; height: 20px; border-radius: 50%; background: #FFFFFF; display: block"></span></span>
            @endif
          </div>
        @endforeach
      </div>
    @endif

    @if ($section === '감사 로그')
    <section style="background: #FFFFFF; border: 1px solid #E4E0D8; border-radius: 16px; overflow: hidden">
      <div style="padding: 16px 20px; display: flex; flex-wrap: wrap; gap: 10px; justify-content: space-between; align-items: center; border-bottom: 1px solid #E4E0D8">
        <h2 style="margin: 0; font-size: 17px; font-weight: 700">감사 로그</h2>
        <div style="display: flex; flex-wrap: wrap; gap: 6px">
          @foreach ($tabs as $t)
            <a href="{{ $t['href'] }}" class="btn-a" style="{{ $t['style'] }}">{{ $t['label'] }}</a>
          @endforeach
        </div>
      </div>
      <div style="overflow-x: auto">
        <table style="width: 100%; min-width: 900px; border-collapse: collapse">
          <thead><tr><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">시간</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">관리자</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">구분</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">작업</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">대상</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">IP</th></tr></thead>
          <tbody>
            @forelse ($logs as $l)
              <tr>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap; color: #66625B; font-variant-numeric: tabular-nums">{{ $l['t'] }}</td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap">{{ $l['who'] }}</td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap"><span style="{{ $l['pill'] }}">{{ $l['cat'] }}</span></td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap; font-weight: 700">{{ $l['what'] }}</td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap">{{ $l['target'] }}</td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap; color: #66625B; font-variant-numeric: tabular-nums">{{ $l['ip'] }}</td>
              </tr>
            @empty
              <tr><td colspan="6" style="padding: 32px 16px; font-size: 14px; color: #66625B; text-align: center">기록이 없어요.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div style="padding: 14px 20px; display: flex; justify-content: flex-end">@include('admin.partials.pages')</div>
    </section>
    @endif
  </div>
@endsection
