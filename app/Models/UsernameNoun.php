<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UsernameNoun extends Model
{
    use HasFactory;

    /**
     * 대량 할당 가능한 속성들입니다.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'word',
        'category',
    ];

    /**
     * 타입 캐스팅이 필요한 속성들입니다.
     *
     * @var array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}

