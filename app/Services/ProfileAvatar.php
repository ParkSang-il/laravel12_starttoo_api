<?php

namespace App\Services;

use App\Models\ProjectAccount;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ProfileAvatar
{
    public function upload(ProjectAccount $account, UploadedFile $file): ProjectAccount
    {
        abort_unless(extension_loaded('gd'), 503, '사진 처리 기능을 사용할 수 없습니다.');
        $source = @imagecreatefromstring($file->get());
        if (! $source) {
            throw ValidationException::withMessages(['photo' => '사진을 읽을 수 없습니다.']);
        }
        try {
            $original = app(ImageEncoder::class)->encode($source, 1080);
            $thumbnail = app(ImageEncoder::class)->encode($source, 192);
        } finally {
            imagedestroy($source);
        }
        $prefix = 'profile/'.now()->format('Y/m/d').'/'.$account->project_code.'/'.$account->id.'/'.Str::uuid();
        $extension = function_exists('imagejpeg') ? 'jpg' : 'png';
        $mime = $extension === 'jpg' ? 'image/jpeg' : 'image/png';
        $paths = [$prefix.'.'.$extension, $prefix.'-thumb.'.$extension];
        try {
            foreach ([$original, $thumbnail] as $i => $bytes) {
                if (! Storage::disk('s3')->put($paths[$i], $bytes, ['visibility' => 'private', 'ContentType' => $mime])) {
                    throw new RuntimeException('Profile object upload failed.');
                }
            }
            [$saved, $old] = app(ProjectDatabase::class)->database()->transaction(function () use ($account, $paths) {
                $locked = ProjectAccount::where('project_id', $account->project_id)->lockForUpdate()->findOrFail($account->id);
                $old = $locked->getAttributes();
                $locked->forceFill(['avatar_disk' => 's3', 'avatar_path' => $paths[0],
                    'avatar_thumbnail_path' => $paths[1], 'avatar_url' => null, 'avatar_thumbnail_url' => null])->save();

                return [$locked, $old];
            });
        } catch (Throwable $e) {
            $this->cleanup(['avatar_disk' => 's3', 'avatar_path' => $paths[0], 'avatar_thumbnail_path' => $paths[1]]);
            report($e);
            abort(503, '사진을 저장하지 못했습니다. 잠시 후 다시 시도해 주세요.');
        }
        $this->cleanup($old);

        return $saved;
    }

    public function delete(ProjectAccount $account): ProjectAccount
    {
        [$saved, $old] = app(ProjectDatabase::class)->database()->transaction(function () use ($account) {
            $locked = ProjectAccount::where('project_id', $account->project_id)->lockForUpdate()->findOrFail($account->id);
            $old = $locked->getAttributes();
            $locked->forceFill(['avatar_disk' => null, 'avatar_path' => null, 'avatar_thumbnail_path' => null,
                'avatar_url' => null, 'avatar_thumbnail_url' => null])->save();

            return [$locked, $old];
        });
        $this->cleanup($old);

        return $saved;
    }

    private function cleanup(array $attributes): void
    {
        if (($attributes['avatar_disk'] ?? null) !== 's3') {
            return;
        }
        $paths = array_values(array_filter([$attributes['avatar_path'] ?? null, $attributes['avatar_thumbnail_path'] ?? null],
            fn ($path) => is_string($path) && str_starts_with($path, 'profile/')));
        if (! $paths) {
            return;
        }
        try {
            if (! Storage::disk('s3')->delete($paths)) {
                Log::warning('Profile object cleanup failed.');
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
