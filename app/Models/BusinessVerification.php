<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BusinessVerification extends Model
{
    use HasFactory;

    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'business_verifications';

    /**
     * 대량 할당 가능한 속성들
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'business_name',
        'business_number',
        'business_certificate',
        'license_certificate',
        'address',
        'address_detail',
        'contact_phone_public',
        'available_regions',
        'main_styles',
        'status',
        'rejected_reason',
        'approved_at',
        'safety_education_certificate',
    ];

    /**
     * 타입 캐스팅이 필요한 속성들
     *
     * @var array<string, string>
     */
    protected $casts = [
        'contact_phone_public' => 'boolean',
        'available_regions' => 'array',
        'main_styles' => 'array',
        'approved_at' => 'datetime',
    ];

    /**
     * 사용자 관계
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

