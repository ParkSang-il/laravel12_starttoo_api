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
            // 게시되면 푸시(운영 콘솔 「게시할 때 푸시 보내기」). 보내는 것은 sendDuePushes
            'push_pending' => (bool) ($in['push'] ?? false),
        ]);
    }

    /** 공지 분류 → 푸시 종류: 이벤트는 광고성(정보통신망법), 공지 · 업데이트는 정보성 */
    public static function pushIsInfo(string $category): bool
    {
        return $category !== 'event';
    }

    /**
     * 게시 시각이 된 「푸시 보낼 공지」를 보낸다(바로 게시 · 예약 게시 공통, 매분 starttoo:announce-push).
     * 제목 · 본문 첫 줄로 보내고, 누르면 공지 상세가 열린다. 실패하면 기록하고 다시 시도하지 않는다.
     * 반환: [보낸 수, 실패 메시지들]
     */
    public function sendDuePushes(?int $onlyId = null): array
    {
        $db = $this->db();
        $q = $db->table('starttoo_announcements')->where('push_pending', true)->where('active', true)->where('published_at', '<=', now());
        if ($onlyId !== null) $q->where('id', $onlyId);
        $sent = 0;
        $errors = [];
        foreach ($q->orderBy('id')->limit(20)->get() as $a) {
            // 같은 공지를 두 번 보내지 않게 먼저 내린다
            if (!$db->table('starttoo_announcements')->where('id', $a->id)->where('push_pending', true)->update(['push_pending' => false])) continue;
            $info = self::pushIsInfo($a->category);
            $firstLine = trim(strtok(str_replace("\r", '', (string) $a->body), "\n") ?: '');
            try {
                $campaigns = app(PushCampaigns::class);
                $id = $campaigns->create(['kind' => $info ? 'info' : 'marketing', 'sender' => 'STARTTOO',
                    'title' => mb_strimwidth($a->title, 0, $info ? 60 : 45, '…'),
                    'body' => mb_strimwidth($firstLine !== '' ? $firstLine : $a->title, 0, 200, '…'),
                    'target_type' => 'notice', 'target_id' => (string) $a->id, 'user_ids' => null]);
                $campaigns->control($id, 'start');
                $db->table('starttoo_announcements')->where('id', $a->id)->update(['push_campaign_id' => $id]);
                $sent++;
            } catch (\Throwable $e) {
                report($e);
                $errors[] = "공지 #{$a->id}: " . ($e instanceof \InvalidArgumentException ? $e->getMessage() : '푸시를 만들지 못했어요');
            }
        }
        return [$sent, $errors];
    }

    /** 바꿀 값만: title · body · category · pinned · active · published_at · push_pending */
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
