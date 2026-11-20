<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * 카카오·네이버 가입 시 제공자 프로필 사진·닉네임을 가입 화면 기본값으로 준비한다.
 * - 사진: 아직 프로필 사진이 없을 때만 내려받아 저장한다(회원이 바꾸면 그 사진으로 저장).
 * - 닉네임: 규칙에 맞고 사용 중이 아니면 추천 닉네임으로 하루 보관한다.
 * 실패해도 로그인은 막지 않는다.
 */
class SignupProfileSeed
{
    /** 사진을 내려받을 수 있는 제공자 이미지 서버(그 외 주소는 받지 않는다). */
    private const IMAGE_HOSTS = ['kakaocdn.net', 'pstatic.net'];
    private const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

    public function apply(int $userId, array $profile): void
    {
        try {
            $this->nickname($userId, $profile['nickname'] ?? null);
        } catch (\Throwable $e) {
            report($e);
        }
        try {
            $this->photo($userId, $profile['image'] ?? null);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Profiles::show에서 쓰는 가입용 추천 닉네임(없으면 null). */
    public static function suggestedNickname(int $userId): ?string
    {
        $name = Cache::get(self::key($userId));
        if (! is_string($name)) return null;
        $taken = app(ProjectDatabase::class)->database()->table('starttoo_users')
            ->where('nickname', $name)->where('id', '!=', $userId)->exists();

        return $taken ? null : $name;
    }

    private static function key(int $userId): string
    {
        return "starttoo:signup-nickname:{$userId}";
    }

    private function nickname(int $userId, mixed $nickname): void
    {
        if (! is_string($nickname)) return;
        // 닉네임 규칙(한글·영문·숫자)에 맞게 다른 문자는 뺀다.
        $name = strtolower(preg_replace('/[^가-힣a-zA-Z0-9]/u', '', $nickname) ?? '');
        $max = preg_match('/[가-힣]/u', $name) ? 8 : 15;
        $length = mb_strlen($name);
        if ($length < 2 || $length > $max) return;
        Cache::put(self::key($userId), $name, now()->addDay());
    }

    private function photo(int $userId, mixed $url): void
    {
        if (! is_string($url) || ! str_starts_with($url, 'https://')) return;
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $allowed = array_filter(self::IMAGE_HOSTS, fn ($h) => $host === $h || str_ends_with($host, '.'.$h));
        if (! $allowed) return;

        $db = app(ProjectDatabase::class)->database();
        if ($db->table('starttoo_users')->where('id', $userId)->value('avatar_path') !== null) return;

        $response = Http::connectTimeout(3)->timeout(6)->withOptions(['allow_redirects' => false])->get($url);
        $bytes = $response->successful() ? $response->body() : '';
        if ($bytes === '' || strlen($bytes) > self::MAX_IMAGE_BYTES) return;

        $photos = app(ProfilePhotos::class);
        $path = $photos->storeBytes($bytes);
        // 그 사이 회원이 사진을 정했다면 덮어쓰지 않고 방금 저장한 사진을 지운다.
        $updated = $db->table('starttoo_users')->where('id', $userId)->whereNull('avatar_path')
            ->update(['avatar_path' => $path, 'updated_at' => now()]);
        if (! $updated) $photos->delete($path);
    }
}
