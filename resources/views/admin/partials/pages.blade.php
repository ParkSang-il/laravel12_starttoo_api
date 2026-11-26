{{-- 디자인(회원 관리)의 페이지 버튼. $pages = AdminUi::pages() --}}
<div style="display: flex; gap: 4px">
  @if ($pages['prev'])
    <a href="{{ $pages['prev'] }}" class="btn-a" aria-label="이전 페이지" style="width: 36px; height: 36px; border-radius: 8px; border: 1px solid #E4E0D8; background: #FFFFFF; color: #66625B">‹</a>
  @else
    <span class="btn-a" aria-hidden="true" style="width: 36px; height: 36px; border-radius: 8px; border: 1px solid #E4E0D8; background: #FFFFFF; color: #D9D4CB">‹</span>
  @endif
  @foreach ($pages['items'] as $p)
    <a href="{{ $p['href'] }}" class="btn-a" @if ($p['on']) aria-current="page" @endif style="width: 36px; height: 36px; border-radius: 8px; {{ $p['on'] ? 'border: 0; background: #141413; color: #FFFFFF; font-weight: 700' : 'border: 1px solid #E4E0D8; background: #FFFFFF; color: #141413' }}">{{ $p['n'] }}</a>
  @endforeach
  @if ($pages['next'])
    <a href="{{ $pages['next'] }}" class="btn-a" aria-label="다음 페이지" style="width: 36px; height: 36px; border-radius: 8px; border: 1px solid #E4E0D8; background: #FFFFFF; color: #66625B">›</a>
  @else
    <span class="btn-a" aria-hidden="true" style="width: 36px; height: 36px; border-radius: 8px; border: 1px solid #E4E0D8; background: #FFFFFF; color: #D9D4CB">›</span>
  @endif
</div>
