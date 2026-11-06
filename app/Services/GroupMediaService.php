<?php
namespace App\Services;

use App\Models\ProjectAccount;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GroupMediaService
{
    public function __construct(private ProjectDatabase $database, private GroupPlaceService $places, private ImageEncoder $encoder) {}
    private function table() { return $this->database->database()->table('group_media'); }

    public function authorize(ProjectAccount $actor, string $group, string $purpose, ?string $place): void {
        if ($purpose === 'chat') { app(GroupMessageService::class)->authorizePhotoUpload($actor, $group); return; }
        $access = $this->places->show($actor, $group, (string) $place);
        abort_unless($access['can_manage'] || ($purpose === 'care_record' && $access['access'] === 'care'), 403, '이 사진을 올릴 권한이 없습니다.');
    }
    private function matching(object $row, array $data, string $hash): array {
        abort_unless($row->purpose === $data['purpose'] && $row->place_id === ($data['place_id'] ?? null)
            && hash_equals($row->source_hash, $hash) && in_array($row->status, ['pending', 'attached'], true), 409, '같은 사진 요청의 내용이 변경됐어요.');
        return ['id' => $row->id, 'width' => (int) $row->width, 'height' => (int) $row->height];
    }
    public function upload(ProjectAccount $actor, string $group, UploadedFile $file, array $data): array {
        $this->authorize($actor, $group, $data['purpose'], $data['place_id'] ?? null);
        $hash = hash_file('sha256', $file->getRealPath());
        $find = fn () => $this->table()->where('project_id', $actor->project_id)->where('group_id', $group)
            ->where('uploader_id', $actor->id)->where('upload_id', $data['upload_id'])->first();
        if ($row = $find()) return $this->matching($row, $data, $hash);
        abort_unless(extension_loaded('gd') && function_exists('imagejpeg'), 503, '사진 처리 기능을 사용할 수 없습니다.');
        $source = @imagecreatefromstring($file->get());
        if (!$source) throw ValidationException::withMessages(['photo' => '사진을 읽을 수 없어요.']);
        try {
            if (function_exists('exif_read_data')) {
                $exif = @exif_read_data($file->getRealPath());
                $orientation = (int) ($exif['Orientation'] ?? 1);
                if (in_array($orientation, [2, 4, 5, 7], true)) imageflip($source, IMG_FLIP_HORIZONTAL);
                $angle = match ($orientation) { 3, 4 => 180, 5, 6 => -90, 7, 8 => 90, default => 0 };
                if ($angle) { $rotated = imagerotate($source, $angle, 0); if ($rotated) { imagedestroy($source); $source = $rotated; } }
            }
            $shared = $this->encoder->encode($source, 1600); $thumb = $this->encoder->encode($source, 320);
        } finally { imagedestroy($source); }
        $size = getimagesizefromstring($shared); $id = (string) Str::uuid();
        $prefix = 'chat/'.$this->database->code().'/groups/'.$group.'/'.now()->format('Y/m').'/'.$id;
        $paths = [$prefix.'/shared.jpg', $prefix.'/thumb.jpg'];
        try {
            foreach ([$shared, $thumb] as $i => $bytes) {
                if (!Storage::disk('s3')->put($paths[$i], $bytes, ['visibility' => 'private', 'ContentType' => 'image/jpeg'])) throw new \RuntimeException('media put failed');
            }
            $result = $this->database->database()->transaction(function () use ($actor, $group, $data, $hash, $find, $id, $paths, $size, $shared) {
                $this->database->database()->table('care_groups')->where('project_id', $actor->project_id)->where('id', $group)->lockForUpdate()->firstOrFail();
                $this->authorize($actor, $group, $data['purpose'], $data['place_id'] ?? null);
                if ($row = $find()) return [$this->matching($row, $data, $hash), false];
                $this->table()->insert(['id' => $id, 'project_id' => $actor->project_id, 'group_id' => $group,
                    'uploader_id' => $actor->id, 'upload_id' => $data['upload_id'], 'purpose' => $data['purpose'],
                    'place_id' => $data['place_id'] ?? null, 'status' => 'pending', 'disk' => 's3',
                    'object_key' => $paths[0], 'thumbnail_key' => $paths[1], 'mime' => 'image/jpeg',
                    'width' => $size[0], 'height' => $size[1], 'bytes' => strlen($shared), 'source_hash' => $hash,
                    'position' => 0, 'created_at' => now(), 'updated_at' => now()]);
                return [['id' => $id, 'width' => $size[0], 'height' => $size[1]], true];
            }, 3);
        } catch (\Throwable $e) {
            $this->cleanup($paths);
            if ($e instanceof ValidationException || $e instanceof \Symfony\Component\HttpKernel\Exception\HttpException) throw $e;
            Log::warning('Group photo upload failed.', ['type' => get_class($e)]);
            abort(503, '사진을 저장하지 못했어요. 다시 시도해 주세요.');
        }
        if (!$result[1]) $this->cleanup($paths);
        return $result[0];
    }
    private function cleanup(array $paths): void {
        try { if (!Storage::disk('s3')->delete($paths)) Log::warning('Group photo temporary cleanup failed.'); }
        catch (\Throwable) { Log::warning('Group photo temporary cleanup failed.'); }
    }
    /** Caller holds the resource's group lock, inside the same transaction. */
    public function claim(ProjectAccount $actor, string $group, string $purpose, ?string $place, array $ids): array {
        if (!$ids) return [];
        $rows = $this->table()->where('project_id', $actor->project_id)->where('group_id', $group)
            ->whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');
        abort_unless(count($ids) <= 4 && count(array_unique($ids)) === count($ids) && $rows->count() === count($ids), 422, '사진을 다시 선택해 주세요.');
        foreach ($ids as $id) {
            $row = $rows[$id];
            abort_unless((int) $row->uploader_id === (int) $actor->id && $row->purpose === $purpose && $row->place_id === $place
                && $row->status === 'pending' && $row->purged_at === null && $row->created_at > now()->subDay()->format('Y-m-d H:i:s'), 422, '사진을 다시 올려 주세요.');
        }
        return array_map(fn ($id) => $rows[$id], $ids);
    }
    public function attach(array $rows, string $field, string|int $id): void {
        if (!in_array($field, ['message_id', 'animal_id', 'care_record_id'], true)) throw new \LogicException('Invalid media association');
        foreach ($rows as $position => $row) $this->table()->where('id', $row->id)->update([
            'status' => 'attached', $field => $id, 'position' => $position, 'attached_at' => now(), 'updated_at' => now()]);
    }
    public function release(array $ids, int $actor): void {
        if ($ids) $this->table()->whereIn('id', $ids)->where('status', 'attached')->update([
            'status' => 'deleted', 'deleted_by' => $actor, 'deleted_at' => now(), 'purge_after' => now()->addDays(30), 'updated_at' => now()]);
    }
    public function presentMany(string $field, array $ids): array {
        if (!$ids) return [];
        if (!in_array($field, ['id', 'message_id', 'care_record_id'], true)) throw new \LogicException('Invalid media lookup');
        $result = []; $until = now()->addMinutes(10);
        foreach ($this->table()->whereIn($field, $ids)->where('status', 'attached')->whereNull('purged_at')->orderBy('position')->get() as $row) {
            $entry = ['id' => $row->id, 'width' => (int) $row->width, 'height' => (int) $row->height,
                'url' => Storage::disk($row->disk)->temporaryUrl($row->object_key, $until),
                'thumbnail_url' => Storage::disk($row->disk)->temporaryUrl($row->thumbnail_key, $until), 'expires_at' => $until->toISOString()];
            $result[$row->{$field}][] = $entry;
        }
        return $result;
    }
    public function purge(int $limit): int {
        $expired = $this->table()->where('status', 'pending')->where('created_at', '<=', now()->subDay())->limit($limit)->pluck('id');
        $this->table()->whereIn('id', $expired)->where('status', 'pending')->update(['status' => 'deleted', 'deleted_at' => now(), 'purge_after' => now(), 'updated_at' => now()]);
        $rows = $this->table()->where('status', 'deleted')->whereNull('purged_at')->where('purge_after', '<=', now())->limit($limit)->get();
        $count = 0;
        foreach ($rows as $row) {
            try {
                $paths = array_values(array_filter([$row->object_key, $row->thumbnail_key]));
                if ($paths && !Storage::disk($row->disk)->delete($paths)) continue;
                $this->table()->where('id', $row->id)->where('status', 'deleted')->update([
                    'object_key' => null, 'thumbnail_key' => null, 'purged_at' => now(), 'updated_at' => now()]);
                $count++;
            } catch (\Throwable) { Log::warning('Group photo purge failed.'); }
        }
        $this->database->database()->table('device_push_tokens')->whereIn('session_id', function ($q) {
            $q->select('id')->from('project_auth_sessions')->where('last_seen_at', '<=', now()->subDays(90));
        })->delete();
        return $count;
    }
}
