<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * 무빙 변환(직접 FFmpeg). 기획서 「인코딩 · 저장」.
 *  1. 확인: ffprobe 로 길이 · 영상 트랙 · 회전 · 해상도. 제한을 넘으면 encode_failed(이유)
 *  2. 변환: HLS 360p · 540p · 720p · 1080p(원본 화질까지만, 올려 만들지 않음), H.264 High + AAC 160k, 2초 조각
 *  3. 포스터: 고른 커버 시점 프레임(실패하면 첫 프레임) → 작품 사진과 같은 변환으로 저장(= 작품 · 게시물 첫 사진)
 *  4. 내용 검사: 1차는 없음(신고 기반)
 * 저장: video/starttoo/hls/{영상 id}/master.m3u8 · v0/index.m3u8 · v0/s000.ts …
 * 변환 본체([encodeFile])는 운영자 · 외부 광고 영상(AdVideos)도 같이 쓴다.
 */
class VideoTranscoder
{
    /**
     * 짧은 변(px) → 영상 비트레이트(kbps). 타투 선 · 질감이 뭉개지지 않게 높게 잡는다(2026-11-27 화질 상향).
     * 실제 화질은 CRF(품질 고정)로 정하고, 이 값은 순간 최대치(maxrate) 기준이다.
     */
    private const LEVELS = [360 => 1200, 540 => 2200, 720 => 4000, 1080 => 7000];

    /** CRF: 낮을수록 고화질(18 ≈ 눈으로 원본과 구분 어려움, 23 = ffmpeg 기본) */
    private const CRF = 19;

    private function db() { return app(ProjectDatabase::class)->database(); }

    private function videos(): Videos { return app(Videos::class); }

    /** $final: 마지막 시도면 알 수 없는 오류도 실패로 확정한다(아니면 예외를 던져 큐가 다시 시도) */
    public function process(string $id, bool $final): void
    {
        $row = $this->videos()->row($id);
        if (!$row || $row->status !== 'processing') return;
        $this->db()->table('starttoo_videos')->where('id', $id)->update(['attempts' => $this->db()->raw('attempts + 1'), 'stage' => 'probe', 'updated_at' => now()]);
        $work = storage_path('app/video-work/' . $id);
        File::deleteDirectory($work);
        File::ensureDirectoryExists($work);
        $src = $work . '/source.mp4';
        try {
            $this->videos()->client()->getObject(['Bucket' => $this->videos()->bucket(), 'Key' => $row->source_key, 'SaveAs' => $src]);
            $out = $this->encodeFile($src, $work, 'video/starttoo/hls/' . $id, (int) $row->cover_ms, (bool) $row->muted,
                fn (string $stage) => $this->stage($id, $stage));

            $this->db()->table('starttoo_videos')->where('id', $id)->update([
                'hls_path' => $out['hls_path'], 'poster_path' => $out['poster_path'], 'stage' => 'done',
                'renditions' => json_encode($out['renditions']),
                'duration_ms' => $out['duration_ms'], 'width' => $out['width'], 'height' => $out['height'],
                'processed_at' => now(), 'updated_at' => now(),
            ]);
            $row = $this->videos()->row($id);
            if ($row->auto_publish) {
                $this->videos()->publish($row);
            } else {
                $this->db()->table('starttoo_videos')->where('id', $id)->update(['status' => 'ready', 'updated_at' => now()]);
                $this->videos()->changed($this->videos()->row($id), 'ready');
            }
        } catch (VideoEncodeError $e) {
            $this->videos()->fail_($row, $e->codeName);
        } catch (\Throwable $e) {
            report($e);
            if ($final) {
                $this->videos()->fail_($this->videos()->row($id) ?? $row, 'UNKNOWN');
                return;
            }
            // 다음 시도가 처음부터 다시 하도록 남긴다
            $this->db()->table('starttoo_videos')->where('id', $id)->update(['stage' => null, 'updated_at' => now()]);
            throw $e;
        } finally {
            File::deleteDirectory($work);
        }
    }

    /**
     * 영상 파일 1개 변환 본체: 확인 → HLS 변환 → 포스터 → 저장소에 올리기.
     * $src 로컬 원본 · $work 작업 폴더(안에 out/ · poster.jpg 를 만든다) · $base 저장소 키 앞부분(…/master.m3u8)
     * $stage: 단계가 바뀔 때(probe · transcode · thumbnail)
     * @return array{hls_path: string, poster_path: string, duration_ms: int, width: int, height: int, renditions: array}
     * @throws VideoEncodeError 영상 자체 문제 · \RuntimeException 그 밖(다시 시도할 만한 오류)
     */
    public function encodeFile(string $src, string $work, string $base, int $coverMs = 0, bool $muted = false, ?callable $stage = null): array
    {
        File::ensureDirectoryExists($work . '/out');

        // 1. 확인
        $probe = Process::timeout(60)->run([config('starttoo.video_ffprobe'), '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', $src]);
        $info = $probe->successful() ? json_decode($probe->output(), true) : null;
        if (!is_array($info)) throw new VideoEncodeError('CORRUPTED');
        $video = collect($info['streams'] ?? [])->firstWhere('codec_type', 'video');
        if (!$video || empty($video['width']) || empty($video['height'])) throw new VideoEncodeError('UNSUPPORTED');
        $seconds = (float) ($info['format']['duration'] ?? $video['duration'] ?? 0);
        if ($seconds <= 0) throw new VideoEncodeError('CORRUPTED');
        if ($seconds > (int) config('starttoo.video_max_seconds') + 1) throw new VideoEncodeError('TOO_LONG');
        if ($seconds < (int) config('starttoo.video_min_seconds') - 0.5) throw new VideoEncodeError('TOO_SHORT');
        [$w, $h] = [(int) $video['width'], (int) $video['height']];
        $rotation = (int) ($video['tags']['rotate'] ?? 0);
        foreach ((array) ($video['side_data_list'] ?? []) as $side) {
            if (isset($side['rotation'])) { $rotation = (int) $side['rotation']; break; }
        }
        // ffmpeg 는 회전 정보를 따라 자동으로 돌려 변환한다 → 실제 보이는 크기
        if (in_array(abs($rotation) % 360, [90, 270], true)) [$w, $h] = [$h, $w];
        $hasAudio = !$muted && collect($info['streams'] ?? [])->contains('codec_type', 'audio');

        // 2. 변환
        if ($stage) $stage('transcode');
        $short = min($w, $h);
        // 모든 영상: 원본 화질까지(1080p 포함) 만든다. 원본보다 높은 화질은 만들지 않는다
        $levels = array_values(array_filter(array_keys(self::LEVELS), fn ($s) => $s <= $short + 8));
        if (!$levels) $levels = [max(144, $short - $short % 2)];
        $portrait = $h >= $w;
        $split = '[0:v]split=' . count($levels) . implode('', array_map(fn ($i) => "[s{$i}]", array_keys($levels)));
        $scales = array_map(fn ($i, $s) => "[s{$i}]scale=" . ($portrait ? "{$s}:-2" : "-2:{$s}") . ",setsar=1[v{$i}]", array_keys($levels), $levels);
        $args = [config('starttoo.video_ffmpeg'), '-y', '-hide_banner', '-loglevel', 'error', '-i', $src,
            '-filter_complex', $split . ';' . implode(';', $scales)];
        $map = [];
        foreach ($levels as $i => $s) {
            $kbps = self::LEVELS[$s] ?? 1000;
            // 품질 고정(CRF) + 순간 최대치 제한: 단순한 장면은 작게, 복잡한 장면(선 · 질감)은 최대치까지 쓴다
            array_push($args, '-map', "[v{$i}]", "-maxrate:v:{$i}", "{$kbps}k", "-bufsize:v:{$i}", ($kbps * 2) . 'k');
            if ($hasAudio) array_push($args, '-map', '0:a:0');
            $map[] = $hasAudio ? "v:{$i},a:{$i}" : "v:{$i}";
        }
        // medium: veryfast 보다 2~3배 느리지만 같은 크기에서 화질이 좋다(8코어 기준 90초 영상 1~2분)
        array_push($args, '-c:v', 'libx264', '-preset', 'medium', '-crf', (string) self::CRF, '-profile:v', 'high', '-pix_fmt', 'yuv420p',
            '-force_key_frames', 'expr:gte(t,n_forced*2)', '-sc_threshold', '0');
        if ($hasAudio) array_push($args, '-c:a', 'aac', '-b:a', '160k', '-ac', '2');
        else $args[] = '-an';
        array_push($args, '-f', 'hls', '-hls_time', '2', '-hls_playlist_type', 'vod', '-hls_flags', 'independent_segments',
            '-hls_segment_filename', $work . '/out/v%v/s%03d.ts', '-master_pl_name', 'master.m3u8',
            '-var_stream_map', implode(' ', $map), $work . '/out/v%v/index.m3u8');
        $run = Process::timeout(540)->run($args);
        if (!$run->successful() || !is_file($work . '/out/master.m3u8')) {
            throw new \RuntimeException('ffmpeg failed: ' . mb_substr($run->errorOutput(), 0, 500));
        }

        // 3. 포스터
        if ($stage) $stage('thumbnail');
        $poster = $work . '/poster.jpg';
        $at = max(0, min($coverMs / 1000, $seconds - 0.1));
        $shot = fn ($t) => Process::timeout(60)->run([config('starttoo.video_ffmpeg'), '-y', '-hide_banner', '-loglevel', 'error',
            '-ss', sprintf('%.3f', $t), '-i', $src, '-frames:v', '1', '-q:v', '2', $poster]);
        if (!$shot($at)->successful() || !is_file($poster)) $shot(0);
        if (!is_file($poster)) throw new \RuntimeException('poster failed');

        // 저장(변환본 → 저장소, 포스터 → 작품 사진 변환)
        $disk = Storage::disk('starttoo');
        foreach (File::allFiles($work . '/out') as $file) {
            $rel = str_replace('\\', '/', $file->getRelativePathname());
            $isList = str_ends_with($rel, '.m3u8');
            $stream = fopen($file->getPathname(), 'r');
            $ok = $disk->put($base . '/' . $rel, $stream, ['visibility' => 'private',
                'ContentType' => $isList ? 'application/vnd.apple.mpegurl' : 'video/mp2t',
                // 조각은 바뀌지 않아 1년 캐시, 목록은 짧게
                'CacheControl' => $isList ? 'public, max-age=300' : 'public, max-age=31536000, immutable']);
            if (is_resource($stream)) fclose($stream);
            if (!$ok) throw new \RuntimeException("upload failed: {$rel}");
        }
        $posterPath = app(ArtworkPhotos::class)->storeBytes((string) file_get_contents($poster));

        return [
            'hls_path' => $base . '/master.m3u8', 'poster_path' => $posterPath,
            'duration_ms' => (int) round($seconds * 1000), 'width' => $w, 'height' => $h,
            'renditions' => array_map(fn ($s) => ['short' => $s, 'kbps' => self::LEVELS[$s] ?? null], $levels),
        ];
    }

    /** 작업이 강제로 끝났다(시간 · 메모리 초과) */
    public function markFailed(string $id): void
    {
        $row = $this->videos()->row($id);
        if ($row && $row->status === 'processing') $this->videos()->fail_($row, 'UNKNOWN');
    }

    private function stage(string $id, string $stage): void
    {
        $this->db()->table('starttoo_videos')->where('id', $id)->update(['stage' => $stage, 'updated_at' => now()]);
        $this->videos()->event($id, 'processing', ['stage' => $stage]);
    }
}
