<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/** Normalize status writes without losing stages when an older app sends neutered. */
class GroupAnimalState
{
    public const STAGES = ['needed', 'trapScheduled', 'surgery', 'released', 'done'];

    public function normalize(array $data, ?object $current = null): array
    {
        $explicitStage = array_key_exists('tnr_stage', $data);
        if (array_key_exists('tnr_dates', $data) && !$explicitStage) {
            throw ValidationException::withMessages(['tnr_stage' => ['단계별 날짜 변경에는 TNR 단계가 필요합니다.']]);
        }
        $oldStage = $current?->tnr_stage ?? (($current?->neutered ?? false) ? 'done' : 'needed');
        $stage = $data['tnr_stage'] ?? $oldStage;
        if (!$explicitStage && array_key_exists('neutered', $data)
            && (bool) $data['neutered'] !== (bool) ($current?->neutered ?? false)) {
            $stage = $data['neutered'] ? 'done' : 'needed';
        }
        $index = array_search($stage, self::STAGES, true);
        $neutered = $index >= 2;
        if ($explicitStage && array_key_exists('neutered', $data) && (bool) $data['neutered'] !== $neutered) {
            throw ValidationException::withMessages(['neutered' => ['TNR 단계와 중성화 여부가 일치하지 않습니다.']]);
        }
        $dates = $current?->tnr_dates ? json_decode($current->tnr_dates, true, 512, JSON_THROW_ON_ERROR) : [];
        foreach ($data['tnr_dates'] ?? [] as $key => $value) {
            if (array_search($key, self::STAGES, true) > $index) {
                throw ValidationException::withMessages(['tnr_dates' => ['현재 단계 이후의 날짜는 저장할 수 없습니다.']]);
            }
            if ($value === null) {
                unset($dates[$key]);
            } else {
                $dates[$key] = $value;
            }
        }
        $dates = array_filter($dates, fn ($key) => array_search($key, self::STAGES, true) <= $index, ARRAY_FILTER_USE_KEY);
        if ($explicitStage && $stage !== 'needed' && !isset($dates[$stage])) {
            throw ValidationException::withMessages(["tnr_dates.$stage" => ['선택한 TNR 단계의 날짜가 필요합니다.']]);
        }
        $data['tnr_stage'] = $stage;
        $data['tnr_dates'] = json_encode((object) $dates, JSON_THROW_ON_ERROR);
        $data['neutered'] = $neutered;

        $status = $data['life_status'] ?? $current?->life_status ?? 'active';
        if ($status === 'active') {
            if (isset($data['life_status_at'])) {
                throw ValidationException::withMessages(['life_status_at' => ['활동 중 상태에는 변경 날짜를 지정하지 않습니다.']]);
            }
            $data['life_status_at'] = null;
        } elseif (!array_key_exists('life_status_at', $data)) {
            $data['life_status_at'] = $status !== ($current?->life_status ?? 'active')
                ? today()->toDateString() : $current?->life_status_at;
        }
        return $data;
    }
}
