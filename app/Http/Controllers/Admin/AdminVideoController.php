<?php

namespace App\Http\Controllers\Admin;

use App\Jobs\TranscodeStarttooVideo;
use App\Services\Starttoo\MediaUrls;
use App\Services\Starttoo\Videos;
use App\Support\AdminUi as Ui;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * 운영 콘솔 무빙(동영상) — docs/video.md. 기획서 「관리자 화면」: 새 화면 없이 기존 화면에 더한다.
 *  · AI 작업 모니터 → 종류 「영상 변환」(이 컨트롤러의 jobs, 같은 모양): 단계 · 실패 이유 · [다시 변환] · 대기 수 · 평균 처리 시간
 *  · 영상 재생(play): 게시물 화면 「무빙」 카드 · 검수에서 영상을 직접 본다(HLS)
 * 조회는 모든 역할, 다시 변환은 ai.manage.
 */
class AdminVideoController extends AdminBaseController
{
    private const TABS = ['전체' => null, '처리 중' => ['uploading', 'processing'], '완료' => ['ready', 'published'], '실패' => ['upload_failed', 'encode_failed', 'rejected']];
    private const STATE = [
        'uploading' => ['올리는 중', 'gray'], 'processing' => ['변환 중', 'blue'], 'ready' => ['게시 대기', 'amber'],
        'published' => ['게시됨', 'green'], 'upload_failed' => ['업로드 실패', 'red'], 'encode_failed' => ['변환 실패', 'red'], 'rejected' => ['반려', 'red'],
    ];
    private const STAGES = ['probe' => '확인 (ffprobe)', 'transcode' => '변환 (HLS)', 'thumbnail' => '포스터', 'done' => '저장'];
    private const KIND = ['work' => '작품', 'design' => '도안'];

    private function videos(): Videos { return app(Videos::class); }

    public function jobs(Request $request): View
    {
        $db = $this->db();
        $tab = array_key_exists((string) $request->query('tab'), self::TABS) ? (string) $request->query('tab') : '전체';
        $page = $this->page($request);
        $q = $db->table('starttoo_videos as v')->leftJoin('starttoo_users as u', 'u.id', '=', 'v.user_id')->select('v.*', 'u.nickname', 'u.artist_status');
        if (self::TABS[$tab]) $q->whereIn('v.status', self::TABS[$tab]);
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('v.created_at')->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)->get();

        $selId = (string) $request->query('sel', '');
        $sel = Str::isUuid($selId)
            ? $db->table('starttoo_videos as v')->leftJoin('starttoo_users as u', 'u.id', '=', 'v.user_id')->where('v.id', $selId)->select('v.*', 'u.nickname', 'u.artist_status')->first()
            : null;
        $sel ??= $rows->first();

        $who = fn ($r) => ($r->nickname ?? '-') . ($r->artist_status === 'approved' ? ' (아티스트)' : '');
        $kind = function ($r) {
            $p = json_decode((string) $r->payload, true) ?: [];
            return '무빙 · ' . ($r->target === 'post' ? '게시물' : (self::KIND[$p['kind'] ?? 'work'] ?? '작품'));
        };
        // 소요: 다 올린 시각 → 변환 끝(올리는 시간은 폰 사정이라 빼고 서버 처리만)
        $took = fn ($r) => $r->uploaded_at && $r->processed_at
            ? number_format(abs(Carbon::parse($r->processed_at)->diffInSeconds(Carbon::parse($r->uploaded_at))), 0) . '초' : '-';
        $code = fn ($r) => 'vid_' . substr(str_replace('-', '', $r->id), 0, 6);
        $jobs = $rows->map(fn ($r) => [
            'id' => $code($r), 'kind' => $kind($r), 'who' => $who($r), 'whoHref' => Ui::memberUrl($r->user_id),
            'at' => Ui::date($r->created_at, 'm.d H:i:s'), 'took' => $took($r),
            'state' => self::STATE[$r->status][0] ?? $r->status, 'pill' => Ui::pill(self::STATE[$r->status][1] ?? 'gray'),
            'delivery' => round($r->size_bytes / 1048576, 1) . 'MB · ' . ($r->duration_ms ? round($r->duration_ms / 1000) . '초' : '-'),
            'dPill' => Ui::pill('gray'),
            'rowStyle' => Ui::rowBg($sel && $r->id === $sel->id), 'href' => Ui::url(['sel' => $r->id]),
        ])->all();

        // 큐: video-transcode 대기 · 가장 오래 기다린 작업
        try {
            $queueConn = DB::connection(config('queue.connections.database.connection') ?: config('database.default'));
            $queueTable = config('queue.connections.database.table', 'jobs');
            $waiting = $queueConn->table($queueTable)->where('queue', 'video-transcode')->whereNull('reserved_at')->count();
            $oldest = $queueConn->table($queueTable)->where('queue', 'video-transcode')->whereNull('reserved_at')->min('created_at');
        } catch (\Throwable $e) {
            report($e);
            [$waiting, $oldest] = [0, null];
        }
        $delayed = $oldest && time() - (int) $oldest > 600;

        $today = fn () => $db->table('starttoo_videos')->where('created_at', '>=', today());
        $failedToday = $today()->whereIn('status', ['upload_failed', 'encode_failed'])->count();
        $doneToday = $today()->whereIn('status', ['ready', 'published'])->count();
        $avg = $today()->whereNotNull('uploaded_at')->whereNotNull('processed_at')->whereIn('status', ['ready', 'published'])
            ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, uploaded_at, processed_at)) as s')->value('s');
        $kpis = [
            ['label' => '대기 중', 'value' => number_format($waiting), 'sub' => $oldest ? '가장 오래 기다린 작업 ' . (int) ceil((time() - (int) $oldest) / 60) . '분' : '큐 비어 있음', 'subStyle' => Ui::sub($delayed ? 'down' : 'muted')],
            ['label' => '변환 중', 'value' => number_format($db->table('starttoo_videos')->where('status', 'processing')->count()),
                'sub' => '올리는 중 ' . number_format($db->table('starttoo_videos')->where('status', 'uploading')->count()), 'subStyle' => Ui::sub('muted')],
            ['label' => '오늘 실패', 'value' => number_format($failedToday), 'sub' => $failedToday ? '아래 「실패」 탭에서 이유 확인' : '없음', 'subStyle' => Ui::sub($failedToday > 0 ? 'down' : 'muted')],
            ['label' => '오늘 완료', 'value' => number_format($doneToday), 'sub' => $avg === null ? '평균 처리 시간 -' : '평균 ' . number_format((float) $avg) . '초 (목표 180초)',
                'subStyle' => Ui::sub($avg !== null && $avg > 180 ? 'down' : 'muted')],
        ];

        $steps = [];
        $selView = null;
        if ($sel) {
            $events = $db->table('starttoo_video_events')->where('video_id', $sel->id)->orderBy('id')->get();
            $at = function (string $status, ?string $stage = null) use ($events) {
                $e = $events->first(fn ($e) => $e->status === $status && ($stage === null || str_contains((string) $e->detail, "\"{$stage}\"")));
                return $e ? Ui::date($e->created_at, 'H:i:s') : null;
            };
            $isFail = in_array($sel->status, ['upload_failed', 'encode_failed', 'rejected'], true);
            $order = ['probe', 'transcode', 'thumbnail', 'done'];
            $reachedStage = $sel->stage ? array_search($sel->stage, $order, true) : ($sel->processed_at ? 3 : -1);
            $labels = [
                ['업로드 세션 · 조각 주소', $at('uploading'), true],
                ['나눠 올리기 완료 (폰 → 저장소)', $at('processing') ?? ($sel->uploaded_at ? Ui::date($sel->uploaded_at, 'H:i:s') : null), (bool) $sel->uploaded_at],
            ];
            foreach (['probe', 'transcode', 'thumbnail'] as $i => $stage) {
                $labels[] = [self::STAGES[$stage], $at('processing', $stage), $reachedStage !== false && $reachedStage >= $i];
            }
            $labels[] = ['게시 · 알림 (소켓 · 푸시)', $at('published') ?? $at('ready'), in_array($sel->status, ['ready', 'published'], true)];
            $failAt = $isFail ? (int) collect($labels)->search(fn ($l) => !$l[2]) : -1;
            foreach ($labels as $i => [$label, $t, $reached]) {
                $failHere = $isFail && $i === $failAt;
                $c = $failHere ? '#A3301A' : ($reached ? '#141413' : '#D9D4CB');
                $next = $labels[$i + 1][2] ?? false;
                $steps[] = ['label' => $label, 't' => $failHere ? '실패' : ($t ?? ($reached ? '' : '-')), 'weight' => $reached || $failHere ? 700 : 500,
                    'dot' => 'width: 12px; height: 12px; border-radius: 50%; flex-shrink: 0; margin-top: 4px; background: ' . $c,
                    'line' => $i === count($labels) - 1 ? 'display: none' : 'flex: 1; width: 2px; background: ' . ($reached && $next && !$failHere ? '#141413' : '#E4E0D8')];
            }
            $renditions = array_map(fn ($r) => ($r['short'] ?? '?') . 'p', json_decode((string) $sel->renditions, true) ?: []);
            $target = $sel->published_id
                ? ($sel->published_type === 'post' ? Ui::publicUrl('post', $sel->published_id) : Ui::publicUrl('artwork', $sel->published_id)) : null;
            $selView = [
                'id' => $code($sel), 'kind' => $kind($sel), 'who' => $who($sel), 'whoHref' => Ui::memberUrl($sel->user_id),
                'state' => self::STATE[$sel->status][0] ?? $sel->status, 'pill' => Ui::pill(self::STATE[$sel->status][1] ?? 'gray'),
                'isFail' => $isFail, 'code' => $sel->error_code ?? '-',
                'reason' => $sel->error_code ? (Videos::ERRORS[$sel->error_code] ?? Videos::ERRORS['UNKNOWN']) : '',
                'file' => round($sel->size_bytes / 1048576, 1) . 'MB · ' . ($sel->width && $sel->height ? "{$sel->width}×{$sel->height}" : '-')
                    . ' · ' . ($sel->duration_ms ? round($sel->duration_ms / 1000, 1) . '초' : '-') . ($sel->muted ? ' · 소리 끔' : ''),
                'renditions' => $renditions ? implode(' · ', $renditions) : '-',
                'attempts' => (int) $sel->attempts . '회',
                'source' => $sel->source_deleted_at ? '삭제됨(보관 기간 지남)' : ($sel->uploaded_at ? '보관 중 (' . config('starttoo.video_source_keep_days') . '일)' : '아직 없음'),
                'playHref' => $sel->hls_path ? route('admin.videos.play', $sel->id) : null,
                'targetHref' => $target,
                'canRetry' => in_array($sel->status, ['encode_failed', 'processing'], true) && $sel->uploaded_at && !$sel->source_deleted_at && $this->can($request, 'ai.manage'),
                'retryAction' => route('admin.videos.retry', $sel->id),
            ];
        }

        return view('admin.ai-jobs', [
            'video' => true, 'kpis' => $kpis, 'jobs' => $jobs, 'steps' => $steps, 'sel' => $selView, 'model' => 'ffmpeg (HLS · H.264 + AAC)',
            'queueLabel' => ($delayed ? '영상 큐 지연' : '영상 큐 정상') . ' · 대기 ' . number_format($waiting) . '건',
            'queueStyle' => $delayed ? 'background: #FFF1D6; color: #7A4F00' : 'background: #E3F4E9; color: #1D6B3A',
            'queueDot' => $delayed ? '#7A4F00' : '#1D6B3A',
            'tabs' => Ui::tabs(array_keys(self::TABS), $tab),
            'pages' => Ui::pages($page, false, (int) ceil($total / self::PER_PAGE)),
        ]);
    }

    /** 다시 변환: 실패했거나 멈춘 영상을 처음부터 다시 큐에 넣는다(원본이 남아 있을 때만) */
    public function retry(Request $request, string $id): RedirectResponse
    {
        $this->need($request, 'ai.manage');
        abort_unless(Str::isUuid($id), 404);
        $row = $this->videos()->row($id);
        abort_unless($row, 404);
        if (!in_array($row->status, ['encode_failed', 'processing'], true) || !$row->uploaded_at || $row->source_deleted_at) {
            return back()->withErrors(['retry' => '원본이 남아 있는 변환 실패 · 멈춘 영상만 다시 변환할 수 있어요.']);
        }
        $this->db()->table('starttoo_videos')->where('id', $id)->update(['status' => 'processing', 'stage' => null, 'error_code' => null,
            'attempts' => 0, 'processed_at' => null, 'updated_at' => now()]);
        $this->videos()->event($id, 'processing', ['retry_by' => (int) $this->admin($request)->id]);
        TranscodeStarttooVideo::dispatch($id);
        $this->audit($request, '콘텐츠', '무빙 다시 변환', 'vid_' . substr(str_replace('-', '', $id), 0, 6));
        return back()->with('status', '다시 변환을 시작했어요. 끝나면 회원에게 알림이 가요.');
    }

    /** 영상 재생(HLS). 게시 여부와 관계없이 운영자는 볼 수 있다(검수용) */
    public function play(Request $request, string $id): View
    {
        abort_unless(Str::isUuid($id), 404);
        $row = $this->db()->table('starttoo_videos as v')->leftJoin('starttoo_users as u', 'u.id', '=', 'v.user_id')
            ->where('v.id', $id)->select('v.*', 'u.nickname')->first();
        abort_unless($row && $row->hls_path, 404);
        $payload = json_decode((string) $row->payload, true) ?: [];
        return view('admin.video-play', [
            'hls' => $this->videos()->cdn($row->hls_path),
            'poster' => app(MediaUrls::class)->url($row->poster_path, 500),
            'title' => trim((string) ($row->target === 'post' ? ($payload['content'] ?? '') : ($payload['description'] ?? ''))) ?: '(내용 없음)',
            'who' => $row->nickname ?? '-', 'whoHref' => Ui::memberUrl($row->user_id),
            'state' => self::STATE[$row->status][0] ?? $row->status, 'pill' => Ui::pill(self::STATE[$row->status][1] ?? 'gray'),
            'info' => round(($row->duration_ms ?? 0) / 1000, 1) . '초 · ' . ($row->width && $row->height ? "{$row->width}×{$row->height}" : '-') . ($row->muted ? ' · 소리 끔' : ''),
            'jobsHref' => route('admin.ai-jobs.videos', ['sel' => $row->id]),
            'contentHref' => $row->published_id ? route('admin.contents', ['tab' => '무빙', 'q' => '#' . $row->published_id]) : null,
        ]);
    }
}
