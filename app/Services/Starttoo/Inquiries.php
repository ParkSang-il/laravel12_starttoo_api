<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/**
 * 1:1 문의. 앱 MY 「1:1 문의」(작성 · 수정 · 추가 문의)와 운영 콘솔 「1:1 문의」(답변)가 함께 쓴다.
 *
 * 흐름: 회원 문의(waiting) → 운영자 답변(answered, 앱 알림 inquiry_answer) → 회원 추가 문의(다시 waiting) → …
 * 수정: 답변을 기다리는 동안(waiting)만 제목 · 분류와 마지막 질문(본문 · 사진)을 고칠 수 있다.
 * 사진: 작품 사진 업로드(artworks/media)로 올린 뒤 media id 를 보내면 첨부한다(질문마다 최대 3장).
 */
class Inquiries
{
    public const CATEGORIES = [
        'account' => '계정 · 로그인',
        'booking' => '상담 · 예약',
        'payment' => '결제 · 환불',
        'ad' => '광고',
        'report' => '신고 · 제재',
        'bug' => '오류 · 개선 제안',
        'etc' => '기타',
    ];
    public const TITLE_MAX = 100;
    public const BODY_MAX = 2000;
    public const ANSWER_MAX = 4000;
    public const MAX_IMAGES = 3;
    /** 답변을 기다리는 문의는 회원마다 이만큼까지 */
    public const MAX_WAITING = 5;
    private const PER_PAGE = 20;

    private function db() { return app(ProjectDatabase::class)->database(); }

    // ── 앱 ───────────────────────────────

    public function listing(int $userId, array $input): array
    {
        $page = max(1, min(500, (int) ($input['page'] ?? 1)));
        $rows = $this->db()->table('starttoo_inquiries')->where('user_id', $userId)
            ->orderByDesc('id')->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE + 1)->get();
        $items = $rows->take(self::PER_PAGE);
        $messages = $this->messages($items->pluck('id')->all());
        return [
            'items' => $items->map(fn ($q) => $this->card($q, $messages[$q->id] ?? []))->values()->all(),
            'has_more' => $rows->count() > self::PER_PAGE,
        ] + $this->options();
    }

    /** 작성 화면: 분류 · 글자 수 · 사진 수 */
    public function options(): array
    {
        return ['categories' => $this->categories(),
            'limits' => ['title' => self::TITLE_MAX, 'body' => self::BODY_MAX, 'images' => self::MAX_IMAGES]];
    }

    public function detail(int $userId, int $id): array
    {
        $q = $this->mine($userId, $id);
        return $this->card($q, $this->messages([$q->id])[$q->id] ?? []);
    }

    public function create(int $userId, array $input): array
    {
        $data = $this->validated($input, true);
        $waiting = $this->db()->table('starttoo_inquiries')->where('user_id', $userId)->where('status', 'waiting')->count();
        if ($waiting >= self::MAX_WAITING) {
            $this->fail(429, 'INQUIRY_LIMIT', '답변을 기다리는 문의가 ' . self::MAX_WAITING . '건 있어요. 답변을 받은 뒤 새로 문의해주세요.');
        }
        $id = $this->db()->transaction(function () use ($userId, $data) {
            $images = $this->claim($userId, $data['image_media_ids']);
            $id = $this->db()->table('starttoo_inquiries')->insertGetId([
                'user_id' => $userId, 'category' => $data['category'], 'title' => $data['title'], 'status' => 'waiting',
                'asked_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->db()->table('starttoo_inquiry_messages')->insert([
                'inquiry_id' => $id, 'author' => 'user', 'body' => $data['body'], 'images' => $images ? json_encode($images) : null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            return $id;
        });
        \App\Support\AdminUi::forgetBadges();
        return $this->detail($userId, $id);
    }

    /** 답변을 기다리는 동안: 제목 · 분류 + 마지막 질문(본문 · 사진). keep_images = 남길 기존 사진 key */
    public function edit(int $userId, int $id, array $input): array
    {
        $q = $this->mine($userId, $id);
        if ($q->status !== 'waiting') $this->fail(409, 'INQUIRY_ANSWERED', '답변이 달린 문의는 고칠 수 없어요. 추가 문의로 남겨주세요.');
        $data = $this->validated($input, true);
        $last = $this->db()->table('starttoo_inquiry_messages')->where('inquiry_id', $id)->orderByDesc('id')->first();
        if (!$last || $last->author !== 'user') $this->fail(409, 'INQUIRY_ANSWERED', '답변이 달린 문의는 고칠 수 없어요. 추가 문의로 남겨주세요.');
        $current = json_decode((string) $last->images, true) ?: [];
        $keep = array_values(array_intersect($current, array_map('strval', (array) ($input['keep_images'] ?? []))));
        if (count($keep) + count($data['image_media_ids']) > self::MAX_IMAGES) {
            $this->fail(422, 'INVALID_INQUIRY', '사진은 ' . self::MAX_IMAGES . '장까지 붙일 수 있어요.');
        }
        $this->db()->transaction(function () use ($userId, $id, $q, $last, $data, $keep) {
            $images = [...$keep, ...$this->claim($userId, $data['image_media_ids'])];
            $this->db()->table('starttoo_inquiries')->where('id', $id)->update([
                'category' => $data['category'], 'title' => $data['title'], 'updated_at' => now(),
            ]);
            $this->db()->table('starttoo_inquiry_messages')->where('id', $last->id)->update([
                'body' => $data['body'], 'images' => $images ? json_encode($images) : null, 'edited_at' => now(), 'updated_at' => now(),
            ]);
        });
        return $this->detail($userId, $id);
    }

    /** 답변을 받은 문의에 이어서 묻기(다시 답변 대기) */
    public function followUp(int $userId, int $id, array $input): array
    {
        $q = $this->mine($userId, $id);
        if ($q->status !== 'answered') $this->fail(409, 'INQUIRY_WAITING', '아직 답변을 기다리는 중이에요. 내용을 더하려면 문의를 수정해주세요.');
        $data = $this->validated($input, false);
        $this->db()->transaction(function () use ($userId, $id, $data) {
            $images = $this->claim($userId, $data['image_media_ids']);
            $this->db()->table('starttoo_inquiry_messages')->insert([
                'inquiry_id' => $id, 'author' => 'user', 'body' => $data['body'], 'images' => $images ? json_encode($images) : null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->db()->table('starttoo_inquiries')->where('id', $id)->update(['status' => 'waiting', 'asked_at' => now(), 'updated_at' => now()]);
        });
        \App\Support\AdminUi::forgetBadges();
        return $this->detail($userId, $id);
    }

    // ── 운영 콘솔 ───────────────────────────

    /** 운영자 답변: 답변 완료로 바꾸고 회원에게 앱 알림(inquiry_answer)을 보낸다 */
    public function answer(int $id, string $body, int $adminId): object
    {
        $body = trim(str_replace("\r\n", "\n", $body));
        if ($body === '') throw new \InvalidArgumentException('답변을 적어주세요.');
        if (mb_strlen($body) > self::ANSWER_MAX) throw new \InvalidArgumentException('답변은 ' . number_format(self::ANSWER_MAX) . '자까지 적을 수 있어요.');
        $q = $this->db()->table('starttoo_inquiries')->where('id', $id)->first();
        if (!$q) throw new \InvalidArgumentException('문의를 찾을 수 없어요.');
        $this->db()->transaction(function () use ($q, $body, $adminId) {
            $this->db()->table('starttoo_inquiry_messages')->insert([
                'inquiry_id' => $q->id, 'author' => 'admin', 'admin_id' => $adminId, 'body' => $body,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->db()->table('starttoo_inquiries')->where('id', $q->id)->update(['status' => 'answered', 'answered_at' => now(), 'updated_at' => now()]);
            app(Notifications::class)->notice((int) $q->user_id, 'inquiry_answer', 'inquiry', (string) $q->id, 'answered');
        });
        \App\Support\AdminUi::forgetBadges();
        return $q;
    }

    /** 콘솔 상세용 대화(사진 URL · 답변한 관리자 이름 포함) */
    public function thread(int $id): array
    {
        $names = $this->db()->table('starttoo_admins')->pluck('name', 'id');
        return array_map(fn ($m) => $m + ['admin_name' => $m['admin_id'] ? ($names[$m['admin_id']] ?? '관리자') : null],
            $this->messages([$id], true)[$id] ?? []);
    }

    public function categoryLabel(string $id): string
    {
        return self::CATEGORIES[$id] ?? $id;
    }

    // ── 내부 ───────────────────────────────

    private function categories(): array
    {
        return array_map(fn ($id, $label) => ['id' => $id, 'label' => $label], array_keys(self::CATEGORIES), self::CATEGORIES);
    }

    private function mine(int $userId, int $id): object
    {
        $q = $this->db()->table('starttoo_inquiries')->where('id', $id)->where('user_id', $userId)->first();
        if (!$q) $this->fail(404, 'INQUIRY_NOT_FOUND', '문의를 찾을 수 없어요.');
        return $q;
    }

    private function validated(array $input, bool $withTitle): array
    {
        $rules = [
            'body' => ['required', 'string', 'max:' . self::BODY_MAX],
            'image_media_ids' => ['nullable', 'array', 'max:' . self::MAX_IMAGES],
            'image_media_ids.*' => ['uuid'],
        ];
        if ($withTitle) {
            $rules['category'] = ['required', 'in:' . implode(',', array_keys(self::CATEGORIES))];
            $rules['title'] = ['required', 'string', 'max:' . self::TITLE_MAX];
        }
        $v = Validator::make($input, $rules, [
            'category.*' => '문의 분류를 골라주세요.',
            'title.required' => '제목을 적어주세요.', 'title.max' => '제목은 ' . self::TITLE_MAX . '자까지 적을 수 있어요.',
            'body.required' => '문의 내용을 적어주세요.', 'body.max' => '내용은 ' . number_format(self::BODY_MAX) . '자까지 적을 수 있어요.',
            'image_media_ids.max' => '사진은 ' . self::MAX_IMAGES . '장까지 붙일 수 있어요.',
        ]);
        if ($v->fails()) $this->fail(422, 'INVALID_INQUIRY', $v->errors()->first());
        $title = $withTitle ? trim((string) $input['title']) : '';
        $body = trim(str_replace("\r\n", "\n", (string) $input['body']));
        if (($withTitle && $title === '') || $body === '') $this->fail(422, 'INVALID_INQUIRY', $body === '' ? '문의 내용을 적어주세요.' : '제목을 적어주세요.');
        return [
            'category' => $input['category'] ?? null, 'title' => $title, 'body' => $body,
            'image_media_ids' => array_values(array_unique(array_map('strval', (array) ($input['image_media_ids'] ?? [])))),
        ];
    }

    /** 올려 둔 사진을 이 문의에 붙이고 경로를 올린 순서대로 돌려준다 */
    private function claim(int $userId, array $mediaIds): array
    {
        if (!$mediaIds) return [];
        $paths = app(ArtworkUploads::class)->claim($userId, $mediaIds);
        return array_values(array_filter(array_map(fn ($id) => $paths[$id] ?? null, $mediaIds)));
    }

    /** @return array<int, list<array>> 문의 번호 => 대화(오래된 순) */
    private function messages(array $ids, bool $withAdmin = false): array
    {
        if (!$ids) return [];
        $urls = app(MediaUrls::class);
        $out = [];
        foreach ($this->db()->table('starttoo_inquiry_messages')->whereIn('inquiry_id', $ids)->orderBy('id')->get() as $m) {
            $images = json_decode((string) $m->images, true) ?: [];
            $row = [
                'id' => (int) $m->id, 'author' => $m->author, 'body' => (string) $m->body,
                'images' => array_map(fn ($p) => ['key' => $p, 'url' => $urls->url($p, 1080), 'thumb' => $urls->url($p, 400)], $images),
                'edited' => $m->edited_at !== null,
                'created_at' => Carbon::parse($m->created_at)->toIso8601String(),
            ];
            if ($withAdmin) $row['admin_id'] = $m->admin_id === null ? null : (int) $m->admin_id;
            $out[(int) $m->inquiry_id][] = $row;
        }
        return $out;
    }

    private function card(object $q, array $messages): array
    {
        return [
            'id' => (int) $q->id, 'category' => $q->category, 'category_label' => $this->categoryLabel($q->category),
            'title' => $q->title, 'status' => $q->status,
            'created_at' => Carbon::parse($q->created_at)->toIso8601String(),
            'answered_at' => $q->answered_at ? Carbon::parse($q->answered_at)->toIso8601String() : null,
            // 답변 대기 중에는 수정, 답변을 받은 뒤에는 추가 문의
            'can_edit' => $q->status === 'waiting', 'can_follow_up' => $q->status === 'answered',
            'messages' => $messages,
        ];
    }

    private function fail(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(compact('code', 'message'), $status));
    }
}
