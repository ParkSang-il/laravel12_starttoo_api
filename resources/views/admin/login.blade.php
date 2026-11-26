@extends('admin.layouts.guest')
@section('title', '관리자 로그인')
@section('content')
<div style="min-height: 100vh; background: #F4F2EE; color: #141413; font-family: 'Noto Sans KR', system-ui, sans-serif; display: flex; flex-wrap: wrap">
  <section style="flex: 1 1 420px; background: #0E0E0F; color: #F2EFEA; padding: clamp(32px, 6vw, 72px); box-sizing: border-box; display: flex; flex-direction: column; justify-content: space-between; gap: 48px; position: relative; overflow: hidden">
    <span style="font-family: Unbounded, 'Arial Black', sans-serif; font-weight: 800; font-size: 20px; letter-spacing: -0.5px">STARTTOO<span style="color: #FF5A36">.</span></span>
    <div style="display: flex; flex-direction: column; gap: 16px; position: relative">
      <span style="font-family: Unbounded, 'Arial Black', sans-serif; font-size: clamp(40px, 5vw, 64px); font-weight: 800; line-height: 0.95; letter-spacing: -2px">ADMIN<br>CONSOLE<span style="color: #FF5A36">.</span></span>
      <p style="margin: 0; font-size: 15px; line-height: 1.6; color: #BDB8B0; max-width: 360px">작가 심사, 콘텐츠 · 신고 처리, 예약 모니터링을 위한 운영자 전용 화면입니다.</p>
    </div>
    <span style="font-size: 12px; color: #8E8A84">허가된 운영자만 접속할 수 있습니다.</span>
    <div style="position: absolute; right: -120px; bottom: -120px; width: 360px; height: 360px; border-radius: 50%; border: 1.5px solid #232326" aria-hidden="true"></div>
  </section>
  <section style="flex: 999 1 480px; display: flex; align-items: center; justify-content: center; padding: 48px 20px; box-sizing: border-box">
    <form method="POST" action="{{ route('admin.login.submit') }}" style="width: 100%; max-width: 400px; display: flex; flex-direction: column; gap: 18px">
      @csrf
      @if ($errors->any())<p role="alert" style="margin: 0; padding: 12px 14px; border-radius: 10px; background: #FDE4DE; color: #A3301A; font-size: 13px; font-weight: 700">{{ $errors->first() }}</p>@endif
      <div style="display: flex; flex-direction: column; gap: 6px; margin-bottom: 8px">
        <h1 style="margin: 0; font-size: 28px; font-weight: 900; letter-spacing: -0.5px">관리자 로그인</h1>
        <p style="margin: 0; font-size: 14px; color: #66625B">등록된 운영자 계정과 2단계 인증이 필요해요.</p>
      </div>
      <label style="display: flex; flex-direction: column; gap: 8px; font-size: 14px; font-weight: 700">이메일
        <input type="email" name="email" required autocomplete="username" value="{{ old('email') }}" placeholder="ops@starttoo.example" style="height: 48px; border-radius: 10px; border: 1px solid #D9D4CB; background: #FFFFFF; padding: 0 14px; font-size: 15px; font-weight: 400; color: #141413">
      </label>
      <label style="display: flex; flex-direction: column; gap: 8px; font-size: 14px; font-weight: 700">비밀번호
        <input type="password" name="password" required autocomplete="current-password" style="height: 48px; border-radius: 10px; border: 1px solid #D9D4CB; background: #FFFFFF; padding: 0 14px; font-size: 15px; font-weight: 400; color: #141413">
      </label>
      <label style="display: flex; flex-direction: column; gap: 8px; font-size: 14px; font-weight: 700">인증 앱 6자리 코드
        <input name="otp" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" required placeholder="000000" style="height: 48px; border-radius: 10px; border: 1.5px solid #141413; background: #FFFFFF; padding: 0 14px; font-size: 18px; font-weight: 500; letter-spacing: 6px; color: #141413">
      </label>
      <button type="submit" style="height: 52px; border-radius: 12px; background: #141413; color: #FFFFFF; font-size: 16px; font-weight: 700; display: flex; align-items: center; justify-content: center; margin-top: 6px; border: 0; cursor: pointer; width: 100%">로그인</button>
      <div style="display: flex; gap: 10px; align-items: flex-start; padding: 12px 14px; border-radius: 10px; background: #ECEAE5">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#55524C" stroke-width="1.8" stroke-linecap="round" aria-hidden="true" style="flex-shrink: 0; margin-top: 1px"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
        <span style="font-size: 12px; line-height: 1.6; color: #55524C">모든 접속과 작업은 감사 로그에 기록됩니다. 5회 연속 실패 시 계정이 30분간 잠겨요.</span>
      </div>
    </form>
  </section>
</div>
@endsection
