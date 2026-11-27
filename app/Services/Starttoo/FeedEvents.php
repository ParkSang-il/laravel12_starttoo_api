<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Illuminate\Support\Str;

/**
 * 홈 피드 카드 노출 · 체류 · 열기 기록(docs/feed-ranking.md). 앱이 여러 건을 모아 보낸다.
 * 추천용이라 일부가 빠져도 된다: 잘못된 항목은 조용히 건너뛰고, 없는 게시물은 기록하지 않는다.
 */
class FeedEvents
{
    public const MAX_ITEMS = 50;
    public const MAX_MS = 60000;
    /** 1초 넘게 보여야 노출로 센다 */
    public const VIEW_MS = 1000;
    /** 같은 회원이 같은 카드를 다시 봐도 30분 안에는 노출을 한 번만 센다 */
    private const REPEAT_MINUTES = 30;
    private const EVENTS_DAYS = 90;
    private const SEEN_DAYS = 30;

    private function db() { return app(ProjectDatabase::class)->database(); }

    /** in: items[{target_type: artwork|post, target_id, event: view|open, ms, position}] */
    public function record(int $viewer, array $in): array
    {
        $rows = [];
        foreach (array_slice(array_values((array) ($in['items'] ?? [])), 0, self::MAX_ITEMS) as $it) {
            if (!is_array($it)) continue;
            $type = $it['target_type'] ?? null; $id = (string) ($it['target_id'] ?? ''); $event = $it['event'] ?? null;
            if (!in_array($type, ['artwork', 'post'], true) || !Str::isUuid($id) || !in_array($event, ['view', 'open'], true)) continue;
            $ms = $event === 'view' ? max(0, min(self::MAX_MS, (int) ($it['ms'] ?? 0))) : 0;
            if ($event === 'view' && $ms < 300) continue; // 스쳐 지나간 카드
            $position = isset($it['position']) ? max(0, min(65535, (int) $it['position'])) : null;
            $rows[] = ['type' => $type, 'id' => strtolower($id), 'event' => $event, 'ms' => $ms, 'position' => $position];
        }
        if (!$rows) return ['ok' => true];
        $db = $this->db();
        // 실제로 있는 게시물만 기록한다(없는 id 로 집계를 부풀리지 않게)
        $exists = ['artwork' => [], 'post' => []];
        foreach (['artwork' => 'starttoo_artworks', 'post' => 'starttoo_posts'] as $type => $table) {
            $ids = array_values(array_unique(array_column(array_filter($rows, fn ($r) => $r['type'] === $type), 'id')));
            if ($ids) $exists[$type] = array_flip($db->table($table)->whereIn('id', $ids)->pluck('id')->map(fn ($v) => strtolower((string) $v))->all());
        }
        $rows = array_values(array_filter($rows, fn ($r) => isset($exists[$r['type']][$r['id']])));
        if (!$rows) return ['ok' => true];
        $now = now();
        $db->table('starttoo_feed_events')->insert(array_map(fn ($r) => [
            'user_id' => $viewer, 'target_type' => $r['type'], 'target_id' => $r['id'], 'event' => $r['event'],
            'ms' => $r['ms'], 'position' => $r['position'], 'created_at' => $now,
        ], $rows));

        // 카드별로 묶어 한 번씩 더한다
        $per = [];
        foreach ($rows as $r) {
            $k = $r['type'].':'.$r['id'];
            $per[$k] ??= ['type' => $r['type'], 'id' => $r['id'], 'ms' => 0, 'opens' => 0, 'viewed' => false];
            if ($r['event'] === 'open') $per[$k]['opens']++;
            else {
                $per[$k]['ms'] += $r['ms'];
                if ($r['ms'] >= self::VIEW_MS) $per[$k]['viewed'] = true;
            }
        }
        $recent = $now->copy()->subMinutes(self::REPEAT_MINUTES);
        foreach ($per as $p) {
            $views = 0;
            if ($p['viewed']) {
                $key = ['user_id' => $viewer, 'target_type' => $p['type'], 'target_id' => $p['id']];
                $seen = $db->table('starttoo_feed_seen')->where($key)->first();
                if (!$seen) {
                    $db->table('starttoo_feed_seen')->insertOrIgnore([...$key, 'views' => 1, 'last_at' => $now]);
                    $views = 1;
                } elseif ($seen->last_at < $recent->format('Y-m-d H:i:s')) {
                    $db->table('starttoo_feed_seen')->where($key)->update(['views' => min(65535, (int) $seen->views + 1), 'last_at' => $now]);
                    $views = 1;
                }
            }
            $key = ['target_type' => $p['type'], 'target_id' => $p['id']];
            $db->table('starttoo_feed_stats')->insertOrIgnore([...$key, 'updated_at' => $now]);
            $db->table('starttoo_feed_stats')->where($key)->update([
                'views' => $db->raw('views + '.$views),
                'view_ms' => $db->raw('view_ms + '.(int) min($p['ms'], self::MAX_MS * self::MAX_ITEMS)),
                'opens' => $db->raw('opens + '.(int) $p['opens']),
                'updated_at' => $now,
            ]);
        }
        return ['ok' => true];
    }

    /** 보관 기간이 지난 원본 · 본 기록을 지운다(매일). */
    public function prune(): array
    {
        $db = $this->db();
        $events = 0; $seen = 0;
        $before = now()->subDays(self::EVENTS_DAYS);
        do {
            $n = $db->table('starttoo_feed_events')->where('created_at', '<', $before)->orderBy('id')->limit(5000)->delete();
            $events += $n;
        } while ($n === 5000);
        $seenBefore = now()->subDays(self::SEEN_DAYS);
        do {
            $n = $db->table('starttoo_feed_seen')->where('last_at', '<', $seenBefore)->limit(5000)->delete();
            $seen += $n;
        } while ($n === 5000);
        return ['events' => $events, 'seen' => $seen];
    }

    /** 탈퇴: 이 회원의 행동 기록을 지운다(집계 숫자는 누구 것인지 남지 않아 그대로 둔다). */
    public function forget(int $userId): void
    {
        $this->db()->table('starttoo_feed_events')->where('user_id', $userId)->delete();
        $this->db()->table('starttoo_feed_seen')->where('user_id', $userId)->delete();
    }
}
