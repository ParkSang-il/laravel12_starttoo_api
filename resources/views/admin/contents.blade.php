@extends('admin.layouts.app')
@section('title', '게시물 · 작품 관리')
@section('content')

  <div style="flex: 999 1 560px; min-width: 0; padding: 28px clamp(16px, 3vw, 40px) 48px; box-sizing: border-box; display: flex; flex-direction: column; gap: 24px">
    <header style="display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 16px">
      <div style="display: flex; flex-direction: column; gap: 6px">
        <h1 style="margin: 0; font-size: 26px; font-weight: 900; letter-spacing: -0.5px">게시물 · 작품 관리</h1>
        <p style="margin: 0; font-size: 14px; color: #66625B">작품 · 도안 · 피팅 도안 · 커뮤니티 게시물의 노출 상태를 관리해요. 회원의 신체 사진은 저장 · 열람하지 않아요.</p>
      </div>
      <form method="GET" action="{{ route('admin.contents') }}" style="display: flex; flex-wrap: wrap; gap: 8px">
        @if (request('author'))<a href="{{ route('admin.contents', request()->except('author', 'page')) }}" class="btn-a" style="height: 40px; padding: 0 14px; border-radius: 10px; background: #141413; color: #FFFFFF; font-size: 13px; font-weight: 700">U-{{ (int) request('author') }} 회원 글만 · 해제</a>@endif
        @foreach (['tab', 'state', 'sort', 'author'] as $k) @if (request($k))<input type="hidden" name="{{ $k }}" value="{{ request($k) }}">@endif @endforeach
        <label style="height: 40px; min-width: 260px; border-radius: 10px; border: 1px solid #D9D4CB; background: #FFFFFF; display: flex; align-items: center; gap: 8px; padding: 0 12px; color: #66625B"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg><span style="position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0)">콘텐츠 검색</span><input type="search" name="q" value="{{ $q }}" maxlength="40" placeholder="제목, 작성자, ID" style="flex: 1; min-width: 0; border: 0; outline: none; font-size: 14px; color: #141413"></label>
      </form>
    </header>
    <section style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: space-between">
      <div style="display: flex; flex-wrap: wrap; gap: 6px">
        @foreach ($tabs as $t)
          <a href="{{ $t['href'] }}" class="btn-a" style="{{ $t['style'] }}">{{ $t['label'] }}</a>
        @endforeach
      </div>
      <div style="display: flex; gap: 6px">
        <a href="{{ $stateHref }}" class="btn-a" style="height: 36px; padding: 0 12px; border-radius: 10px; border: 1px solid #E4E0D8; background: #FFFFFF; font-size: 13px; font-weight: 500; color: #141413">{{ $stateLabel }}</a>
        <a href="{{ $sortHref }}" class="btn-a" style="height: 36px; padding: 0 12px; border-radius: 10px; border: 1px solid #E4E0D8; background: #FFFFFF; font-size: 13px; font-weight: 500; color: #141413">{{ $sortLabel }}</a>
      </div>
    </section>

    <form method="POST" action="{{ $bulkAction }}" style="display: contents" data-bulk-form>
    @csrf
    @if ($can['hide'] || $can['delete'] || $can['fit'])
    <section data-bulk-bar hidden style="padding: 12px 16px; border-radius: 12px; background: #141413; color: #FFFFFF; display: flex; flex-wrap: wrap; align-items: center; gap: 10px">
        <span data-bulk-count style="flex: 1; font-size: 14px; font-weight: 700">0개 선택됨</span>
        @if ($can['fit'])
          <button type="submit" name="action" value="fit_block" style="height: 36px; padding: 0 14px; border-radius: 8px; border: 1px solid #3A3A3E; background: none; color: #FFFFFF; font-size: 13px; font-weight: 700">피팅 차단</button>
          <button type="submit" name="action" value="fit_allow" style="height: 36px; padding: 0 14px; border-radius: 8px; border: 1px solid #3A3A3E; background: none; color: #FFFFFF; font-size: 13px; font-weight: 700">피팅 허용</button>
        @endif
        @if ($can['hide'])
          <button type="submit" name="action" value="hide" style="height: 36px; padding: 0 14px; border-radius: 8px; border: 1px solid #3A3A3E; background: none; color: #FFFFFF; font-size: 13px; font-weight: 700">숨김</button>
          <button type="submit" name="action" value="show" style="height: 36px; padding: 0 14px; border-radius: 8px; border: 1px solid #3A3A3E; background: none; color: #FFFFFF; font-size: 13px; font-weight: 700">다시 노출</button>
        @endif
        @if ($can['recommend'])
          <button type="submit" name="action" value="unrecommend" style="height: 36px; padding: 0 14px; border-radius: 8px; border: 1px solid #3A3A3E; background: none; color: #FFFFFF; font-size: 13px; font-weight: 700">추천 제외</button>
        @endif
        @if ($can['delete'])
          <button type="submit" name="action" value="delete" data-confirm="선택한 콘텐츠를 삭제할까요? 앱에서 바로 사라지고, 되돌리기는 최고 관리자만 할 수 있어요." style="height: 36px; padding: 0 14px; border-radius: 8px; border: 0; background: #FF5A36; color: #0E0E0F; font-size: 13px; font-weight: 700">삭제</button>
        @endif
      </section>
    @endif

    <section style="display: grid; grid-template-columns: repeat(auto-fill, minmax(min(220px, 100%), 1fr)); gap: 14px">
      @forelse ($items as $it)
        <article style="background: #FFFFFF; border: 1px solid #E4E0D8; border-radius: 16px; overflow: hidden; display: flex; flex-direction: column">
          <div style="{{ $it['thumb'] }}">
            @if ($it['image'])<img src="{{ $it['image'] }}" alt="" loading="lazy" style="position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover">@endif
            @if ($can['hide'] || $can['delete'] || $can['fit'])
              <label style="position: absolute; top: 10px; left: 10px; width: 32px; height: 32px; border-radius: 8px; background: rgba(255,255,255,0.92); display: flex; align-items: center; justify-content: center">
                <input type="checkbox" name="ids[]" value="{{ $it['id'] }}" data-bulk-check aria-label="{{ $it['aria'] }}" style="width: 18px; height: 18px; margin: 0; accent-color: #141413">
              </label>
            @endif
            <span style="{{ $it['pill'] }}; position: absolute; top: 14px; right: 10px">{{ $it['state'] }}</span>
          </div>
          <div style="padding: 14px; display: flex; flex-direction: column; gap: 6px">
            <span style="font-size: 12px; color: #66625B">{{ $it['kind'] }} · {{ $it['code'] }}@if ($it['publicHref']) · <a href="{{ $it['publicHref'] }}" target="_blank" rel="noopener" style="color: #1F4F99; font-weight: 700">공개 페이지</a>@endif</span>
            <span style="font-size: 14px; font-weight: 700; line-height: 1.4">{{ $it['title'] }}</span>
            <span style="font-size: 12px; color: #66625B">@if ($it['authorHref'])<a href="{{ $it['authorHref'] }}" style="color: #141413; font-weight: 700">{{ $it['author'] }}</a>@else{{ $it['author'] }}@endif · {{ $it['date'] }}</span>
            <span style="font-size: 12px; color: #55524C">{{ $it['likesLabel'] }} · @if ($it['reportsHref'])<a href="{{ $it['reportsHref'] }}" style="color: #A3301A; font-weight: 700">신고 {{ $it['reports'] }}</a>@else 신고 {{ $it['reports'] }}@endif</span>
          </div>
        </article>
      @empty
        <p style="grid-column: 1 / -1; margin: 0; padding: 40px 16px; font-size: 14px; color: #66625B; text-align: center; background: #FFFFFF; border: 1px solid #E4E0D8; border-radius: 16px">조건에 맞는 콘텐츠가 없어요.</p>
      @endforelse
    </section>
    </form>

    <div style="display: flex; justify-content: space-between; align-items: center; font-size: 13px; color: #66625B">
      <span>{{ $totalLabel }}</span>
      @include('admin.partials.pages')
    </div>
  </div>
@endsection
