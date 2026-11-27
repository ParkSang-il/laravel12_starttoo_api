<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 운영자 · 외부 광고 영상(무빙 피드 moving_inline 만, 사진 또는 영상 중 하나).
 * 원본을 로컬 디스크에 두고 TranscodeStarttooAdClip(video-transcode 큐)이 무빙과 같은 변환(VideoTranscoder::encodeFile)을 한다.
 * 끝나면 포스터를 광고 사진(image_path)으로 쓰고, 앱 응답의 video(hls_url · poster_url · duration_ms · muted)로 재생한다.
 */
class AdClips
{
    public const SLOTS = ['moving_inline'];

    private function db() { return app(ProjectDatabase::class)->database(); }

    /** 원본(업로드 파일 또는 로컬 디스크 경로)을 맡기고 변환을 시작한다 */
    public function attach(int $adId, UploadedFile|string $source): void
    {
        $key = 'ad-clips/' . $adId . '-' . Str::random(8) . '.mp4';
        if ($source instanceof UploadedFile) {
            Storage::disk('local')->putFileAs(dirname($key), $source, basename($key));
        } else {
            Storage::disk('local')->copy($source, $key);
        }
        $old = $this->db()->table('starttoo_ads')->where('id', $adId)->first(['clip_source', 'clip_hls_path']);
        $this->db()->table('starttoo_ads')->where('id', $adId)->update([
            'clip_status' => 'processing', 'clip_source' => $key, 'clip_error' => null, 'updated_at' => now(),
        ]);
        if ($old?->clip_source) Storage::disk('local')->delete($old->clip_source);
        \App\Jobs\TranscodeStarttooAdClip::dispatch($adId);
    }

    /** 변환(큐 작업). $final: 마지막 시도면 알 수 없는 오류도 실패로 확정 */
    public function process(int $adId, bool $final): void
    {
        $ad = $this->db()->table('starttoo_ads')->where('id', $adId)->first();
        if (!$ad || $ad->clip_status !== 'processing' || !$ad->clip_source) return;
        $work = storage_path('app/video-work/ad-' . $adId);
        File::deleteDirectory($work);
        File::ensureDirectoryExists($work);
        try {
            $src = Storage::disk('local')->path($ad->clip_source);
            if (!is_file($src)) throw new VideoEncodeError('CORRUPTED');
            $base = 'video/starttoo/ads/' . $adId . '-' . Str::random(6);
            $out = app(VideoTranscoder::class)->encodeFile($src, $work, $base);
            $old = $ad->clip_hls_path;
            $this->db()->table('starttoo_ads')->where('id', $adId)->update([
                'clip_status' => 'ready', 'clip_hls_path' => $out['hls_path'], 'clip_duration_ms' => $out['duration_ms'],
                // 포스터 = 광고 사진(사진만 보이는 곳 · 이전 앱 · 콘솔 미리보기)
                'image_path' => $out['poster_path'], 'clip_source' => null, 'updated_at' => now(),
            ]);
            Storage::disk('local')->delete($ad->clip_source);
            if ($old && $old !== $out['hls_path']) $this->deleteHls($old);
        } catch (VideoEncodeError $e) {
            $this->failed($adId, $e->codeName);
        } catch (\Throwable $e) {
            report($e);
            if ($final) {
                $this->failed($adId, 'UNKNOWN');
                return;
            }
            throw $e;
        } finally {
            File::deleteDirectory($work);
        }
    }

    public function failed(int $adId, string $code): void
    {
        $this->db()->table('starttoo_ads')->where('id', $adId)->where('clip_status', 'processing')
            ->update(['clip_status' => 'failed', 'clip_error' => $code, 'updated_at' => now()]);
    }

    /** 영상을 떼고 사진 광고로 돌린다(사진으로 바꿀 때) */
    public function detach(int $adId): void
    {
        $ad = $this->db()->table('starttoo_ads')->where('id', $adId)->first(['clip_source', 'clip_hls_path']);
        if (!$ad) return;
        if ($ad->clip_source) Storage::disk('local')->delete($ad->clip_source);
        if ($ad->clip_hls_path) $this->deleteHls($ad->clip_hls_path);
        $this->db()->table('starttoo_ads')->where('id', $adId)->update(['clip_status' => null, 'clip_source' => null,
            'clip_hls_path' => null, 'clip_duration_ms' => null, 'clip_error' => null, 'updated_at' => now()]);
    }

    /** 앱 응답용(작가 광고의 video 와 같은 모양) */
    public function playback(object $ad): ?array
    {
        if (($ad->clip_status ?? null) !== 'ready' || !$ad->clip_hls_path) return null;
        return ['id' => 'ad-' . $ad->id, 'hls_url' => app(Videos::class)->cdn($ad->clip_hls_path),
            'poster_url' => app(MediaUrls::class)->url($ad->image_path, 500),
            'duration_ms' => (int) $ad->clip_duration_ms, 'width' => null, 'height' => null, 'muted' => false];
    }

    /** 콘솔 표시용 상태 문구 */
    public static function label(?string $status, ?string $error): ?string
    {
        return match ($status) {
            'processing' => '영상 변환 중(끝나면 노출)',
            'ready' => '영상',
            'failed' => '영상 변환 실패 · ' . (Videos::ERRORS[$error ?? ''] ?? Videos::ERRORS['UNKNOWN']),
            default => null,
        };
    }

    private function deleteHls(string $hlsPath): void
    {
        try {
            Storage::disk('starttoo')->deleteDirectory(dirname($hlsPath));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
