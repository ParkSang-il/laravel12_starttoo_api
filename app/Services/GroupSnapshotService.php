<?php

namespace App\Services;

use App\Models\ProjectAccount;

/**
 * 그룹 한 곳의 장소·친구·돌봄 기록을 한 번의 요청으로 모아 돌려준다.
 *
 * 앱이 장소마다 친구·기록 목록을 따로 요청하면 네트워크 왕복이 요청 수만큼 쌓인다.
 * 권한 검사와 응답 형식은 기존 목록 서비스를 그대로 재사용해 개별 목록 API와 동일하게 유지한다.
 */
class GroupSnapshotService
{
    private const MAX_PAGES = 1000;

    public function __construct(
        private GroupPlaceService $places,
        private GroupAnimalService $animals,
        private GroupCareService $care,
    ) {}

    public function show(ProjectAccount $actor, string $group): array
    {
        $canManage = false;
        $places = $this->all(function (int $page) use ($actor, $group, &$canManage) {
            $data = $this->places->index($actor, $group, $page);
            $canManage = (bool) $data['can_manage'];

            return $data;
        }, 'places');

        return [
            'group_id' => $group,
            'can_manage' => $canManage,
            'places' => array_map(fn (array $place) => [
                'place' => $place,
                'animals' => $this->all(fn (int $page) => $this->animals->index($actor, $group, $place['id'], $page), 'animals'),
                'records' => $this->all(fn (int $page) => $this->care->index($actor, $group, $place['id'], $page), 'records'),
            ], $places),
        ];
    }

    private function all(callable $load, string $key): array
    {
        $rows = [];
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $data = $load($page);
            array_push($rows, ...$data[$key]);
            if (! $data['has_more']) {
                return $rows;
            }
        }
        abort(422, '목록이 너무 많아 한 번에 불러올 수 없습니다.');
    }
}
