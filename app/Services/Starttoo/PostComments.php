<?php

namespace App\Services\Starttoo;

/** 작품 댓글의 검증·잠금·답글·멘션 규칙을 일반 게시물 저장소에서 재사용한다. */
class PostComments extends ArtworkComments
{
    protected const CONTENT_TABLE = 'starttoo_posts';
    protected const CONTENT_ID = 'post_id';
    protected const COMMENTS = 'starttoo_post_comments';
    protected const LIKES = 'starttoo_post_comment_likes';
    protected const REQUESTS = 'starttoo_post_comment_requests';
    protected const REPORT_TYPE = 'post_comment';
    protected const DOMAIN = 'post';

    protected function contentService(): Artworks|Posts { return app(Posts::class); }
}
