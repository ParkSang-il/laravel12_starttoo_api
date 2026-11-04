<?php

namespace App\Services;

use App\Models\ProjectAccount;

class NicknameGenerator
{
    public function __construct(private ProjectDatabase $database) {}

    public function forAccount(ProjectAccount $account): string
    {
        if (!$account->exists) {
            throw new \LogicException('닉네임 태그는 저장된 계정 ID로 발급해야 합니다.');
        }
        $words = $this->database->database()->table('nickname_words')->where('is_active', true);
        $adjective = (clone $words)->where('type', 'adjective')->inRandomOrder()->value('word') ?? '다정한';
        $noun = (clone $words)->where('type', 'noun')->inRandomOrder()->value('word') ?? '밥지기';

        // The database primary key is unique even when concurrent signups select the same words.
        return $adjective.' '.$noun.'#'.$account->id;
    }
}
