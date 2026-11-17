<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

class TermsConsents
{
    public function catalog(): array
    {
        try {
            $catalog = json_decode(file_get_contents(resource_path('starttoo/terms.json')), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($catalog) || !is_bool($catalog['is_draft'] ?? null)
                || ($catalog['version'] ?? null) !== config('starttoo.terms_version')
                || !is_array($catalog['documents'] ?? null) || !$catalog['documents']) {
                throw new \RuntimeException('약관 설정이 올바르지 않습니다.');
            }
            if ($catalog['is_draft'] && !config('starttoo.draft_terms_enabled', false)) {
                $this->fail(503, 'TERMS_NOT_AVAILABLE', '약관을 준비하고 있어요. 잠시 후 다시 시도해주세요.');
            }
            $ids = [];
            foreach ($catalog['documents'] as $document) {
                foreach (['id', 'version', 'title', 'content'] as $field) {
                    if (!is_string($document[$field] ?? null) || trim($document[$field]) === '') {
                        throw new \RuntimeException('약관 문서 내용이 비어 있습니다.');
                    }
                }
                if (!is_bool($document['required'] ?? null) || in_array($document['id'], $ids, true)) {
                    throw new \RuntimeException('약관 항목 설정이 올바르지 않습니다.');
                }
                $ids[] = $document['id'];
            }
            // 본문이나 필수 여부가 바뀌면 같은 버전명이어도 기존 화면의 제출을 거절한다.
            $catalog['revision'] = hash('sha256', json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            return $catalog;
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->fail(503, 'TERMS_NOT_AVAILABLE', '약관을 불러오지 못했어요. 잠시 후 다시 시도해주세요.');
        }
    }

    public function accept(string $token, array $input, AuthSessions $sessions): array
    {
        $catalog = $this->catalog();
        if ($input['version'] !== $catalog['version'] || $input['revision'] !== $catalog['revision']) {
            $this->fail(409, 'TERMS_VERSION_CHANGED', '약관이 변경됐어요. 새 약관을 확인해주세요.');
        }
        $choices = [];
        foreach ($catalog['documents'] as $document) {
            $accepted = $input['consents'][$document['id']] ?? false;
            if (!is_bool($accepted) || ($document['required'] && !$accepted)) {
                $this->fail(422, 'CONSENT_REQUIRED', '필수 항목에 동의해주세요.');
            }
            $choices[$document['id']] = $accepted;
        }
        if (array_diff(array_keys($input['consents']), array_keys($choices))) {
            $this->fail(422, 'INVALID_CONSENT', '동의 항목을 다시 확인해주세요.');
        }
        $hash = hash('sha256', json_encode([$catalog['version'], $catalog['revision'], $choices], JSON_THROW_ON_ERROR));
        $db = app(ProjectDatabase::class)->database();
        return $db->transaction(function () use ($db, $token, $input, $catalog, $choices, $hash, $sessions) {
            $identity = $sessions->authenticate($token);
            // 회원 행 잠금으로 같은 회원의 동시 제출을 직렬화한다.
            $user = $db->table('starttoo_users')->where('id', $identity['account']['id'])->lockForUpdate()->first();
            $sessions->authenticate($token);
            $submissions = $db->table('starttoo_consent_submissions')->where('user_id', $user->id);
            $existing = (clone $submissions)->where('request_key', $input['request_key'])->first()
                ?? (clone $submissions)->where('version', $catalog['version'])->first();
            if ($existing) {
                if ($existing->payload_hash !== $hash) {
                    $this->fail(409, 'CONSENT_CONFLICT', '이미 저장한 동의 내용과 달라요. 다시 확인해주세요.');
                }
                return json_decode($existing->response_payload, true, 512, JSON_THROW_ON_ERROR);
            }
            $at = now();
            $submissionId = (string) Str::uuid();
            $db->table('starttoo_users')->where('id', $user->id)->update(['terms_version' => $catalog['version'], 'updated_at' => $at]);
            $result = $sessions->authenticate($token)['account'];
            $db->table('starttoo_consent_submissions')->insert([
                'id' => $submissionId, 'user_id' => $user->id, 'version' => $catalog['version'],
                'revision' => $catalog['revision'], 'request_key' => $input['request_key'],
                'payload_hash' => $hash, 'is_draft' => $catalog['is_draft'],
                'documents_snapshot' => json_encode($catalog['documents'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'response_payload' => json_encode($result, JSON_THROW_ON_ERROR), 'recorded_at' => $at,
            ]);
            foreach ($catalog['documents'] as $document) {
                // 선택 항목의 미동의도 명시적으로 기록하고 동의 당시 본문을 함께 보존한다.
                $db->table('starttoo_consent_records')->insert([
                    'submission_id' => $submissionId, 'document_id' => $document['id'],
                    'document_version' => $document['version'], 'content_sha256' => hash('sha256', $document['content']),
                    'required' => $document['required'], 'accepted' => $choices[$document['id']], 'recorded_at' => $at,
                ]);
            }
            // 권한과 요청 검증을 통과한 변경만 유효 활동으로 반영한다.
            $sessions->authenticate($token, true);
            return $result;
        }, 3);
    }

    private function fail(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(compact('code', 'message'), $status));
    }
}
