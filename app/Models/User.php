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
        'suspended_until',
        'suspension_type',
        'suspension_reason',
        'suspended_by',
        'suspended_at',
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
            'suspended_until' => 'datetime:Y-m-d H:i:s',
            'suspended_at' => 'datetime:Y-m-d H:i:s',
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

    /**
     * 로그인 기록 관계
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function loginLogs()
    {
        return $this->hasMany(UserLoginLog::class);
    }

    /**
     * 정지 처리한 관리자 관계
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function suspendedByUser()
    {
        return $this->belongsTo(User::class, 'suspended_by');
    }

    /**
     * 정지 여부 확인
     *
     * @return bool
     */
    public function isSuspended(): bool
    {
        if (!$this->suspended_until) {
            return false;
        }

        // 영구정지 (9999-12-31)
        if ($this->suspended_until->year === 9999) {
            return true;
        }

        // 기간 정지 (현재 시간이 정지 해제 일시보다 이전이면 정지 중)
        return now()->lt($this->suspended_until);
    }

    /**
     * 정지 상태 텍스트 반환
     *
     * @return string|null
     */
    public function getSuspensionStatusText(): ?string
    {
        if (!$this->isSuspended()) {
            return null;
        }

        if ($this->suspended_until->year === 9999) {
            return '영구정지';
        }

        $days = now()->diffInDays($this->suspended_until, false);
        if ($days > 0) {
            return "정지 중 (남은 기간: {$days}일)";
        }

        return '정지 만료';
    }

    public function followings()
    {
        return $this->belongsToMany(User::class, 'user_follows', 'follower_id', 'followee_id');
    }

    public function followers()
    {
        return $this->belongsToMany(User::class, 'user_follows', 'followee_id', 'follower_id');
    }
}
