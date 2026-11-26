<?php

namespace App\Http\Controllers;

use App\Services\ProjectDatabase;
use App\Services\Starttoo\MediaUrls;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * 공유 링크(www.starttoo.com/artwork?id=… 등).
 * - 앱이 깔린 폰: iOS Universal Links · Android App Links로 앱이 바로 열린다(아래 .well-known 파일).
 * - 앱이 없거나 PC: 이 미리보기 페이지(제목 · 대표 사진 · 앱 받기)를 보여 준다. 메신저 미리보기(og 태그)도 여기서.
 * 공개 · 활성 콘텐츠만 보여 주고, 숨김 · 탈퇴 · 비공개는 「볼 수 없는 콘텐츠」로 안내한다.
 */
class StarttooShareController extends Controller
{
    private function db() { return app(ProjectDatabase::class)->database(); }

    /** iOS Universal Links: 앱이 열 경로만(API · 기타 경로는 브라우저) */
    public function appleAssociation()
    {
        $team = (string) config('starttoo.apple_team_id');
        $bundle = (string) config('starttoo.apple_client_id');
        return response()->json(['applinks' => ['apps' => [], 'details' => [[
            'appIDs' => ["{$team}.{$bundle}"],
            'components' => array_map(fn ($p) => ['/' => $p, 'comment' => 'STARTTOO 공유 링크'], self::PATHS),
        ]]]])->header('Content-Type', 'application/json');
    }

    /** Android App Links: 앱 서명 인증서 지문(STARTTOO_ANDROID_CERT_SHA256, 쉼표로 여러 개) */
    public function assetLinks()
    {
        $prints = array_values(array_filter(array_map('trim', explode(',', (string) config('starttoo.android_cert_sha256')))));
        return response()->json([[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => ['namespace' => 'android_app', 'package_name' => (string) config('starttoo.android_package'), 'sha256_cert_fingerprints' => $prints],
        ]]);
    }

    /** 앱이 여는 공유 경로(앱 라우터 경로와 같다) */
    public const PATHS = ['/artwork', '/post', '/shop', '/user', '/community/post'];

    public function show(Request $request)
    {
        $path = '/'.trim($request->path(), '/');
        $id = (string) $request->query('id', '');
        $card = app(ProjectDatabase::class)->run('starttoo', fn () => match ($path) {
            '/artwork' => $this->artwork($id),
            '/post' => $this->post($id),
            '/shop', '/user' => $this->member((int) $id, $path === '/shop'),
            '/community/post' => $this->community((int) $id),
            default => null,
        });
        return response()->view('starttoo.share', [
            'card' => $card,
            'url' => $request->fullUrl(),
            'ios' => (string) config('starttoo.app_store_url'),
            'android' => (string) config('starttoo.play_store_url'),
        ])->header('Cache-Control', 'public, max-age=300');
    }

    private function artwork(string $id): ?array
    {
        if (!Str::isUuid($id)) return null;
        $a = $this->db()->table('starttoo_artworks as a')->join('starttoo_users as u', 'u.id', '=', 'a.user_id')
            ->where('a.id', $id)->where('a.visibility', 'public')->where('a.moderation_hidden', false)
            ->where('u.status', 'active')->where('u.moderation_hidden', false)
            ->first(['a.id', 'a.kind', 'a.title', 'a.description', 'u.nickname']);
        if (!$a) return null;
        $path = $this->db()->table('starttoo_artwork_images')->where('artwork_id', $a->id)->orderBy('position')->value('path');
        return ['kind' => $a->kind === 'design' ? '도안' : '작품', 'title' => $a->title ?: ($a->kind === 'design' ? '도안' : '작품'),
            'text' => (string) $a->description, 'author' => $a->nickname, 'image' => app(MediaUrls::class)->url($path, 1200)];
    }

    private function post(string $id): ?array
    {
        if (!Str::isUuid($id)) return null;
        $p = $this->db()->table('starttoo_posts as p')->join('starttoo_users as u', 'u.id', '=', 'p.user_id')
            ->where('p.id', $id)->where('p.moderation_hidden', false)->where('u.status', 'active')->where('u.moderation_hidden', false)
            ->first(['p.id', 'p.content', 'u.nickname']);
        if (!$p) return null;
        $path = $this->db()->table('starttoo_post_images')->where('post_id', $p->id)->orderBy('position')->value('path');
        return ['kind' => '게시물', 'title' => $p->nickname.'님의 게시물', 'text' => (string) $p->content, 'author' => $p->nickname,
            'image' => app(MediaUrls::class)->url($path, 1200)];
    }

    private function member(int $id, bool $shop): ?array
    {
        $u = $this->db()->table('starttoo_users')->where('id', $id)->where('status', 'active')->where('moderation_hidden', false)
            ->first(['id', 'nickname', 'bio', 'avatar_path', 'artist_status']);
        if (!$u) return null;
        $studio = $this->db()->table('starttoo_shops')->where('user_id', $id)->value('studio_name');
        $artist = $u->artist_status === 'approved';
        return ['kind' => $artist ? '타투 작가' : '회원', 'title' => trim($u->nickname.($studio ? ' · '.$studio : '')),
            'text' => (string) $u->bio, 'author' => null, 'image' => app(MediaUrls::class)->url($u->avatar_path, 600)];
    }

    private function community(int $id): ?array
    {
        $p = $this->db()->table('starttoo_community_posts as p')->join('starttoo_users as u', 'u.id', '=', 'p.user_id')
            ->where('p.id', $id)->where('p.moderation_hidden', false)->where('u.status', 'active')->where('u.moderation_hidden', false)
            ->first(['p.title', 'p.content', 'p.images', 'u.nickname']);
        if (!$p) return null;
        $images = json_decode((string) $p->images, true) ?: [];
        return ['kind' => '커뮤니티', 'title' => $p->title, 'text' => (string) $p->content, 'author' => $p->nickname,
            'image' => app(MediaUrls::class)->url($images[0] ?? null, 1200)];
    }
}
