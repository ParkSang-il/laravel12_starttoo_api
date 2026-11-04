<?php

namespace App\Services;

class NicknameGenerator
{
    public function __construct(private ProjectDatabase $database) {}

    public function generate(): string
    {
        $words = $this->database->database()->table('nickname_words')->where('is_active', true);
        $adjective = (clone $words)->where('type', 'adjective')->inRandomOrder()->value('word') ?? '다정한';
        $noun = (clone $words)->where('type', 'noun')->inRandomOrder()->value('word') ?? '밥지기';

        return $adjective.' '.$noun;
    }
}
