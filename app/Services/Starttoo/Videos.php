<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Aws\S3\S3Client;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * 무빙(동영상) 1차 — docs/video.md.
 * 앱이 메타(게시 정보 · 크기 · 길이 · 해시)를 보내면 원본을 Object Storage 에 바로 나눠 올릴 서명 주소를 준다.
 * 다 올리면(complete) 변환 작업(TranscodeStarttooVideo, video-transcode 큐)이 HLS · 포스터를 만들고,
 * 자동 게시면 포스터를 첫 사진으로 기존 작품 · 게시물을 만든다. 게시 전에는 본인 게시 대기함에만 보인다.
 */
class Videos
{
    /** 나눠 올리는 조각 크기(마지막 조각만 작을 수 있다, S3 최소 5MB) */
    public const PART_BYTES = 10 * 1024 * 1024;

    /** 끝나지 않은 업로드를 정리하는 시간 */
    public const UPLOAD_HOURS = 24;

    /** 사용자에게 보이는 실패 이유 */
    public const ERRORS = [
        'TOO_LONG' => '영상이 너무 길어요',
        'TOO_SHORT' => '영상이 너무 짧아요',
        'UNSUPPORTED' => '지원하지 않는 형식이에요',
        'CORRUPTED' => '파일이 손상됐어요',
        'UPLOAD_EXPIRED' => '올리는 시간이 너무 오래 걸려 멈췄어요',
        'PUBLISH_FAILED' => '게시 정보를 확인하지 못했어요. 다시 올려주세요',
        'UNKNOWN' => '잠시 후 다시 올려주세요',
    ];

    private function db() { return app(ProjectDatabase::class)->database(); }

    public function client(): S3Client { return Storage::disk('starttoo')->getClient(); }

    public function bucket(): string { return (string) config('filesystems.disks.starttoo.bucket'); }

    // ── 업로드 ─────────────────────────────────────────

    /**
     * 업로드 세션 만들기.
     * in: request_key, target(artwork · post), payload(작품: kind · description · styles … / 게시물: content),
     *     size_bytes, duration_ms, width, height, sha256, cover_ms, muted, auto_publish
     */
    public function create(int $userId, array $in): array
    {
        if (trim((string) config('starttoo.video_cdn_url')) === '') $this->fail(503, 'VIDEO_DISABLED', '무빙은 아직 준비 중이에요.');
        $user = $this->db()->table('starttoo_users')->where('id', $userId)->first(['artist_status']);
        // 1차: 승인된 아티스트만 올린다
        if (($user->artist_status ?? null) !== 'approved') $this->fail(403, 'VIDEO_ARTIST_ONLY', '무빙은 아티스트만 올릴 수 있어요.');
        $maxBytes = (int) config('starttoo.video_max_mb') * 1024 * 1024;
        $v = Validator::make($in, [
            'request_key' => ['required', 'uuid'],
            'target' => ['required', Rule::in(['artwork', 'post'])],
            'payload' => ['required', 'array'],
            'size_bytes' => ['required', 'integer', 'min:1', 'max:' . $maxBytes],
            'duration_ms' => ['required', 'integer', 'min:0'],
            'width' => ['nullable', 'integer', 'min:1', 'max:8192'],
            'height' => ['nullable', 'integer', 'min:1', 'max:8192'],
            'sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'cover_ms' => ['nullable', 'integer', 'min:0'],
            'muted' => ['nullable', 'boolean'],
            'auto_publish' => ['nullable', 'boolean'],
        ]);
        if ($v->fails()) {
            if ($v->errors()->has('size_bytes')) $this->fail(422, 'VIDEO_TOO_LARGE', '영상은 ' . config('starttoo.video_max_mb') . 'MB 이하로 올려주세요.');
            $this->fail(422, 'INVALID_VIDEO', '영상 정보를 확인해주세요.');
        }
        $d = $v->validated();
        $this->checkDuration((int) $d['duration_ms']);
        $payload = $this->validPayload($d['target'], $d['payload'], $d['request_key']);

        $db = $this->db();
        $existing = $db->table('starttoo_videos')->where('user_id', $userId)->where('request_key', $d['request_key'])->first();
        // 응답을 받지 못해 다시 보낸 같은 요청: 남은 조각 주소를 다시 준다
        if ($existing) return $this->session($existing, null);

        if ($db->table('starttoo_videos')->where('user_id', $userId)->where('sha256', $d['sha256'])
            ->whereNotIn('status', ['upload_failed', 'encode_failed', 'rejected'])->exists()) {
            $this->fail(409, 'VIDEO_DUPLICATE', '이미 올린 영상이에요.');
        }
        if ($db->table('starttoo_videos')->where('user_id', $userId)->where('status', 'uploading')->exists()) {
            $this->fail(409, 'VIDEO_UPLOAD_BUSY', '올리는 영상이 끝나면 올릴 수 있어요.');
        }
        $limit = (int) config('starttoo.video_daily_limit');
        if ($limit > 0 && $db->table('starttoo_videos')->where('user_id', $userId)->where('created_at', '>=', today())->count() >= $limit) {
            $this->fail(429, 'VIDEO_DAILY_LIMIT', "무빙은 하루 {$limit}개까지 올릴 수 있어요.");
        }

        $id = (string) Str::uuid();
        $key = 'video/starttoo/src/' . now('Asia/Seoul')->format('Y/m/d') . '/' . $id . '.mp4';
        $upload = $this->client()->createMultipartUpload(['Bucket' => $this->bucket(), 'Key' => $key, 'ContentType' => 'video/mp4']);
        $parts = (int) max(1, ceil((int) $d['size_bytes'] / self::PART_BYTES));
        $db->table('starttoo_videos')->insert([
            'id' => $id, 'user_id' => $userId, 'request_key' => $d['request_key'], 'target' => $d['target'],
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'status' => 'uploading',
            'auto_publish' => (bool) ($d['auto_publish'] ?? true), 'source_key' => $key, 'upload_id' => $upload['UploadId'],
            'parts_total' => $parts, 'size_bytes' => (int) $d['size_bytes'], 'sha256' => $d['sha256'],
            'duration_ms' => (int) $d['duration_ms'], 'width' => $d['width'] ?? null, 'height' => $d['height'] ?? null,
            'cover_ms' => (int) ($d['cover_ms'] ?? 0), 'muted' => (bool) ($d['muted'] ?? false),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->event($id, 'uploading', ['parts' => $parts, 'size' => (int) $d['size_bytes']]);
        return $this->session($this->row($id), null);
    }

    /** 만료된 조각 서명 주소 다시 받기(numbers 를 비우면 전체) */
    public function parts(int $userId, string $id, array $in): array
    {
        $row = $this->mine($userId, $id);
        if ($row->status !== 'uploading' || !$row->upload_id) $this->fail(409, 'VIDEO_NOT_UPLOADING', '올리는 중인 영상이 아니에요.');
        $numbers = array_values(array_filter(array_map('intval', (array) ($in['numbers'] ?? [])), fn ($n) => $n >= 1 && $n <= (int) $row->parts_total));
        return $this->session($row, $numbers ?: null);
    }

    /** 모든 조각을 올렸다: parts = [{n, etag}] → 합치기 → 변환 큐 */
    public function complete(int $userId, string $id, array $in): array
    {
        $row = $this->mine($userId, $id);
        if ($row->status !== 'uploading') return $this->view($row);
        $parts = [];
        foreach ((array) ($in['parts'] ?? []) as $p) {
            $n = (int) ($p['n'] ?? 0);
            $etag = trim((string) ($p['etag'] ?? ''));
            if ($n < 1 || $n > (int) $row->parts_total || $etag === '') continue;
            $parts[$n] = ['PartNumber' => $n, 'ETag' => '"' . trim($etag, '"') . '"'];
        }
        // 앱이 백그라운드에 있는 동안 일부 조각의 ETag 를 받지 못했을 수 있다 → 저장소에 올라간 조각 목록으로 채운다
        if (count($parts) !== (int) $row->parts_total) {
            try {
                $marker = null;
                do {
                    $res = $this->client()->listParts(['Bucket' => $this->bucket(), 'Key' => $row->source_key,
                        'UploadId' => $row->upload_id] + ($marker ? ['PartNumberMarker' => $marker] : []));
                    foreach ($res['Parts'] ?? [] as $p) {
                        $n = (int) $p['PartNumber'];
                        if ($n >= 1 && $n <= (int) $row->parts_total) $parts[$n] = ['PartNumber' => $n, 'ETag' => $p['ETag']];
                    }
                    $marker = !empty($res['IsTruncated']) ? ($res['NextPartNumberMarker'] ?? null) : null;
                } while ($marker);
            } catch (\Throwable $e) {
                report($e);
            }
        }
        if (count($parts) !== (int) $row->parts_total) $this->fail(422, 'INVALID_VIDEO_PARTS', '아직 다 올리지 못했어요.');
        ksort($parts);
        try {
            $this->client()->completeMultipartUpload(['Bucket' => $this->bucket(), 'Key' => $row->source_key,
                'UploadId' => $row->upload_id, 'MultipartUpload' => ['Parts' => array_values($parts)]]);
        } catch (\Throwable $e) {
            report($e);
            $this->fail(409, 'VIDEO_PARTS_MISMATCH', '올린 조각을 합치지 못했어요. 다시 시도해주세요.');
        }
        $this->db()->table('starttoo_videos')->where('id', $id)->where('status', 'uploading')
            ->update(['status' => 'processing', 'upload_id' => null, 'uploaded_at' => now(), 'updated_at' => now()]);
        $this->changed($this->row($id), 'processing');
        \App\Jobs\TranscodeStarttooVideo::dispatch($id);
        return $this->view($this->row($id));
    }

    /** 게시 전(올리는 중 · 준비 중 · 준비 끝)에 게시 정보(캡션 · 태그 등) · 자동 게시 바꾸기 */
    public function update(int $userId, string $id, array $in): array
    {
        $row = $this->mine($userId, $id);
        if (!in_array($row->status, ['uploading', 'processing', 'ready'], true)) $this->fail(409, 'VIDEO_NOT_EDITABLE', '게시된 무빙은 게시물에서 고쳐주세요.');
        $values = ['updated_at' => now()];
        if (array_key_exists('payload', $in)) {
            if (!is_array($in['payload'])) $this->fail(422, 'INVALID_VIDEO', '게시 정보를 확인해주세요.');
            $values['payload'] = json_encode($this->validPayload($row->target, $in['payload'], $row->request_key), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        if (array_key_exists('auto_publish', $in)) $values['auto_publish'] = filter_var($in['auto_publish'], FILTER_VALIDATE_BOOLEAN);
        $this->db()->table('starttoo_videos')->where('id', $id)->update($values);
        return $this->view($this->row($id));
    }

    /** 준비 끝(자동 게시를 끈 영상) → 게시 */
    public function publishByUser(int $userId, string $id): array
    {
        $row = $this->mine($userId, $id);
        if ($row->status === 'published') return $this->view($row);
        if ($row->status !== 'ready') $this->fail(409, 'VIDEO_NOT_READY', '아직 영상을 준비하고 있어요.');
        $this->publish($row);
        return $this->view($this->row($id));
    }

    /**
     * 취소 · 삭제(게시 전 어느 상태든). 저장소 조각 · 변환본까지 지운다.
     * 게시된 무빙은 작품 · 게시물 삭제로 지운다(그때 영상 파일은 정리 작업이 지운다).
     */
    public function delete(int $userId, string $id): array
    {
        $row = $this->mine($userId, $id);
        if ($row->status === 'published') $this->fail(409, 'VIDEO_PUBLISHED', '게시된 무빙은 게시물에서 삭제해주세요.');
        $this->purgeFiles($row);
        $this->db()->table('starttoo_videos')->where('id', $id)->delete();
        $this->event($id, 'deleted', null);
        return ['id' => $id, 'deleted' => true];
    }

    /** 게시 대기함 · 미니바(pending=1: 게시 전 + 최근 하루 안에 게시된 것) */
    public function listMine(int $userId, bool $pending): array
    {
        $q = $this->db()->table('starttoo_videos')->where('user_id', $userId);
        if ($pending) $q->where(fn ($w) => $w->where('status', '<>', 'published')->orWhere('published_at', '>=', now()->subDay()));
        return ['items' => $q->orderByDesc('created_at')->limit(50)->get()->map(fn ($r) => $this->view($r))->all()];
    }

    public function show(int $userId, string $id): array { return $this->view($this->mine($userId, $id)); }

    // ── 재생 ───────────────────────────────────────────

    /** 게시된 작품 · 게시물의 영상 재생 정보(없으면 null). 피드 카드 · 상세가 쓴다 */
    public function forTarget(string $type, string $id): ?array
    {
        try {
            $row = $this->db()->table('starttoo_videos')->where('published_type', $type)->where('published_id', $id)->where('status', 'published')->first();
        } catch (\Illuminate\Database\QueryException $e) {
            // 마이그레이션(000062) 전에 코드가 먼저 배포돼도 피드가 깨지지 않게
            return null;
        }
        return $row ? $this->playback($row) : null;
    }

    public function playback(object $row): ?array
    {
        if (!$row->hls_path) return null;
        return ['id' => $row->id, 'hls_url' => $this->cdn($row->hls_path),
            'poster_url' => app(MediaUrls::class)->url($row->poster_path, 500),
            'duration_ms' => (int) $row->duration_ms, 'width' => $row->width === null ? null : (int) $row->width,
            'height' => $row->height === null ? null : (int) $row->height, 'muted' => (bool) $row->muted];
    }

    /** 시청 기록 여러 개를 한 번에: items = [{video_id, watched_ms, completed, looped, played_3s, cta}] */
    public function views(?int $viewer, array $in): array
    {
        $items = array_slice((array) ($in['items'] ?? []), 0, 50);
        $db = $this->db();
        $day = today()->toDateString();
        foreach ($items as $it) {
            $vid = (string) ($it['video_id'] ?? '');
            if (!Str::isUuid($vid) || !$db->table('starttoo_videos')->where('id', $vid)->where('status', 'published')->exists()) continue;
            $key = ['video_id' => $vid, 'user_id' => $viewer ?? 0, 'day' => $day];
            $db->table('starttoo_video_views')->insertOrIgnore($key);
            $db->table('starttoo_video_views')->where($key)->update([
                'plays' => $db->raw('plays + 1'),
                'watched_3s' => $db->raw('watched_3s + ' . (!empty($it['played_3s']) ? 1 : 0)),
                'completed' => $db->raw('completed + ' . (!empty($it['completed']) ? 1 : 0)),
                'loops' => $db->raw('loops + ' . max(0, min(100, (int) ($it['looped'] ?? 0)))),
                'watched_ms' => $db->raw('watched_ms + ' . max(0, min(3600000, (int) ($it['watched_ms'] ?? 0)))),
                'cta_clicks' => $db->raw('cta_clicks + ' . (!empty($it['cta']) ? 1 : 0)),
            ]);
        }
        return ['ok' => true];
    }

    // ── 무빙 피드(VID-06 홈 무빙 탭 · VID-06B 작가 무빙 이어 보기) ─────────

    /**
     * 게시된 무빙을 최신순으로 10개씩. 작품 · 게시물 피드와 같은 공개 범위 · 차단 · 숨김 규칙(카드는 기존 피드 조회로 만든다).
     * in: cursor · artist(회원 번호, 그 작가 무빙만) · from(영상 id, 작가 이어 보기 시작 위치)
     * 응답: items(디자인 한 칸 모양) · next_cursor · (artist 일 때) total · index(from 의 순서)
     */
    public function shorts(?int $viewer, array $in): array
    {
        $db = $this->db();
        $artist = isset($in['artist']) && ctype_digit((string) $in['artist']) ? (int) $in['artist'] : null;
        $base = fn () => $db->table('starttoo_videos as v')->join('starttoo_users as u', 'u.id', '=', 'v.user_id')
            ->where('v.status', 'published')->where('u.status', 'active')->where('u.moderation_hidden', false)
            ->when($artist, fn ($q) => $q->where('v.user_id', $artist))
            ->where(fn ($w) => $w
                ->where(fn ($a) => $a->where('v.published_type', 'artwork')->whereExists(fn ($e) => $e->selectRaw('1')->from('starttoo_artworks as a')
                    ->whereColumn('a.id', 'v.published_id')->where('a.visibility', 'public')->where('a.moderation_hidden', false)))
                ->orWhere(fn ($p) => $p->where('v.published_type', 'post')->whereExists(fn ($e) => $e->selectRaw('1')->from('starttoo_posts as p')
                    ->whereColumn('p.id', 'v.published_id')->where('p.moderation_hidden', false))));
        $q = $base();
        app(Blocks::class)->filter($q, 'v.user_id', $viewer);

        $cursor = null;
        if (!empty($in['cursor']) && is_string($in['cursor'])) {
            $c = json_decode((string) base64_decode(strtr($in['cursor'], '-_', '+/'), true), true);
            if (is_array($c) && isset($c['at'], $c['id']) && Str::isUuid((string) $c['id'])) $cursor = $c;
        }
        $index = null;
        // 작가 이어 보기: 누른 무빙부터 시작(그보다 최신은 index 로 센다)
        if (!$cursor && $artist && !empty($in['from']) && Str::isUuid((string) $in['from'])) {
            $from = $db->table('starttoo_videos')->where('id', $in['from'])->where('user_id', $artist)->first(['id', 'published_at']);
            if ($from) {
                $cursor = ['at' => (string) $from->published_at, 'id' => $from->id, 'inclusive' => true];
                $index = (clone $q)->where(fn ($w) => $w->where('v.published_at', '>', $from->published_at)
                    ->orWhere(fn ($s) => $s->where('v.published_at', $from->published_at)->where('v.id', '>', $from->id)))->count();
            }
        }
        if ($cursor) {
            $op = !empty($cursor['inclusive']) ? '<=' : '<';
            $q->where(fn ($w) => $w->where('v.published_at', '<', $cursor['at'])
                ->orWhere(fn ($s) => $s->where('v.published_at', $cursor['at'])->where('v.id', $op, $cursor['id'])));
        }
        $rows = $q->orderByDesc('v.published_at')->orderByDesc('v.id')->limit(11)
            ->get(['v.*', 'u.nickname', 'u.avatar_path', 'u.interest_region']);
        $more = $rows->count() > 10;
        $rows = $rows->take(10)->values();

        // 카드: 기존 작품 · 게시물 피드 조회를 그대로 써서 좋아요 · 저장 · 팔로우 · 댓글 수를 맞춘다
        $artworkIds = $rows->where('published_type', 'artwork')->pluck('published_id')->all();
        $postIds = $rows->where('published_type', 'post')->pluck('published_id')->all();
        $cards = [];
        if ($artworkIds) foreach (app(Artworks::class)->feed(['scope' => 'public'], $viewer, null, $artworkIds)['items'] as $c) $cards['artwork:' . $c['id']] = $c;
        if ($postIds) foreach (app(Posts::class)->feedItems($postIds, $viewer, 'public') as $c) $cards['post:' . $c['id']] = $c;
        $saves = ($artworkIds ? $db->table('starttoo_artwork_interactions')->whereIn('artwork_id', $artworkIds)->where('kind', 'save')
                ->groupBy('artwork_id')->selectRaw('artwork_id as id, count(*) as n')->pluck('n', 'id')->all() : [])
            + ($postIds ? $db->table('starttoo_post_saves')->whereIn('post_id', $postIds)->groupBy('post_id')
                ->selectRaw('post_id as id, count(*) as n')->pluck('n', 'id')->all() : []);
        $studios = $db->table('starttoo_shops')->whereIn('user_id', $rows->pluck('user_id')->unique()->all())->pluck('studio_name', 'user_id')->all();
        $regions = collect(app(Profiles::class)->options()['regions'])->pluck('label', 'id')->all();

        $items = [];
        foreach ($rows as $r) {
            $card = $cards[$r->published_type . ':' . $r->published_id] ?? null;
            if (!$card) continue; // 이 회원에게 숨긴 게시물 등
            $isArtwork = $r->published_type === 'artwork';
            $kind = $isArtwork ? ($card['kind'] === 'design' ? 'flash' : 'work') : 'post';
            $studio = trim((string) ($studios[$r->user_id] ?? ''));
            $style = $isArtwork ? ($card['styles'][0]['label'] ?? null) : null;
            $items[] = [
                'id' => $r->id, 'target_type' => $r->published_type, 'target_id' => $r->published_id,
                'artist_id' => (int) $r->user_id,
                'author' => $studio !== '' ? "{$studio} · {$r->nickname}" : (string) $r->nickname,
                'meta' => implode(' · ', array_filter([$regions[$r->interest_region] ?? null, $style])),
                'type' => $kind,
                'caption' => (string) ($isArtwork ? ($card['description'] ?? '') : ($card['content'] ?? '')),
                'tags' => array_map(fn ($t) => '#' . $t, array_slice((array) ($card['tags'] ?? []), 0, 5)),
                'hls_url' => $this->cdn($r->hls_path),
                'poster_url' => app(MediaUrls::class)->url($r->poster_path, 1080),
                'duration_ms' => (int) $r->duration_ms, 'muted' => (bool) $r->muted,
                'width' => $r->width === null ? null : (int) $r->width, 'height' => $r->height === null ? null : (int) $r->height,
                // 연결 버튼: 작품 → 상담 요청, 도안 → 내 몸에 피팅 (대상 = 그 작품 · 도안)
                'linked_id' => $isArtwork ? $r->published_id : null,
                'likes' => (int) ($card['interaction']['like_count'] ?? 0),
                'comments' => (int) ($card['comment_count'] ?? 0),
                'saves' => (int) ($saves[$r->published_id] ?? 0),
                'liked' => (bool) ($card['interaction']['liked'] ?? false),
                'saved' => (bool) ($card['interaction']['saved'] ?? false),
                'following' => (bool) ($card['author']['following'] ?? false),
                'is_mine' => $viewer !== null && (int) $r->user_id === $viewer,
                'avatar_url' => app(MediaUrls::class)->url($r->avatar_path, 300),
            ];
        }
        $last = $rows->last();
        $next = $more && $last ? rtrim(strtr(base64_encode(json_encode(['at' => (string) $last->published_at, 'id' => $last->id])), '+/', '-_'), '=') : null;
        $out = ['items' => $items, 'next_cursor' => $next];
        if ($artist) {
            $countQ = $base();
            app(Blocks::class)->filter($countQ, 'v.user_id', $viewer);
            $out['total'] = $countQ->count();
            $out['index'] = $index ?? 0;
            $out['artist_name'] = (string) $db->table('starttoo_users')->where('id', $artist)->value('nickname');
        }
        return $out;
    }

    // ── 변환 · 게시(작업이 부른다) ──────────────────────

    /** 포스터를 첫 사진으로 작품 · 게시물을 만든다. 실패하면 encode_failed(PUBLISH_FAILED) */
    public function publish(object $row): void
    {
        $db = $this->db();
        $payload = json_decode((string) $row->payload, true) ?: [];
        try {
            // 포스터를 「미리 올린 사진」 1장으로 기록하고 기존 등록과 같이 첨부한다
            $uploadId = (string) Str::uuid();
            $db->table('starttoo_artwork_uploads')->insert([
                'id' => $uploadId, 'user_id' => $row->user_id, 'request_key' => (string) Str::uuid(),
                'source_hash' => hash('sha256', $row->id . ':poster'), 'path' => $row->poster_path,
                'status' => 'ready', 'processed_at' => now(), 'created_at' => now(),
            ]);
            if ($row->target === 'artwork') {
                [$valid, $values] = app(Artworks::class)->validatedInput($payload);
                $targetId = app(Artworks::class)->insertFor((int) $row->user_id, $valid, $values, [$uploadId]);
            } else {
                $targetId = app(Posts::class)->insertFor((int) $row->user_id, $row->request_key, (string) ($payload['content'] ?? ''), [$uploadId]);
            }
        } catch (\Throwable $e) {
            report($e);
            // 포스터 파일은 영상 쪽에 남겨 두고(다시 게시 · 정리용) 첨부 기록만 지운다
            if (isset($uploadId)) $db->table('starttoo_artwork_uploads')->where('id', $uploadId)->whereNull('attached_at')->delete();
            $this->fail_($row, 'PUBLISH_FAILED');
            return;
        }
        $db->table('starttoo_videos')->where('id', $row->id)->update(['status' => 'published', 'published_type' => $row->target,
            'published_id' => $targetId, 'published_at' => now(), 'updated_at' => now()]);
        $this->changed($this->row($row->id), 'published');
    }

    /** 실패 확정 + 알림 */
    public function fail_(object $row, string $code, string $status = 'encode_failed'): void
    {
        $this->db()->table('starttoo_videos')->where('id', $row->id)->update(['status' => $status, 'error_code' => $code,
            'processed_at' => now(), 'updated_at' => now()]);
        $this->changed($this->row($row->id), $status, ['error' => $code]);
    }

    /** 상태가 바뀌었다: 기록 · 소켓 · (게시 · 준비 끝 · 실패 · 검토는) 알림 */
    public function changed(object $row, string $status, ?array $detail = null): void
    {
        $this->event($row->id, $status, $detail);
        try {
            event(new \App\Events\StarttooVideoChanged((int) $row->user_id, $row->id, $status,
                $row->published_type, $row->published_id));
        } catch (\Throwable $e) {
            report($e);
        }
        if (in_array($status, ['published', 'ready', 'upload_failed', 'encode_failed', 'held', 'rejected'], true)) {
            app(Notifications::class)->notice((int) $row->user_id, 'video', 'video', $row->id, $status);
        }
    }

    /** 신고 누적으로 게시물이 숨겨졌다(Reports::autoHide) */
    public function heldByReports(string $type, string $targetId): void
    {
        $row = $this->db()->table('starttoo_videos')->where('published_type', $type)->where('published_id', $targetId)->first();
        if ($row) $this->changed($row, 'held');
    }

    public function isVideoTarget(string $type, string $targetId): bool
    {
        if (!in_array($type, ['artwork', 'post'], true)) return false;
        try {
            return $this->db()->table('starttoo_videos')->where('published_type', $type)->where('published_id', $targetId)->exists();
        } catch (\Illuminate\Database\QueryException $e) {
            return false;
        }
    }

    public function event(string $videoId, string $status, ?array $detail): void
    {
        $this->db()->table('starttoo_video_events')->insert(['video_id' => $videoId, 'status' => $status,
            'detail' => $detail === null ? null : json_encode($detail, JSON_UNESCAPED_UNICODE), 'created_at' => now()]);
    }

    // ── 정리(스케줄러) ───────────────────────────────────

    /**
     * 매시: 24시간 안에 다 못 올린 업로드 → upload_failed(조각 삭제) · 게시물이 지워진 영상 파일 삭제 ·
     * 원본 보관 기간 지난 원본 삭제 · ready 7일 알림
     */
    public function sweep(): array
    {
        $db = $this->db();
        $out = ['expired' => 0, 'orphans' => 0, 'sources' => 0, 'ready_reminded' => 0];
        foreach ($db->table('starttoo_videos')->where('status', 'uploading')->where('created_at', '<', now()->subHours(self::UPLOAD_HOURS))->get() as $r) {
            $this->abortUpload($r);
            $db->table('starttoo_videos')->where('id', $r->id)->update(['status' => 'upload_failed', 'error_code' => 'UPLOAD_EXPIRED', 'upload_id' => null, 'updated_at' => now()]);
            $this->changed($this->row($r->id), 'upload_failed', ['error' => 'UPLOAD_EXPIRED']);
            $out['expired']++;
        }
        foreach ($db->table('starttoo_videos')->where('status', 'published')->get(['id', 'published_type', 'published_id', 'source_key', 'hls_path', 'poster_path', 'upload_id', 'source_deleted_at']) as $r) {
            $table = $r->published_type === 'post' ? 'starttoo_posts' : 'starttoo_artworks';
            if ($db->table($table)->where('id', $r->published_id)->exists()) continue;
            // 포스터는 작품 · 게시물 사진으로 이미 지워졌다
            $r->poster_path = null;
            $this->purgeFiles($r);
            $db->table('starttoo_videos')->where('id', $r->id)->delete();
            $out['orphans']++;
        }
        $keep = max(1, (int) config('starttoo.video_source_keep_days'));
        foreach ($db->table('starttoo_videos')->whereIn('status', ['published', 'ready'])->whereNull('source_deleted_at')
            ->where('processed_at', '<', now()->subDays($keep))->limit(200)->get(['id', 'source_key']) as $r) {
            Storage::disk('starttoo')->delete($r->source_key);
            $db->table('starttoo_videos')->where('id', $r->id)->update(['source_deleted_at' => now()]);
            $out['sources']++;
        }
        foreach ($db->table('starttoo_videos')->where('status', 'ready')->whereNull('ready_notified_at')->where('processed_at', '<', now()->subDays(7))->get() as $r) {
            $db->table('starttoo_videos')->where('id', $r->id)->update(['ready_notified_at' => now()]);
            app(Notifications::class)->notice((int) $r->user_id, 'video', 'video', $r->id, 'ready');
            $out['ready_reminded']++;
        }
        return $out;
    }

    public function purgeFiles(object $row): void
    {
        $this->abortUpload($row);
        $disk = Storage::disk('starttoo');
        try {
            if ($row->source_key && !$row->source_deleted_at) $disk->delete($row->source_key);
            if ($row->hls_path) $disk->deleteDirectory(dirname($row->hls_path));
            if ($row->poster_path) $disk->delete($row->poster_path);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function abortUpload(object $row): void
    {
        if (!$row->upload_id) return;
        try {
            $this->client()->abortMultipartUpload(['Bucket' => $this->bucket(), 'Key' => $row->source_key, 'UploadId' => $row->upload_id]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    // ── 공통 ───────────────────────────────────────────

    public function row(string $id): ?object { return $this->db()->table('starttoo_videos')->where('id', $id)->first(); }

    private function mine(int $userId, string $id): object
    {
        $row = Str::isUuid($id) ? $this->db()->table('starttoo_videos')->where('id', $id)->where('user_id', $userId)->first() : null;
        if (!$row) $this->fail(404, 'VIDEO_NOT_FOUND', '영상을 찾을 수 없어요.');
        return $row;
    }

    /** 앱이 받는 게시 대기 카드 · 미니바 정보 */
    public function view(object $row): array
    {
        $payload = json_decode((string) $row->payload, true) ?: [];
        $status = $row->status;
        // 게시 뒤 신고 누적으로 숨겨지면 「검토 중」
        if ($status === 'published' && $row->published_id) {
            $table = $row->published_type === 'post' ? 'starttoo_posts' : 'starttoo_artworks';
            if ($this->db()->table($table)->where('id', $row->published_id)->value('moderation_hidden')) $status = 'held';
        }
        $error = $row->error_code ? (self::ERRORS[$row->error_code] ?? self::ERRORS['UNKNOWN']) : null;
        if ($row->error_code === 'TOO_LONG') $error .= '(' . config('starttoo.video_max_seconds') . '초)';
        return [
            'id' => $row->id, 'status' => $status, 'target' => $row->target,
            'kind' => $row->target === 'artwork' ? ($payload['kind'] ?? 'work') : 'post',
            'caption' => (string) ($row->target === 'artwork' ? ($payload['description'] ?? '') : ($payload['content'] ?? '')),
            'payload' => $payload, 'auto_publish' => (bool) $row->auto_publish,
            'parts_total' => (int) $row->parts_total, 'size_bytes' => (int) $row->size_bytes,
            'duration_ms' => $row->duration_ms === null ? null : (int) $row->duration_ms,
            'stage' => $row->stage,
            'poster_url' => $row->poster_path ? app(MediaUrls::class)->url($row->poster_path, 500) : null,
            'error_code' => $row->error_code, 'error_message' => $error,
            'published_type' => $row->published_type, 'published_id' => $row->published_id,
            'playback' => $row->status === 'ready' ? $this->playback($row) : null,
            'created_at' => (string) $row->created_at, 'published_at' => $row->published_at ? (string) $row->published_at : null,
        ];
    }

    /** 업로드 세션 응답: 조각별 서명 주소(6시간) */
    private function session(object $row, ?array $numbers): array
    {
        $urls = [];
        if ($row->status === 'uploading' && $row->upload_id) {
            $client = $this->client();
            foreach ($numbers ?? range(1, (int) $row->parts_total) as $n) {
                $cmd = $client->getCommand('UploadPart', ['Bucket' => $this->bucket(), 'Key' => $row->source_key,
                    'UploadId' => $row->upload_id, 'PartNumber' => $n]);
                $urls[] = ['n' => $n, 'url' => (string) $client->createPresignedRequest($cmd, '+6 hours')->getUri()];
            }
        }
        return ['video' => $this->view($row), 'part_bytes' => self::PART_BYTES, 'parts' => $urls];
    }

    private function checkDuration(int $ms): void
    {
        // 폰이 잰 길이와 서버가 잰 길이가 조금 다를 수 있어 0.5초 여유를 둔다
        if ($ms > (int) config('starttoo.video_max_seconds') * 1000 + 500) $this->fail(422, 'VIDEO_TOO_LONG', '영상은 ' . config('starttoo.video_max_seconds') . '초까지 올릴 수 있어요.');
        if ($ms < (int) config('starttoo.video_min_seconds') * 1000 - 500) $this->fail(422, 'VIDEO_TOO_SHORT', '영상은 ' . config('starttoo.video_min_seconds') . '초 이상이어야 해요.');
    }

    /** 게시 정보를 게시할 때와 같은 규칙으로 미리 검증한다. 작품 요청 키 = 영상 요청 키(같은 게시를 두 번 만들지 않게) */
    private function validPayload(string $target, array $payload, string $requestKey): array
    {
        if ($target === 'artwork') {
            $payload['request_key'] = $requestKey;
            unset($payload['media_ids']);
            app(Artworks::class)->validatedInput($payload);
            return $payload;
        }
        $content = is_string($payload['content'] ?? null) ? trim($payload['content']) : '';
        if (mb_strlen($content) > 2000) $this->fail(422, 'INVALID_POST', '본문은 2000자 이하로 입력해주세요.');
        return ['content' => $content];
    }

    public function cdn(string $path): string
    {
        $base = rtrim(trim((string) config('starttoo.video_cdn_url')), '/');
        return $base . '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
    }

    private function fail(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(compact('code', 'message'), $status));
    }
}
