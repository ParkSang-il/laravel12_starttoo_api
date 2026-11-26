@extends('admin.layouts.app')
@section('title', '회원 관리')
@section('content')

  <div style="flex: 999 1 560px; min-width: 0; padding: 28px clamp(16px, 3vw, 40px) 48px; box-sizing: border-box; display: flex; flex-direction: column; gap: 24px">
    <header style="display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 16px">
      <div style="display: flex; flex-direction: column; gap: 6px">
        <h1 style="margin: 0; font-size: 26px; font-weight: 900; letter-spacing: -0.5px">회원 관리</h1>
        <p style="margin: 0; font-size: 14px; color: #66625B">회원 상태를 조회하고 정지 · 휴면 · 탈퇴를 관리해요.</p>
      </div>
      @if ($canExport)
        <div style="display: flex; flex-wrap: wrap; gap: 8px"><a href="{{ route('admin.members.export', request()->only('tab', 'q')) }}" class="btn-a" style="height: 40px; padding: 0 16px; border-radius: 10px; font-size: 14px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; border: 1px solid #D9D4CB; background: #FFFFFF; color: #141413">엑셀 내보내기</a></div>
      @endif
    </header>
    <section style="background: #FFFFFF; border: 1px solid #E4E0D8; border-radius: 16px; overflow: hidden">
      <div style="padding: 16px 20px; display: flex; flex-wrap: wrap; gap: 10px; align-items: center; border-bottom: 1px solid #E4E0D8">
        <form method="GET" action="{{ route('admin.members') }}" style="flex: 1 1 260px; display: flex">
          @if (request('tab'))<input type="hidden" name="tab" value="{{ request('tab') }}">@endif
          <label style="flex: 1; height: 40px; border-radius: 10px; border: 1px solid #D9D4CB; display: flex; align-items: center; gap: 8px; padding: 0 12px; color: #66625B">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
            <span style="position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0)">회원 검색</span>
            <input type="search" name="q" value="{{ $q }}" maxlength="40" placeholder="닉네임, 회원 ID" style="flex: 1; min-width: 0; border: 0; outline: none; font-size: 14px; color: #141413">
          </label>
        </form>
        <div style="display: flex; flex-wrap: wrap; gap: 6px">
          @foreach ($tabs as $t)
            <a href="{{ $t['href'] }}" class="btn-a" style="{{ $t['style'] }}">{{ $t['label'] }}</a>
          @endforeach
        </div>
      </div>
      <div style="overflow-x: auto">
        <table style="width: 100%; min-width: 980px; border-collapse: collapse">
          <thead><tr><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">회원 ID</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">닉네임</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">유형</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">가입 경로</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">가입일</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">최근 접속</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">신고 누적</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">상태</th></tr></thead>
          <tbody>
            @forelse ($rows as $r)
              <tr>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap; color: #66625B; font-variant-numeric: tabular-nums">@if ($r['profileHref'])<a href="{{ $r['profileHref'] }}" target="_blank" rel="noopener" title="공개 프로필 · 상점" style="color: #66625B; text-decoration: underline; text-underline-offset: 3px">{{ $r['id'] }}</a>@else{{ $r['id'] }}@endif</td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap; font-weight: 700">{{ $r['nick'] }}
                  <span style="display: block; margin-top: 2px; font-size: 12px; font-weight: 500"><a href="{{ $r['contentsHref'] }}" style="color: #1F4F99">쓴 글</a> · <a href="{{ $r['bookingsHref'] }}" style="color: #1F4F99">예약</a></span></td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap"><span style="{{ $r['typePill'] }}">{{ $r['type'] }}</span></td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap">{{ $r['via'] }}</td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap; color: #66625B">{{ $r['joined'] }}</td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap; color: #66625B">{{ $r['last'] }}</td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap">@if ($r['reportsHref'])<a href="{{ $r['reportsHref'] }}" style="color: #A3301A; font-weight: 700; text-decoration: underline; text-underline-offset: 3px">{{ $r['reports'] }}</a>@else{{ $r['reports'] }}@endif</td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap"><span style="{{ $r['statePill'] }}">{{ $r['state'] }}</span></td>
              </tr>
            @empty
              <tr><td colspan="8" style="padding: 32px 16px; font-size: 14px; color: #66625B; text-align: center">조건에 맞는 회원이 없어요.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div style="padding: 14px 20px; display: flex; justify-content: space-between; align-items: center; font-size: 13px; color: #66625B">
        <span>{{ $countLabel }}</span>
        @include('admin.partials.pages')
      </div>
    </section>
  </div>
@endsection
