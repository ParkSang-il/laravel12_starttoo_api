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
        $path = null;
        try {
            if (!extension_loaded('gd')) {
                throw new \RuntimeException('GD is required for artwork images.');
            }
            $source = @imagecreatefromstring($file->get());
            if (!$source) {
                throw new \RuntimeException('Unable to decode artwork image.');
            }
            try {
                $bytes = app(ImageEncoder::class)->encode($source, 1600);
            } finally {
                imagedestroy($source);
            }
            $jpeg = function_exists('imagejpeg');
            $path = 'artwork/starttoo/'.$userId.'/'.Str::uuid().($jpeg ? '.jpg' : '.png');
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
