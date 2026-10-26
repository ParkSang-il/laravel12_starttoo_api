<?php

namespace App\Models;

use App\Models\Concerns\UsesProjectDatabase;
use App\Services\ProjectDatabase;
use Illuminate\Foundation\Auth\User as Authenticatable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

class ProjectAccount extends Authenticatable implements JWTSubject
{
    use UsesProjectDatabase;

    protected $appends = ['project_code'];

    public function getProjectCodeAttribute(): string
    {
        return app(ProjectDatabase::class)->code();
    }

    // Project and privilege fields must only be assigned by trusted server code.
    protected $fillable = ['display_name'];

    protected $hidden = ['phone'];

    protected function casts(): array
    {
        return ['phone_verified_at' => 'datetime'];
    }

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return ['project_id' => $this->project_id, 'project_code' => $this->project_code];
    }
}
