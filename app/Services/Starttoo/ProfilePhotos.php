<?php

namespace App\Services\Starttoo;

use App\Services\ImageEncoder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ProfilePhotos
{
    public function store(int $userId, UploadedFile $file): string
    {
        $path = null;
        try {
            if (!extension_loaded('gd')) {
                throw new \RuntimeException('GD is required for STARTTOO profile photos.');
            }
            $source = @imagecreatefromstring($file->get());
            if (!$source) {
                throw new \RuntimeException('Unable to decode profile photo.');
            }
            try {
                // 기존 이미지 인코더로 축소·재인코딩하며 원본 메타데이터는 보관하지 않는다.
                $bytes = app(ImageEncoder::class)->encode($source, 1080);
            } finally {
                imagedestroy($source);
            }
            $jpeg = function_exists('imagejpeg');
            $path = 'profile/starttoo/'.$userId.'/'.Str::uuid().($jpeg ? '.jpg' : '.png');
            if (!Storage::disk('starttoo')->put($path, $bytes, [
                'visibility' => 'private', 'ContentType' => $jpeg ? 'image/jpeg' : 'image/png',
            ])) {
                throw new \RuntimeException('Unable to store profile photo.');
            }
            return $path;
        } catch (Throwable $e) {
            $this->delete($path);
            report($e);
            throw new HttpResponseException(response()->json([
                'code' => 'PROFILE_PHOTO_FAILED', 'message' => '사진을 저장하지 못했어요. 다시 시도해주세요.',
            ], 503));
        }
    }

    public function delete(?string $path): void
    {
        if (!$path || !str_starts_with($path, 'profile/starttoo/')) {
            return;
        }
        try {
            if (!Storage::disk('starttoo')->delete($path)) {
                report(new \RuntimeException('Unable to clean up STARTTOO profile photo.'));
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
