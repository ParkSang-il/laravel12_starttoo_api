<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Portfolio extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'portfolios';

    /**
     * 대량 할당 가능한 속성들
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'title',
        'description',
        'work_date',
        'price',
        'is_public',
        'is_sensitive',
        'views',
        'likes_count',
        'comments_count',
    ];

    /**
     * 타입 캐스팅이 필요한 속성들
     *
     * @var array<string, string>
     */
    protected $casts = [
        'work_date' => 'datetime:Y-m-d',
        'price' => 'decimal:2',
        'is_public' => 'boolean',
        'is_sensitive' => 'boolean',
        'views' => 'integer',
        'likes_count' => 'integer',
        'comments_count' => 'integer',
        'deleted_at' => 'datetime:Y-m-d H:i:s',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
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

    /**
     * 사업자 정보 관계
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function businessVerification()
    {
        return $this->hasOne(BusinessVerification::class, 'user_id', 'user_id');
    }

    /**
     * 포트폴리오 이미지 관계
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function images()
    {
        return $this->hasMany(PortfolioImage::class)->orderBy('image_order');
    }

    /**
     * 포트폴리오 태그 관계 (다대다)
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'portfolio_tags')
            ->withTimestamps();
    }

    /**
     * 포트폴리오 좋아요 관계
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function likes()
    {
        return $this->hasMany(PortfolioLike::class);
    }

    /**
     * 포트폴리오 좋아요 로그 관계
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function likeLogs()
    {
        return $this->hasMany(PortfolioLikeLog::class);
    }

    /**
     * 포트폴리오 통계 관계
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function stats()
    {
        return $this->hasOne(PortfolioStat::class);
    }

    /**
     * 포트폴리오 댓글 관계
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function comments()
    {
        return $this->hasMany(PortfolioComment::class);
    }

    /**
     * 포트폴리오 상위 댓글 관계 (대댓글 제외)
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function topLevelComments()
    {
        return $this->hasMany(PortfolioComment::class)->whereNull('parent_id');
    }

    /**
     * 포트폴리오 신고 관계
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function reports()
    {
        return $this->hasMany(PortfolioReport::class);
    }

    /**
     * 통계 가져오기 또는 생성
     *
     * @return PortfolioStat
     */
    public function getOrCreateStats(): PortfolioStat
    {
        if (!$this->stats) {
            $stat = PortfolioStat::initialize(
                $this->id,
                $this->created_at ?? now()
            );
            // 관계 새로고침
            $this->load('stats');
            // 통계 객체 새로고침하여 exists 플래그 설정
            $stat->refresh();
            return $stat;
        }
        return $this->stats;
    }
}

