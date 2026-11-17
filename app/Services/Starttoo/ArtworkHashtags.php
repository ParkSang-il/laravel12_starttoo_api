<?php

namespace App\Services\Starttoo;

final class ArtworkHashtags
{
    public static function extract(string $description): array
    {
        preg_match_all('/(?<![\p{L}\p{N}\p{M}_#\/&=])#([\p{L}\p{N}_][\p{L}\p{N}\p{M}_]*)/u', $description, $matches);
        // 표시 원문은 설명에 유지하고, 저장하는 태그는 # 없이 소문자로 통일한다.
        return array_values(array_unique(array_map(fn ($tag) => mb_strtolower($tag, 'UTF-8'), $matches[1])));
    }
}
