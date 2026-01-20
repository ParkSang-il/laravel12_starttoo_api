<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PortfolioComment extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'portfolio_comments';

    /**
     * 대량 할당 가능한 속성들
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'portfolio_id',
        'user_id',
        'parent_id',
        'content',
        'gif_image_url',
        'replies_count',
        'is_deleted',
        'is_pinned',
    ];

    /**
     * 타입 캐스팅이 필요한 속성들
     *
     * @var array<string, string>
     */
    protected $casts = [
        'replies_count' => 'integer',
        'is_deleted' => 'boolean',
        'is_pinned' => 'boolean',
        'deleted_at' => 'datetime:Y-m-d H:i:s',
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

    /**
     * 작성자 관계
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 부모 댓글 관계
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function parent()
    {
        return $this->belongsTo(PortfolioComment::class, 'parent_id');
    }

    /**
     * 대댓글 관계 (삭제된 것 포함)
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function replies()
    {
        return $this->hasMany(PortfolioComment::class, 'parent_id')
            ->withTrashed()
            ->orderBy('created_at', 'asc');
    }

    /**
     * 상위 댓글인지 확인
     *
     * @return bool
     */
    public function isTopLevel(): bool
    {
        return $this->parent_id === null;
    }

    /**
     * 댓글 신고 관계
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function reports()
    {
        return $this->hasMany(PortfolioCommentReport::class, 'comment_id');
    }
}

