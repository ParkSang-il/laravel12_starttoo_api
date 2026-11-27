{{-- 화면 안 설정 항목 탭. $sections = AdminUi::sections() 의 탭 목록, $sectionsLabel = 읽어 주는 이름 --}}
<nav role="tablist" aria-label="{{ $sectionsLabel ?? '설정 항목' }}" style="display: flex; flex-wrap: wrap; gap: 6px">
  @foreach ($sections as $t)
    <a href="{{ $t['href'] }}" class="btn-a" role="tab" aria-selected="{{ $t['on'] ? 'true' : 'false' }}" style="{{ $t['style'] }}; display: inline-flex; align-items: center; gap: 6px">{{ $t['label'] }}@if ($t['count'] > 0)<span style="min-width: 18px; height: 18px; padding: 0 5px; border-radius: 9px; background: #FF5A36; color: #FFFFFF; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; box-sizing: border-box">{{ $t['count'] }}</span>@endif</a>
  @endforeach
</nav>
