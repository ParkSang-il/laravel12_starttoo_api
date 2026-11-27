@extends('advertiser.layouts.web')
@section('title', '견적서 확인')

{{-- 견적서(비밀 링크) 열기 전: 받은 이메일 확인. 광고주 로그인 화면과 같은 모양 --}}
@section('content')
<main class="adv-wrap" style="padding-top: 56px; max-width: 520px">
  <form method="POST" action="{{ route('advertiser.quote.verify', $token) }}" class="adv-card" style="padding: clamp(24px, 4vw, 40px); display: flex; flex-direction: column; gap: 18px" novalidate>
    @csrf
    <div style="display: flex; flex-direction: column; gap: 6px">
      <h1 style="margin: 0; font-size: 24px; font-weight: 900">견적서 확인</h1>
      <span style="font-size: 13px; color: #66625B; line-height: 1.6">광고 문의 때 적은 이메일을 입력하면 견적서를 볼 수 있어요.</span>
    </div>
    @error('email')<div role="alert" style="border-radius: 10px; background: #FDE4DE; color: #6E2111; padding: 10px 14px; font-size: 13px">{{ $message }}</div>@enderror
    <label style="display: flex; flex-direction: column; gap: 6px"><span class="adv-label">이메일</span><input type="email" name="email" required autocomplete="email" maxlength="150" value="{{ old('email') }}" class="adv-input"></label>
    <button type="submit" class="adv-btn">견적서 보기</button>
    <div style="display: flex; flex-wrap: wrap; justify-content: space-between; gap: 8px; font-size: 13px">
      <a href="{{ route('advertiser.login') }}">광고주 계정이 있어요 → 로그인</a>
    </div>
    <span style="font-size: 12px; color: #77736B; line-height: 1.6">이메일이 기억나지 않으면 견적 메일에 답장하거나 {{ config('advertiser.ops_email') }} 로 문의해주세요.</span>
  </form>
</main>
@endsection
