@extends('admin.layouts.app')
@section('title', '운영 대시보드')
@section('content')

  <div style="flex: 999 1 560px; min-width: 0; padding: 28px clamp(16px, 3vw, 40px) 48px; box-sizing: border-box; display: flex; flex-direction: column; gap: 24px">
    <header style="display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 16px">
      <div style="display: flex; flex-direction: column; gap: 6px">
        <h1 style="margin: 0; font-size: 26px; font-weight: 900; letter-spacing: -0.5px">운영 대시보드</h1>
        <p style="margin: 0; font-size: 14px; color: #66625B">서비스 핵심 지표와 처리 대기 업무를 한눈에 확인해요.</p>
      </div>
      <div style="display: flex; flex-wrap: wrap; gap: 8px"><span style="height: 40px; padding: 0 14px; border-radius: 10px; background: #FFFFFF; border: 1px solid #E4E0D8; font-size: 13px; font-weight: 500; display: inline-flex; align-items: center; color: #55524C">{{ $today }}</span></div>
    </header>
    <section style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(220px, 100%), 1fr)); gap: 14px">
      @foreach ($kpis as $k)
        <div style="background: #FFFFFF; border: 1px solid #E4E0D8; border-radius: 16px; padding: 18px 20px; display: flex; flex-direction: column; gap: 10px">
          <span style="font-size: 13px; color: #66625B; font-weight: 500">{{ $k['label'] }}</span>
          <strong style="font-family: Unbounded, 'Arial Black', sans-serif; font-size: 28px; font-weight: 700; letter-spacing: -1px">{{ $k['value'] }}</strong>
          <span style="{{ $k['deltaStyle'] }}">{{ $k['delta'] }}</span>
        </div>
      @endforeach
    </section>

    <section style="display: flex; flex-wrap: wrap; gap: 14px">
      <div style="background: #FFFFFF; border: 1px solid #E4E0D8; border-radius: 16px; padding: 20px; flex: 2 1 480px; min-width: 0; display: flex; flex-direction: column; gap: 18px">
        <div style="display: flex; justify-content: space-between; align-items: baseline">
          <h2 style="margin: 0; font-size: 16px; font-weight: 700">일별 작품 업로드</h2>
          <span style="font-size: 12px; color: #66625B">최근 7일</span>
        </div>
        <div style="height: 200px; display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 14px; align-items: end">
          @foreach ($bars as $b)
            <div style="display: flex; flex-direction: column; align-items: center; gap: 8px; height: 100%; justify-content: flex-end">
              <span style="font-size: 12px; font-weight: 700">{{ $b['v'] }}</span>
              <span style="{{ $b['style'] }}"></span>
              <span style="font-size: 12px; color: #66625B">{{ $b['d'] }}</span>
            </div>
          @endforeach
        </div>
      </div>
      <div style="background: #FFFFFF; border: 1px solid #E4E0D8; border-radius: 16px; padding: 20px; flex: 1 1 300px; min-width: 0; display: flex; flex-direction: column; gap: 6px">
        <h2 style="margin: 0 0 8px; font-size: 16px; font-weight: 700">처리 대기</h2>
        @foreach ($queue as $q)
          <a href="{{ $q['href'] }}" style="height: 56px; display: flex; align-items: center; gap: 12px; border-bottom: 1px solid #F0EDE7">
            <span style="flex: 1; font-size: 14px; font-weight: 500">{{ $q['label'] }}</span>
            <span style="{{ $q['pill'] }}">{{ $q['count'] }}</span>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#66625B" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
          </a>
        @endforeach
      </div>
    </section>

    <section style="background: #FFFFFF; border: 1px solid #E4E0D8; border-radius: 16px; overflow: hidden">
      <div style="padding: 18px 20px; display: flex; justify-content: space-between; align-items: baseline">
        <h2 style="margin: 0; font-size: 16px; font-weight: 700">최근 운영 활동</h2>
        <a href="{{ route('admin.settings') }}" style="font-size: 13px; color: #66625B">감사 로그 전체</a>
      </div>
      <div style="overflow-x: auto">
        <table style="width: 100%; min-width: 760px; border-collapse: collapse">
          <thead><tr><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">시간</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">관리자</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">작업</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">대상</th></tr></thead>
          <tbody>
            @forelse ($logs as $l)
              <tr><td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap; color: #66625B">{{ $l['t'] }}</td><td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap">{{ $l['who'] }}</td><td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap; font-weight: 700">{{ $l['what'] }}</td><td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap">{{ $l['target'] }}</td></tr>
            @empty
              <tr><td colspan="4" style="padding: 24px 16px; font-size: 14px; color: #66625B; text-align: center">아직 운영 활동 기록이 없어요.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </section>
  </div>
@endsection
