@extends('advertiser.layouts.web')
@section('title', '광고주 로그인')

@section('content')
<main class="adv-wrap" style="padding-top: 56px; max-width: 520px">
  <form method="POST" action="{{ route('advertiser.login.submit') }}" class="adv-card" style="padding: clamp(24px, 4vw, 40px); display: flex; flex-direction: column; gap: 18px" novalidate>
    @csrf
    <div style="display: flex; flex-direction: column; gap: 6px">
      <h1 style="margin: 0; font-size: 24px; font-weight: 900">광고주 로그인</h1>
      <span style="font-size: 13px; color: #66625B; line-height: 1.6">견적 · 계약 · 성과 보고를 한 곳에서 볼 수 있어요. 앱 회원 계정과는 따로예요.</span>
    </div>
    @error('email')<div role="alert" style="border-radius: 10px; background: #FDE4DE; color: #6E2111; padding: 10px 14px; font-size: 13px">{{ $message }}</div>@enderror
    <label style="display: flex; flex-direction: column; gap: 6px"><span class="adv-label">이메일</span><input type="email" name="email" required autocomplete="username" value="{{ old('email') }}" class="adv-input"></label>
    <label style="display: flex; flex-direction: column; gap: 6px"><span class="adv-label">비밀번호</span><input type="password" name="password" required autocomplete="current-password" maxlength="72" class="adv-input">@error('password')<span class="adv-err">{{ $message }}</span>@enderror</label>
    <button type="submit" class="adv-btn">로그인</button>
    <div style="display: flex; flex-wrap: wrap; justify-content: space-between; gap: 8px; font-size: 13px">
      <a href="{{ route('advertiser.password.forgot') }}">비밀번호를 잊었어요 · 처음 설정할게요</a>
      <a href="{{ route('advertiser.inquiry') }}">아직 계정이 없어요 → 광고 문의</a>
    </div>
    <span style="font-size: 12px; color: #77736B; line-height: 1.6">계정은 견적서를 받은 이메일로 만들어져요. 견적서 화면에서 비밀번호를 정하거나, 위 링크로 비밀번호 설정 메일을 받아주세요.</span>
  </form>
</main>
@endsection
