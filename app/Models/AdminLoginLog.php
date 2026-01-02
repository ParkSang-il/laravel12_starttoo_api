<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdminLoginLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'admin_id',
        'username',
        'ip_address',
        'action',
        'is_success',
        'failure_reason',
    ];

    protected $casts = [
        'is_success' => 'boolean',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

    public function admin()
    {
        return $this->belongsTo(Admin::class);
    }
}

