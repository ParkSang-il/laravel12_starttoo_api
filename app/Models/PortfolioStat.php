<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PortfolioStat extends Model
{
    use HasFactory;

    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'portfolio_stats';

    /**
     * 기본 키 이름
     *
     * @var string
     */
    protected $primaryKey = 'portfolio_id';

    /**
     * 기본 키 타입
     *
     * @var string
     */
    protected $keyType = 'int';

    /**
     * 자동 증가 사용 안 함
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * 대량 할당 가능한 속성들
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'portfolio_id',
        'total_views',
        'total_likes',
        'total_shares',
        'total_comments',
        'recent_views',
        'recent_likes',
        'recent_shares',
        'recent_comments',
        'first_published_at',
        'last_activity_at',
    ];

    /**
     * 타입 캐스팅이 필요한 속성들
     *
     * @var array<string, string>
     */
    protected $casts = [
        'total_views' => 'integer',
        'total_likes' => 'integer',
        'total_shares' => 'integer',
        'total_comments' => 'integer',
        'recent_views' => 'integer',
        'recent_likes' => 'integer',
        'recent_shares' => 'integer',
        'recent_comments' => 'integer',
        'first_published_at' => 'datetime:Y-m-d H:i:s',
        'last_activity_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

    /**
     * updated_at만 사용
     *
     * @var bool
     */
    public $timestamps = true;

    /**
     * created_at 사용 안 함
     *
     * @var string|null
     */
    const CREATED_AT = null;

    /**
     * 포트폴리오 관계
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function portfolio()
    {
        return $this->belongsTo(Portfolio::class);
    }

    /**
     * 통계 초기화 (포트폴리오 생성 시)
     *
     * @param int $portfolioId
     * @param \DateTime $publishedAt
     * @return self
     */
    public static function initialize(int $portfolioId, \DateTime $publishedAt): self
    {
        return self::create([
            'portfolio_id' => $portfolioId,
            'total_views' => 0,
            'total_likes' => 0,
            'total_shares' => 0,
            'total_comments' => 0,
            'recent_views' => 0,
            'recent_likes' => 0,
            'recent_shares' => 0,
            'recent_comments' => 0,
            'first_published_at' => $publishedAt,
            'last_activity_at' => null,
        ]);
    }

    /**
     * 조회수 증가
     *
     * @return void
     */
    public function incrementViews(): void
    {
        if (!$this->exists) {
            return;
        }
        
        $this->increment('total_views');
        $this->increment('recent_views');
        $this->update(['last_activity_at' => now()]);
    }

    /**
     * 좋아요 수 증가
     *
     * @return void
     */
    public function incrementLikes(): void
    {
        if (!$this->exists) {
            return;
        }
        
        $this->increment('total_likes');
        $this->increment('recent_likes');
        $this->update(['last_activity_at' => now()]);
    }

    /**
     * 좋아요 수 감소
     *
     * @return void
     */
    public function decrementLikes(): void
    {
        if (!$this->exists) {
            return;
        }
        
        $this->decrement('total_likes');
        $this->decrement('recent_likes');
        $this->update(['last_activity_at' => now()]);
    }

    /**
     * 공유 수 증가
     *
     * @return void
     */
    public function incrementShares(): void
    {
        $this->increment('total_shares');
        $this->increment('recent_shares');
        $this->update(['last_activity_at' => now()]);
    }

    /**
     * 댓글 수 증가
     *
     * @return void
     */
    public function incrementComments(): void
    {
        if (!$this->exists) {
            return;
        }
        
        $this->increment('total_comments');
        $this->increment('recent_comments');
        $this->update(['last_activity_at' => now()]);
    }

    /**
     * 댓글 수 감소
     *
     * @return void
     */
    public function decrementComments(): void
    {
        if (!$this->exists) {
            return;
        }
        
        $this->decrement('total_comments');
        $this->decrement('recent_comments');
    }
}

