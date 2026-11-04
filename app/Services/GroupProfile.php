<?php

namespace App\Services;

/** Public profile fields only; callers must check the group/place access first. */
class GroupProfile
{
    public function summary(object $account): array
    {
        return [
            'account_id' => (int) $account->account_id,
            'display_name' => $account->display_name,
            'nickname_tag' => isset($account->nickname_tag) ? (string) $account->nickname_tag : null,
            'nickname' => $account->display_name === null ? null : $account->display_name.(isset($account->nickname_tag) ? '#'.$account->nickname_tag : ''),
            'avatar_url' => $this->imageUrl($account->avatar_disk ?? null, $account->avatar_path ?? null, $account->avatar_url ?? null),
            'avatar_thumbnail_url' => $this->imageUrl($account->avatar_disk ?? null, $account->avatar_thumbnail_path ?? null, $account->avatar_thumbnail_url ?? null),
        ];
    }

    public function imageUrl(?string $disk, ?string $path, ?string $legacy = null): ?string
    {
        if ($disk === 's3' && $path && str_starts_with($path, 'profile/')) {
            return \Illuminate\Support\Facades\Storage::disk('s3')->temporaryUrl($path, now()->addHour());
        }
        return $this->url($legacy);
    }

    private function url(?string $value): ?string
    {
        if (!$value) return null;
        $value = trim($value);
        return filter_var($value, FILTER_VALIDATE_URL) && in_array(strtolower(parse_url($value, PHP_URL_SCHEME) ?? ''), ['https', 'http'], true)
            ? $value : null;
    }
}
