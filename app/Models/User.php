<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use PHPOpenSourceSaver\JWT\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * 대량 할당 가능한 속성들입니다.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'username',
        'profile_image',
        'bio',
        'is_verified',
        'provider',
        'provider_id',
        'avatar',
    ];

    /**
     * 직렬화 시 숨겨야 할 속성들입니다.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
    ];

    /**
     * 타입 캐스팅이 필요한 속성들입니다.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_verified' => 'boolean',
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
    public function getJWTCustomClaims()
    {
        return [];
    }
}
