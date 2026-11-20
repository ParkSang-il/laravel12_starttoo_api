<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * 작품 사진 선업로드. 사진을 고르는 즉시 1장씩 받아 검사·변환(EXIF 제거·재인코딩) 후
 * 비공개 S3에 저장하고, 작품 등록·편집은 media_id로 첨부한다.
 */
class ArtworkUploads
{
    /** 게시하지 않은 사진 보관 시간 */
    private const EXPIRES_HOURS = 24;

    /** 회원별 미첨부 사진 상한(작품 1개 최대 10장의 3배) */
    private const PENDING_LIMIT = 30;

    public function upload(string $token, array $input, mixed $file, AuthSessions $sessions, Artworks $artworks): array
    {
        $userId = (int) $artworks->ready($token, $sessions)['account']['id'];
        if (Validator::make($input, ['request_key' => ['required', 'uuid'], 'async' => ['sometimes', 'boolean']])->fails()) {
            $this->fail(422, 'INVALID_ARTWORK_PHOTOS', '사진 업로드 요청을 확인해주세요.');
        }
        if (!$file instanceof UploadedFile || Validator::make(['photo' => $file], [
            'photo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=4096,max_height=4096'],
        ])->fails()) {
            $this->fail(422, 'INVALID_ARTWORK_PHOTOS', '사진은 5MB 이하의 JPG·PNG·WebP로 선택해주세요.');
        }
        $hash = hash_file('sha256', $file->getRealPath());
        $db = app(ProjectDatabase::class)->database();
        $find = fn () => $db->table('starttoo_artwork_uploads')->where('user_id', $userId)
            ->where('request_key', $input['request_key'])->first();
        // 만료된 미첨부 기록은 같은 요청 키의 재시도보다 먼저 정리한다.
        $this->purgeExpired($userId);
        // 응답을 받지 못해 다시 보낸 같은 사진은 이미 저장한 결과를 돌려준다.
        if ($existing = $find()) return $this->result($existing, $hash);
        $pending = $db->table('starttoo_artwork_uploads')->where('user_id', $userId)->whereNull('attached_at')->count();
        if ($pending >= self::PENDING_LIMIT) {
            $this->fail(429, 'ARTWORK_UPLOAD_LIMIT', '올려둔 사진이 너무 많아요. 작품을 게시하거나 잠시 후 다시 시도해주세요.');
        }

        // 원본만 빠르게 받아 두고 변환은 백그라운드 작업이 맡는다.
        $photos = app(ArtworkPhotos::class);
        $path = $photos->storeRaw($file);
        $id = (string) Str::uuid();
        try {
            $db->table('starttoo_artwork_uploads')->insert([
                'id' => $id, 'user_id' => $userId, 'request_key' => $input['request_key'],
                'source_hash' => $hash, 'path' => $path, 'status' => 'processing', 'created_at' => now(),
            ]);
        } catch (QueryException $e) {
            // 같은 키의 동시 재시도: 먼저 저장된 사진을 쓰고 이번 파일은 지운다.
            $photos->delete([$path]);
            if ($existing = $find()) return $this->result($existing, $hash);
            throw $e;
        }
        // async를 보내지 않는 이전 버전 앱은 응답에 사진 주소가 바로 필요하므로 이 요청에서 변환한다.
        if (filter_var($input['async'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            try {
                \App\Jobs\ProcessStarttooArtworkUpload::dispatch($id);
            } catch (\Throwable $e) {
                // 큐에 넣지 못해도 상태 조회가 직접 변환한다.
                report($e);
            }
        } else {
            $this->process($id, true);
            $row = $db->table('starttoo_artwork_uploads')->where('id', $id)->first();
            if ($row->status !== 'ready') {
                $this->fail(503, 'ARTWORK_PHOTO_FAILED', '작품 사진을 저장하지 못했어요. 다시 시도해주세요.');
            }
        }
        return $this->view($db->table('starttoo_artwork_uploads')->where('id', $id)->first());
    }

    /**
     * 처리 상태 조회. 변환 대기가 길어지면(큐 지연·worker 중단) 이 요청에서 직접 변환한다.
     */
    public function status(string $token, string $id, AuthSessions $sessions, Artworks $artworks): array
    {
        $userId = (int) $artworks->ready($token, $sessions)['account']['id'];
        $db = app(ProjectDatabase::class)->database();
        $row = $db->table('starttoo_artwork_uploads')->where('id', $id)->where('user_id', $userId)->first();
        if (!$row) $this->fail(404, 'ARTWORK_MEDIA_UNAVAILABLE', '사진을 확인하지 못했어요. 사진을 다시 선택해주세요.');
        if ($row->status === 'processing' && Carbon::parse($row->created_at)->lt(now()->subSeconds(15))) {
            $this->process($id, true);
            $row = $db->table('starttoo_artwork_uploads')->where('id', $id)->first();
        }
        return $this->view($row);
    }

    /**
     * 원본을 변환해 첨부 가능한 사진으로 바꾼다. 같은 사진을 동시에 두 번 변환하지 않는다.
     * $final이면 실패 시 failed로 확정한다.
     */
    public function process(string $id, bool $final = false): void
    {
        $db = app(ProjectDatabase::class)->database();
        // 2분 넘게 잡혀 있는 작업은 중단된 것으로 보고 다시 잡는다.
        $locked = $db->table('starttoo_artwork_uploads')->where('id', $id)->where('status', 'processing')
            ->where(fn ($q) => $q->whereNull('locked_at')->orWhere('locked_at', '<', now()->subMinutes(2)))
            ->update(['locked_at' => now(), 'attempts' => $db->raw('attempts + 1')]);
        if (!$locked) return;
        $row = $db->table('starttoo_artwork_uploads')->where('id', $id)->first();
        $photos = app(ArtworkPhotos::class);
        try {
            $path = $photos->storeBytes($photos->readRaw($row->path));
        } catch (\Throwable $e) {
            if ($final || (int) $row->attempts >= 3) {
                $this->markFailed($id);
            } else {
                $db->table('starttoo_artwork_uploads')->where('id', $id)->update(['locked_at' => null]);
            }
            if (!$final) throw $e;
            return;
        }
        $updated = $db->table('starttoo_artwork_uploads')->where('id', $id)->where('status', 'processing')
            ->update(['path' => $path, 'status' => 'ready', 'locked_at' => null, 'processed_at' => now()]);
        // 그 사이 정리된 사진이면 방금 만든 파일을 지운다. 성공하면 원본을 지운다.
        $photos->delete([$updated ? $row->path : $path]);
    }

    public function markFailed(string $id): void
    {
        $db = app(ProjectDatabase::class)->database();
        $row = $db->table('starttoo_artwork_uploads')->where('id', $id)->where('status', 'processing')->first();
        if (!$row) return;
        if ($db->table('starttoo_artwork_uploads')->where('id', $id)->where('status', 'processing')
            ->update(['status' => 'failed', 'locked_at' => null, 'processed_at' => now()])) {
            app(ArtworkPhotos::class)->delete([$row->path]);
        }
    }

    private function view(object $row): array
    {
        return ['id' => $row->id, 'status' => $row->status,
            'url' => $row->status === 'ready' ? app(MediaUrls::class)->url($row->path, 500) : null];
    }

    /**
     * 작품 등록·편집 트랜잭션 안에서 호출한다. 본인이 올린 미첨부 사진만 첨부하고
     * media_id => S3 경로를 돌려준다.
     */
    public function claim(int $userId, array $mediaIds): array
    {
        if (!$mediaIds) return [];
        $db = app(ProjectDatabase::class)->database();
        $rows = $db->table('starttoo_artwork_uploads')->where('user_id', $userId)
            ->whereIn('id', $mediaIds)->lockForUpdate()->get()->keyBy('id');
        $limit = now()->subHours(self::EXPIRES_HOURS);
        foreach ($mediaIds as $mediaId) {
            $row = $rows->get($mediaId);
            if (!$row || $row->attached_at !== null || Carbon::parse($row->created_at)->lt($limit) || $row->status === 'failed') {
                $this->fail(409, 'ARTWORK_MEDIA_UNAVAILABLE', '사진을 확인하지 못했어요. 사진을 다시 선택해주세요.');
            }
            // 변환을 마친 사진만 게시할 수 있다.
            if ($row->status !== 'ready') {
                $this->fail(409, 'ARTWORK_MEDIA_PROCESSING', '사진을 정리하고 있어요. 잠시 후 다시 시도해주세요.');
            }
        }
        $db->table('starttoo_artwork_uploads')->whereIn('id', $mediaIds)->update(['attached_at' => now()]);
        return $rows->map(fn ($row) => $row->path)->all();
    }

    /** 작품 사진을 지울 때 업로드 기록도 함께 지운다. */
    public function forget(array $paths): void
    {
        if (!$paths) return;
        app(ProjectDatabase::class)->database()->table('starttoo_artwork_uploads')->whereIn('path', $paths)->delete();
    }

    /** 이 회원이 게시하지 않고 24시간이 지난 사진을 지운다. 예약 작업 없이 업로드 때 정리한다. */
    public function purgeExpired(int $userId): void
    {
        $db = app(ProjectDatabase::class)->database();
        $rows = $db->table('starttoo_artwork_uploads')->where('user_id', $userId)->whereNull('attached_at')
            ->where('created_at', '<', now()->subHours(self::EXPIRES_HOURS))->limit(100)->get(['id', 'path']);
        $paths = [];
        foreach ($rows as $row) {
            // 그 사이 작품에 첨부됐다면 지우지 않는다. 실제로 지운 행의 파일만 정리한다.
            if ($db->table('starttoo_artwork_uploads')->where('id', $row->id)->whereNull('attached_at')->delete()) {
                $paths[] = $row->path;
            }
        }
        app(ArtworkPhotos::class)->delete($paths);
    }

    private function result(object $row, string $hash): array
    {
        if ($row->source_hash !== $hash) {
            $this->fail(409, 'ARTWORK_REQUEST_CONFLICT', '사진 업로드 요청이 변경됐어요. 사진을 다시 선택해주세요.');
        }
        return $this->view($row);
    }

    private function fail(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(compact('code', 'message'), $status));
    }
}
