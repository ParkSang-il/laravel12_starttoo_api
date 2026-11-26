<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;

/** MY 「공지사항」. 앱은 목록 · 상세만 읽는다. 등록 · 수정 · 내림은 서버 명령. */
class Announcements
{
    public const CATEGORIES = ['notice' => '공지', 'update' => '업데이트', 'event' => '이벤트'];

    private function db() { return app(ProjectDatabase::class)->database(); }

    private function visible()
    {
        return $this->db()->table('starttoo_announcements')->where('active', true)->where('published_at', '<=', now());
    }

    /** 목록: 고정 글 먼저, 그다음 최신 순. latest_id = 가장 최근 글 번호(앱의 「새 공지」 표시용) */
    public function listing(array $input): array
    {
        $page = max(1, (int) ($input['page'] ?? 1));
        $rows = $this->visible()->orderByDesc('pinned')->orderByDesc('published_at')->orderByDesc('id')
            ->offset(($page - 1) * 20)->limit(21)->get();
        return [
            'items' => $rows->take(20)->map(fn ($a) => $this->format($a, false))->values()->all(),
            'has_more' => $rows->count() > 20,
            'latest_id' => (int) ($this->visible()->max('id') ?? 0),
        ];
    }

    public function detail(int $id): array
    {
        $a = $this->visible()->where('id', $id)->first();
        if (!$a) throw new HttpResponseException(response()->json(['code' => 'ANNOUNCEMENT_NOT_FOUND', 'message' => '공지사항을 찾을 수 없어요.'], 404));
        return $this->format($a, true);
    }

    private function format(object $a, bool $body): array
    {
        $out = [
            'id' => (int) $a->id, 'category' => $a->category, 'category_label' => self::CATEGORIES[$a->category] ?? '공지',
            'title' => $a->title, 'pinned' => (bool) $a->pinned,
            'published_at' => Carbon::parse($a->published_at)->toIso8601String(),
        ];
        if ($body) $out['body'] = $a->body;
        return $out;
    }

    // ── 운영 명령 ────────────────────────────────

    public function add(array $in): int
    {
        $title = trim((string) ($in['title'] ?? ''));
        $body = str_replace('\n', "\n", trim((string) ($in['body'] ?? '')));
        $category = (string) ($in['category'] ?? 'notice');
        if ($title === '' || mb_strlen($title) > 120) throw new \InvalidArgumentException('제목은 1~120자로 적어주세요.');
        if ($body === '' || mb_strlen($body) > 10000) throw new \InvalidArgumentException('본문은 1~10000자로 적어주세요.');
        if (!isset(self::CATEGORIES[$category])) throw new \InvalidArgumentException('분류는 notice · update · event 중 하나예요.');
        return (int) $this->db()->table('starttoo_announcements')->insertGetId([
            'category' => $category, 'title' => $title, 'body' => $body, 'pinned' => (bool) ($in['pin'] ?? false), 'active' => true,
            'published_at' => ($in['at'] ?? null) ? Carbon::parse($in['at']) : now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** 바꿀 값만: title · body · category · pinned · active */
    public function update(int $id, array $values): bool
    {
        if (isset($values['body'])) $values['body'] = str_replace('\n', "\n", $values['body']);
        return $this->db()->table('starttoo_announcements')->where('id', $id)->update($values + ['updated_at' => now()]) > 0;
    }

    public function all(): array
    {
        return $this->db()->table('starttoo_announcements')->orderByDesc('id')->limit(50)->get()->all();
    }
}
