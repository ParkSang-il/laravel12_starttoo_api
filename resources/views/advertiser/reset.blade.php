@extends('advertiser.layouts.web')
@section('title', '비밀번호 설정')
@section('noindex', '1')

@section('content')
<main class="adv-wrap" style="padding-top: 56px; max-width: 520px">
  @if (! $owner)
    <div class="adv-card" style="padding: clamp(24px, 4vw, 40px); display: flex; flex-direction: column; gap: 14px">
      <h1 style="margin: 0; font-size: 24px; font-weight: 900">링크가 만료됐어요</h1>
      <span style="font-size: 14px; color: #66625B; line-height: 1.6">비밀번호 설정 링크는 {{ config('advertiser.password_link_minutes', 60) }}분 동안 한 번만 쓸 수 있어요. 다시 받아주세요.</span>
      <a href="{{ route('advertiser.password.forgot') }}" class="adv-btn">링크 다시 받기</a>
    </div>
  @else
    <form method="POST" action="{{ route('advertiser.password.save', $token) }}" class="adv-card" style="padding: clamp(24px, 4vw, 40px); display: flex; flex-direction: column; gap: 18px" novalidate>
      @csrf
      <div style="display: flex; flex-direction: column; gap: 6px">
        <h1 style="margin: 0; font-size: 24px; font-weight: 900">비밀번호 설정</h1>
        <span style="font-size: 13px; color: #66625B">{{ $owner->company }} · {{ $owner->email }}</span>
      </div>
      <input type="hidden" name="email" value="{{ $owner->email }}" autocomplete="username">
      <label style="display: flex; flex-direction: column; gap: 6px"><span class="adv-label">새 비밀번호 <span style="font-weight: 400; color: #77736B">· 영문 · 숫자 섞어 8자 이상</span></span><input type="password" name="password" required minlength="8" maxlength="72" autocomplete="new-password" class="adv-input @error('password') is-invalid @enderror">@error('password')<span class="adv-err">{{ $message }}</span>@enderror</label>
      <label style="display: flex; flex-direction: column; gap: 6px"><span class="adv-label">새 비밀번호 확인</span><input type="password" name="password_confirmation" required maxlength="72" autocomplete="new-password" class="adv-input"></label>
      <button type="submit" class="adv-btn">설정하고 로그인</button>
    </form>
  @endif
</main>
@endsection
