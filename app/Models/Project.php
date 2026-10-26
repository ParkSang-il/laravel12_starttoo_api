<?php

namespace App\Models;

use App\Models\Concerns\UsesProjectDatabase;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    use UsesProjectDatabase;

    protected $fillable = ['code', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
