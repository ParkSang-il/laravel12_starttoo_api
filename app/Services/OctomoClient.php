<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class OctomoClient
{
    public function configured(): bool
    {
        return is_string(config('services.octomo.api_key')) && trim(config('services.octomo.api_key')) !== '';
    }

    public function messageExists(string $phone, string $text): bool
    {
        abort_unless($this->configured(), 503, '휴대폰 인증 서비스가 설정되지 않았어요.');
        try {
            $response = Http::acceptJson()->asJson()->withToken(config('services.octomo.api_key'), 'Octomo')
                ->connectTimeout(3)->timeout(8)->post('https://api.octoverse.kr/octomo/v1/public/message/exists', [
                    'mobileNum' => $phone, 'text' => $text, 'withinMinutes' => 5,
                ]);
        } catch (ConnectionException) {
            abort(503, '인증 문자 수신 여부를 확인하지 못했어요. 잠시 후 다시 확인해 주세요.');
        }
        // Do not expose provider responses, credentials or phone/message payloads in errors.
        abort_unless($response->successful() && is_bool($response->json('exists')), 503,
            '휴대폰 인증 서비스에 연결하지 못했어요. 잠시 후 다시 확인해 주세요.');
        return $response->json('exists') === true;
    }
}
