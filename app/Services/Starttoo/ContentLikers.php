<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Validator;

/**
 * 작품·일반 게시물의 좋아요한 회원.
 * 피드의 「OO님 외 여러 명이 좋아합니다」 줄과 좋아요 목록 화면에 사용한다.
 * 댓글과 같은 기준으로 비활성·운영자 숨김·양방향 차단 회원은 제외한다.
 */
class ContentLikers
{
    private const SOURCES = [
        'artwork' => ['table' => 'starttoo_artwork_interactions', 'column' => 'artwork_id', 'like_only' => true],
        'post' => ['table' => 'starttoo_post_likes', 'column' => 'post_id', 'like_only' => false],
    ];

    private function visible(string $type, ?int $viewer): Builder
    {
        $source = self::SOURCES[$type];
        $q = app(ProjectDatabase::class)->database()->table($source['table'].' as l')
            ->join('starttoo_users as lu', 'lu.id', '=', 'l.user_id')
            ->where('lu.status', 'active')->where('lu.moderation_hidden', false);
        if ($source['like_only']) $q->where('l.kind', 'like');
        return app(Blocks::class)->filter($q, 'l.user_id', $viewer);
    }

    private function format(object $row): array
    {
        return ['id' => (int) $row->user_id, 'nickname' => $row->nickname ?? '',
            'avatar_url' => app(MediaUrls::class)->url($row->avatar_path, 300),
            // 승인된 작가면 앱이 프로필을 거치지 않고 바로 상점을 연다
            'is_artist' => ($row->artist_status ?? null) === 'approved'];
    }

    /** 콘텐츠별 최근 좋아요 회원 최대 3명. 피드 한 페이지를 한 번의 조회로 처리한다. */
    public function recent(string $type, array $ids, ?int $viewer, int $limit = 3): array
    {
        if (!$ids) return [];
        $column = self::SOURCES[$type]['column'];
        $ranked = $this->visible($type, $viewer)->whereIn("l.$column", $ids)
            ->selectRaw("l.$column as content_id, l.user_id, lu.nickname, lu.avatar_path, lu.artist_status, ROW_NUMBER() OVER (PARTITION BY l.$column ORDER BY l.id DESC) as rn");
        $rows = app(ProjectDatabase::class)->database()->query()->fromSub($ranked, 'r')
            ->where('r.rn', '<=', $limit)->orderBy('r.content_id')->orderBy('r.rn')->get();
        $result = array_fill_keys($ids, []);
        foreach ($rows as $row) $result[$row->content_id][] = $this->format($row);
        return $result;
    }

    /** 좋아요 목록. 콘텐츠 열람 권한은 상세 조회와 동일하게 확인한다. */
    public function listing(string $type, string $id, array $input, ?int $viewer): array
    {
        if (Validator::make($input, ['cursor' => ['nullable', 'integer', 'min:1']])->fails()) {
            $this->fail(422, 'INVALID_LIKERS_CURSOR', '좋아요 목록을 다시 열어주세요.');
        }
        // 비공개·숨김·차단 콘텐츠는 상세와 같은 404로 응답한다.
        $type === 'artwork' ? app(Artworks::class)->detail($id, $viewer) : app(Posts::class)->detail($id, $viewer);
        $column = self::SOURCES[$type]['column'];
        $q = $this->visible($type, $viewer)->where("l.$column", $id);
        if (isset($input['cursor'])) $q->where('l.id', '<', (int) $input['cursor']);
        $rows = $q->orderByDesc('l.id')->limit(21)->get(['l.id', 'l.user_id', 'lu.nickname', 'lu.avatar_path', 'lu.artist_status']);
        $page = $rows->take(20);
        $following = $viewer === null || $page->isEmpty() ? [] : app(ProjectDatabase::class)->database()
            ->table('starttoo_follows')->where('user_id', $viewer)->whereIn('target_user_id', $page->pluck('user_id'))
            ->pluck('target_user_id')->map(fn ($v) => (int) $v)->all();
        return [
            'items' => $page->map(fn ($row) => $this->format($row) + ['following' => in_array((int) $row->user_id, $following, true)])->values()->all(),
            'next_cursor' => $rows->count() > 20 ? (string) $page->last()->id : null,
            'viewer_id' => $viewer,
        ];
    }

    private function fail(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(compact('code', 'message'), $status));
    }
}
