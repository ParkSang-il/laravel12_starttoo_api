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
            'avatar_url' => $this->url($account->avatar_url),
            'avatar_thumbnail_url' => $this->url($account->avatar_thumbnail_url),
        ];
    }

    private function url(?string $value): ?string
    {
        if (!$value) return null;
        $value = trim($value);
        return filter_var($value, FILTER_VALIDATE_URL) && in_array(strtolower(parse_url($value, PHP_URL_SCHEME) ?? ''), ['https', 'http'], true)
            ? $value : null;
    }
}
