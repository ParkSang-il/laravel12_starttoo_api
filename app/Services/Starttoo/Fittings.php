<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

/**
 * 가상 타투 피팅(FIT-01~08).
 * 앱이 사진 위에 도안 위치 · 크기 · 각도를 잡은 배치 이미지를 만들고, 서버가 나노바나나로 실제 시술처럼 합성한다.
 * 내 신체 사진은 AI 처리 동의 후에만 받고, 합성이 끝나면 AI 입력본은 지운다(결과와 비교용 축소본만 보관함에 남김).
 */
class Fittings
{
    /** 디자인 FIT-03B 부위 · 보이는 면 (앱 models/virtual_model.dart 와 같다) */
    public const PARTS = [
        '전완' => ['en' => 'forearm', 'faces' => ['안쪽' => 'inner side', '바깥쪽' => 'outer side']],
        '상완' => ['en' => 'upper arm', 'faces' => ['바깥쪽' => 'outer side', '안쪽' => 'inner side']],
        '손목·손등' => ['en' => 'wrist and back of the hand', 'faces' => ['손등' => 'back of the hand', '손목 안쪽' => 'inner wrist']],
        '어깨' => ['en' => 'shoulder', 'faces' => ['앞' => 'front', '옆' => 'side', '뒤' => 'back']],
        '쇄골·가슴' => ['en' => 'collarbone and chest', 'faces' => ['정면' => 'front view', '한쪽' => 'one side']],
        '등' => ['en' => 'back', 'faces' => ['전체' => 'full back', '상단' => 'upper back', '하단' => 'lower back']],
        '옆구리' => ['en' => 'side ribs', 'faces' => ['왼쪽' => 'left side', '오른쪽' => 'right side']],
        '허벅지' => ['en' => 'thigh', 'faces' => ['앞' => 'front', '옆' => 'outer side', '뒤' => 'back']],
        '종아리' => ['en' => 'calf', 'faces' => ['뒤' => 'back', '옆' => 'outer side']],
        '발목·발등' => ['en' => 'ankle and top of the foot', 'faces' => ['발등' => 'top of the foot', '안쪽' => 'inner ankle', '바깥쪽' => 'outer ankle']],
        '목' => ['en' => 'neck', 'faces' => ['뒷목' => 'back of the neck', '옆목' => 'side of the neck']],
    ];
    public const TONES = ['very light fair', 'light medium', 'medium olive', 'medium brown', 'deep dark brown'];
    public const BUILDS = ['슬림' => 'slim', '보통' => 'average', '탄탄' => 'athletic toned'];
    /**
     * 부위별 옷차림(안전 필터 PROHIBITED_CONTENT 방지). 상체 · 하체는 운동복을 입혀 부위만 드러낸다.
     * [여성, 남성]
     */
    private const WEAR = [
        '어깨' => ['wearing a black athletic tank top', 'wearing a black athletic tank top'],
        '쇄골·가슴' => ['wearing a black high-neck sports bra that fully covers the chest, only the collarbone and upper chest above it visible', 'wearing a black crew-neck t-shirt pulled down slightly at the collar, only the collarbone visible'],
        // 여성 등: 브라 끈이 등을 가리지 않게 목 뒤로만 묶는 오픈백 홀터 상의(등 전체가 맨살), 로우라이즈 레깅스
        '등' => ['wearing a black open-back halter top tied only at the back of the neck, so the entire back from the shoulder blades down to the lower back is completely bare skin with no straps or fabric crossing it, and low-rise black leggings, photographed from behind, hair tied up', 'wearing black athletic shorts, photographed from behind, upper back bare'],
        '옆구리' => ['wearing a black sports bra and black athletic leggings, arm raised to show the side ribs', 'wearing black athletic shorts and a black tank top lifted at the side to show the ribs'],
        '허벅지' => ['wearing black athletic bike shorts', 'wearing black athletic shorts'],
        '상완' => ['wearing a black sleeveless athletic top', 'wearing a black sleeveless athletic top'],
        '목' => ['wearing a black crew-neck t-shirt, hair tied up', 'wearing a black crew-neck t-shirt'],
    ];
    /** 가상 모델 성별 → 프롬프트 */
    public const GENDERS = ['female' => 'young adult woman', 'male' => 'young adult man'];
    /** 조건 하나당 만드는 모델 사진(포즈 · 조명) */
    public const VARIANTS = [['정면', '자연광'], ['정면', '스튜디오'], ['살짝 비튼', '실내'], ['근접', '자연광']];
    private const KIND_EN = ['정면' => 'straight-on view', '살짝 비튼' => 'slightly rotated angle', '근접' => 'close-up crop'];
    private const LIGHT_EN = ['자연광' => 'soft natural daylight', '스튜디오' => 'even studio lighting', '실내' => 'warm indoor lighting'];

    private function db() { return app(ProjectDatabase::class)->database(); }

    // ── 공통 ─────────────────────────────────────

    public function assertEnabled(): void
    {
        if (!config('starttoo.fit_enabled', true) || !app(NanoBanana::class)->configured()) {
            $this->fail(503, 'FIT_UNAVAILABLE', '가상 피팅을 잠시 이용할 수 없어요. 잠시 후 다시 시도해주세요.');
        }
    }

    public function remaining(int $viewer): int
    {
        $used = $this->db()->table('starttoo_fit_results')->where('user_id', $viewer)
            ->where('created_at', '>=', today())->where('status', '<>', 'failed')->count();
        return max(0, (int) config('starttoo.fit_daily_limit', 3) - $used);
    }

    /** FIT-01 피팅 메인: 남은 횟수 · 최근 결과 */
    public function home(int $viewer): array
    {
        return [
            'enabled' => (bool) config('starttoo.fit_enabled', true) && app(NanoBanana::class)->configured(),
            'daily_limit' => (int) config('starttoo.fit_daily_limit', 3),
            'remaining' => $this->remaining($viewer),
            'recent' => $this->db()->table('starttoo_fit_results')->where('user_id', $viewer)->where('status', '<>', 'failed')
                ->orderByDesc('created_at')->limit(6)->get()->map(fn ($r) => $this->format($r))->all(),
        ];
    }

    // ── FIT-03B · 03C 가상 모델 ───────────────────

    /**
     * 조건에 맞는 모델 사진. 운영자가 올린 사진(starttoo:fit-models-import)을 쓴다.
     * 톤 · 체형까지 맞는 사진이 없으면 같은 부위 · 면의 사진을 보여 준다(match=near).
     * STARTTOO_FIT_MODEL_AI=true 일 때만 없는 조건을 나노바나나로 만든다(status=generating).
     */
    public function models(array $input): array
    {
        $q = $this->modelQuery($input);
        $table = fn (array $where) => $this->db()->table('starttoo_fit_models')->where($where)->where('is_active', true)->orderBy('sort')->orderBy('id')->get();
        $rows = $table($q);
        $match = 'exact';
        if ($rows->where('status', 'ready')->isEmpty() && !config('starttoo.fit_model_ai', false)) {
            // 같은 성별 · 부위 · 면 → 없으면 성별 상관없이 같은 부위 · 면
            $rows = $table(['part' => $q['part'], 'face' => $q['face'], 'gender' => $q['gender']]);
            if ($rows->where('status', 'ready')->isEmpty()) $rows = $table(['part' => $q['part'], 'face' => $q['face']]);
            $match = 'near';
        }
        if ($rows->isEmpty() && config('starttoo.fit_model_ai', false)) {
            $this->assertEnabled();
            $this->queueModels($q);
            $rows = $table($q);
        }
        // 큐가 늦으면 조회 요청이 한 장씩 직접 만든다.
        $stale = $rows->first(fn ($r) => $r->status === 'pending' && Carbon::parse($r->updated_at)->lt(now()->subSeconds(40)));
        if ($stale) {
            $this->generateModel((int) $stale->id);
            $rows = $table($q);
        }
        $letters = range('A', 'Z');
        $ready = $rows->where('status', 'ready')->values();
        return [
            'match' => $match,
            'status' => $rows->contains('status', 'pending') ? 'generating' : ($ready->isEmpty() ? 'empty' : 'ready'),
            'items' => $ready->map(fn ($r, $i) => [
                'id' => (int) $r->id, 'name' => '모델 '.($letters[$i] ?? $i + 1), 'kind' => $r->kind, 'light' => $r->light,
                'image_url' => app(MediaUrls::class)->url($r->image_path, 900),
            ])->all(),
        ];
    }

    private function modelQuery(array $input): array
    {
        $part = (string) ($input['part'] ?? '');
        $face = (string) ($input['face'] ?? '');
        $build = (string) ($input['build'] ?? '');
        $tone = filter_var($input['tone'] ?? null, FILTER_VALIDATE_INT);
        // 이전 앱은 성별을 보내지 않는다 → 여성
        $gender = isset(self::GENDERS[$input['gender'] ?? '']) ? $input['gender'] : 'female';
        if (!isset(self::PARTS[$part]) || !isset(self::PARTS[$part]['faces'][$face]) || !isset(self::BUILDS[$build])
            || $tone === false || $tone < 0 || $tone >= count(self::TONES)) {
            $this->fail(422, 'INVALID_FIT', '가상 모델 조건을 다시 골라주세요.');
        }
        return ['part' => $part, 'face' => $face, 'gender' => $gender, 'tone' => $tone, 'build' => $build];
    }

    /** 조건 하나의 모델 사진 4장을 생성 대기로 넣고 큐에 보낸다. */
    public function queueModels(array $q, int $variants = 4): int
    {
        $ids = [];
        foreach (array_slice(self::VARIANTS, 0, max(1, $variants)) as $i => [$kind, $light]) {
            try {
                $ids[] = $this->db()->table('starttoo_fit_models')->insertGetId($q + [
                    'kind' => $kind, 'light' => $light, 'status' => 'pending', 'sort' => $i, 'is_active' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } catch (QueryException $e) {
                // 동시에 같은 조건을 요청했으면 먼저 넣은 쪽을 쓴다.
            }
        }
        foreach ($ids as $id) $this->dispatchModel((int) $id);
        return count($ids);
    }

    /** 한 번에 여러 장을 넣을 때 분당 한도(429)에 걸리지 않게 7초 간격으로 큐에 넣는다. */
    private static int $spread = 0;

    public function dispatchModel(int $id): void
    {
        try {
            \App\Jobs\GenerateStarttooFitModel::dispatch($id)->delay(now()->addSeconds(7 * self::$spread++));
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function generateModel(int $id): void
    {
        $lock = Cache::lock('starttoo:fit-model:'.$id, 90);
        if (!$lock->get()) return;
        try {
            $m = $this->db()->table('starttoo_fit_models')->where('id', $id)->first();
            if (!$m || $m->status !== 'pending') return;
            $this->db()->table('starttoo_fit_models')->where('id', $id)->update(['updated_at' => now()]);
            $part = self::PARTS[$m->part] ?? null;
            if (!$part) return;
            $female = ($m->gender ?? 'male') === 'female';
            $wear = self::WEAR[$m->part][$female ? 0 : 1] ?? 'wearing simple black clothing that does not cover the area';
            $prompt = sprintf(
                'A tasteful, non-sexual fitness catalog style photograph for a tattoo placement preview app. '
                .'A %s\'s %s (%s), clearly %s body, %s skin tone, %s body build, %s, %s. '
                .'The model is '.$wear.'. '
                .'The skin on that area is clean with absolutely no tattoos, marks or jewelry. The %s fills most of the frame and is clearly visible for placing a tattoo. '
                .'No face visible, plain dark neutral background, natural skin texture and pores. '
                .'No text, no watermark, no logo. Vertical 3:4 portrait framing.',
                self::GENDERS[$m->gender ?? 'male'] ?? 'person', $part['en'], $part['faces'][$m->face] ?? $m->face,
                $female ? 'feminine, smooth, slender' : 'masculine', self::TONES[(int) $m->tone] ?? 'medium',
                self::BUILDS[$m->build] ?? 'average', self::KIND_EN[$m->kind] ?? 'straight-on view',
                self::LIGHT_EN[$m->light] ?? 'soft natural daylight', $part['en'],
            );
            try {
                $bytes = app(NanoBanana::class)->generate($prompt, [], '3:4');
                $path = app(ArtworkPhotos::class)->storeBytes($bytes);
                $this->db()->table('starttoo_fit_models')->where('id', $id)->update(['image_path' => $path, 'status' => 'ready', 'updated_at' => now()]);
            } catch (Throwable $e) {
                report(new \RuntimeException('Fit model generation failed: '.$e->getMessage()));
                $this->db()->table('starttoo_fit_models')->where('id', $id)->update(['status' => 'failed', 'updated_at' => now()]);
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * 운영자가 올린 가상 모델 사진 등록. 폴더 구조: {dir}/{부위}/{면}/파일.jpg
     * 파일 이름에 _ 로 구분해 선택 값을 넣을 수 있다(없으면 기본값):
     *   성별 여/남(기본 여) · 톤 1~5(1 밝은 · 3 중간 · 5 짙은, 기본 3) · 체형 슬림/보통/탄탄(기본 보통) · 포즈 정면/살짝비튼/근접(기본 정면) · 조명 자연광/스튜디오/실내(기본 자연광)
     *   예) 전완/안쪽/모델1_2_슬림_근접.jpg
     * 등록한 파일은 {dir}/_done 으로 옮긴다. 같은 파일(해시)은 다시 등록하지 않는다.
     *
     * @return array{added: int, skipped: int, unknown: array<int, string>}
     */
    public function importModels(string $dir): array
    {
        $norm = function (string $s): string {
            if (class_exists(\Normalizer::class)) $s = (string) \Normalizer::normalize($s, \Normalizer::FORM_C);
            return str_replace(['·', ' ', '-'], '', $s);
        };
        $parts = [];
        foreach (self::PARTS as $label => $def) $parts[$norm($label)] = $label;
        $builds = array_keys(self::BUILDS);
        $kinds = ['정면' => '정면', '살짝비튼' => '살짝 비튼', '근접' => '근접'];
        $lights = ['자연광', '스튜디오', '실내'];
        $added = 0;
        $skipped = 0;
        $unknown = [];
        $files = \Illuminate\Support\Facades\File::allFiles($dir);
        foreach ($files as $file) {
            $rel = str_replace('\\', '/', $file->getRelativePathname());
            if (str_starts_with($rel, '_done/') || str_starts_with($file->getFilename(), '.')) continue;
            if (!in_array(strtolower($file->getExtension()), ['jpg', 'jpeg', 'png', 'webp'], true)) continue;
            $segments = explode('/', $rel);
            if (count($segments) !== 3) { $unknown[] = $rel; continue; }
            $part = $parts[$norm($segments[0])] ?? null;
            $face = null;
            if ($part) {
                foreach (array_keys(self::PARTS[$part]['faces']) as $f) if ($norm($f) === $norm($segments[1])) $face = $f;
            }
            if (!$part || !$face) { $unknown[] = $rel; continue; }
            $tone = 2;
            $gender = 'female';
            $build = '보통';
            $kind = '정면';
            $light = '자연광';
            foreach (preg_split('/_/', $norm(pathinfo($segments[2], PATHINFO_FILENAME))) as $token) {
                if (preg_match('/^[1-5]$/', $token)) $tone = (int) $token - 1;
                elseif (in_array($token, ['여', '여자', '여성'], true)) $gender = 'female';
                elseif (in_array($token, ['남', '남자', '남성'], true)) $gender = 'male';
                elseif (in_array($token, $builds, true)) $build = $token;
                elseif (isset($kinds[$token])) $kind = $kinds[$token];
                elseif (in_array($token, $lights, true)) $light = $token;
            }
            $bytes = (string) file_get_contents($file->getPathname());
            $hash = hash('sha256', $bytes);
            if ($this->db()->table('starttoo_fit_models')->where('source_hash', $hash)->exists()) {
                $skipped++;
            } else {
                $path = app(ArtworkPhotos::class)->storeBytes($bytes);
                $sort = (int) $this->db()->table('starttoo_fit_models')->where(compact('part', 'face'))->max('sort') + 1;
                $this->db()->table('starttoo_fit_models')->insert([
                    'part' => $part, 'face' => $face, 'gender' => $gender, 'tone' => $tone, 'build' => $build, 'kind' => $kind, 'light' => $light,
                    'image_path' => $path, 'source_hash' => $hash, 'source_name' => $rel, 'status' => 'ready', 'sort' => $sort,
                    'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $added++;
            }
            $done = rtrim($dir, '/').'/_done/'.dirname($rel);
            \Illuminate\Support\Facades\File::ensureDirectoryExists($done);
            \Illuminate\Support\Facades\File::move($file->getPathname(), $done.'/'.$file->getFilename());
        }
        return compact('added', 'skipped', 'unknown');
    }

    /** 운영: 모델 사진 끄기(목록에서 빼기). source_name 일부 또는 id */
    public function deactivateModels(string $match): int
    {
        $q = $this->db()->table('starttoo_fit_models');
        ctype_digit($match) ? $q->where('id', (int) $match) : $q->where('source_name', 'like', '%'.$match.'%');
        return $q->update(['is_active' => false, 'updated_at' => now()]);
    }

    // ── FIT-05 도안 ──────────────────────────────

    /**
     * 도안 고르기: tab=artist(진입 도안의 작가 도안) · saved(저장한 도안).
     * 공개 · 숨김 아님 · 차단 아님인 도안(kind=design)만.
     */
    public function stencils(int $viewer, array $input): array
    {
        $artworks = app(Artworks::class);
        $tab = in_array($input['tab'] ?? 'saved', ['artist', 'saved'], true) ? $input['tab'] : 'saved';
        $selected = null;
        if (!empty($input['artwork']) && Str::isUuid((string) $input['artwork'])) {
            $selected = $artworks->feedQuery($viewer, 'public')->where('a.id', $input['artwork'])->first();
        }
        $q = $artworks->feedQuery($viewer, 'public')->where('a.kind', 'design');
        if ($tab === 'artist') {
            if (!$selected) return ['items' => [], 'selected' => null];
            $q->where('a.user_id', $selected->user_id);
        } else {
            $q->join('starttoo_artwork_interactions as sv', fn ($j) => $j->on('sv.artwork_id', '=', 'a.id')->where('sv.user_id', $viewer)->where('sv.kind', 'save'));
        }
        $rows = $q->orderByDesc('a.created_at')->limit(60)->get();
        return [
            'items' => $rows->map(fn ($r) => $this->stencil($r))->all(),
            'selected' => $selected ? $this->stencil($selected) : null,
        ];
    }

    private function stencil(object $a): array
    {
        $path = $this->db()->table('starttoo_artwork_images')->where('artwork_id', $a->id)->orderBy('position')->value('path');
        return [
            'artwork_id' => $a->id, 'title' => $a->title, 'kind' => $a->kind, 'color' => $a->color,
            'artist' => ['id' => (int) $a->user_id, 'nickname' => $a->author_nickname ?? ''],
            'image_url' => app(MediaUrls::class)->url($path, 1200),
        ];
    }

    // ── FIT-06 → 07 합성 ────────────────────────

    /**
     * 합성 요청(multipart). payload: request_key, consent=true, source(photo|virtual), part, face, artwork_id | (파일 stencil), model_id(virtual)
     * 파일: composite(배치 이미지, 필수) · photo(내 사진 원본 축소본, source=photo 필수) · stencil(직접 가져온 도안)
     */
    public function create(int $viewer, array $input, ?UploadedFile $composite, ?UploadedFile $photo, ?UploadedFile $stencil): array
    {
        $this->assertEnabled();
        if (Validator::make($input, [
            'request_key' => ['required', 'uuid'],
            'consent' => ['required', 'accepted'],
            'source' => ['required', 'in:photo,virtual'],
            'part' => ['nullable', 'string', 'max:20'],
            'face' => ['nullable', 'string', 'max:20'],
            'artwork_id' => ['nullable', 'uuid'],
            'model_id' => ['nullable', 'integer'],
        ])->fails()) {
            $this->fail(422, 'INVALID_FIT', '피팅 요청을 확인해주세요.');
        }
        if (!empty($input['part']) && !isset(self::PARTS[$input['part']])) $this->fail(422, 'INVALID_FIT', '부위를 다시 골라주세요.');
        $image = ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192', 'dimensions:max_width=4096,max_height=4096'];
        $files = ['composite' => $composite, 'photo' => $photo, 'stencil' => $stencil];
        $rules = ['composite' => ['required', ...$image], 'photo' => ['nullable', ...$image], 'stencil' => ['nullable', ...$image]];
        if (Validator::make($files, $rules)->fails()) $this->fail(422, 'INVALID_FIT', '사진은 8MB 이하의 JPG·PNG·WebP로 선택해주세요.');

        $db = $this->db();
        if ($existing = $db->table('starttoo_fit_results')->where('user_id', $viewer)->where('request_key', $input['request_key'])->first()) {
            return $this->format($existing) + ['remaining' => $this->remaining($viewer)];
        }
        $modelId = null;
        $beforePath = null;
        if ($input['source'] === 'virtual') {
            $model = $db->table('starttoo_fit_models')->where('id', (int) ($input['model_id'] ?? 0))->where('status', 'ready')->first();
            if (!$model) $this->fail(422, 'INVALID_FIT', '가상 모델 사진을 다시 골라주세요.');
            $modelId = (int) $model->id;
            $beforePath = $model->image_path;
        } elseif (!$photo) {
            $this->fail(422, 'INVALID_FIT', '사진을 다시 선택해주세요.');
        }
        $artworkId = $input['artwork_id'] ?? null;
        if ($artworkId) {
            $ok = app(Artworks::class)->feedQuery($viewer, 'public')->where('a.id', $artworkId)->where('a.kind', 'design')->exists();
            if (!$ok) $this->fail(409, 'FIT_STENCIL_UNAVAILABLE', '이 도안은 지금 피팅할 수 없어요. 다른 도안을 골라주세요.');
        } elseif (!$stencil) {
            $this->fail(422, 'INVALID_FIT', '입힐 도안을 골라주세요.');
        }
        if ($this->remaining($viewer) <= 0) {
            $this->fail(429, 'FIT_DAILY_LIMIT', '오늘 AI 피팅을 모두 사용했어요. 내일 다시 이용해주세요.');
        }

        $photos = app(ArtworkPhotos::class);
        $saved = [];
        try {
            $saved['input'] = $this->storeTemp($composite);
            if ($stencil) $saved['stencil'] = $this->storeTemp($stencil);
            if ($photo) $saved['before'] = $photos->storeBytes((string) $photo->get());
        } catch (Throwable $e) {
            $photos->delete(array_values($saved));
            throw $e;
        }
        $id = (string) Str::uuid();
        try {
            $db->table('starttoo_fit_results')->insert([
                'id' => $id, 'user_id' => $viewer, 'request_key' => $input['request_key'], 'artwork_id' => $artworkId,
                'model_id' => $modelId, 'source' => $input['source'], 'part' => $input['part'] ?? null, 'face' => $input['face'] ?? null,
                'input_path' => $saved['input'], 'stencil_path' => $saved['stencil'] ?? null,
                'before_path' => $saved['before'] ?? $beforePath, 'status' => 'processing',
                'consent_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (QueryException $e) {
            $photos->delete(array_values($saved));
            if ($existing = $db->table('starttoo_fit_results')->where('user_id', $viewer)->where('request_key', $input['request_key'])->first()) {
                return $this->format($existing) + ['remaining' => $this->remaining($viewer)];
            }
            throw $e;
        }
        try {
            \App\Jobs\ProcessStarttooFitResult::dispatch($id);
        } catch (Throwable $e) {
            // 큐에 넣지 못해도 결과 조회가 직접 처리한다.
            report($e);
        }
        return $this->format($db->table('starttoo_fit_results')->where('id', $id)->first()) + ['remaining' => $this->remaining($viewer)];
    }

    private function storeTemp(UploadedFile $file): string
    {
        $path = 'artwork/starttoo/tmp/fit/'.now('Asia/Seoul')->format('Y/m/d').'/'.Str::uuid().'.raw';
        if (!Storage::disk('starttoo')->put($path, (string) $file->get(), ['visibility' => 'private'])) {
            $this->fail(503, 'FIT_FAILED', '사진을 저장하지 못했어요. 다시 시도해주세요.');
        }
        return $path;
    }

    /** 나노바나나 합성(큐 또는 조회 요청에서). 성공 · 실패 모두 AI 입력 임시 파일은 지운다. */
    public function process(string $id): void
    {
        $lock = Cache::lock('starttoo:fit-result:'.$id, 100);
        if (!$lock->get()) return;
        try {
            $r = $this->db()->table('starttoo_fit_results')->where('id', $id)->first();
            if (!$r || $r->status !== 'processing') return;
            $this->db()->table('starttoo_fit_results')->where('id', $id)->update(['updated_at' => now()]);
            try {
                $disk = Storage::disk('starttoo');
                $composite = (string) $disk->get($r->input_path);
                $stencilBytes = $r->stencil_path ? (string) $disk->get($r->stencil_path) : $this->artworkImage($r->artwork_id);
                if ($composite === '' || $stencilBytes === '') throw new \RuntimeException('FIT_INPUT_MISSING');
                $where = '';
                if ($r->part && isset(self::PARTS[$r->part])) {
                    $p = self::PARTS[$r->part];
                    $where = ' on the '.$p['en'].($r->face && isset($p['faces'][$r->face]) ? ' ('.$p['faces'][$r->face].')' : '');
                }
                $prompt = 'Image 1 is a photo of a body'.$where.' with a tattoo design roughly overlaid as a flat, semi-transparent sticker to show the intended placement. '
                    .'Image 2 is the original tattoo design artwork. '
                    .'Edit image 1 so that the tattoo looks like a real, fully healed tattoo inked into this skin. '
                    .'Keep exactly the same position, size, rotation and mirroring as the overlay in image 1, and keep the design\'s lines, shapes, shading and colors faithful to image 2 (do not redesign, simplify or add elements). '
                    .'Ignore any white or paper background of the design: only the ink goes on the skin. '
                    .'Wrap the ink naturally around the curvature and muscles of the body, follow the skin\'s lighting, shadows, pores and fine texture, with ink sitting under the skin — no sticker edges, outlines, glow or halo. '
                    .'Do not change anything else: same person, skin tone, pose, background, clothing, framing and aspect ratio. No text, no watermark, no extra tattoos.';
                $bytes = app(NanoBanana::class)->generate($prompt, [[$composite, $this->mime($composite)], [$stencilBytes, $this->mime($stencilBytes)]]);
                if ($r->source === 'virtual') $bytes = $this->labelVirtual($bytes);
                $path = app(ArtworkPhotos::class)->storeBytes($bytes);
                $this->db()->table('starttoo_fit_results')->where('id', $id)->update(['result_path' => $path, 'status' => 'ready', 'input_path' => null, 'stencil_path' => null, 'updated_at' => now()]);
            } catch (Throwable $e) {
                $code = str_starts_with($e->getMessage(), 'FIT_AI_NO_IMAGE') ? 'blocked' : 'ai_failed';
                report(new \RuntimeException('Fit result failed: '.Str::limit($e->getMessage(), 80)));
                $this->db()->table('starttoo_fit_results')->where('id', $id)->update(['status' => 'failed', 'error' => $code, 'input_path' => null, 'stencil_path' => null, 'updated_at' => now()]);
            }
            $this->deleteFiles(array_filter([$r->input_path, $r->stencil_path]));
        } finally {
            $lock->release();
        }
    }

    private function artworkImage(?string $artworkId): string
    {
        if (!$artworkId) return '';
        $path = $this->db()->table('starttoo_artwork_images')->where('artwork_id', $artworkId)->orderBy('position')->value('path');
        return $path ? (string) Storage::disk('starttoo')->get($path) : '';
    }

    private function mime(string $bytes): string
    {
        $info = @getimagesizefromstring($bytes);
        return in_array($info['mime'] ?? '', ['image/png', 'image/webp', 'image/jpeg'], true) ? $info['mime'] : 'image/jpeg';
    }

    /** 가상 모델 결과 · 상담 첨부 이미지에 「VIRTUAL MODEL」 표시(디자인 FIT-03B 안내) */
    private function labelVirtual(string $bytes): string
    {
        $img = @imagecreatefromstring($bytes);
        if (!$img) return $bytes;
        try {
            $w = imagesx($img);
            $h = imagesy($img);
            $font = 5;
            $text = 'STARTTOO VIRTUAL MODEL';
            $tw = imagefontwidth($font) * strlen($text);
            $th = imagefontheight($font);
            $pad = max(8, (int) round($w * 0.015));
            $bg = imagecolorallocatealpha($img, 14, 14, 15, 40);
            $fg = imagecolorallocate($img, 242, 239, 234);
            imagefilledrectangle($img, $pad, $h - $pad - $th - 12, $pad + $tw + 16, $h - $pad, $bg);
            imagestring($img, $font, $pad + 8, $h - $pad - $th - 6, $text, $fg);
            ob_start();
            imagepng($img);
            return (string) ob_get_clean();
        } finally {
            imagedestroy($img);
        }
    }

    // ── FIT-07 · 08 조회 · 삭제 ───────────────────

    public function show(int $viewer, string $id): array
    {
        $r = $this->own($viewer, $id);
        // 큐가 늦거나 멈췄으면 조회 요청이 직접 처리한다.
        if ($r->status === 'processing' && Carbon::parse($r->updated_at)->lt(now()->subSeconds(25))) {
            $this->process($id);
            $r = $this->own($viewer, $id);
        }
        return $this->format($r) + ['remaining' => $this->remaining($viewer)];
    }

    public function list(int $viewer, array $input): array
    {
        $page = max(1, (int) ($input['page'] ?? 1));
        $rows = $this->db()->table('starttoo_fit_results')->where('user_id', $viewer)->where('status', '<>', 'failed')
            ->orderByDesc('created_at')->offset(($page - 1) * 30)->limit(31)->get();
        return ['items' => $rows->take(30)->map(fn ($r) => $this->format($r))->values()->all(), 'has_more' => $rows->count() > 30];
    }

    public function delete(int $viewer, string $id): array
    {
        $r = $this->own($viewer, $id);
        $this->db()->table('starttoo_fit_results')->where('id', $id)->delete();
        $paths = array_filter([$r->input_path, $r->stencil_path]);
        // 상담에 첨부한 결과 파일은 상담 기록과 함께 남긴다. 가상 모델 사진(before)은 공용이라 지우지 않는다.
        if (!$r->attached_at) $paths[] = $r->result_path;
        if ($r->source === 'photo' && !$r->attached_at) $paths[] = $r->before_path;
        $this->deleteFiles(array_values(array_filter($paths)));
        return ['deleted' => true];
    }

    /** 상담 요청서에 결과 첨부(Consultations::start). 결과 파일 경로를 돌려준다. */
    public function claimForConsult(int $viewer, string $id): string
    {
        $r = $this->db()->table('starttoo_fit_results')->where('id', $id)->where('user_id', $viewer)->lockForUpdate()->first();
        if (!$r || $r->status !== 'ready' || !$r->result_path) $this->fail(409, 'FIT_RESULT_UNAVAILABLE', '피팅 결과를 확인하지 못했어요. 다시 골라주세요.');
        $this->db()->table('starttoo_fit_results')->where('id', $id)->update(['attached_at' => $r->attached_at ?? now()]);
        return $r->result_path;
    }

    private function own(int $viewer, string $id): object
    {
        $r = Str::isUuid($id) ? $this->db()->table('starttoo_fit_results')->where('id', $id)->where('user_id', $viewer)->first() : null;
        if (!$r) $this->fail(404, 'FIT_NOT_FOUND', '피팅 결과를 찾을 수 없어요.');
        return $r;
    }

    private function deleteFiles(array $paths): void
    {
        $paths = array_values(array_filter($paths, fn ($p) => is_string($p) && str_starts_with($p, 'artwork/starttoo/')));
        if (!$paths) return;
        try {
            Storage::disk('starttoo')->delete($paths);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function format(object $r): array
    {
        $artwork = $r->artwork_id ? $this->db()->table('starttoo_artworks as a')->join('starttoo_users as u', 'u.id', '=', 'a.user_id')
            ->where('a.id', $r->artwork_id)->first(['a.id', 'a.title', 'a.user_id', 'u.nickname']) : null;
        $urls = app(MediaUrls::class);
        return [
            'id' => $r->id, 'status' => $r->status, 'error' => $r->error, 'source' => $r->source,
            'part' => $r->part, 'face' => $r->face,
            'result_url' => $urls->url($r->result_path, 1600), 'before_url' => $urls->url($r->before_path, 1600),
            'thumb_url' => $urls->url($r->result_path, 400),
            'artwork' => $artwork ? ['id' => $artwork->id, 'title' => $artwork->title, 'artist_id' => (int) $artwork->user_id, 'artist_nickname' => $artwork->nickname] : null,
            'attached' => $r->attached_at !== null,
            'created_at' => Carbon::parse($r->created_at)->toIso8601String(),
        ];
    }

    private function fail(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(compact('code', 'message'), $status));
    }
}
