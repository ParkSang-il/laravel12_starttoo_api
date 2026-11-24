<?php

namespace App\Services\Starttoo;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Http;

/**
 * 카카오 로컬 API로 주소·장소를 좌표로 바꾼다. REST 키는 서버에만 두고 앱에 내려보내지 않는다.
 * 결과: address(도로명 우선) · jibun · area(구 동) · lat · lng
 */
class KakaoLocal
{
    private const BASE = 'https://dapi.kakao.com/v2/local/search/';

    /** 주소 검색 결과가 없으면 장소(건물·상호) 검색으로 찾는다. 최대 10개. */
    public function search(string $query): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) return [];
        $items = array_map(fn ($d) => $this->fromAddress($d), $this->request('address.json', ['query' => $query, 'size' => 10]));
        if (!$items) $items = array_map(fn ($d) => $this->fromPlace($d), $this->request('keyword.json', ['query' => $query, 'size' => 10]));
        $unique = [];
        foreach (array_filter($items) as $item) $unique[$item['address']] ??= $item;
        return array_values($unique);
    }

    /** 저장할 때 서버가 다시 좌표를 찾는다. 앱이 보낸 좌표는 믿지 않는다. */
    public function geocode(string $address): ?array
    {
        $found = array_filter(array_map(fn ($d) => $this->fromAddress($d), $this->request('address.json', ['query' => $address, 'size' => 1])));
        return $found ? array_values($found)[0] : null;
    }

    private function request(string $path, array $query): array
    {
        $key = config('starttoo.kakao_rest_key');
        if (!$key) $this->fail(503, 'MAP_NOT_CONFIGURED', '주소 검색을 준비하고 있어요. 잠시 후 다시 시도해주세요.');
        try {
            $response = Http::withHeaders(['Authorization' => 'KakaoAK '.$key])
                ->connectTimeout(3)->timeout(6)->get(self::BASE.$path, $query);
        } catch (\Throwable $e) {
            report($e);
            $this->fail(502, 'ADDRESS_SEARCH_FAILED', '주소를 검색하지 못했어요. 잠시 후 다시 시도해주세요.');
        }
        if (!$response->ok()) {
            // 키·권한 오류 내용은 로그에만 남기고 앱에는 일반 안내만 준다.
            report(new \RuntimeException('Kakao local '.$path.' '.$response->status()));
            $this->fail(502, 'ADDRESS_SEARCH_FAILED', '주소를 검색하지 못했어요. 잠시 후 다시 시도해주세요.');
        }
        return $response->json('documents') ?? [];
    }

    private function fromAddress(array $d): ?array
    {
        $road = $d['road_address'] ?? null;
        $jibun = $d['address'] ?? null;
        $base = $jibun ?: $road;
        if (!$base || !isset($d['x'], $d['y'])) return null;
        return $this->item($road['address_name'] ?? ($d['address_name'] ?? ''), $jibun['address_name'] ?? ($d['address_name'] ?? ''),
            trim(($base['region_2depth_name'] ?? '').' '.($jibun['region_3depth_name'] ?? $base['region_3depth_name'] ?? '')), $d['y'], $d['x'], null);
    }

    private function fromPlace(array $d): ?array
    {
        if (!isset($d['x'], $d['y'])) return null;
        $parts = preg_split('/\s+/u', $d['address_name'] ?? '');
        return $this->item($d['road_address_name'] ?: ($d['address_name'] ?? ''), $d['address_name'] ?? '',
            trim(($parts[1] ?? '').' '.($parts[2] ?? '')), $d['y'], $d['x'], $d['place_name'] ?? null);
    }

    private function item(string $address, string $jibun, string $area, $lat, $lng, ?string $place): ?array
    {
        if ($address === '') return null;
        return ['address' => $address, 'jibun' => $jibun, 'area' => mb_substr($area, 0, 40), 'place' => $place,
            'lat' => round((float) $lat, 7), 'lng' => round((float) $lng, 7)];
    }

    private function fail(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(['code' => $code, 'message' => $message], $status));
    }
}
