@extends('admin.layouts.app')
@section('title', '무빙 영상')
@section('content')
{{-- 무빙 영상 재생(검수용). HLS: Safari 는 기본 재생, 그 밖의 브라우저는 hls.js --}}
  <div style="flex: 999 1 560px; min-width: 0; padding: 28px clamp(16px, 3vw, 40px) 48px; box-sizing: border-box; display: flex; flex-direction: column; gap: 20px">
    <header style="display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 16px">
      <div style="display: flex; flex-direction: column; gap: 6px">
        <h1 style="margin: 0; font-size: 26px; font-weight: 900; letter-spacing: -0.5px">무빙 영상</h1>
        <p style="margin: 0; font-size: 14px; color: #66625B"><a href="{{ $whoHref }}" style="color: #141413; text-decoration: underline; text-underline-offset: 3px">{{ $who }}</a> · {{ $info }}</p>
      </div>
      <div style="display: flex; flex-wrap: wrap; gap: 8px">
        <a href="{{ $jobsHref }}" style="height: 40px; padding: 0 16px; border-radius: 10px; font-size: 14px; font-weight: 700; display: inline-flex; align-items: center; border: 1px solid #D9D4CB; background: #FFFFFF; color: #141413; text-decoration: none">변환 기록</a>
        @if ($contentHref)<a href="{{ $contentHref }}" style="height: 40px; padding: 0 16px; border-radius: 10px; font-size: 14px; font-weight: 700; display: inline-flex; align-items: center; border: 1px solid #D9D4CB; background: #FFFFFF; color: #141413; text-decoration: none">게시물 관리(숨김 · 삭제)</a>@endif
      </div>
    </header>
    <section style="display: flex; flex-wrap: wrap; gap: 20px; align-items: flex-start">
      <div style="flex: 0 1 380px; width: 100%; max-width: 380px; aspect-ratio: 9 / 16; background: #0E0E0F; border-radius: 16px; overflow: hidden">
        <video id="player" controls playsinline preload="metadata" poster="{{ $poster }}" style="width: 100%; height: 100%; object-fit: contain; background: #0E0E0F"></video>
      </div>
      <div style="flex: 1 1 280px; min-width: 0; background: #FFFFFF; border: 1px solid #E4E0D8; border-radius: 16px; padding: 20px; display: flex; flex-direction: column; gap: 12px">
        <div style="display: flex; justify-content: space-between; gap: 10px"><strong style="font-size: 15px">내용</strong><span style="{{ $pill }}">{{ $state }}</span></div>
        <p style="margin: 0; font-size: 14px; line-height: 1.6; white-space: pre-wrap">{{ $title }}</p>
        <span id="player-error" role="alert" hidden style="font-size: 13px; color: #A3301A">영상을 불러오지 못했어요. CDN 주소(STARTTOO_VIDEO_CDN_URL)가 비공개 파일을 내보내는지 확인해주세요.</span>
        <span style="font-size: 12px; color: #66625B; line-height: 1.5">신고 사유를 확인하며 볼 때는 신고 화면의 시점을 참고해 재생 막대를 옮겨주세요. 숨김 · 삭제는 「게시물 관리」에서 해요.</span>
      </div>
    </section>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/hls.js@1.5.17/dist/hls.min.js"></script>
  <script>
    (function () {
      var video = document.getElementById('player');
      var src = @json($hls);
      var fail = function () { document.getElementById('player-error').hidden = false; };
      if (video.canPlayType('application/vnd.apple.mpegurl')) {
        video.src = src;
        video.addEventListener('error', fail);
      } else if (window.Hls && Hls.isSupported()) {
        var hls = new Hls();
        hls.on(Hls.Events.ERROR, function (_, data) { if (data.fatal) fail(); });
        hls.loadSource(src);
        hls.attachMedia(video);
      } else {
        fail();
      }
    })();
  </script>
@endsection
