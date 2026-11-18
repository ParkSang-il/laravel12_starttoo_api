<?php

namespace App\Services\Starttoo;

use Illuminate\Support\Facades\Storage;

/** 업로드 위치와 분리하여 앱에 표시할 미디어 주소를 만든다. */
class MediaUrls
{
    public function url(?string $path): ?string
    {
        if (!$path) return null;
        $base = rtrim(trim((string) config('starttoo.cdn_url', '')), '/');
        if ($base === '') return Storage::disk('starttoo')->temporaryUrl($path, now()->addHour());
        // DB에는 버킷명이 아닌 artwork/starttoo/... 또는 profile/starttoo/... 키가 저장된다.
        $key = implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
        return $base.'/'.$key;
    }
}
