<?php

namespace App\Http\Controllers\Admin;

use App\Services\Starttoo\ArtistApplications;
use App\Services\Starttoo\IdentityVerifications;
use App\Services\Starttoo\MediaUrls;
use App\Services\Starttoo\Moderation;
use App\Services\Starttoo\Profiles;
use App\Services\Starttoo\Reports;
use App\Services\Starttoo\Sanctions;
use App\Support\AdminUi as Ui;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * 운영 콘솔 처리 화면: M-04 아티스트 심사 · M-05 게시물 · 작품 · M-06 신고 · 차단.
 * 처리는 서버 명령과 같은 서비스(ArtistApplications::review · Reports::moderate · Moderation)를 부른다.
 */
class AdminModerationController extends AdminBaseController
{
    /** 서비스의 작업자 식별자 규칙(문자 · 숫자 · _.@-)에 맞춘 관리자 이름 */
    private function operator(Request $request): string
    {
        $a = $this->admin($request);
        return mb_substr(preg_replace('/[^\p{L}\p{N}_.@-]+/u', '_', 'admin' . $a->id . '_' . $a->name), 0, 100);
    }

    // ================================================================ M-04 아티스트 심사

    private const ARTIST_TABS = ['대기' => ['submitted', 'reviewing'], '보완' => ['needs_changes'], '처리됨' => ['approved', 'rejected'], '전체' => null];
    private const ARTIST_LABEL = ['submitted' => '대기', 'reviewing' => '검토 중', 'needs_changes' => '보완 요청', 'approved' => '승인', 'rejected' => '반려'];
    private const ARTIST_CODE = ['submitted' => 'PENDING', 'reviewing' => 'REVIEWING', 'needs_changes' => 'NEEDS_REVISION', 'approved' => 'APPROVED', 'rejected' => 'REJECTED'];
    private const ARTIST_KIND = ['submitted' => 'amber', 'reviewing' => 'blue', 'needs_changes' => 'amber', 'approved' => 'green', 'rejected' => 'red'];

    public function artistReview(Request $request): View
    {
        $tab = array_key_exists((string) $request->query('tab'), self::ARTIST_TABS) ? (string) $request->query('tab') : '대기';
        $regions = array_column(app(Profiles::class)->options()['regions'], 'label', 'id');
        $urls = app(MediaUrls::class);

        $q = $this->db()->table('starttoo_artist_applications as a')->join('starttoo_users as u', 'u.id', '=', 'a.user_id')
            ->select('a.id', 'a.user_id', 'a.status', 'a.region', 'a.submitted_at', 'u.nickname', 'u.avatar_path');
        if (self::ARTIST_TABS[$tab]) $q->whereIn('a.status', self::ARTIST_TABS[$tab]);
        // 대기는 먼저 들어온 순, 나머지는 최근 순
        $tab === '대기' ? $q->orderBy('a.submitted_at') : $q->orderByDesc('a.submitted_at');
        $list = $q->limit(100)->get();

        $selId = (int) $request->query('sel', 0);
        if (!$selId || !$this->db()->table('starttoo_artist_applications')->where('id', $selId)->exists()) $selId = (int) ($list->first()->id ?? 0);

        $apps = $list->map(fn ($a) => [
            'name' => $a->nickname ?? '-',
            'region' => $regions[$a->region] ?? $a->region,
            'date' => Ui::date($a->submitted_at, 'm.d'),
            'avatar' => $urls->url($a->avatar_path, 300),
            'status' => self::ARTIST_LABEL[$a->status] ?? $a->status,
            'pill' => Ui::pill(self::ARTIST_KIND[$a->status] ?? 'gray'),
            'href' => Ui::url(['sel' => $a->id, 'lic' => null]),
            'rowStyle' => 'width: 100%; min-height: 72px; padding: 12px 16px; border: 0; border-bottom: 1px solid #F0EDE7; display: flex; align-items: center; gap: 12px; '
                . ((int) $a->id === $selId ? 'background: #F4F2EE' : 'background: #FFFFFF'),
        ])->all();

        $sel = null;
        if ($selId) {
            $row = $this->db()->table('starttoo_artist_applications')->where('id', $selId)->first();
            $detail = app(ArtistApplications::class)->operatorDetail($selId);
            $user = $this->db()->table('starttoo_users')->where('id', $row->user_id)->first(['nickname', 'avatar_path', 'artist_status']);
            $licensed = $row->license_number_enc !== null || $row->license_path !== null;
            $canLicense = $this->can($request, 'license.view');
            $showLicense = $licensed && $canLicense && $request->boolean('lic');
            if ($showLicense) {
                $this->audit($request, '심사', '자격증 이미지 열람', "{$user->nickname} (U-{$row->user_id})");
            }
            $open = in_array($row->status, ['submitted', 'reviewing'], true);
            $approved = ($user->artist_status ?? null) === 'approved';
            // 기능별 권한: 지금 서버는 작가 승인 하나로 열린다. 화면에는 실제 상태만 보여 준다(바꿀 수 없음).
            $caps = array_map(fn ($c) => $c + ['track' => Ui::switchTrack($c['on'], true, 4, 42)], [
                ['id' => 'portfolio', 'label' => '포트폴리오 · 상점', 'note' => '작가 승인으로 열림', 'on' => $approved],
                ['id' => 'consultation', 'label' => '시술 상담', 'note' => config('starttoo.consultation_enabled') ? '작가 승인 + 상담 기능 켜짐' : '상담 기능 꺼짐', 'on' => $approved && config('starttoo.consultation_enabled')],
                ['id' => 'booking', 'label' => '예약', 'note' => '작가 승인으로 열림', 'on' => $approved],
                ['id' => 'payment', 'label' => '결제', 'note' => '결제 업체 연결 전', 'on' => false],
            ]);
            $sel = [
                'id' => $selId,
                'name' => $user->nickname ?? '-',
                'avatar' => $urls->url($user->avatar_path ?? null, 300),
                'real' => Ui::maskName(app(IdentityVerifications::class)->realName((int) $row->user_id)),
                'region' => $regions[$row->region] ?? $row->region,
                'uid' => 'U-' . $row->user_id,
                'memberHref' => Ui::memberUrl($row->user_id),
                'shopHref' => $approved ? Ui::publicUrl('shop', $row->user_id) : null,
                'photos' => array_map(fn ($p) => $p['url'], $detail['photos'] ?? []),
                'works' => count($detail['photos'] ?? []),
                'link' => $row->portfolio_link ?: '-',
                'bio' => $row->bio,
                'code' => (self::ARTIST_LABEL[$row->status] ?? $row->status) . ' · ' . (self::ARTIST_CODE[$row->status] ?? $row->status),
                'pill' => Ui::pill(self::ARTIST_KIND[$row->status] ?? 'gray'),
                'open' => $open,
                'note' => $row->review_note,
                'licensed' => $licensed,
                'licNumber' => $row->license_number_enc ? Ui::maskCode(Crypt::decryptString($row->license_number_enc)) : '-',
                'licUrl' => $showLicense && $row->license_path ? Storage::disk('starttoo')->temporaryUrl($row->license_path, now()->addMinutes(5)) : null,
                'licHref' => $licensed && $canLicense ? Ui::url(['lic' => 1]) : null,
                'licCode' => $licensed ? '제출됨 · 검토 필요' : (config('starttoo.artist_license_required') ? '미제출' : 'NOT_AVAILABLE'),
                'licPill' => Ui::pill($licensed ? 'amber' : 'gray'),
                'caps' => $caps,
                'decideAction' => route('admin.artist-review.decide', $selId),
            ];
        }

        return view('admin.artist-review', [
            'tabs' => Ui::tabs(array_keys(self::ARTIST_TABS), $tab, 'tab', ['sel', 'lic']),
            'apps' => $apps,
            'sel' => $sel,
            'canDecide' => $this->can($request, 'artist.review'),
        ]);
    }

    public function decideArtist(Request $request, int $id): RedirectResponse
    {
        $this->need($request, 'artist.review');
        $data = $request->validate([
            'decision' => ['required', 'in:APPROVED,REJECTED,NEEDS_REVISION'],
            'reason' => ['required', 'string', 'max:1000'],
        ], ['reason.required' => '결정 사유를 입력해주세요.']);
        $status = ['APPROVED' => 'approved', 'REJECTED' => 'rejected', 'NEEDS_REVISION' => 'needs_changes'][$data['decision']];
        $row = $this->db()->table('starttoo_artist_applications as a')->join('starttoo_users as u', 'u.id', '=', 'a.user_id')
            ->where('a.id', $id)->first(['a.user_id', 'a.status', 'u.nickname']);
        abort_unless($row, 404);
        try {
            app(ArtistApplications::class)->review($id, $status, $this->operator($request), trim($data['reason']));
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }
        Ui::forgetBadges();
        Cache::forget('starttoo:admin:dashboard');
        $what = ['approved' => '아티스트 승인', 'rejected' => '아티스트 반려', 'needs_changes' => '보완 요청'][$status];
        $this->audit($request, '심사', $what, "{$row->nickname} (U-{$row->user_id})", ['status' => [$row->status, $status], 'reason' => trim($data['reason'])]);

        return back()->with('status', "{$row->nickname}님 · {$what} 처리했어요");
    }

    // ================================================================ M-05 게시물 · 작품

    // 무빙: 영상이 붙은 작품 · 도안 · 게시물(docs/video.md)
    private const CONTENT_TABS = ['전체', '작품', '도안', '피팅 도안', '무빙', '게시물', '리뷰', '커뮤니티'];
    private const CONTENT_STATES = ['전체', '노출', '숨김', '삭제됨'];
    private const CONTENT_SORTS = ['최신 등록순', '신고 많은순'];
    private const CONTENT_PAGE = 24;
    private const KIND_LABEL = ['artwork' => '작품', 'design' => '도안', 'post' => '게시물', 'review' => '리뷰', 'community' => '커뮤니티'];

    /** 콘텐츠 종류별 조회를 같은 열로 맞춘다(전체 탭은 UNION) */
    private function contentQueries(string $tab, string $term, ?int $author = null)
    {
        $db = $this->db();
        $reports = fn (string $type, string $col) => "(select count(*) from starttoo_reports r where r.target_type = '{$type}' and r.target_id = {$col})";
        $pending = fn (string $type, string $col) => "(select count(*) from starttoo_reports r where r.target_type = '{$type}' and r.target_id = {$col} and r.status = 'pending')";
        $parts = [];
        // 무빙 탭: 게시된 영상이 있는 작품 · 게시물만
        $video = fn (string $type, string $col) => fn ($w) => $w->selectRaw('1')->from('starttoo_videos as vd')
            ->where('vd.published_type', $type)->whereColumn('vd.published_id', $col)->where('vd.status', 'published');
        if (in_array($tab, ['전체', '작품', '도안', '피팅 도안', '무빙'], true)) {
            $q = $db->table('starttoo_artworks as a')->join('starttoo_users as u', 'u.id', '=', 'a.user_id')
                ->selectRaw("'artwork' as ctype, CAST(a.id AS CHAR(36)) as cid, a.title as title, a.user_id as author_id, u.nickname as author, a.created_at as created_at,"
                    . " a.moderation_hidden as hidden, a.moderation_removed_at as removed_at, a.kind as kind, a.fit_blocked as fit_blocked, a.recommend_excluded as recommend_excluded,"
                    . " (select count(*) from starttoo_artwork_interactions x where x.artwork_id = a.id and x.kind = 'like') as likes,"
                    . " (select count(*) from starttoo_fit_results f where f.artwork_id = a.id) as fits, NULL as images,"
                    . " {$reports('artwork', 'a.id')} as reports, {$pending('artwork', 'a.id')} as pending");
            if ($tab === '작품') $q->where('a.kind', 'work');
            if (in_array($tab, ['도안', '피팅 도안'], true)) $q->where('a.kind', 'design');
            if ($tab === '무빙') $q->whereExists($video('artwork', 'a.id'));
            $parts[] = [$q, 'a.title', 'a.id', true, 'a.user_id'];
        }
        if (in_array($tab, ['전체', '게시물', '무빙'], true)) {
            $q = $db->table('starttoo_posts as p')->join('starttoo_users as u', 'u.id', '=', 'p.user_id')
                ->selectRaw("'post' as ctype, CAST(p.id AS CHAR(36)) as cid, SUBSTRING(p.content, 1, 80) as title, p.user_id as author_id, u.nickname as author, p.created_at as created_at,"
                    . " p.moderation_hidden as hidden, p.moderation_removed_at as removed_at, NULL as kind, 0 as fit_blocked, 0 as recommend_excluded,"
                    . " (select count(*) from starttoo_post_likes x where x.post_id = p.id) as likes, 0 as fits, NULL as images,"
                    . " {$reports('post', 'p.id')} as reports, {$pending('post', 'p.id')} as pending");
            if ($tab === '무빙') $q->whereExists($video('post', 'p.id'));
            $parts[] = [$q, 'p.content', 'p.id', true, 'p.user_id'];
        }
        if (in_array($tab, ['전체', '리뷰'], true)) {
            $q = $db->table('starttoo_reviews as v')->join('starttoo_users as u', 'u.id', '=', 'v.customer_id')
                ->selectRaw("'review' as ctype, CAST(v.id AS CHAR(36)) as cid, SUBSTRING(v.content, 1, 80) as title, v.customer_id as author_id, u.nickname as author, v.created_at as created_at,"
                    . " v.moderation_hidden as hidden, v.moderation_removed_at as removed_at, NULL as kind, 0 as fit_blocked, 0 as recommend_excluded,"
                    . " v.helpful_count as likes, 0 as fits, v.images as images,"
                    . " {$reports('review', 'v.id')} as reports, {$pending('review', 'v.id')} as pending");
            $parts[] = [$q, 'v.content', 'v.id', false, 'v.customer_id'];
        }
        if (in_array($tab, ['전체', '커뮤니티'], true)) {
            $q = $db->table('starttoo_community_posts as c')->join('starttoo_users as u', 'u.id', '=', 'c.user_id')
                ->selectRaw("'community' as ctype, CAST(c.id AS CHAR(36)) as cid, c.title as title, c.user_id as author_id, u.nickname as author, c.created_at as created_at,"
                    . " c.moderation_hidden as hidden, c.moderation_removed_at as removed_at, NULL as kind, 0 as fit_blocked, 0 as recommend_excluded,"
                    . " c.like_count as likes, 0 as fits, c.images as images,"
                    . " {$reports('community', 'c.id')} as reports, {$pending('community', 'c.id')} as pending");
            $parts[] = [$q, 'c.title', 'c.id', false, 'c.user_id'];
        }
        $out = [];
        foreach ($parts as [$q, $titleCol, $idCol, $uuid, $authorCol]) {
            // 회원 관리에서 오면: 그 회원이 쓴 것만
            if ($author) $q->where($authorCol, $author);
            if ($term !== '') {
                $like = '%' . addcslashes(mb_substr($term, 0, 40), '%_\\') . '%';
                $id = ltrim($term, '#');
                $q->where(function ($w) use ($like, $titleCol, $idCol, $id, $uuid) {
                    $w->where($titleCol, 'like', $like)->orWhere('u.nickname', 'like', $like);
                    if ($uuid ? \Illuminate\Support\Str::isUuid($id) : ctype_digit($id)) $w->orWhere($idCol, $uuid ? $id : (int) $id);
                });
            }
            $out[] = $q;
        }
        return $out;
    }

    public function contents(Request $request): View
    {
        $tab = in_array($request->query('tab'), self::CONTENT_TABS, true) ? $request->query('tab') : '전체';
        $state = in_array($request->query('state'), self::CONTENT_STATES, true) ? $request->query('state') : '전체';
        $sort = in_array($request->query('sort'), self::CONTENT_SORTS, true) ? $request->query('sort') : '최신 등록순';
        $term = trim((string) $request->query('q', ''));
        $page = $this->page($request);
        $isFit = $tab === '피팅 도안';

        $author = ctype_digit((string) $request->query('author', '')) ? (int) $request->query('author') : null;
        $queries = $this->contentQueries($tab, $term, $author);
        $union = array_shift($queries);
        foreach ($queries as $q) $union->unionAll($q);
        $base = $this->db()->query()->fromSub($union, 'x');
        match ($state) {
            '노출' => $base->where('x.hidden', 0),
            '숨김' => $base->where('x.hidden', 1)->whereNull('x.removed_at'),
            '삭제됨' => $base->whereNotNull('x.removed_at'),
            default => null,
        };
        $total = (clone $base)->count();
        $sort === '신고 많은순' ? $base->orderByDesc('x.reports')->orderByDesc('x.created_at') : $base->orderByDesc('x.created_at');
        $rows = $base->offset(($page - 1) * self::CONTENT_PAGE)->limit(self::CONTENT_PAGE)->get();

        // 첫 사진(작품 · 게시물은 사진 표, 리뷰 · 커뮤니티는 JSON)
        $artworkIds = $rows->where('ctype', 'artwork')->pluck('cid')->all();
        $postIds = $rows->where('ctype', 'post')->pluck('cid')->all();
        $thumbs = ($artworkIds ? $this->db()->table('starttoo_artwork_images')->whereIn('artwork_id', $artworkIds)->where('position', 0)->pluck('path', 'artwork_id')->all() : [])
            + ($postIds ? $this->db()->table('starttoo_post_images')->whereIn('post_id', $postIds)->where('position', 0)->pluck('path', 'post_id')->all() : []);
        // 무빙: 영상이 붙은 작품 · 게시물(카드에 ▶ 길이 · 영상 보기)
        $videos = [];
        if ($artworkIds || $postIds) {
            foreach ($this->db()->table('starttoo_videos')->where('status', 'published')
                ->where(fn ($w) => $w->where(fn ($a) => $a->where('published_type', 'artwork')->whereIn('published_id', $artworkIds ?: ['']))
                    ->orWhere(fn ($p) => $p->where('published_type', 'post')->whereIn('published_id', $postIds ?: [''])))
                ->get(['id', 'published_type', 'published_id', 'duration_ms']) as $v) {
                $s = (int) round($v->duration_ms / 1000);
                $videos["{$v->published_type}:{$v->published_id}"] = ['href' => route('admin.videos.play', $v->id),
                    'label' => intdiv($s, 60) . ':' . str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT)];
            }
        }
        $urls = app(MediaUrls::class);
        $tones = ['#D9D4CB', '#CFCAC1', '#E4E0D8', '#C9C4BA'];
        $kinds = ['노출' => 'green', '검토 중' => 'amber', '숨김' => 'gray', '삭제됨' => 'gray', '피팅 허용' => 'blue', '피팅 차단' => 'red', '추천 제외' => 'gray'];

        $items = [];
        foreach ($rows->values() as $i => $x) {
            $path = $thumbs[$x->cid] ?? ((json_decode((string) $x->images, true) ?: [])[0] ?? null);
            $label = match (true) {
                $x->removed_at !== null => '삭제됨',
                (bool) $x->hidden => '숨김',
                $isFit => $x->fit_blocked ? '피팅 차단' : '피팅 허용',
                (bool) $x->recommend_excluded => '추천 제외',
                (int) $x->pending > 0 => '검토 중',
                default => '노출',
            };
            $kind = $x->ctype === 'artwork' ? ($x->kind === 'design' ? ($isFit ? '피팅 도안' : '도안') : '작품') : self::KIND_LABEL[$x->ctype];
            $video = $videos["{$x->ctype}:{$x->cid}"] ?? null;
            if ($video) $kind = '무빙 · ' . $kind;
            $title = trim(preg_replace('/\s+/u', ' ', (string) $x->title)) ?: '(내용 없음)';
            $items[] = [
                'video' => $video,
                'id' => "{$x->ctype}:{$x->cid}",
                'code' => $x->ctype === 'artwork' || $x->ctype === 'post' ? '#' . substr($x->cid, 0, 8) : '#' . $x->cid,
                'kind' => $kind, 'title' => mb_strimwidth($title, 0, 80, '…'),
                'author' => $x->author ?? '-', 'date' => Ui::date($x->created_at, 'm.d'),
                'authorHref' => Ui::memberUrl($x->author_id),
                // 공개 미리보기(앱 밖 공유 페이지): 숨김이 아닌 작품 · 게시물 · 커뮤니티 글만
                'publicHref' => !$x->hidden ? Ui::publicUrl($x->ctype, $x->cid) : null,
                'state' => $label, 'pill' => Ui::pill($kinds[$label] ?? 'gray'),
                'likesLabel' => $isFit ? '피팅 ' . number_format((int) $x->fits) . '회' : ($x->ctype === 'review' ? '도움 ' : '좋아요 ') . number_format((int) $x->likes),
                'reports' => number_format((int) $x->reports),
                'reportsHref' => (int) $x->reports > 0 ? route('admin.reports', ['tab' => '전체', 'target' => "{$x->ctype}:{$x->cid}"]) : null,
                'aria' => mb_strimwidth($title, 0, 40, '…') . ' 선택',
                'image' => $urls->url($path, 600),
                'thumb' => 'position: relative; aspect-ratio: 4 / 3; overflow: hidden; background: ' . $tones[$i % 4],
            ];
        }

        $cycle = fn (array $list, string $cur) => $list[(array_search($cur, $list, true) + 1) % count($list)];
        return view('admin.contents', [
            'tabs' => Ui::tabs(self::CONTENT_TABS, $tab, 'tab', ['page']),
            'items' => $items,
            'q' => $term,
            'stateLabel' => '노출 상태: ' . $state,
            'stateHref' => Ui::url(['state' => ($n = $cycle(self::CONTENT_STATES, $state)) === '전체' ? null : $n, 'page' => null]),
            'sortLabel' => $sort,
            'sortHref' => Ui::url(['sort' => ($n = $cycle(self::CONTENT_SORTS, $sort)) === '최신 등록순' ? null : $n, 'page' => null]),
            'isFit' => $isFit,
            'can' => [
                'hide' => $this->can($request, 'content.hide'),
                'delete' => $this->can($request, 'content.delete'),
                'recommend' => $this->can($request, 'content.recommend') && in_array($tab, ['전체', '작품', '도안'], true),
                'fit' => $this->can($request, 'content.fit') && $isFit,
            ],
            'pages' => Ui::pages($page, false, (int) ceil($total / self::CONTENT_PAGE)),
            'totalLabel' => '총 ' . number_format($total) . '개',
            'bulkAction' => route('admin.contents.bulk'),
        ]);
    }

    public function bulkContents(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['string', 'max:60'],
            'action' => ['required', 'in:hide,show,delete,unrecommend,fit_block,fit_allow'],
        ], ['ids.required' => '처리할 콘텐츠를 선택해주세요.']);
        $this->need($request, match ($data['action']) {
            'hide', 'show' => 'content.hide', 'delete' => 'content.delete', 'unrecommend' => 'content.recommend', default => 'content.fit',
        });
        $moderation = app(Moderation::class);
        $canRestore = $this->can($request, 'content.restore');
        $operator = $this->operator($request);
        $changed = [];
        foreach (array_unique($data['ids']) as $key) {
            $parsed = $moderation->parseKey($key);
            if (!$parsed) continue;
            [$type, $id] = $parsed;
            $done = $moderation->apply($type, $id, $data['action'], $operator, $canRestore);
            // 「다시 노출」은 추천 제외도 함께 되돌린다
            if ($data['action'] === 'show') $done = $moderation->apply($type, $id, 'recommend', $operator, $canRestore) || $done;
            if ($done) $changed[] = $key;
        }
        $what = ['hide' => '콘텐츠 숨김', 'show' => '콘텐츠 다시 노출', 'delete' => '콘텐츠 삭제', 'unrecommend' => '추천 제외',
            'fit_block' => '피팅 차단', 'fit_allow' => '피팅 허용'][$data['action']];
        if ($changed) {
            Cache::forget('starttoo:admin:dashboard');
            Ui::forgetBadges();
            $this->audit($request, '콘텐츠', $what, mb_strimwidth(implode(', ', $changed), 0, 200, '…'), ['ids' => $changed]);
        }
        $skipped = count(array_unique($data['ids'])) - count($changed);
        $msg = count($changed) . "개 콘텐츠 · {$what} 처리했어요";
        if ($skipped > 0) {
            $msg .= " (바뀌지 않은 {$skipped}개" . ($data['action'] === 'show' && !$canRestore ? ' · 삭제된 콘텐츠는 최고 관리자만 되돌릴 수 있어요' : '') . ')';
        }
        return back()->with('status', $msg);
    }

    // ================================================================ M-06 신고 · 차단

    private const REPORT_TABS = ['미처리' => ['pending'], '처리됨' => ['resolved', 'dismissed'], '전체' => null];
    private const REPORT_STATE = ['pending' => ['미처리', 'red'], 'resolved' => ['처리 완료', 'green'], 'dismissed' => ['반려', 'gray']];
    private const TYPE_LABEL = ['user' => '회원', 'artwork' => '작품', 'post' => '게시물', 'comment' => '작품 댓글', 'post_comment' => '게시물 댓글',
        'message' => '채팅', 'consultation' => '상담', 'review' => '리뷰', 'community' => '커뮤니티', 'community_comment' => '커뮤니티 댓글'];
    private const REASON_LABEL = ['spam' => '스팸 · 광고', 'harassment' => '욕설 · 혐오 · 괴롭힘', 'sexual' => '음란 · 불쾌한 콘텐츠', 'violence' => '폭력적인 내용',
        'illegal' => '불법 정보', 'rights' => '저작권 침해', 'fraud' => '사기 · 외부 거래 유도', 'privacy' => '개인정보 노출', 'other' => '기타'];

    /** 신고 대상으로 가는 콘솔 링크: 콘텐츠는 게시물 · 작품 화면, 회원은 회원 관리 */
    private function targetHref(string $type, string $id): ?string
    {
        // 상담 · 채팅 신고 → 상담 · 채팅 모니터링의 그 방
        if ($type === 'consultation') return route('admin.chats', ['tab' => '전체', 'sel' => (int) $id]);
        if ($type === 'message') {
            $room = $this->db()->table('starttoo_messages')->where('id', (int) $id)->value('consultation_id');
            return $room ? route('admin.chats', ['tab' => '전체', 'sel' => (int) $room]) : null;
        }
        return $type === 'user' ? Ui::memberUrl((int) $id) : Ui::contentUrl($type, $id);
    }

    private function targetLabel(string $type, string $id): string
    {
        $code = match ($type) {
            'user' => 'U-' . $id, 'consultation' => 'C-' . $id,
            'artwork', 'post' => '#' . substr($id, 0, 8),
            default => '#' . $id,
        };
        return (self::TYPE_LABEL[$type] ?? $type) . ' ' . $code;
    }

    public function reports(Request $request): View
    {
        $tab = array_key_exists((string) $request->query('tab'), self::REPORT_TABS) ? (string) $request->query('tab') : '미처리';
        $page = $this->page($request);
        $canSuspend = $this->can($request, 'report.suspend');
        $pen = in_array($request->query('pen'), array_keys(Sanctions::PENALTIES), true) ? $request->query('pen') : '경고';
        if ($pen !== '경고' && !$canSuspend) $pen = '경고';

        $sameTarget = '(select count(*) from starttoo_reports s where s.target_type = r.target_type and s.target_id = r.target_id)';
        $q = $this->db()->table('starttoo_reports as r')->select('r.*')->selectRaw("$sameTarget as same_count");
        if (self::REPORT_TABS[$tab]) $q->whereIn('r.status', self::REPORT_TABS[$tab]);
        // 콘텐츠 화면에서 「신고 N」으로 들어왔을 때: 그 대상의 신고만
        if ($target = app(Moderation::class)->parseKey((string) $request->query('target', ''))) {
            $q->where('r.target_type', $target[0])->where('r.target_id', (string) $target[1]);
        }
        // 회원 관리 「신고 누적」에서 오면: 그 회원이 받은 신고만
        if (ctype_digit((string) $request->query('subject', ''))) $q->where('r.subject_user_id', (int) $request->query('subject'));
        $total = (clone $q)->count();
        $list = $q->orderByDesc('r.id')->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)->get();

        $selId = (int) $request->query('sel', 0);
        $selRow = $selId ? $this->db()->table('starttoo_reports as r')->select('r.*')->selectRaw("$sameTarget as same_count")->where('r.id', $selId)->first() : null;
        $selRow ??= $list->first();

        $rows = $list->map(fn ($r) => [
            'date' => Ui::date($r->created_at, 'm.d H:i'),
            'type' => self::TYPE_LABEL[$r->target_type] ?? $r->target_type,
            'reason' => self::REASON_LABEL[$r->reason] ?? $r->reason,
            'target' => $this->targetLabel($r->target_type, (string) $r->target_id),
            'targetHref' => $this->targetHref($r->target_type, (string) $r->target_id),
            'count' => (int) $r->same_count,
            'state' => self::REPORT_STATE[$r->status][0] ?? $r->status,
            'pill' => Ui::pill(self::REPORT_STATE[$r->status][1] ?? 'gray'),
            'href' => Ui::url(['sel' => $r->id, 'pen' => null]),
            'rowStyle' => $selRow && (int) $r->id === (int) $selRow->id ? 'background: #F4F2EE' : 'background: #FFFFFF',
        ])->all();

        $sel = null;
        if ($selRow) {
            $subject = $this->db()->table('starttoo_users')->where('id', $selRow->subject_user_id)->first(['nickname', 'status', 'suspended_until']);
            $snapshot = json_decode((string) $selRow->snapshot, true) ?: [];
            $reporters = $this->db()->table('starttoo_reports')->where('target_type', $selRow->target_type)->where('target_id', $selRow->target_id)
                ->distinct()->count('reporter_id');
            $sel = [
                'id' => (int) $selRow->id,
                'reason' => self::REASON_LABEL[$selRow->reason] ?? $selRow->reason,
                'target' => $this->targetLabel($selRow->target_type, (string) $selRow->target_id),
                'targetHref' => $this->targetHref($selRow->target_type, (string) $selRow->target_id),
                'publicHref' => Ui::publicUrl($selRow->target_type, (string) $selRow->target_id),
                'authorHref' => Ui::memberUrl($selRow->subject_user_id),
                'authorReportsHref' => Ui::memberReportsUrl((int) $selRow->subject_user_id),
                'author' => !$subject ? '-' : (($subject->status === 'withdrawn' ? '(탈퇴 회원)' : ($subject->nickname ?? '-')) . ' · U-' . $selRow->subject_user_id
                    . ($subject->status === 'suspended' ? ' · ' . ($subject->suspended_until ? Ui::date($subject->suspended_until, 'm.d') . '까지 정지 중' : '영구 정지') : '')),
                'count' => (int) $selRow->same_count, 'reporters' => $reporters,
                'excerpt' => $this->excerpt($selRow->target_type, $snapshot),
                'details' => trim((string) $selRow->details),
                'state' => self::REPORT_STATE[$selRow->status][0] ?? $selRow->status,
                'pill' => Ui::pill(self::REPORT_STATE[$selRow->status][1] ?? 'gray'),
                'open' => $selRow->status === 'pending',
                'resolveAction' => route('admin.reports.resolve', $selRow->id),
            ];
        }

        $penalties = array_map(fn ($label) => [
            'label' => $label, 'on' => $label === $pen,
            'locked' => $label !== '경고' && !$canSuspend,
            'href' => Ui::url(['pen' => $label]),
            'style' => 'height: 40px; border-radius: 8px; font-size: 13px; font-weight: 700; '
                . ($label === $pen ? 'background: #141413; color: #FFFFFF; border: 1px solid #141413' : 'background: #FFFFFF; color: #141413; border: 1px solid #D9D4CB')
                . ($label !== '경고' && !$canSuspend ? '; opacity: 0.45' : ''),
        ], array_keys(Sanctions::PENALTIES));

        return view('admin.reports', [
            'tabs' => Ui::tabs(array_keys(self::REPORT_TABS), $tab, 'tab', ['sel', 'pen', 'page']),
            'rows' => $rows, 'penalties' => $penalties, 'pen' => $pen, 'sel' => $sel,
            'canDismiss' => $this->can($request, 'report.dismiss'),
            'canResolve' => $this->can($request, 'report.hide') && $this->can($request, $pen === '경고' ? 'report.warn' : 'report.suspend'),
            'pages' => Ui::pages($page, false, (int) ceil($total / self::PER_PAGE)),
        ]);
    }

    /** 신고 접수 때 남긴 내용(상담은 신고 시 첨부된 최근 메시지만 · 상담방 전체는 열지 않는다) */
    private function excerpt(string $type, array $s): string
    {
        if ($type === 'consultation') {
            $lines = array_map(fn ($m) => ($m['sender_id'] === null ? '[안내]' : 'U-' . $m['sender_id']) . ': '
                . ($m['kind'] === 'text' ? (string) $m['body'] : '(' . $m['kind'] . ')'), array_slice($s['messages'] ?? [], -10));
            return $lines ? implode("\n", $lines) : '(첨부된 메시지 없음)';
        }
        if ($type === 'message') return ($s['kind'] ?? 'text') === 'text' ? (string) ($s['body'] ?? '') : '(' . ($s['kind'] ?? '메시지') . ')';
        $parts = array_filter([$s['title'] ?? null, $s['nickname'] ?? null, $s['content'] ?? null, $s['description'] ?? null, $s['bio'] ?? null,
            isset($s['rating']) ? '평점 ' . $s['rating'] : null], fn ($v) => is_string($v) && trim($v) !== '');
        return $parts ? implode("\n", $parts) : '(남은 내용 없음)';
    }

    public function resolveReport(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'result' => ['required', 'in:처리 완료,반려'],
            'penalty' => ['required', 'in:' . implode(',', array_keys(Sanctions::PENALTIES))],
        ]);
        $report = $this->db()->table('starttoo_reports')->where('id', $id)->first();
        abort_unless($report, 404);
        if ($report->status !== 'pending') return back()->withErrors(['result' => '이미 처리된 신고예요.']);
        $dismiss = $data['result'] === '반려';
        $this->need($request, $dismiss ? 'report.dismiss' : 'report.hide');
        if (!$dismiss) $this->need($request, $data['penalty'] === '경고' ? 'report.warn' : 'report.suspend');

        $operator = $this->operator($request);
        $target = $this->targetLabel($report->target_type, (string) $report->target_id);
        // 같은 대상의 미처리 신고를 함께 처리한다(신고한 회원마다 처리 결과 알림이 간다)
        $ids = $this->db()->table('starttoo_reports')->where('target_type', $report->target_type)->where('target_id', $report->target_id)
            ->where('status', 'pending')->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all();
        $reason = $dismiss ? '운영 콘솔 · 신고 반려' : "운영 콘솔 · 숨김 + {$data['penalty']}";
        try {
            foreach ($ids as $rid) app(Reports::class)->moderate($rid, $dismiss ? 'dismiss' : 'hide', $operator, $reason);
            $sanction = $dismiss ? null : app(Sanctions::class)->apply((int) $report->subject_user_id, $data['penalty'], $reason, $id, (int) $this->admin($request)->id);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['result' => $e->getMessage()]);
        }
        Ui::forgetBadges();
        Cache::forget('starttoo:admin:dashboard');

        $author = $this->db()->table('starttoo_users')->where('id', $report->subject_user_id)->value('nickname') ?? ('U-' . $report->subject_user_id);
        if ($dismiss) {
            $this->audit($request, '제재', '신고 반려', "{$target} · 신고 " . count($ids) . '건', ['reports' => $ids]);
            return back()->with('status', '신고 ' . count($ids) . '건을 반려했어요');
        }
        $this->audit($request, '제재', "콘텐츠 숨김 + {$data['penalty']}", "{$target} · {$author} (U-{$report->subject_user_id})",
            ['reports' => $ids, 'penalty' => $data['penalty'], 'until' => $sanction['until'] ?? null]);
        return back()->with('status', "{$author} · 숨김 + {$data['penalty']} 적용했어요" . (($sanction['applied'] ?? true) ? '' : ' (탈퇴 회원이라 이력만 남겼어요)'));
    }
}
