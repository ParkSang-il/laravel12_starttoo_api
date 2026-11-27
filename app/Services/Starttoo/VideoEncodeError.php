<?php

namespace App\Services\Starttoo;

/** 영상 자체 문제(길이 · 형식 · 손상)로 변환할 수 없다. codeName: CORRUPTED · UNSUPPORTED · TOO_LONG · TOO_SHORT */
class VideoEncodeError extends \RuntimeException
{
    public function __construct(public string $codeName)
    {
        parent::__construct($codeName);
    }
}
