@extends('advertiser.layouts.web')
@section('title', '광고주 계정 만들기')

{{-- 견적서 메일 링크(비밀 토큰 = 받은 이메일 인증)로 처음 들어왔을 때: 비밀번호를 정하면 로그인되고 견적서가 열린다. 광고주 로그인 화면과 같은 모양 --}}
@section('content')
<main class="adv-wrap" style="padding-top: 56px; max-width: 520px">
  <form method="POST" action="{{ route('advertiser.quote.account', $token) }}" class="adv-card" style="padding: clamp(24px, 4vw, 40px); display: flex; flex-direction: column; gap: 18px" novalidate>
    @csrf
    <div style="display: flex; flex-direction: column; gap: 6px">
      <h1 style="margin: 0; font-size: 24px; font-weight: 900">광고주 계정 만들기</h1>
      <span style="font-size: 13px; color: #66625B; line-height: 1.6">메일 링크로 <b style="color: #141413">{{ $email }}</b> 인증을 마쳤어요. 비밀번호를 정하면 {{ $company }} 견적서가 열리고, 앞으로 이 이메일로 로그인해 견적 · 계약 · 성과 보고를 볼 수 있어요.</span>
    </div>
    @error('account')<div role="alert" style="border-radius: 10px; background: #FDE4DE; color: #6E2111; padding: 10px 14px; font-size: 13px">{{ $message }}</div>@enderror
    <label style="display: flex; flex-direction: column; gap: 6px"><span class="adv-label">이메일</span><input type="email" name="email" value="{{ $email }}" readonly autocomplete="username" class="adv-input"></label>
    <label style="display: flex; flex-direction: column; gap: 6px"><span class="adv-label">비밀번호 <span style="font-weight: 400; color: #77736B">· 영문 · 숫자 포함 8자 이상</span></span><input type="password" name="password" required minlength="8" maxlength="72" autocomplete="new-password" class="adv-input">@error('password')<span class="adv-err">{{ $message }}</span>@enderror</label>
    <label style="display: flex; flex-direction: column; gap: 6px"><span class="adv-label">비밀번호 확인</span><input type="password" name="password_confirmation" required maxlength="72" autocomplete="new-password" class="adv-input"></label>
    <button type="submit" class="adv-btn">계정 만들고 견적서 보기</button>
    <div style="display: flex; flex-wrap: wrap; justify-content: space-between; gap: 8px; font-size: 13px">
      <a href="{{ route('advertiser.login') }}">이미 비밀번호를 만들었어요 → 로그인</a>
    </div>
    <span style="font-size: 12px; color: #77736B; line-height: 1.6">이 링크는 견적 메일을 받은 분만 열 수 있어요. 앱 회원 계정과는 따로예요. 문의: {{ config('advertiser.ops_email') }}</span>
  </form>
</main>
@endsection
