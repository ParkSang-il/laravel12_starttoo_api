<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tag extends Model
{
    use HasFactory;

    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'tags';

    /**
     * 대량 할당 가능한 속성들
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'usage_count',
    ];

    /**
     * 타입 캐스팅이 필요한 속성들
     *
     * @var array<string, string>
     */
    protected $casts = [
        'usage_count' => 'integer',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

    /**
     * 포트폴리오 태그 관계 (다대다)
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function portfolios()
    {
        return $this->belongsToMany(Portfolio::class, 'portfolio_tags')
            ->withTimestamps();
    }
}

