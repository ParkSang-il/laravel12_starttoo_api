<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PortfolioVideo extends Model
{
    use HasFactory;

    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'portfolio_videos';

    /**
     * 대량 할당 가능한 속성들
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'portfolio_id',
        'video_file_path',
        'video_url',
        'video_thumbnail_url',
        'video_job_id',
        'video_status',
        'video_order',
    ];

    /**
     * 타입 캐스팅이 필요한 속성들
     *
     * @var array<string, string>
     */
    protected $casts = [
        'video_job_id' => 'integer',
        'video_order' => 'integer',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

    /**
     * 포트폴리오 관계
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function portfolio()
    {
        return $this->belongsTo(Portfolio::class);
    }
}

