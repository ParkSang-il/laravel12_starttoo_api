<?php

namespace App\Services\Starttoo;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * 나노바나나(Gemini 이미지 모델) 호출. 키는 서버 설정(STARTTOO_GEMINI_API_KEY)에만 있다.
 * 키 · 이미지 내용은 로그에 남기지 않는다.
 */
class NanoBanana
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    /**
     * 영역 분할(Gemini 텍스트 모델). [$what] 영역들의 [박스(0~1000: y0,x0,y1,x1), 마스크 PNG 바이트] 목록.
     * 마스크는 박스 크기 기준 확률(0~255) 흑백 이미지다.
     *
     * @return array<int, array{0: array{0:int,1:int,2:int,3:int}, 1: string}>
     */
    public function segment(string $bytes, string $mime, string $what): array
    {
        $key = trim((string) config('starttoo.gemini_api_key', ''));
        if ($key === '') throw new RuntimeException('FIT_AI_NOT_CONFIGURED');
        $model = (string) config('starttoo.gemini_text_model', 'gemini-2.5-flash');
        $prompt = 'Give the segmentation masks for '.$what.'. '
            .'Output a JSON list of segmentation masks where each entry contains the 2D bounding box in the key "box_2d", '
            .'the segmentation mask in key "mask", and the text label in the key "label". Use descriptive labels.';
        $response = Http::connectTimeout(5)->timeout(60)->acceptJson()
            ->withHeaders(['x-goog-api-key' => $key])
            ->post(sprintf(self::ENDPOINT, rawurlencode($model)), [
                'contents' => [['role' => 'user', 'parts' => [
                    ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($bytes)]],
                    ['text' => $prompt],
                ]]],
                // 분할은 생각(thinking)을 끄는 편이 정확하다(Gemini 문서 권장)
                'generationConfig' => ['temperature' => 0.2, 'thinkingConfig' => ['thinkingBudget' => 0], 'responseMimeType' => 'application/json'],
            ]);
        if (!$response->successful()) {
            $reason = (string) $response->json('error.status', '');
            throw new RuntimeException('FIT_SEG_HTTP_'.$response->status().($reason !== '' ? " {$reason}" : ''));
        }
        $text = '';
        foreach ((array) $response->json('candidates.0.content.parts', []) as $part) $text .= (string) ($part['text'] ?? '');
        // ```json … ``` 로 감싸 오는 경우
        $text = trim(preg_replace('/^```(?:json)?|```$/m', '', $text) ?? '');
        $items = json_decode($text, true);
        if (!is_array($items)) return [];
        $out = [];
        foreach ($items as $item) {
            $box = $item['box_2d'] ?? null;
            $mask = (string) ($item['mask'] ?? '');
            if (!is_array($box) || count($box) !== 4 || $mask === '') continue;
            $mask = preg_replace('#^data:image/[a-z]+;base64,#', '', $mask);
            $png = base64_decode((string) $mask, true);
            if ($png === false || $png === '') continue;
            $out[] = [array_map('intval', $box), $png];
        }
        return $out;
    }

    public function configured(): bool
    {
        return trim((string) config('starttoo.gemini_api_key', '')) !== '';
    }

    /**
     * 프롬프트 + 이미지들(바이트, mime) → 생성 이미지 바이트.
     *
     * @param  array<int, array{0: string, 1: string}>  $images  [[bytes, mime], ...]
     */
    public function generate(string $prompt, array $images = [], ?string $aspectRatio = null): string
    {
        $key = trim((string) config('starttoo.gemini_api_key', ''));
        if ($key === '') throw new RuntimeException('FIT_AI_NOT_CONFIGURED');
        $parts = [['text' => $prompt]];
        foreach ($images as [$bytes, $mime]) {
            $parts[] = ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($bytes)]];
        }
        $config = ['responseModalities' => ['IMAGE']];
        if ($aspectRatio) $config['imageConfig'] = ['aspectRatio' => $aspectRatio];
        $model = (string) config('starttoo.gemini_image_model', 'gemini-2.5-flash-image');

        $response = Http::connectTimeout(5)->timeout(70)->acceptJson()
            ->withHeaders(['x-goog-api-key' => $key])
            ->post(sprintf(self::ENDPOINT, rawurlencode($model)), [
                'contents' => [['role' => 'user', 'parts' => $parts]],
                'generationConfig' => $config,
            ]);
        if (!$response->successful()) {
            // 상태 코드 + Google 오류 이유(짧게)만 남긴다. 키 · 이미지는 남기지 않는다.
            $reason = (string) $response->json('error.status', '');
            $message = mb_substr((string) $response->json('error.message', ''), 0, 160);
            throw new RuntimeException('FIT_AI_HTTP_'.$response->status().($reason !== '' ? " {$reason}" : '').($message !== '' ? " | {$message}" : ''));
        }
        foreach ((array) $response->json('candidates.0.content.parts', []) as $part) {
            $data = $part['inlineData']['data'] ?? $part['inline_data']['data'] ?? null;
            if (is_string($data) && $data !== '') {
                $bytes = base64_decode($data, true);
                if ($bytes !== false) return $bytes;
            }
        }
        // 안전 필터 등으로 이미지가 없을 때
        throw new RuntimeException('FIT_AI_NO_IMAGE:'.((string) $response->json('candidates.0.finishReason', 'unknown')));
    }
}
