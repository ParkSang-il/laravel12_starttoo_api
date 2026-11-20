<?php

namespace App\Services\Starttoo;

use App\Services\ImageEncoder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ArtworkPhotos
{
    public function store(int $userId, UploadedFile $file): string
    {
        return $this->storeBytes((string) $file->get());
    }

    /** 원본을 변환하지 않고 임시 경로에 저장한다(백그라운드 변환 전). */
    public function storeRaw(UploadedFile $file): string
    {
        $path = 'artwork/starttoo/tmp/'.now('Asia/Seoul')->format('Y/m/d').'/'.Str::uuid().'.raw';
        if (!Storage::disk('starttoo')->put($path, (string) $file->get(), ['visibility' => 'private'])) {
            throw new HttpResponseException(response()->json([
                'code' => 'ARTWORK_PHOTO_FAILED', 'message' => '작품 사진을 저장하지 못했어요. 다시 시도해주세요.',
            ], 503));
        }
        return $path;
    }

    public function readRaw(string $path): string
    {
        $bytes = Storage::disk('starttoo')->get($path);
        if (!is_string($bytes) || $bytes === '') throw new \RuntimeException('Raw artwork image is missing.');
        return $bytes;
    }

    /** 원본 바이트를 변환(EXIF 제거·긴 변 1600px·재인코딩)해 저장하고 경로를 돌려준다. */
    public function storeBytes(string $original): string
    {
        $path = null;
        try {
            if (!extension_loaded('gd')) {
                throw new \RuntimeException('GD is required for artwork images.');
            }
            $source = @imagecreatefromstring($original);
            if (!$source) {
                throw new \RuntimeException('Unable to decode artwork image.');
            }
            try {
                $bytes = app(ImageEncoder::class)->encode($source, 1600);
            } finally {
                imagedestroy($source);
            }
            $jpeg = function_exists('imagejpeg');
            // 저장 경로: artwork/starttoo/연도/월/일/UUID. 기존 회원번호 경로의 사진은 DB 경로 그대로 유지된다.
            $path = 'artwork/starttoo/'.now('Asia/Seoul')->format('Y/m/d').'/'.Str::uuid().($jpeg ? '.jpg' : '.png');
            if (!Storage::disk('starttoo')->put($path, $bytes, [
                'visibility' => 'private', 'ContentType' => $jpeg ? 'image/jpeg' : 'image/png',
            ])) {
                throw new \RuntimeException('Unable to store artwork image.');
            }
            return $path;
        } catch (Throwable $e) {
            $this->delete($path === null ? [] : [$path]);
            report($e);
            throw new HttpResponseException(response()->json([
                'code' => 'ARTWORK_PHOTO_FAILED', 'message' => '작품 사진을 저장하지 못했어요. 다시 시도해주세요.',
            ], 503));
        }
    }

    public function delete(array $paths): void
    {
        $paths = array_values(array_filter($paths, fn ($path) => is_string($path) && str_starts_with($path, 'artwork/starttoo/')));
        if (!$paths) return;
        try {
            if (!Storage::disk('starttoo')->delete($paths)) {
                report(new \RuntimeException('Unable to clean up artwork images.'));
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
