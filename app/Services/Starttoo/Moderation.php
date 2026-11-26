<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;

/**
 * 운영 콘솔 콘텐츠 처리(게시물 · 작품 화면 일괄 처리). 신고와 무관하게 콘텐츠를 직접 숨기거나 되돌린다.
 * - hide 숨김 / show 다시 노출 / delete 삭제(복구 가능한 숨김: moderation_removed_at)
 * - unrecommend · recommend: 작품 · 도안을 인기 목록에서 빼기 · 되돌리기
 * - fit_block · fit_allow: 도안을 가상 피팅 도안 목록에서 빼기 · 되돌리기
 * 앱의 공개 조회는 모두 moderation_hidden 을 보고 있으므로 숨김은 바로 반영된다.
 * 처리마다 starttoo_safety_audits 에도 남긴다(신고 처리 기록과 같은 자리).
 */
class Moderation
{
    public const TYPES = [
        'artwork' => ['table' => 'starttoo_artworks', 'uuid' => true, 'versioned' => true],
        'post' => ['table' => 'starttoo_posts', 'uuid' => true, 'versioned' => true],
        'review' => ['table' => 'starttoo_reviews', 'uuid' => false, 'versioned' => false],
        'community' => ['table' => 'starttoo_community_posts', 'uuid' => false, 'versioned' => false],
    ];
    public const ACTIONS = ['hide', 'show', 'delete', 'unrecommend', 'recommend', 'fit_block', 'fit_allow'];

    private function db() { return app(ProjectDatabase::class)->database(); }

    /** "artwork:uuid" 같은 키 → [type, id] (형식이 틀리면 null) */
    public function parseKey(string $key): ?array
    {
        [$type, $id] = array_pad(explode(':', $key, 2), 2, '');
        $def = self::TYPES[$type] ?? null;
        if (!$def) return null;
        if ($def['uuid'] ? !\Illuminate\Support\Str::isUuid($id) : !ctype_digit($id)) return null;
        return [$type, $def['uuid'] ? $id : (int) $id];
    }

    /**
     * 처리 결과: ['changed' => 바뀐 수, 'skipped' => 해당 없음(삭제된 콘텐츠를 권한 없이 노출 등)]
     * $canRestore: 삭제(복구 가능한 숨김)된 콘텐츠를 다시 노출할 수 있는지(최고 관리자)
     */
    public function apply(string $type, string|int $id, string $action, string $operator, bool $canRestore): bool
    {
        $def = self::TYPES[$type] ?? throw new \InvalidArgumentException('콘텐츠 종류를 확인해주세요.');
        if (!in_array($action, self::ACTIONS, true)) throw new \InvalidArgumentException('처리를 확인해주세요.');
        if (in_array($action, ['unrecommend', 'recommend', 'fit_block', 'fit_allow'], true) && $type !== 'artwork') return false;
        $db = $this->db();
        return $db->transaction(function () use ($db, $def, $type, $id, $action, $operator, $canRestore) {
            $row = $db->table($def['table'])->where('id', $id)->lockForUpdate()->first();
            if (!$row) return false;
            if (in_array($action, ['fit_block', 'fit_allow'], true) && $row->kind !== 'design') return false;
            $removed = $row->moderation_removed_at !== null;
            $values = match ($action) {
                'hide' => $row->moderation_hidden ? null : ['moderation_hidden' => true],
                'delete' => $removed ? null : ['moderation_hidden' => true, 'moderation_removed_at' => now()],
                'show' => (!$row->moderation_hidden || ($removed && !$canRestore)) ? null : ['moderation_hidden' => false, 'moderation_removed_at' => null],
                'unrecommend' => $row->recommend_excluded ? null : ['recommend_excluded' => true],
                'recommend' => !$row->recommend_excluded ? null : ['recommend_excluded' => false],
                'fit_block' => $row->fit_blocked ? null : ['fit_blocked' => true],
                'fit_allow' => !$row->fit_blocked ? null : ['fit_blocked' => false],
            };
            if ($values === null) return false;
            if ($def['versioned'] && array_key_exists('moderation_hidden', $values)) $values['version'] = (int) $row->version + 1;
            $db->table($def['table'])->where('id', $id)->update($values + ['updated_at' => $row->updated_at]);
            $before = array_intersect_key((array) $row, $values);
            $db->table('starttoo_safety_audits')->insert([
                'actor_type' => 'operator', 'operator' => mb_substr($operator, 0, 100), 'target_type' => $type, 'target_id' => (string) $id,
                'action' => 'console_'.$action, 'reason' => '운영 콘솔 콘텐츠 처리',
                'changes' => json_encode(['before' => $before, 'after' => $values], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
            return true;
        }, 3);
    }
}
