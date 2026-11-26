@extends('admin.layouts.app')
@section('title', '공지 · 배너 · FAQ')
@section('content')

  <div style="flex: 999 1 560px; min-width: 0; padding: 28px clamp(16px, 3vw, 40px) 48px; box-sizing: border-box; display: flex; flex-direction: column; gap: 24px">
    <header style="display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 16px">
      <div style="display: flex; flex-direction: column; gap: 6px">
        <h1 style="margin: 0; font-size: 26px; font-weight: 900; letter-spacing: -0.5px">공지 · 배너 · FAQ</h1>
        <p style="margin: 0; font-size: 14px; color: #66625B">앱 MY 「공지사항」에 보이는 글을 관리해요.</p>
      </div>
      @if ($canManage)
        <div style="display: flex; flex-wrap: wrap; gap: 8px"><a href="{{ $newHref }}" class="btn-a" style="height: 40px; padding: 0 16px; border-radius: 10px; font-size: 14px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; gap: 6px; border: 0; background: #141413; color: #FFFFFF">공지 작성</a></div>
      @endif
    </header>
    <section style="display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-start">
      <div style="background: #FFFFFF; border: 1px solid #E4E0D8; border-radius: 16px; flex: 3 1 560px; min-width: 0; overflow: hidden">
        <div role="tablist" style="padding: 14px 16px; display: flex; flex-wrap: wrap; gap: 6px; border-bottom: 1px solid #E4E0D8">
          <span class="btn-a" role="tab" aria-selected="true" style="{{ \App\Support\AdminUi::tab(true) }}">공지사항</span>
        </div>
        <div style="overflow-x: auto">
          <table style="width: 100%; min-width: 680px; border-collapse: collapse">
            <thead><tr><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">제목</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">분류</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">게시일</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">상태</th><th style="text-align: left; padding: 12px 16px; font-size: 12px; color: #66625B; border-bottom: 1px solid #E4E0D8; white-space: nowrap">노출</th></tr></thead>
            <tbody>
              @forelse ($rows as $n)
              <tr style="{{ $n['rowStyle'] }}">
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap"><a href="{{ $n['href'] }}" class="btn-a" style="padding: 0; border: 0; background: none; font-size: 14px; font-weight: 700; color: #141413; text-align: left">{{ $n['title'] }}</a></td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap">{{ $n['b'] }}</td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap; color: #66625B">{{ $n['c'] }}</td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap"><span style="{{ $n['pill'] }}">{{ $n['state'] }}</span></td>
                <td style="padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F0EDE7; white-space: nowrap">
                  @if ($n['canToggle'])
                    <form method="POST" action="{{ $n['action'] }}" style="display: contents">@csrf<button type="submit" role="switch" aria-checked="{{ $n['on'] ? 'true' : 'false' }}" aria-label="{{ $n['aria'] }}" style="{{ $n['track'] }}"><span style="{{ $knob }}"></span></button></form>
                  @else
                    <span role="switch" aria-checked="{{ $n['on'] ? 'true' : 'false' }}" aria-disabled="true" aria-label="{{ $n['aria'] }}" style="{{ $n['track'] }}"><span style="{{ $knob }}"></span></span>
                  @endif
                </td>
              </tr>
              @empty
              <tr><td colspan="5" style="padding: 32px 16px; font-size: 14px; color: #66625B; text-align: center">아직 공지가 없어요.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
      <div style="background: #FFFFFF; border: 1px solid #E4E0D8; border-radius: 16px; flex: 2 1 360px; min-width: 0; padding: 20px; display: flex; flex-direction: column; gap: 14px">
        <div style="display: flex; justify-content: space-between; align-items: center"><h2 style="margin: 0; font-size: 17px; font-weight: 700">{{ $isNew ? '공지 작성' : '편집' }}</h2><span style="{{ $sel['pill'] }}">{{ $sel['state'] }}</span></div>
        <label style="display: flex; flex-direction: column; gap: 6px"><span style="font-size: 13px; font-weight: 700; color: #3A3731">제목</span><input name="title" form="f-save" required maxlength="120" value="{{ old('title', $sel['title']) }}" @disabled(! $canManage) style="height: 44px; border-radius: 10px; border: 1px solid #D9D4CB; padding: 0 12px; font-size: 14px; color: #141413; background: #FFFFFF; box-sizing: border-box; width: 100%"></label>
        <label style="display: flex; flex-direction: column; gap: 6px"><span style="font-size: 13px; font-weight: 700; color: #3A3731">분류</span>
          <select name="category" form="f-save" @disabled(! $canManage) style="height: 44px; border-radius: 10px; border: 1px solid #D9D4CB; padding: 0 12px; font-size: 14px; color: #141413; background: #FFFFFF; box-sizing: border-box; width: 100%">
            @foreach ($categories as $value => $label)<option value="{{ $value }}" @selected(old('category', $sel['category']) === $value)>{{ $label }}</option>@endforeach
          </select>
        </label>
        <label style="display: flex; flex-direction: column; gap: 6px"><span style="font-size: 13px; font-weight: 700; color: #3A3731">본문</span><textarea name="body" form="f-save" required maxlength="10000" rows="8" @disabled(! $canManage) style="border-radius: 10px; border: 1px solid #D9D4CB; padding: 12px; font-size: 14px; line-height: 1.5; resize: vertical; color: #141413; box-sizing: border-box; width: 100%">{{ old('body', $sel['body']) }}</textarea></label>
        <label style="display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 700"><input type="hidden" name="pinned" value="0" form="f-save"><input type="checkbox" name="pinned" value="1" form="f-save" @checked(old('pinned', $sel['pinned'])) @disabled(! $canManage) style="width: 18px; height: 18px; margin: 0; accent-color: #141413">맨 위 고정</label>
        @if ($canPush)
          <div style="display: flex; flex-direction: column; gap: 4px">
            <label style="display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 700"><input type="hidden" name="push" value="0" form="f-save"><input type="checkbox" name="push" value="1" form="f-save" @checked(old('push')) style="width: 18px; height: 18px; margin: 0; accent-color: #141413">게시할 때 푸시 보내기</label>
            <span style="font-size: 12px; color: #66625B; line-height: 1.5; padding-left: 26px">{{ $pushNote }}</span>
          </div>
        @endif
        @if ($canManage)
          <div style="display: flex; gap: 8px"><button type="submit" form="f-save" style="height: 40px; padding: 0 16px; border-radius: 10px; font-size: 14px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; gap: 6px; border: 0; background: #141413; color: #FFFFFF; flex: 1">{{ $saveLabel }}</button></div>
          <span style="font-size: 12px; color: #66625B; line-height: 1.5">게시하면 바로 앱에 보여요. 예약 게시는 서버 명령(starttoo:announce add --at=)으로 해요.</span>
        @endif
      </div>
    </section>
  </div>
    <form id="f-save" method="POST" action="{{ $saveAction }}" hidden>@csrf</form>
@endsection
