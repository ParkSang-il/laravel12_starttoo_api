<?php

namespace Database\Seeders;

use App\Models\UsernameAdjective;
use Illuminate\Database\Seeder;

class UsernameAdjectiveSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $adjectives = [
            ['word' => '깊은',       'tone' => 'dark'],
            ['word' => '짙은',       'tone' => 'dark'],
            ['word' => '어두운',     'tone' => 'dark'],
            ['word' => '차가운',     'tone' => 'dark'],
            ['word' => '무거운',     'tone' => 'dark'],
            ['word' => '날카로운',   'tone' => 'bold'],
            ['word' => '거친',       'tone' => 'bold'],
            ['word' => '단단한',     'tone' => 'bold'],
            ['word' => '강렬한',     'tone' => 'bold'],
            ['word' => '거침없는',   'tone' => 'bold'],
            ['word' => '묵직한',     'tone' => 'bold'],
            ['word' => '선명한',     'tone' => 'bold'],
            ['word' => '도드라진',   'tone' => 'bold'],
            ['word' => '뚜렷한',     'tone' => 'bold'],
            ['word' => '날렵한',     'tone' => 'bold'],
            ['word' => '불안한',     'tone' => 'dark'],
            ['word' => '위태로운',   'tone' => 'dark'],
            ['word' => '뒤틀린',     'tone' => 'edgy'],
            ['word' => '뒤엉킨',     'tone' => 'edgy'],
            ['word' => '부드러운',   'tone' => 'soft'],
            ['word' => '고요한',     'tone' => 'soft'],
            ['word' => '잔잔한',     'tone' => 'soft'],
            ['word' => '은은한',     'tone' => 'soft'],
            ['word' => '조용한',     'tone' => 'soft'],
            ['word' => '따뜻한',     'tone' => 'soft'],
            ['word' => '가벼운',     'tone' => 'soft'],
            ['word' => '포근한',     'tone' => 'soft'],
            ['word' => '유연한',     'tone' => 'soft'],
            ['word' => '섬세한',     'tone' => 'soft'],
            ['word' => '차분한',     'tone' => 'soft'],
            ['word' => '아늑한',     'tone' => 'soft'],
            ['word' => '고급스러운', 'tone' => 'soft'],
            ['word' => '여린',       'tone' => 'soft'],
            ['word' => '담담한',     'tone' => 'soft'],
            ['word' => '조심스러운', 'tone' => 'soft'],
            ['word' => '흐릿한',     'tone' => 'mystic'],
            ['word' => '희미한',     'tone' => 'mystic'],
            ['word' => '아른한',     'tone' => 'mystic'],
            ['word' => '비밀스러운', 'tone' => 'mystic'],
            ['word' => '낯선',       'tone' => 'mystic'],
            ['word' => '기묘한',     'tone' => 'mystic'],
            ['word' => '몽환적인',   'tone' => 'mystic'],
            ['word' => '이상한',     'tone' => 'mystic'],
            ['word' => '숨겨진',     'tone' => 'mystic'],
            ['word' => '감춰진',     'tone' => 'mystic'],
            ['word' => '사라지는',   'tone' => 'mystic'],
            ['word' => '흐느끼는',   'tone' => 'mystic'],
            ['word' => '잠든',       'tone' => 'mystic'],
            ['word' => '깨어난',     'tone' => 'mystic'],
            ['word' => '타오르는',   'tone' => 'fire'],
            ['word' => '식지않는',   'tone' => 'fire'],
            ['word' => '뜨거운',     'tone' => 'fire'],
            ['word' => '불붙은',     'tone' => 'fire'],
            ['word' => '숨막히는',   'tone' => 'fire'],
            ['word' => '자유로운',   'tone' => 'free'],
            ['word' => '거침없는',   'tone' => 'free'],
            ['word' => '즉흥적인',   'tone' => 'free'],
            ['word' => '방황하는',   'tone' => 'free'],
            ['word' => '헝클어진',   'tone' => 'free'],
            ['word' => '번지는',     'tone' => 'extra'],
            ['word' => '흩어진',     'tone' => 'extra'],
            ['word' => '남겨진',     'tone' => 'extra'],
            ['word' => '메마른',     'tone' => 'extra'],
            ['word' => '젖은',       'tone' => 'extra'],
            ['word' => '조각난',     'tone' => 'extra'],
            ['word' => '무너진',     'tone' => 'extra'],
            ['word' => '비틀린',     'tone' => 'extra'],
            ['word' => '스치는',     'tone' => 'extra'],
            ['word' => '묶인',       'tone' => 'extra'],
            ['word' => '엮인',       'tone' => 'extra'],
            ['word' => '부서진',     'tone' => 'extra'],
            ['word' => '잔혹한',     'tone' => 'extra'],
            ['word' => '쓸쓸한',     'tone' => 'extra'],
            ['word' => '침울한',     'tone' => 'dark'],
            ['word' => '적막한',     'tone' => 'dark'],
            ['word' => '서늘한',     'tone' => 'dark'],
            ['word' => '그늘진',     'tone' => 'dark'],
            ['word' => '멍든',       'tone' => 'dark'],
            ['word' => '얼어붙은',   'tone' => 'dark'],
            ['word' => '칠흑같은',   'tone' => 'dark'],
            ['word' => '심연의',     'tone' => 'dark'],
            ['word' => '고독한',     'tone' => 'dark'],
            ['word' => '가라앉은',   'tone' => 'dark'],
            ['word' => '비명지르는', 'tone' => 'dark'],
            ['word' => '잠긴',       'tone' => 'dark'],
            ['word' => '무감각한',   'tone' => 'dark'],
            ['word' => '공허한',     'tone' => 'dark'],
            ['word' => '잿빛의',     'tone' => 'dark'],

            // --- Bold / Strong / Intense (강하고 뚜렷한 느낌) ---
            ['word' => '압도적인',   'tone' => 'bold'],
            ['word' => '웅장한',     'tone' => 'bold'],
            ['word' => '절대적인',   'tone' => 'bold'],
            ['word' => '파괴적인',   'tone' => 'bold'],
            ['word' => '맹렬한',     'tone' => 'bold'],
            ['word' => '견고한',     'tone' => 'bold'],
            ['word' => '철벽같은',   'tone' => 'bold'],
            ['word' => '광활한',     'tone' => 'bold'],
            ['word' => '육중한',     'tone' => 'bold'],
            ['word' => '적나라한',   'tone' => 'bold'],
            ['word' => '원초적인',   'tone' => 'bold'],
            ['word' => '야생의',     'tone' => 'bold'],
            ['word' => '무한한',     'tone' => 'bold'],
            ['word' => '거대한',     'tone' => 'bold'],

            // --- Edgy / Rough / Twisted (거칠고 개성있는 느낌) ---
            ['word' => '삐딱한',     'tone' => 'edgy'],
            ['word' => '날선',       'tone' => 'edgy'],
            ['word' => '녹슨',       'tone' => 'edgy'],
            ['word' => '찢겨진',     'tone' => 'edgy'],
            ['word' => '긁힌',       'tone' => 'edgy'],
            ['word' => '중독된',     'tone' => 'edgy'],
            ['word' => '미친',       'tone' => 'edgy'],
            ['word' => '타락한',     'tone' => 'edgy'],
            ['word' => '반항하는',   'tone' => 'edgy'],
            ['word' => '금지된',     'tone' => 'edgy'],
            ['word' => '위험한',     'tone' => 'edgy'],
            ['word' => '기괴한',     'tone' => 'edgy'],

            // --- Soft / Emotional / Delicate (부드럽고 감성적인 느낌) ---
            ['word' => '수줍은',     'tone' => 'soft'],
            ['word' => '나른한',     'tone' => 'soft'],
            ['word' => '그리운',     'tone' => 'soft'],
            ['word' => '애틋한',     'tone' => 'soft'],
            ['word' => '설레는',     'tone' => 'soft'],
            ['word' => '순수한',     'tone' => 'soft'],
            ['word' => '투명한',     'tone' => 'soft'],
            ['word' => '맑은',       'tone' => 'soft'],
            ['word' => '물든',       'tone' => 'soft'],
            ['word' => '번진',       'tone' => 'soft'],
            ['word' => '안온한',     'tone' => 'soft'],
            ['word' => '평온한',     'tone' => 'soft'],
            ['word' => '다정한',     'tone' => 'soft'],
            ['word' => '달콤한',     'tone' => 'soft'],
            ['word' => '우아한',     'tone' => 'soft'],

            // --- Mystic / Dreamy (신비롭고 몽환적인 느낌) ---
            ['word' => '오묘한',     'tone' => 'mystic'],
            ['word' => '홀린',       'tone' => 'mystic'],
            ['word' => '취한',       'tone' => 'mystic'],
            ['word' => '감긴',       'tone' => 'mystic'],
            ['word' => '보이지않는', 'tone' => 'mystic'],
            ['word' => '사라진',     'tone' => 'mystic'],
            ['word' => '끝없는',     'tone' => 'mystic'],
            ['word' => '무형의',     'tone' => 'mystic'],
            ['word' => '초월한',     'tone' => 'mystic'],
            ['word' => '심오한',     'tone' => 'mystic'],
            ['word' => '공허한',     'tone' => 'mystic'],
            ['word' => '신성한',     'tone' => 'mystic'],

            // --- Fire / Passion (열정적이고 뜨거운 느낌) ---
            ['word' => '폭발하는',   'tone' => 'fire'],
            ['word' => '삼키는',     'tone' => 'fire'],
            ['word' => '끓어오르는', 'tone' => 'fire'],
            ['word' => '성난',       'tone' => 'fire'],
            ['word' => '붉은',       'tone' => 'fire'],
            ['word' => '열광하는',   'tone' => 'fire'],

            // --- Extra / Melancholy / Unique (쓸쓸하거나 독특한) ---
            ['word' => '버려진',     'tone' => 'extra'],
            ['word' => '잊혀진',     'tone' => 'extra'],
            ['word' => '잃어버린',   'tone' => 'extra'],
            ['word' => '떨어지는',   'tone' => 'extra'],
            ['word' => '부유하는',   'tone' => 'extra'],
            ['word' => '마지막',     'tone' => 'extra'],
            ['word' => '처음',       'tone' => 'extra'],
            ['word' => '유일한',     'tone' => 'extra'],
            ['word' => '미완성',     'tone' => 'extra'],
            ['word' => '완벽한',     'tone' => 'extra'],
        ];

        foreach ($adjectives as $adjective) {
            UsernameAdjective::firstOrCreate(
                ['word' => $adjective['word']],
                ['tone' => $adjective['tone']]
            );
        }

        $this->command->info(count($adjectives) . '개의 형용사가 시드되었습니다.');
    }
}

