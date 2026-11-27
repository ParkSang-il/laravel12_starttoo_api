@extends('advertiser.layouts.web')
@section('title', '내 광고')
@section('noindex', '1')

@section('content')
<main class="adv-wrap" style="padding-top: 40px; display: flex; flex-direction: column; gap: 24px">
  <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-end; gap: 16px">
    <div style="display: flex; flex-direction: column; gap: 6px">
      <span style="font-size: 13px; color: #66625B">{{ $me->email }}</span>
      <h1 style="margin: 0; font-size: clamp(26px, 3vw, 34px); font-weight: 900; letter-spacing: -0.5px">{{ $me->company }}</h1>
    </div>
    <a href="{{ route('advertiser.inquiry') }}" class="adv-btn is-accent is-sm">새 광고 문의</a>
  </div>

  <section class="adv-card" style="overflow: hidden">
    <div style="padding: 20px 24px 6px"><h2 style="margin: 0; font-size: 17px; font-weight: 700">견적 · 계약</h2></div>
    <div style="overflow-x: auto">
      <table style="width: 100%; min-width: 820px; border-collapse: collapse">
        <thead><tr><th class="adv-th" style="padding-left: 24px">견적</th><th class="adv-th">자리 · 보장 노출</th><th class="adv-th">기간</th><th class="adv-th" style="text-align: right">합계</th><th class="adv-th">상태</th><th class="adv-th" style="padding-right: 24px"></th></tr></thead>
        <tbody>
          @forelse ($contracts as $row)
            @php $c = $row['c']; @endphp
            <tr>
              <td class="adv-td" style="padding-left: 24px"><span style="font-size: 12px; color: #66625B; font-family: ui-monospace, Menlo, monospace">{{ $c->quote_no }}</span><br><b>{{ $c->title }}</b></td>
              <td class="adv-td">{{ $row['slots'] }}<br><span style="font-size: 12px; color: #66625B">보장 {{ number_format($row['imps']) }}회</span></td>
              <td class="adv-td" style="white-space: nowrap">{{ $c->starts_at->format('Y.m.d') }}<br><span style="font-size: 12px; color: #66625B">~ 최대 {{ $c->ends_at->format('m.d') }}</span></td>
              <td class="adv-td adv-num" style="text-align: right; white-space: nowrap; font-weight: 700">₩{{ number_format($c->total) }}</td>
              <td class="adv-td"><span class="adv-pill {{ $row['state'][1] === 'red' ? 'blue' : $row['state'][1] }}">{{ $c->status === 'payment_reported' ? '입금 확인 중' : $row['state'][0] }}</span></td>
              <td class="adv-td" style="padding-right: 24px; white-space: nowrap">
                <span style="display: flex; gap: 6px; justify-content: flex-end">
                  <a href="{{ $row['quoteUrl'] }}" class="adv-btn is-ghost is-sm" style="height: 36px">견적서</a>
                  @if ($row['reportUrl'])<a href="{{ $row['reportUrl'] }}" class="adv-btn is-sm" style="height: 36px">성과 보고</a>@endif
                </span>
              </td>
            </tr>
          @empty
            <tr><td colspan="6" class="adv-td" style="padding: 28px 24px; text-align: center; color: #66625B">아직 받은 견적이 없어요.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </section>

  @if ($inquiries)
    <section class="adv-card" style="overflow: hidden">
      <div style="padding: 20px 24px 6px"><h2 style="margin: 0; font-size: 17px; font-weight: 700">보낸 문의</h2></div>
      <div style="overflow-x: auto">
        <table style="width: 100%; min-width: 620px; border-collapse: collapse">
          <thead><tr><th class="adv-th" style="padding-left: 24px">접수 번호</th><th class="adv-th">원하는 자리</th><th class="adv-th">기간 · 예산</th><th class="adv-th">보낸 날</th><th class="adv-th" style="padding-right: 24px">상태</th></tr></thead>
          <tbody>
            @foreach ($inquiries as $row)
              @php $i = $row['i']; @endphp
              <tr>
                <td class="adv-td" style="padding-left: 24px; font-family: ui-monospace, Menlo, monospace; font-size: 13px">{{ $i->code }}</td>
                <td class="adv-td">{{ $row['slots'] }}</td>
                <td class="adv-td">{{ $i->duration ?? '-' }} · {{ $i->budget ?? '미정' }}</td>
                <td class="adv-td" style="white-space: nowrap">{{ \Illuminate\Support\Carbon::parse($i->created_at)->format('Y.m.d') }}</td>
                <td class="adv-td" style="padding-right: 24px"><span class="adv-pill {{ $i->status === 'new' ? 'amber' : $row['state'][1] }}">{{ $i->status === 'new' ? '검토 중' : $row['state'][0] }}</span>
                  @if ($i->status === 'rejected' && $i->reject_reason)<br><span style="font-size: 12px; color: #66625B">{{ $i->reject_reason }}</span>@endif</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </section>
  @endif

  <div style="display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-start">
    <form method="POST" action="{{ route('advertiser.account.profile') }}" class="adv-card" style="flex: 2 1 420px; min-width: 0; padding: 22px 24px; display: flex; flex-direction: column; gap: 14px" novalidate>
      @csrf
      <h2 style="margin: 0; font-size: 17px; font-weight: 700">내 정보</h2>
      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(200px, 100%), 1fr)); gap: 12px">
        @foreach ([['company', '회사명', true], ['contact_name', '담당자', false], ['phone', '연락처', false]] as [$n, $l, $required])
          <label style="display: flex; flex-direction: column; gap: 4px"><span style="font-size: 13px; color: #3A3731">{{ $l }}@if ($required) <span style="color: #B93415" aria-hidden="true">*</span>@endif</span><input name="{{ $n }}" value="{{ old($n, $me->{$n}) }}" @if ($required) required @endif class="adv-input" style="height: 44px">@error($n)<span class="adv-err">{{ $message }}</span>@enderror</label>
        @endforeach
      </div>
      <span style="font-size: 13px; font-weight: 700; color: #3A3731; margin-top: 4px">세금계산서 정보 <span style="font-weight: 400; color: #77736B">· 입금 알림 때 미리 채워져요</span></span>
      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(200px, 100%), 1fr)); gap: 12px">
        @foreach ([['biz_no', '사업자등록번호', '000-00-00000'], ['biz_name', '상호 · 대표자', ''], ['tax_email', '받을 이메일', '']] as [$n, $l, $ph])
          <label style="display: flex; flex-direction: column; gap: 4px"><span style="font-size: 13px; color: #3A3731">{{ $l }}</span><input name="{{ $n }}" value="{{ old($n, $me->{$n}) }}" placeholder="{{ $ph }}" @if ($n === 'tax_email') type="email" @endif class="adv-input" style="height: 44px">@error($n)<span class="adv-err">{{ $message }}</span>@enderror</label>
        @endforeach
      </div>
      <span style="font-size: 12px; color: #77736B">로그인 이메일은 바꿀 수 없어요. 바꾸려면 담당자에게 알려주세요.</span>
      <div><button type="submit" class="adv-btn is-ghost is-sm">저장</button></div>
    </form>

    <form method="POST" action="{{ route('advertiser.account.password') }}" class="adv-card" style="flex: 1 1 300px; min-width: 0; padding: 22px 24px; display: flex; flex-direction: column; gap: 12px" novalidate>
      @csrf
      <h2 style="margin: 0; font-size: 17px; font-weight: 700">비밀번호 바꾸기</h2>
      <input type="hidden" name="email" value="{{ $me->email }}" autocomplete="username">
      <label style="display: flex; flex-direction: column; gap: 4px"><span style="font-size: 13px; color: #3A3731">지금 비밀번호</span><input type="password" name="current_password" required maxlength="72" autocomplete="current-password" class="adv-input" style="height: 44px">@error('current_password')<span class="adv-err">{{ $message }}</span>@enderror</label>
      <label style="display: flex; flex-direction: column; gap: 4px"><span style="font-size: 13px; color: #3A3731">새 비밀번호 <span style="color: #77736B">· 영문 · 숫자 8자 이상</span></span><input type="password" name="password" required minlength="8" maxlength="72" autocomplete="new-password" class="adv-input" style="height: 44px">@error('password')<span class="adv-err">{{ $message }}</span>@enderror</label>
      <label style="display: flex; flex-direction: column; gap: 4px"><span style="font-size: 13px; color: #3A3731">새 비밀번호 확인</span><input type="password" name="password_confirmation" required maxlength="72" autocomplete="new-password" class="adv-input" style="height: 44px"></label>
      <div><button type="submit" class="adv-btn is-ghost is-sm">바꾸기</button></div>
    </form>
  </div>
</main>
@endsection
