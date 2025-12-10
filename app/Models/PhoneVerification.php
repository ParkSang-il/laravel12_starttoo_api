<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PhoneVerification extends Model
{
    use HasFactory;

    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'phone_verifications';

    /**
     * 대량 할당 가능한 속성들
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'phone',
        'verification_code',
        'verified_at',
        'expires_at',
    ];

    /**
     * 타입 캐스팅이 필요한 속성들
     *
     * @var array<string, string>
     */
    protected $casts = [
        'verified_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}

