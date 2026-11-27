@extends('advertiser.layouts.web')
@section('title', '비밀번호 설정 링크 받기')

@section('content')
<main class="adv-wrap" style="padding-top: 56px; max-width: 520px">
  <form method="POST" action="{{ route('advertiser.password.send') }}" class="adv-card" style="padding: clamp(24px, 4vw, 40px); display: flex; flex-direction: column; gap: 18px" novalidate>
    @csrf
    <div style="display: flex; flex-direction: column; gap: 6px">
      <h1 style="margin: 0; font-size: 24px; font-weight: 900">비밀번호 설정 링크 받기</h1>
      <span style="font-size: 13px; color: #66625B; line-height: 1.6">견적서를 받은 이메일을 적어주세요. 비밀번호를 처음 정하거나 잊었을 때 모두 이 링크로 설정해요.</span>
    </div>
    <label style="display: flex; flex-direction: column; gap: 6px"><span class="adv-label">이메일</span><input type="email" name="email" required autocomplete="username" value="{{ old('email') }}" class="adv-input @error('email') is-invalid @enderror">@error('email')<span class="adv-err">{{ $message }}</span>@enderror</label>
    <button type="submit" class="adv-btn">링크 보내기</button>
    <a href="{{ route('advertiser.login') }}" style="font-size: 13px">로그인으로 돌아가기</a>
  </form>
</main>
@endsection
