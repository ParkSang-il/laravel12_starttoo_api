<?php

namespace App\Models;

use App\Models\Concerns\UsesProjectDatabase;
use App\Services\ProjectDatabase;
use Illuminate\Foundation\Auth\User as Authenticatable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

class ProjectAccount extends Authenticatable implements JWTSubject
{
    use UsesProjectDatabase;

    protected $appends = ['project_code', 'nickname'];

    public function getProjectCodeAttribute(): string
    {
        return app(ProjectDatabase::class)->code();
    }

    // Project and privilege fields must only be assigned by trusted server code.
    protected $fillable = ['display_name'];

    protected $hidden = ['phone', 'avatar_disk', 'avatar_path', 'avatar_thumbnail_path'];

    public function getNicknameAttribute(): ?string
    {
        $name = $this->display_name;
        return $name === null ? null : $name.($this->nickname_tag === null ? '' : '#'.$this->nickname_tag);
    }

    public function getAvatarUrlAttribute($value): ?string
    {
        return app(\App\Services\GroupProfile::class)->imageUrl(
            $this->attributes['avatar_disk'] ?? null, $this->attributes['avatar_path'] ?? null, $value);
    }

    public function getAvatarThumbnailUrlAttribute($value): ?string
    {
        return app(\App\Services\GroupProfile::class)->imageUrl(
            $this->attributes['avatar_disk'] ?? null, $this->attributes['avatar_thumbnail_path'] ?? null, $value);
    }

    protected function casts(): array
    {
        return ['phone_verified_at' => 'datetime', 'nickname_tag' => 'string'];
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
