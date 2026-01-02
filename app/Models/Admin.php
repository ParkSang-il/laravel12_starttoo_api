<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;

class Admin extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    protected $table = 'admins';

    /**
     * 대량 할당 가능한 속성들입니다.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'username',
        'password',
        'role',
        'name',
        'last_login_at',
        'last_login_ip',
    ];

    /**
     * 직렬화 시 숨겨야 할 속성들입니다.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * 타입 캐스팅이 필요한 속성들입니다.
     *
     * @var array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_login_at' => 'datetime:Y-m-d H:i:s',
            'created_at' => 'datetime:Y-m-d H:i:s',
            'updated_at' => 'datetime:Y-m-d H:i:s',
            'deleted_at' => 'datetime:Y-m-d H:i:s',
        ];
    }

    /**
     * 비밀번호를 암호화하여 설정합니다.
     * (이미 암호화된 경우는 그대로 사용)
     *
     * @param string $value
     * @return void
     */
    public function setPasswordAttribute($value)
    {
        // 이미 암호화된 해시인지 확인 (bcrypt 해시는 항상 $2y$로 시작)
        if (!empty($value) && !str_starts_with($value, '$2y$')) {
            $this->attributes['password'] = Hash::make($value);
        } else {
            $this->attributes['password'] = $value;
        }
    }

    /**
     * 최고 관리자 여부 확인
     *
     * @return bool
     */
    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    /**
     * 일반 관리자 여부 확인
     *
     * @return bool
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }
}

