<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable, SoftDeletes;

    /**
     * 대량 할당 가능한 속성들입니다.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_type',
        'phone',
        'phone_verified_at',
        'username',
        'profile_image',
    ];

    /**
     * 직렬화 시 숨겨야 할 속성들입니다.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        // password 필드는 마이그레이션에 없음
    ];

    /**
     * 타입 캐스팅이 필요한 속성들입니다.
     *
     * @var array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_type' => 'integer',
            'phone_verified_at' => 'datetime:Y-m-d H:i:s',
            'deleted_at' => 'datetime:Y-m-d H:i:s',
            'created_at' => 'datetime:Y-m-d H:i:s',
            'updated_at' => 'datetime:Y-m-d H:i:s',
        ];
    }

    /**
     * JWT 식별자를 반환합니다.
     *
     * @return mixed
     */
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    /**
     * JWT 커스텀 클레임을 반환합니다.
     *
     * @return array
     */
    public function getJWTCustomClaims(): array
    {
        return [];
    }

    /**
     * 아티스트 프로필 관계
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function artistProfile()
    {
        return $this->hasOne(ArtistProfile::class);
    }

    /**
     * 포트폴리오 관계
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function portfolios()
    {
        return $this->hasMany(Portfolio::class);
    }
}
