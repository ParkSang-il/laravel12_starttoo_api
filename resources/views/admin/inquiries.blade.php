@extends('admin.layouts.app')
@section('title', '1:1 문의')
@section('content')

  <div style="flex: 999 1 560px; min-width: 0; padding: 28px clamp(16px, 3vw, 40px) 48px; box-sizing: border-box; display: flex; flex-direction: column; gap: 24px">
    <header style="display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 16px">
      <div style="display: flex; flex-direction: column; gap: 6px">
        <h1 style="margin: 0; font-size: 26px; font-weight: 900; letter-spacing: -0.5px">1:1 문의</h1>
        <p style="margin: 0; font-size: 14px; color: #66625B">앱 MY 「1:1 문의」에 남긴 문의에 답변해요. 답변하면 회원에게 앱 알림이 가고, 답변 대기 {{ $lateHours }}시간이 지나면 빨갛게 표시돼요.</p>
      </div>
    </header>
    <section style="display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-start">
      <div style="background: #FFFFFF; border: 1px solid #E4E0D8; border-radius: 16px; flex: 3 1 560px; min-width: 0; overflow: hidden">
        <div role="tablist" style="padding: 14px 16px; display: flex; flex-wrap: wrap; gap: 6px; border-bottom: 1px solid #E4E0D8">
          @foreach ($tabs as $t)
            <a href="{{ $t['href'] }}" class="btn-a" role="tab" style="{{ $t['style'] }}">{{ $t['label'] }}</a>
          @endforeach
        </div>
        <div style="overflow-x: auto">
          <table style="width: 100%; min-width: 720px; border-collapse: collapse">
            <thead><tr><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">번호</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">분류</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8">제목</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">회원</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">마지막 질문</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">상태</th></tr></thead>
            <tbody>
              @forelse ($list as $r)
              <tr style="{{ $r['rowStyle'] }}">
                <td style="padding: 14px 16px; border-bottom: 1px solid #F0EDE7; white-space: nowrap; font-family: ui-monospace, Menlo, monospace; font-size: 13px"><a href="{{ $r['href'] }}" style="color: #141413; font-weight: 700">{{ $r['id'] }}</a></td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap; color: #66625B">{{ $r['category'] }}</td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; max-width: 320px"><a href="{{ $r['href'] }}" style="color: #141413; font-weight: 700; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{ $r['title'] }}</a></td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap">@if ($r['whoHref'])<a href="{{ $r['whoHref'] }}" style="color: #141413; text-decoration: underline; text-underline-offset: 3px">{{ $r['who'] }}</a>@else{{ $r['who'] }}@endif</td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap; {{ $r['askedStyle'] }}">{{ $r['asked'] }}</td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap"><span style="{{ $r['pill'] }}">{{ $r['state'] }}</span></td>
              </tr>
              @empty
              <tr><td colspan="6" style="padding: 32px 16px; font-size: 14px; color: #66625B; text-align: center">문의가 없어요.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
        <div style="padding: 14px 20px; display: flex; justify-content: flex-end">@include('admin.partials.pages')</div>
      </div>

      @if ($sel)
      <div style="background: #FFFFFF; border: 1px solid #E4E0D8; border-radius: 16px; flex: 2 1 380px; min-width: 0; overflow: hidden; display: flex; flex-direction: column">
        <div style="padding: 18px 20px; border-bottom: 1px solid #E4E0D8; display: flex; justify-content: space-between; align-items: flex-start; gap: 8px">
          <div style="display: flex; flex-direction: column; gap: 4px; min-width: 0">
            <span style="font-size: 13px; color: #66625B"><span style="font-family: ui-monospace, Menlo, monospace">{{ $sel['id'] }}</span> · {{ $sel['category'] }} · {{ $sel['created'] }}</span>
            <h2 style="margin: 0; font-size: 17px; font-weight: 700; overflow-wrap: anywhere">{{ $sel['title'] }}</h2>
            <span style="font-size: 13px">@if ($sel['whoHref'])<a href="{{ $sel['whoHref'] }}" style="color: #141413; text-decoration: underline; text-underline-offset: 3px">{{ $sel['who'] }}</a>@else{{ $sel['who'] }}@endif</span>
          </div>
          <span style="{{ $sel['pill'] }}">{{ $sel['state'] }}</span>
        </div>
        <div style="padding: 16px 20px; display: flex; flex-direction: column; gap: 12px; background: #FBFAF8; max-height: 560px; overflow-y: auto">
          @foreach ($sel['messages'] as $m)
            @php $mine = $m['author'] === 'admin'; @endphp
            <div style="display: flex; flex-direction: column; gap: 4px; align-items: {{ $mine ? 'flex-end' : 'flex-start' }}">
              <span style="font-size: 11px; color: #77736B">{{ $m['who'] }} · {{ $m['t'] }}</span>
              <span style="max-width: 88%; padding: 10px 12px; border-radius: 14px; font-size: 13px; line-height: 1.6; white-space: pre-line; overflow-wrap: anywhere; {{ $mine ? 'background: #141413; color: #FFFFFF' : 'background: #FFFFFF; color: #141413; border: 1px solid #E4E0D8' }}">{{ $m['body'] }}</span>
              @if ($m['images'])
                <div style="display: flex; gap: 6px; flex-wrap: wrap">
                  @foreach ($m['images'] as $img)
                    <a href="{{ $img['url'] }}" target="_blank" rel="noopener"><img src="{{ $img['thumb'] }}" alt="첨부 사진" style="width: 72px; height: 72px; object-fit: cover; border-radius: 8px; border: 1px solid #E4E0D8"></a>
                  @endforeach
                </div>
              @endif
            </div>
          @endforeach
        </div>
        @if ($canAnswer)
          <form method="POST" action="{{ $sel['answerAction'] }}" style="padding: 16px 20px; border-top: 1px solid #E4E0D8; display: flex; flex-direction: column; gap: 8px">
            @csrf
            @error('body')<div style="font-size: 13px; color: #B3261E">{{ $message }}</div>@enderror
            <label style="display: flex; flex-direction: column; gap: 6px"><span style="font-size: 12px; font-weight: 700; color: #3A3731">{{ $sel['waiting'] ? '답변' : '답변 더하기' }} <span style="font-weight: 400; color: #66625B">· {{ number_format($answerMax) }}자까지, 회원 앱에 그대로 보여요</span></span>
              <textarea name="body" required maxlength="{{ $answerMax }}" rows="6" data-draft="inquiry-answer-{{ $sel['id'] }}" style="border-radius: 10px; border: 1px solid #D9D4CB; padding: 10px 12px; font-size: 14px; line-height: 1.6; color: #141413; background: #FFFFFF; box-sizing: border-box; width: 100%; resize: vertical; font-family: inherit">{{ old('body') }}</textarea>
            </label>
            <div style="display: flex; justify-content: flex-end"><button type="submit" data-confirm="이 답변을 보낼까요? 보낸 답변은 고칠 수 없고, 회원에게 앱 알림이 가요." style="height: 40px; padding: 0 20px; border-radius: 10px; font-size: 14px; font-weight: 700; border: 0; background: #141413; color: #FFFFFF">답변 보내기</button></div>
          </form>
        @else
          <div style="padding: 14px 20px; border-top: 1px solid #E4E0D8; font-size: 12px; color: #66625B">답변은 CS 담당 이상만 할 수 있어요.</div>
        @endif
      </div>
      @endif
    </section>
  </div>
@endsection
