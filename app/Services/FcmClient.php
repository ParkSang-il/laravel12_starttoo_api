<?php
namespace App\Services;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
class FcmClient {
    private ?array $access = null;
    private bool $warned = false;
    private function bearer(bool $refresh = false): ?array {
        if (!$refresh && $this->access && $this->access['expires'] > time() + 60) return $this->access;
        try {
            $path = config('services.fcm.credentials');
            if (!is_string($path) || !is_readable($path)) throw new \RuntimeException('credentials unavailable');
            $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (empty($data['client_email']) || empty($data['private_key']) || empty($data['project_id'])) throw new \RuntimeException('credentials invalid');
            $encode = fn ($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
            $body = $encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$encode(json_encode([
                'iss' => $data['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token', 'iat' => time(), 'exp' => time() + 3600]));
            if (!openssl_sign($body, $signature, $data['private_key'], OPENSSL_ALGO_SHA256)) throw new \RuntimeException('sign failed');
            $response = Http::connectTimeout(3)->timeout(6)->asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $body.'.'.$encode($signature)]);
            $token = $response->json('access_token');
            if (!$response->successful() || !is_string($token) || $token === '') throw new \RuntimeException('oauth failed');
            return $this->access = ['token' => $token, 'project' => $data['project_id'], 'expires' => time() + min(3600, (int) ($response->json('expires_in') ?? 3600))];
        } catch (\Throwable) {
            if (!$this->warned) { Log::warning('FCM credentials or authorization unavailable.'); $this->warned = true; }
            return null;
        }
    }
    /** Returns only safe classifications; never logs response bodies or tokens. */
    public function send(string $token, array $payload): array {
        $credentials = $this->bearer();
        if (!$credentials) return ['state' => 'skipped'];
        try {
            $send = fn ($c) => Http::withToken($c['token'])->connectTimeout(3)->timeout(6)
                ->post('https://fcm.googleapis.com/v1/projects/'.rawurlencode($c['project']).'/messages:send', ['message' => ['token' => $token, ...$payload]]);
            $response = $send($credentials);
            if ($response->status() === 401 && ($credentials = $this->bearer(true))) $response = $send($credentials);
            if ($response->successful()) return ['state' => 'sent'];
            $code = $response->json('error.status') ?? 'UNKNOWN'; $tokenViolation = false;
            foreach (($response->json('error.details') ?? []) as $detail) {
                if (($detail['@type'] ?? '') === 'type.googleapis.com/google.firebase.fcm.v1.FcmError') $code = $detail['errorCode'] ?? $code;
                if (($detail['@type'] ?? '') === 'type.googleapis.com/google.rpc.BadRequest') {
                    foreach (($detail['fieldViolations'] ?? []) as $violation) if (($violation['field'] ?? '') === 'message.token') $tokenViolation = true;
                }
            }
            if (in_array($code, ['UNREGISTERED', 'SENDER_ID_MISMATCH'], true) || ($code === 'INVALID_ARGUMENT' && $tokenViolation)) return ['state' => 'invalid'];
            if (in_array($code, ['QUOTA_EXCEEDED', 'UNAVAILABLE', 'INTERNAL'], true) || in_array($response->status(), [429, 500, 503], true)) {
                $header = $response->header('Retry-After');
                $delay = is_numeric($header) ? (int) $header : (($at = strtotime($header ?? '')) ? $at - time() : 60);
                return ['state' => 'retry', 'delay' => max(60, min(3600, $delay))];
            }
            Log::warning('FCM delivery rejected.', ['status' => $response->status(), 'code' => is_string($code) ? substr($code, 0, 64) : 'UNKNOWN']);
            return ['state' => 'rejected'];
        } catch (\Throwable) { return ['state' => 'retry', 'delay' => 60]; }
    }
}
