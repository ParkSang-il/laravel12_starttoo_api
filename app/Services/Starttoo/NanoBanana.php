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
            // 상태 코드만 남긴다(본문에 요청 일부가 담길 수 있음).
            throw new RuntimeException('FIT_AI_HTTP_'.$response->status());
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
