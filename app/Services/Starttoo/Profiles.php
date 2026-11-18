<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class Profiles
{
    public function options(): array
    {
        return json_decode(file_get_contents(resource_path('starttoo/profile-options.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public function show(string $token, AuthSessions $sessions): array
    {
        $identity = $sessions->authenticate($token, true);
        $user = app(ProjectDatabase::class)->database()->table('starttoo_users')->where('id', $identity['account']['id'])->first();

        return [...$this->options(), 'account' => $identity['account'],
            'social' => app(Follows::class)->state((int) $user->id, (int) $user->id),
            'connected_providers' => app(ProjectDatabase::class)->database()->table('starttoo_auth_identities')
                ->where('user_id', $user->id)->orderBy('provider')->distinct()->pluck('provider')->all(),
            'profile' => $this->values($user),
            'suggested_nickname' => $user->nickname ?? $this->suggestNickname()];
    }

    private function suggestNickname(): string
    {
        $parts = json_decode(file_get_contents(resource_path('starttoo/nickname-parts.json')), true, 512, JSON_THROW_ON_ERROR);
        $db = app(ProjectDatabase::class)->database();
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $name = $parts['adjectives'][random_int(0, count($parts['adjectives']) - 1)]['word']
                .$parts['nouns'][random_int(0, count($parts['nouns']) - 1)]['word'];
            // 조합 공간이 소진되어도 재시도 횟수에 상한을 둔다.
            if ($attempt >= 20) {
                $name .= random_int(1000, 9999);
            }
            if (!$db->table('starttoo_users')->where('nickname', $name)->exists()) {
                return $name;
            }
        }
        $this->fail(503, 'NICKNAME_UNAVAILABLE', '닉네임을 준비하지 못했어요. 다시 시도해주세요.');
    }

    public function save(string $token, array $input, AuthSessions $sessions, ?UploadedFile $photo = null): array
    {
        $identity = $sessions->authenticate($token);
        if ($photo !== null && Validator::make(['avatar' => $photo], [
            'avatar' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=4096,max_height=4096'],
        ])->fails()) {
            $this->fail(422, 'INVALID_PROFILE_PHOTO', '사진은 JPG·PNG·WebP, 5MB 이하, 가로·세로 4096px 이하로 선택해주세요.');
        }
        $options = $this->options();
        if (is_string($input['nickname'] ?? null)) {
            $input['nickname'] = strtolower(trim($input['nickname']));
        }
        $validator = Validator::make($input, [
            'nickname' => ['required', 'string', 'min:2', 'max:20', 'regex:/^[가-힣a-z0-9]+$/uD'],
            'interest_styles' => ['present', 'array', 'max:5'],
            'interest_styles.*' => ['required', 'string', 'distinct', Rule::in(array_column($options['styles'], 'id'))],
            'interest_region' => ['required', 'string', Rule::in(array_column($options['regions'], 'id'))],
            'bio' => ['sometimes', 'nullable', 'string', 'max:150'],
        ]);
        if ($validator->fails() || !array_is_list($input['interest_styles'] ?? [])) {
            $this->fail(422, 'INVALID_PROFILE', '닉네임(한글·영문·숫자 2~20자), 관심 스타일 최대 5개와 지역을 확인해주세요.');
        }
        $input = $validator->validated();
        $db = app(ProjectDatabase::class)->database();
        if ($identity['account']['next_action'] === 'terms') {
            $this->fail(409, 'PROFILE_TERMS_REQUIRED', '필수 약관에 먼저 동의해주세요.');
        }
        $photos = app(ProfilePhotos::class);
        $newPath = $photo === null ? null : $photos->store($identity['account']['id'], $photo);
        $oldPath = null;
        $committed = false;
        try {
            $result = $db->transaction(function () use ($db, $token, $input, $sessions, $newPath, &$oldPath) {
                $identity = $sessions->authenticate($token);
                $user = $db->table('starttoo_users')->where('id', $identity['account']['id'])->lockForUpdate()->first();
                $sessions->authenticate($token);
                if ($user->terms_version !== config('starttoo.terms_version')) {
                    $this->fail(409, 'PROFILE_TERMS_REQUIRED', '필수 약관에 먼저 동의해주세요.');
                }
                if ($db->table('starttoo_users')->where('nickname', $input['nickname'])->where('id', '!=', $user->id)->exists()) {
                    $this->fail(409, 'NICKNAME_TAKEN', '이미 사용 중인 닉네임이에요. 다른 닉네임을 입력해주세요.');
                }
                $oldPath = $user->avatar_path;
                $db->table('starttoo_users')->where('id', $user->id)->update([
                    'nickname' => $input['nickname'], 'display_name' => $input['nickname'],
                    'interest_styles' => json_encode(array_values($input['interest_styles']), JSON_THROW_ON_ERROR),
                    'interest_region' => $input['interest_region'],
                    'avatar_path' => $newPath ?? $user->avatar_path,
                    'bio' => array_key_exists('bio', $input) ? ($input['bio'] ?? '') : $user->bio,
                    'profile_completed_at' => $user->profile_completed_at ?? now(), 'updated_at' => now(),
                ]);
                // 한 회원 행에 덮어쓰므로 응답 유실 후 같은 요청을 재전송해도 중복 생성하지 않는다.
                return $sessions->authenticate($token, true)['account'];
            }, 3);
            $committed = true;
            if ($newPath !== null) {
                $photos->delete($oldPath);
            }
            return $result;
        } catch (UniqueConstraintViolationException $e) {
            // 서로 다른 회원의 동시 저장도 DB 유일 제약으로 보호한다.
            $this->fail(409, 'NICKNAME_TAKEN', '이미 사용 중인 닉네임이에요. 다른 닉네임을 입력해주세요.');
        } finally {
            if (!$committed) {
                $photos->delete($newPath);
            }
        }
    }

    private function values(object $user): array
    {
        return ['nickname' => $user->nickname,
            'bio' => $user->bio ?? '',
            'avatar_url' => app(MediaUrls::class)->url($user->avatar_path),
            'interest_styles' => $user->interest_styles === null ? [] : json_decode($user->interest_styles, true, 512, JSON_THROW_ON_ERROR),
            'interest_region' => $user->interest_region];
    }

    private function fail(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(compact('code', 'message'), $status));
    }
}
