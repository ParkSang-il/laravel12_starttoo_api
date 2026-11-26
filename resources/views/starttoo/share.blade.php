@php
    $title = $card ? $card['title'] : '스타투';
    $desc = $card ? \Illuminate\Support\Str::limit(preg_replace('/\s+/u', ' ', $card['text'] ?? ''), 120) : '볼 수 없거나 삭제된 콘텐츠예요.';
@endphp
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $title }} · 스타투</title>
<meta name="description" content="{{ $desc }}">
<meta property="og:site_name" content="스타투 STARTTOO">
<meta property="og:title" content="{{ $title }}">
<meta property="og:description" content="{{ $desc }}">
<meta property="og:url" content="{{ $url }}">
@if ($card && $card['image'])
<meta property="og:image" content="{{ $card['image'] }}">
@endif
<meta name="twitter:card" content="summary_large_image">
<style>
  :root { color-scheme: dark; }
  body { margin: 0; background: #0E0E0F; color: #F2EFEA; font: 15px/1.6 -apple-system, BlinkMacSystemFont, "Apple SD Gothic Neo", "Noto Sans KR", sans-serif; }
  main { max-width: 480px; margin: 0 auto; padding: 24px 20px 40px; }
  .brand { font-weight: 800; letter-spacing: .02em; margin-bottom: 20px; }
  .brand b { color: #FF5A36; }
  img { width: 100%; border-radius: 16px; display: block; background: #1F1F22; }
  .kind { display: inline-block; margin-top: 16px; padding: 2px 8px; border-radius: 6px; background: #2A2A2E; font-size: 12px; font-weight: 700; color: #BDB8B0; }
  h1 { font-size: 20px; margin: 8px 0 4px; }
  .author { color: #8F8A84; font-size: 13px; }
  p { color: #CFCAC2; white-space: pre-line; }
  .btn { display: block; text-align: center; padding: 14px; border-radius: 14px; font-weight: 700; text-decoration: none; margin-top: 10px; }
  .open { background: #FF5A36; color: #0E0E0F; }
  .store { border: 1px solid #3A3A3E; color: #F2EFEA; }
</style>
</head>
<body>
<main>
  <div class="brand">STARTTOO<b>.</b></div>
  @if ($card)
    @if ($card['image'])<img src="{{ $card['image'] }}" alt="">@endif
    <span class="kind">{{ $card['kind'] }}</span>
    <h1>{{ $card['title'] }}</h1>
    @if (!empty($card['author']))<div class="author">{{ $card['author'] }}</div>@endif
    @if (!empty($card['text']))<p>{{ \Illuminate\Support\Str::limit($card['text'], 300) }}</p>@endif
  @else
    <h1>볼 수 없는 콘텐츠예요</h1>
    <p>삭제됐거나 비공개로 바뀐 콘텐츠예요.</p>
  @endif
  {{-- 앱이 깔려 있으면 링크를 누를 때 앱이 바로 열린다. 이 페이지가 보이면 앱이 없거나 PC다. --}}
  @if ($ios)<a class="btn open" href="{{ $ios }}">App Store에서 스타투 받기</a>@endif
  @if ($android)<a class="btn store" href="{{ $android }}">Google Play에서 스타투 받기</a>@endif
</main>
</body>
</html>
