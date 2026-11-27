<?php

namespace App\Http\Controllers\Admin;

use App\Services\Starttoo\AdminAudit;
use App\Services\Starttoo\AppVersions;
use App\Support\AdminUi as Ui;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * M-19 앱 버전 · 강제 업데이트. 정책은 AppVersions(starttoo_settings 'app_version')에 저장한다.
 * 스텝퍼 · 스위치는 세션 초안에만 반영되고 [저장]을 눌러야 앱에 적용된다.
 * 잘못 저장하면 모든 회원이 앱을 못 쓰게 될 수 있어 최고 관리자만 저장한다(나머지는 조회).
 */
class AdminAppVersionController extends AdminBaseController
{
    private const PF = ['iOS' => 'ios', 'Android' => 'android'];

    private function draftKey(string $pf): string
    {
        return "admin_appver.$pf";
    }

    /** 세션 초안(없으면 저장된 정책을 releases 순번으로) */
    private function draft(Request $request, string $pf, array $p, array $list): array
    {
        $idx = function (?string $v) use ($list) {
            $i = array_search(AppVersions::normalize($v), $list, true);
            return $i === false ? count($list) - 1 : $i;
        };
        return $request->session()->get($this->draftKey($pf), [
            'min' => $idx($p['min_version']), 'rec' => $idx($p['recommended_version']), 'latest' => $idx($p['latest_version']),
            'block' => (bool) $p['block_api'], 'staged' => (bool) $p['staged'], 'maint' => (bool) $p['maintenance'],
        ]);
    }

    public function show(Request $request): View
    {
        $label = array_key_exists((string) $request->query('pf'), self::PF) ? (string) $request->query('pf') : 'iOS';
        $pf = self::PF[$label];
        $versions = app(AppVersions::class);
        $p = $versions->policy($pf);
        $list = $versions->releases($pf);
        $d = $this->draft($request, $pf, $p, $list);
        $canSave = $this->can($request, 'app.version');
        $pv = $request->query('pv') === '권장' ? '권장' : '강제';

        $box = fn ($bg) => 'border-radius: 12px; padding: 14px; display: flex; flex-direction: column; gap: 8px; ' . $bg;
        $stepBtn = 'width: 32px; height: 32px; border-radius: 8px; border: 1px solid #D9D4CB; background: #FFFFFF; font-size: 16px; flex-shrink: 0';
        $vers = array_map(fn ($v) => $v + [
            'value' => $list[$d[$v['k']]] ?? '-', 'box' => $box($v['bg']), 'stepBtn' => $stepBtn,
            'decAction' => route('admin.app-version.step', [$pf, $v['k'], 'down']), 'incAction' => route('admin.app-version.step', [$pf, $v['k'], 'up']),
            'decAria' => $v['label'] . ' 낮추기', 'incAria' => $v['label'] . ' 높이기',
        ], [
            ['k' => 'min', 'label' => '최소 지원 (강제)', 'note' => '이보다 낮으면 앱을 쓸 수 없어요', 'bg' => 'background: #FDE4DE'],
            ['k' => 'rec', 'label' => '권장 (선택)', 'note' => '이보다 낮으면 닫을 수 있는 안내', 'bg' => 'background: #FFF1D6'],
            ['k' => 'latest', 'label' => '스토어 최신', 'note' => $pf === 'ios' ? 'App Store 배포 버전' : 'Play 스토어 배포 버전', 'bg' => 'background: #F4F2EE'],
        ]);
        $invalid = !($d['min'] <= $d['rec'] && $d['rec'] <= $d['latest']);

        // 버전 분포: 최근 7일 버전 확인 수(앱 실행 · 앞으로 올 때). 회원 수가 아니라 실행 횟수다.
        $counts = $versions->distribution($pf);
        $max = max(1, $counts ? max($counts) : 1);
        $minV = $list[$d['min']] ?? '0.0.0';
        $recV = $list[$d['rec']] ?? '0.0.0';
        $dist = [];
        $forced = $soft = 0;
        foreach ($counts as $ver => $n) {
            [$state, $kind, $color] = version_compare($ver, $minV, '<') ? ['강제 업데이트', 'red', '#A3301A']
                : (version_compare($ver, $recV, '<') ? ['권장 안내', 'amber', '#C98A1B'] : ['정상', 'green', '#141413']);
            if ($kind === 'red') $forced += $n; elseif ($kind === 'amber') $soft += $n;
            $dist[] = ['ver' => $ver, 'users' => number_format($n) . '회', 'state' => $state, 'pill' => Ui::pill($kind),
                'bar' => 'display: block; height: 100%; border-radius: 6px; width: ' . max(2, (int) round($n / $max * 100)) . '%; background: ' . $color];
        }
        $total = array_sum($counts);

        $opts = array_map(fn ($o) => $o + [
            'on' => $d[$o['id']], 'aria' => $o['label'], 'track' => Ui::switchTrack($d[$o['id']], !$canSave),
            'action' => route('admin.app-version.option', [$pf, $o['id']]),
        ], [
            ['id' => 'block', 'label' => '강제 업데이트 시 앱 API 차단', 'note' => '오래된 앱이 서버를 직접 부르는 것도 막아요 (426 Upgrade Required)'],
            ['id' => 'staged', 'label' => '단계적 적용', 'note' => '최소 버전을 올리면 저장 후 ' . AppVersions::STAGED_HOURS . '시간 동안은 권장 안내만, 그 뒤 강제 시작'],
            ['id' => 'maint', 'label' => '점검 모드', 'note' => '버전과 상관없이 점검 안내 화면만 보여줘요'],
        ]);

        $isForce = $pv === '강제';
        $sheetBase = 'border-radius: 20px; background: #FFFFFF; padding: 22px 18px 16px; display: flex; flex-direction: column; align-items: flex-start; gap: 10px';
        $history = app(AdminAudit::class)->query('설정')->where('action', 'like', '앱 버전%')->where('target', 'like', $label . '%')->limit(6)->get()
            ->map(fn ($h) => ['what' => $h->target, 't' => Ui::date($h->created_at, 'm.d H:i'), 'who' => $h->admin_name, 'why' => $h->action])->all();
        $enforceAt = $p['enforce_at'] ? Carbon::parse($p['enforce_at']) : null;
        $dirty = $request->session()->has($this->draftKey($pf));

        // 설정 항목별 탭(?section=). iOS · Android(?pf=)는 두 탭 모두에 적용
        [$section, $sections] = Ui::sections(['업데이트 설정', '변경 이력']);
        return view('admin.app-version', [
            'section' => $section, 'sections' => $sections,
            'vers' => $vers, 'invalid' => $invalid, 'dist' => $dist, 'opts' => $opts, 'knob' => Ui::KNOB, 'canSave' => $canSave,
            'platforms' => array_map(fn ($l) => ['label' => $l, 'style' => Ui::tab($l === $label), 'href' => Ui::url(['pf' => $l])], array_keys(self::PF)),
            'impact' => $total ? '강제 대상 ' . number_format($forced) . '회 (' . number_format($forced / $total * 100, 1) . '%) · 안내 대상 ' . number_format($soft) . '회' : '아직 기록이 없어요',
            'storeUrl' => old('store_url', $p['store_url']),
            'forceMessage' => old('force_message', $p['force_message']), 'softMessage' => old('soft_message', $p['soft_message']),
            'latest' => $list[$d['latest']] ?? '-',
            'liveStyle' => 'height: 40px; padding: 0 14px; border-radius: 10px; font-size: 13px; font-weight: 700; display: inline-flex; align-items: center; ' . Ui::pillColors($p['maintenance'] ? 'red' : 'green'),
            'liveLabel' => $p['maintenance'] ? "$label 점검 모드 켜짐"
                : "$label 운영 중 · 최소 " . ($p['min_version'] ?: '없음') . ($p['staged'] && $enforceAt?->isFuture() ? ' (' . $enforceAt->format('m.d H:i') . '부터 강제)' : ''),
            'previews' => Ui::chips(['강제', '권장'], $pv, 'pv'),
            'sheet' => $isForce ? $sheetBase . '; margin-bottom: auto; margin-top: auto' : $sheetBase,
            'pvTitle' => $isForce ? '업데이트가 필요해요' : '새 버전이 나왔어요',
            'pvBody' => $isForce ? $p['force_message'] : $p['soft_message'],
            'pvCur' => $isForce ? ($list[max(0, $d['min'] - 1)] ?? '-') : ($list[max($d['min'], $d['rec'] - 1)] ?? '-'), 'pvSoft' => !$isForce,
            'pvNote' => $isForce ? '강제: 전체 화면 · 닫기와 뒤로 가기 없음. 업데이트를 누르면 스토어로 이동해요.'
                : '권장: 하단 시트 · 닫으면 ' . max(1, (int) config('starttoo.app_soft_snooze_days', 7)) . '일 동안 다시 안 떠요.',
            'history' => $history,
            'saveAction' => route('admin.app-version.save', $pf),
            'saveLabel' => $invalid ? '버전 순서를 확인해주세요' : ($dirty ? "$label 설정 저장 · 변경 있음" : "$label 설정 저장"),
        ]);
    }

    public function step(Request $request, string $pf, string $key, string $dir): RedirectResponse
    {
        $this->need($request, 'app.version');
        abort_unless(isset(AppVersions::PLATFORMS[$pf]) && in_array($key, ['min', 'rec', 'latest'], true) && in_array($dir, ['up', 'down'], true), 404);
        $versions = app(AppVersions::class);
        $list = $versions->releases($pf);
        $d = $this->draft($request, $pf, $versions->policy($pf), $list);
        $d[$key] = max(0, min(count($list) - 1, $d[$key] + ($dir === 'up' ? 1 : -1)));
        $request->session()->put($this->draftKey($pf), $d);

        return back();
    }

    public function option(Request $request, string $pf, string $id): RedirectResponse
    {
        $this->need($request, 'app.version');
        abort_unless(isset(AppVersions::PLATFORMS[$pf]) && in_array($id, ['block', 'staged', 'maint'], true), 404);
        $versions = app(AppVersions::class);
        $d = $this->draft($request, $pf, $versions->policy($pf), $versions->releases($pf));
        $d[$id] = !$d[$id];
        $request->session()->put($this->draftKey($pf), $d);

        return back();
    }

    public function save(Request $request, string $pf): RedirectResponse
    {
        $this->need($request, 'app.version');
        abort_unless(isset(AppVersions::PLATFORMS[$pf]), 404);
        $data = $request->validate([
            'force_message' => ['required', 'string', 'max:300'],
            'soft_message' => ['required', 'string', 'max:300'],
            'store_url' => ['nullable', 'url', 'max:500', 'starts_with:https://'],
        ], ['store_url.starts_with' => '스토어 주소는 https:// 로 시작해야 해요.']);
        $versions = app(AppVersions::class);
        $p = $versions->policy($pf);
        $list = $versions->releases($pf);
        $d = $this->draft($request, $pf, $p, $list);
        if (!($d['min'] <= $d['rec'] && $d['rec'] <= $d['latest'])) {
            return back()->withErrors(['version' => '최소 지원 ≤ 권장 ≤ 스토어 최신 순서여야 해요.'])->withInput();
        }

        $label = AppVersions::PLATFORMS[$pf];
        $after = [
            'min_version' => $list[$d['min']], 'recommended_version' => $list[$d['rec']], 'latest_version' => $list[$d['latest']],
            'block_api' => $d['block'], 'staged' => $d['staged'], 'maintenance' => $d['maint'], 'store_url' => (string) ($data['store_url'] ?? ''),
        ];
        $minRaised = !AppVersions::valid($p['min_version']) || version_compare($after['min_version'], $p['min_version'], '>');
        $after['enforce_at'] = $after['staged'] ? ($minRaised ? now()->addHours(AppVersions::STAGED_HOURS)->toIso8601String() : $p['enforce_at']) : null;
        $before = array_intersect_key($p, $after);
        $versions->save($pf, $after + ['force_message' => trim($data['force_message']), 'soft_message' => trim($data['soft_message'])], (int) $this->admin($request)->id);

        $names = ['min_version' => '최소', 'recommended_version' => '권장', 'latest_version' => '최신', 'block_api' => 'API 차단', 'staged' => '단계적 적용', 'maintenance' => '점검 모드', 'store_url' => '스토어 주소'];
        $changes = [];
        foreach ($names as $k => $n) {
            if (($before[$k] ?? null) == $after[$k]) continue;
            $changes[] = is_bool($after[$k]) ? $n . ' ' . ($after[$k] ? '켜기' : '끄기') : ($k === 'store_url' ? '스토어 주소 변경' : "{$n} " . ($before[$k] ?: '없음') . " → {$after[$k]}");
        }
        $summary = $label . ' ' . ($changes ? implode(' · ', $changes) : '문구 수정');
        $this->audit($request, '설정', '앱 버전 정책 저장', mb_substr($summary, 0, 200), ['before' => $before, 'after' => $after]);
        $request->session()->forget($this->draftKey($pf));

        return back()->with('status', $summary . ' 저장했어요');
    }
}
