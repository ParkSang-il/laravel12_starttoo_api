<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserLoginLog extends Model
{
    use HasFactory;

    protected $table = 'user_login_logs';

    protected $fillable = [
        'user_id',
        'ip_address',
        'user_agent',
        'device_type',
        'device_model',
        'os',
        'browser',
        'login_type',
        'is_success',
        'failure_reason',
    ];

    protected $casts = [
        'is_success' => 'boolean',
        'created_at' => 'datetime:Y-m-d H:i:s',
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

