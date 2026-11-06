<?php

namespace App\Services;

use App\Models\ProjectAccount;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class GroupAnimalDetailService
{
    public function __construct(private ProjectDatabase $database, private GroupPlaceService $places, private GroupAnimalService $animals) {}

    public function show(ProjectAccount $actor, string $group, string $animal, ?string $cursor): array
    {
        $context = $this->places->linkedAnimalPlaces($actor, $group, $animal);
        $places = $context['places'];
        abort_if($places === [], 404, '친구를 찾을 수 없거나 열람 권한이 없습니다.');
        $data = $this->animals->show($actor, $group, $places[0]['id'], $animal);
        $scope = hash('sha256', json_encode([(int) $actor->project_id, (int) $actor->id, $group, $animal,
            $data['version'], array_map(fn ($p) => [$p['id'], $p['version'], $p['access'], $p['can_manage']], $places)], JSON_THROW_ON_ERROR));
        $before = null;
        if ($cursor !== null) {
            $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
            $before = $decoded === false ? null : json_decode($decoded, true);
            if (!is_array($before) || count($before) !== 3 || !array_is_list($before)
                || !is_string($before[0]) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $before[0])
                || \Illuminate\Support\Facades\Validator::make(['at' => $before[0]], ['at' => 'date_format:Y-m-d H:i:s'])->fails()
                || !is_string($before[1]) || !\Illuminate\Support\Str::isUuid($before[1]) || !is_string($before[2])) {
                throw ValidationException::withMessages(['cursor' => '유효하지 않은 이력 요청입니다.']);
            }
            abort_unless(hash_equals($scope, $before[2]), 409, '친구 정보나 열람 권한이 변경됐어요. 새로 조회해 주세요.');
        }
        // Joins keep history constrained to current links and grants on every page.
        $query = $this->database->database()->table('group_animal_sightings as s')
            ->where('s.project_id', $actor->project_id)->where('s.group_id', $group)->where('s.animal_id', $animal)
            ->whereIn('s.place_id', array_column($places, 'id'));
        $stats = (clone $query)->selectRaw("COUNT(*) as total, MAX(CASE WHEN s.status = 'seen' THEN s.fed_at END) as last_seen_at")->first();
        if ($before !== null) {
            $query->where(function ($q) use ($before) {
                $q->where('s.fed_at', '<', $before[0])->orWhere(function ($q) use ($before) {
                    $q->where('s.fed_at', $before[0])->where('s.record_id', '<', $before[1]);
                });
            });
        }
        $rows = $query->orderByDesc('s.fed_at')->orderByDesc('s.record_id')->limit(21)
            ->get(['s.record_id', 's.place_id', 's.fed_at', 's.status']);
        $page = $rows->take(20);
        $last = $page->last();
        $names = array_column($places, 'name', 'id');
        $iso = fn ($date) => $date === null ? null : CarbonImmutable::parse($date, 'UTC')->toISOString();
        return [
            'group' => $context['group'], 'animal' => $data, 'places' => $places,
            'last_seen_at' => $iso($stats->last_seen_at), 'history_total' => (int) $stats->total,
            'history' => $page->map(fn ($row) => ['id' => $row->record_id, 'place_id' => $row->place_id,
                'place_name' => $names[$row->place_id], 'fed_at' => $iso($row->fed_at), 'status' => $row->status])->values()->all(),
            'next_cursor' => $rows->count() > 20
                ? rtrim(strtr(base64_encode(json_encode([$last->fed_at, $last->record_id, $scope], JSON_THROW_ON_ERROR)), '+/', '-_'), '=') : null,
        ];
    }
}
