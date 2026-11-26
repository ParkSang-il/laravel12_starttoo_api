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
        '쇄골·가슴' => ['en' => 'collarbone and chest', 'faces' => ['정면' => 'front view', '한쪽' => 'one side', '가슴 위' => 'upper chest above the breasts', '가슴 아래' => 'area just below the breasts (underbust and sternum)']],
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
        // 여성: 탱크톱 끈이 어깨를 덮어서 → 가슴만 가리는 끈 없는 밴드 상의(어깨 · 팔 · 윗등 맨살)
        '어깨' => ['wearing a black strapless bandeau top that covers only the breasts, so both shoulders, the upper arms, the collarbones and the upper back are completely bare skin with no straps at all', 'wearing a black athletic tank top with very narrow straps set close to the neck, so the rounded shoulder caps and deltoids are completely bare skin'],
        // 남성: 티셔츠가 쇄골을 가려서 → 목둘레가 깊게 파인 민소매(쇄골 · 윗가슴 맨살, 유두는 가림)
        // 여성: 하이넥 브라가 쇄골을 덮어서 → 가슴만 낮게 가리는 끈 없는 밴드 상의(쇄골 · 윗가슴 · 어깨 맨살)
        '쇄골·가슴' => ['wearing a black strapless bandeau top worn low that covers only the breasts, so the neck, both collarbones, the upper chest above the breasts and the shoulders are completely bare skin with no straps or neckline crossing them','wearing a black athletic tank top with a deep wide scoop neckline, so both collarbones and the upper chest are bare skin while the tank top still covers the nipples'],
        // 여성 등: 브라 끈이 등을 가리지 않게 목 뒤로만 묶는 오픈백 홀터 상의(등 전체가 맨살), 로우라이즈 레깅스
        '등' => ['wearing a black open-back halter top tied only at the back of the neck, so the entire back from the shoulder blades down to the lower back is completely bare skin with no straps or fabric crossing it, and low-rise black leggings, photographed from behind, hair tied up', 'wearing black athletic shorts, photographed from behind, upper back bare'],
        // 남성: 상의를 올리면 유두가 보여서 → 옆이 깊게 파인 민소매(옆구리만 맨살, 가슴 · 유두는 가림)
        '옆구리' => ['wearing a black sports bra and black athletic leggings, arm raised to show the side ribs', 'wearing black athletic shorts and a black athletic tank top with very deep side cut-outs, so the side ribs are bare skin while the chest and nipples stay fully covered by the tank top'],
        '허벅지' => ['wearing black athletic bike shorts', 'wearing black athletic shorts'],
        '상완' => ['wearing a black sleeveless athletic top', 'wearing a black sleeveless athletic top'],
        '목' => ['wearing a black crew-neck t-shirt, hair tied up', 'wearing a black crew-neck t-shirt'],
        // 아래 부위는 옷이 부위를 덮지 않게 명시(빠지면 긴소매 · 긴바지를 입혀 버린다)
        '전완' => ['wearing a black sleeveless top, both arms completely bare from the shoulder to the fingertips', 'wearing a black sleeveless top, both arms completely bare from the shoulder to the fingertips'],
        '손목·손등' => ['wearing a black sleeveless top, arms, wrists and hands completely bare, no watch, no bracelet, no sleeves', 'wearing a black sleeveless top, arms, wrists and hands completely bare, no watch, no bracelet, no sleeves'],
        '종아리' => ['wearing short black athletic shorts, legs completely bare from the thigh down to the ankle, no socks', 'wearing short black athletic shorts, legs completely bare from the thigh down to the ankle, no socks'],
        '발목·발등' => ['barefoot, wearing short black athletic shorts, no socks, no shoes', 'barefoot, wearing short black athletic shorts, no socks, no shoes'],
    ];
    /** 보이는 면에 따라 옷차림이 달라야 하는 경우(WEAR보다 우선). [여성, 남성] */
    private const WEAR_FACE = [
        '쇄골·가슴' => [
            '가슴 아래' => [
                'wearing a black sports bra that covers the breasts and black leggings, with the entire area below the bra — underbust, lower sternum, front ribs and stomach — completely bare skin',
                'wearing only black athletic shorts; the framing starts just below the nipples so the nipples are out of frame, and the lower chest, sternum, ribs and stomach are bare skin',
            ],
        ],
    ];

    /**
     * 부위 · 보이는 면별 카메라 · 자세. 「안쪽」이 반대 팔로, 「옆」이 정면으로, 종아리가 허벅지로
     * 나오던 문제를 막으려고 면마다 어디서 찍는지 · 화면에 무엇만 나오는지를 정확히 적는다.
     */
    private const POSES = [
        '전완' => [
            '안쪽' => 'ONE forearm turned palm-up so the INNER forearm (the soft underside with visible veins) faces the camera, from the elbow to the wrist. Only this one arm is in frame.',
            '바깥쪽' => 'ONE forearm turned palm-down so the OUTER forearm (the top side) faces the camera, from the elbow to the wrist. Only this one arm is in frame.',
        ],
        '상완' => [
            '바깥쪽' => 'Camera at the person\'s side. The OUTER upper arm (deltoid down to the elbow) faces the camera, arm relaxed and hanging down.',
            '안쪽' => 'The person raises ONE arm and bends it with the hand resting behind the head, so the INNER upper arm (the pale soft inner side of the bicep between the armpit and the elbow, the side that normally touches the torso) faces the camera. Only this one raised arm and a little of the torso are in frame. Do NOT show the other arm and do NOT show the outer side of the arm.',
        ],
        '손목·손등' => [
            '손등' => 'ONE hand with the BACK of the hand and the top of the wrist facing the camera, fingers relaxed. Only this one hand is in frame.',
            '손목 안쪽' => 'ONE hand turned palm-up: the PALM and the INNER wrist (the underside of the wrist just below the palm, with visible tendons and veins) face the camera, the inner wrist is the center of the frame. Only this one hand and wrist are in frame, no second arm.',
        ],
        '어깨' => [
            '앞' => 'Camera directly IN FRONT of the person. The FRONT of ONE shoulder (front deltoid, where the shoulder meets the chest) is the center of the frame, seen from the chest side.',
            '옆' => 'Camera at the person\'s SIDE at 90 degrees (profile view). The OUTER side of ONE shoulder (the rounded side deltoid) is the center of the frame, arm hanging down. This must clearly be a side view, not a front or back view.',
            '뒤' => 'Camera directly BEHIND the person. The BACK of ONE shoulder and the shoulder blade are the center of the frame.',
        ],
        '쇄골·가슴' => [
            '정면' => 'Camera straight in front, wide symmetric framing: BOTH collarbones and the whole upper chest from shoulder to shoulder, from the base of the neck to just below the collarbones.',
            '한쪽' => 'Body turned about 45 degrees away from the camera, close crop on ONE side only: one collarbone and the upper chest on that side, from the neck to that shoulder. The other collarbone is out of frame. This must look clearly different from a symmetric front view.',
            '가슴 위' => 'Camera straight in front, close crop of the UPPER CHEST: the bare skin between the collarbones and the top of the breasts (the upper sternum area) is the center of the frame, from the collarbones down to where the top fabric begins.',
            '가슴 아래' => 'Camera straight in front, close crop of the area JUST BELOW the chest: the bare skin of the underbust, the lower sternum (solar plexus) and the front ribs is the center of the frame, from the bottom edge of the top down to the upper stomach.',
        ],
        '등' => [
            '전체' => 'Camera directly behind: the whole back from the shoulders down to the waist.',
            '상단' => 'Camera directly behind: the upper back between and across the shoulder blades.',
            '하단' => 'Camera directly behind: the lower back just above the waistband.',
        ],
        '옆구리' => [
            '왼쪽' => 'Camera at the person\'s LEFT side. The person\'s LEFT arm is raised above the head to expose the LEFT side of the ribcage, which is the center of the frame. The person faces toward the RIGHT edge of the image.',
            '오른쪽' => 'Camera at the person\'s RIGHT side. The person\'s RIGHT arm is raised above the head to expose the RIGHT side of the ribcage, which is the center of the frame. The person faces toward the LEFT edge of the image.',
        ],
        '허벅지' => [
            '앞' => 'Camera directly in front: the FRONT of ONE thigh from the hip to the knee.',
            '옆' => 'Camera at the person\'s SIDE at 90 degrees (profile view): the OUTER side of ONE thigh from the hip to the knee; the knee points toward the side edge of the image. This must clearly be a side view, not a front view.',
            '뒤' => 'Camera directly behind: the BACK of ONE thigh (hamstring) from just below the buttock to the back of the knee.',
        ],
        '종아리' => [
            '뒤' => 'LOWER LEG ONLY: camera directly behind, the BACK of the calf muscle from the back of the knee down to the ankle. The thigh is NOT in frame.',
            '옆' => 'LOWER LEG ONLY: camera at the side, the OUTER side of the calf and shin from the knee down to the ankle. The thigh is NOT in frame.',
        ],
        '발목·발등' => [
            '발등' => 'The TOP of ONE bare foot and the front of the ankle seen from above, toes included.',
            '안쪽' => 'The INNER side of ONE bare ankle (the side facing the other foot, with the inner ankle bone) and the arch of the foot.',
            '바깥쪽' => 'The OUTER side of ONE bare ankle (with the outer ankle bone) and the outer edge of the foot.',
        ],
        '목' => [
            '뒷목' => 'Camera behind: the BACK of the neck from the hairline to the top of the shoulders, hair tied up.',
            '옆목' => 'Camera at the side: the SIDE of the neck from below the ear to the shoulder, head turned slightly away.',
        ],
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
        // 피팅 · 추출 · 필러 · 커버업 공통 하루 횟수. 실패와 기기에서 만든 결과(cost_units 0)는 세지 않는다.
        $limit = (int) config('starttoo.fit_daily_limit', 3);
        if ($limit <= 0) return 999; // 제한 없음(.env 0)
        $used = (int) $this->db()->table('starttoo_fit_results')->where('user_id', $viewer)
            ->where('created_at', '>=', today())->where('status', '<>', 'failed')->sum('cost_units');
        return max(0, $limit - $used);
    }

    /** 필러는 3안(AI 3번)을 1회로 세는 대신 하루 횟수를 따로 둔다(STARTTOO_FIT_FILL_DAILY_LIMIT, 0 = 제한 없음) */
    public function fillUsedToday(int $viewer): bool
    {
        $limit = (int) config('starttoo.fit_fill_daily_limit', 1);
        if ($limit <= 0) return false;
        return $this->db()->table('starttoo_fit_results')->where('user_id', $viewer)->where('type', 'fill')
            ->where('created_at', '>=', today())->where('status', '<>', 'failed')->count() >= $limit;
    }

    /** FIT-01 피팅 메인: 남은 횟수 · 최근 결과 */
    public function home(int $viewer): array
    {
        return [
            'enabled' => (bool) config('starttoo.fit_enabled', true) && app(NanoBanana::class)->configured(),
            'daily_limit' => (int) config('starttoo.fit_daily_limit', 3), // 0 = 제한 없음
            'fill_daily_limit' => (int) config('starttoo.fit_fill_daily_limit', 1),
            'remaining' => $this->remaining($viewer),
            'fill_used' => $this->fillUsedToday($viewer),
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
        $lock = Cache::lock('starttoo:fit-model:'.$id, 200);
        if (!$lock->get()) return;
        try {
            $m = $this->db()->table('starttoo_fit_models')->where('id', $id)->first();
            if (!$m || $m->status !== 'pending') return;
            $this->db()->table('starttoo_fit_models')->where('id', $id)->update(['updated_at' => now()]);
            $part = self::PARTS[$m->part] ?? null;
            if (!$part) return;
            $female = ($m->gender ?? 'male') === 'female';
            $wear = self::WEAR_FACE[$m->part][$m->face][$female ? 0 : 1]
                ?? self::WEAR[$m->part][$female ? 0 : 1]
                ?? 'wearing simple black clothing that does not cover the area';
            $prompt = sprintf(
                'A tasteful, non-sexual fitness catalog style photograph for a tattoo placement preview app. '
                .'A %s\'s %s (%s), clearly %s body, %s skin tone, %s body build, %s, %s. '
                .'The model is '.$wear.'. '
                .'CAMERA AND POSE (must follow exactly): '.(self::POSES[$m->part][$m->face] ?? '').' '
                // 옷은 다른 곳만 가린다: 문신 놓을 부위는 반드시 맨살(소매 · 끈 · 천이 덮으면 안 됨)
                .'BARE SKIN: the '.$part['en'].' itself must be completely bare skin over its whole visible area — no sleeve, strap, fabric, bandage or accessory may cover any part of it. Clothing only covers other parts of the body. '
                .'The skin on that area is clean with absolutely no tattoos, marks or jewelry. The %s fills most of the frame and is clearly visible for placing a tattoo. '
                .'No face visible, plain dark neutral background, natural skin texture and pores. '
                .'Nipples and private areas are always fully covered by clothing and never visible. '
                .'No text, no watermark, no logo. Vertical 3:4 portrait framing.',
                self::GENDERS[$m->gender ?? 'male'] ?? 'person', $part['en'], $part['faces'][$m->face] ?? $m->face,
                $female ? 'feminine, smooth, slender' : 'masculine', self::TONES[(int) $m->tone] ?? 'medium',
                self::BUILDS[$m->build] ?? 'average', self::KIND_EN[$m->kind] ?? 'straight-on view',
                self::LIGHT_EN[$m->light] ?? 'soft natural daylight', $part['en'],
            );
            try {
                $bytes = app(NanoBanana::class)->generate($prompt, [], '3:4');
                $path = app(ArtworkPhotos::class)->storeBytes($bytes);
                // 사진이 바뀌었으니 예전 사진으로 만든 피부 영역은 버린다(다음에 열 때 새로 만든다)
                $this->db()->table('starttoo_fit_models')->where('id', $id)->update(['image_path' => $path, 'skin_path' => null, 'status' => 'ready', 'updated_at' => now()]);
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

    // ── 피부 영역 마스크 ──────────────────────────

    /** 배치 화면용: 내 사진의 피부 마스크(투명 PNG base64). 하루 AI 횟수에 세지 않는다. */
    public function skinForPhoto(int $viewer, ?UploadedFile $photo, bool $retry = false): array
    {
        $this->assertEnabled();
        if (!$photo || Validator::make(['photo' => $photo], ['photo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192']])->fails()) {
            $this->fail(422, 'INVALID_FIT', '사진을 다시 선택해주세요.');
        }
        @ini_set('memory_limit', '512M');
        // 회원당 하루 호출 수 제한(이미지 모델로 마스크를 만들면 1장 비용이 든다). 넘으면 자르지 않고 합성.
        $limit = (int) config('starttoo.fit_skin_daily_limit', 10);
        $key = 'starttoo:fit-skin:'.$viewer.':'.today()->toDateString();
        $count = (int) Cache::get($key, 0);
        if ($limit > 0 && $count >= $limit) $this->fail(429, 'FIT_SKIN_LIMIT', '오늘 피부 영역 찾기를 모두 사용했어요. 도안이 옷에 걸치지 않게 놓아주세요.');
        Cache::put($key, $count + 1, now()->endOfDay());
        $bytes = (string) $photo->get();
        // 다시 찾기 = 앞의 결과가 틀렸다는 뜻 → 더 꼼꼼한 방식(세 판정 다수결)
        $bw = $retry ? $this->buildSkinMaskStrict($bytes) : $this->buildSkinMask($bytes);
        return ['mask' => $bw ? base64_encode($this->alphaMask($bw)) : null];
    }

    /**
     * 배치 화면용: 가상 모델 사진의 피부 마스크(한 번 만들어 저장).
     * retry = 저장된 마스크가 틀렸다는 뜻 → 꼼꼼한 방식으로 다시 만들어 바꿔 저장한다(모두에게 적용).
     */
    public function skinForModel(int $viewer, int $id, bool $retry = false): array
    {
        $m = $this->db()->table('starttoo_fit_models')->where('id', $id)->where('status', 'ready')->first();
        if (!$m) $this->fail(404, 'FIT_NOT_FOUND', '가상 모델 사진을 찾을 수 없어요.');
        if ($retry) {
            $this->assertEnabled();
            // 다시 찾기도 비용이 들어 내 사진과 같은 하루 횟수에서 센다
            $limit = (int) config('starttoo.fit_skin_daily_limit', 10);
            $key = 'starttoo:fit-skin:'.$viewer.':'.today()->toDateString();
            $count = (int) Cache::get($key, 0);
            if ($limit > 0 && $count >= $limit) $this->fail(429, 'FIT_SKIN_LIMIT', '오늘 피부 영역 찾기를 모두 사용했어요. 도안이 옷에 걸치지 않게 놓아주세요.');
            Cache::put($key, $count + 1, now()->endOfDay());
            @ini_set('memory_limit', '512M');
            $bw = $this->buildSkinMaskStrict((string) Storage::disk('starttoo')->get($m->image_path));
            if ($bw) {
                $path = $this->storePng($bw);
                $this->db()->table('starttoo_fit_models')->where('id', $m->id)->update(['skin_path' => $path]);
                return ['mask' => base64_encode($this->alphaMask($bw))];
            }
            return ['mask' => null];
        }
        $path = $this->modelSkinPath($m);
        return ['mask' => $path ? base64_encode($this->alphaMask((string) Storage::disk('starttoo')->get($path))) : null];
    }

    /**
     * 운영: 가상 모델 피부 영역을 미리 만들어 둔다(앱에서 처음 열 때 오래 기다리지 않게).
     * 영역이 없는 사진만. [$redo]면 모두 다시 만든다. 결과: [만듦, 실패]
     */
    public function buildModelSkins(bool $redo = false, ?callable $progress = null): array
    {
        @ini_set('memory_limit', '512M');
        $q = $this->db()->table('starttoo_fit_models')->where('status', 'ready')->where('is_active', true)->orderBy('id');
        if (!$redo) $q->whereNull('skin_path');
        $made = $failed = 0;
        foreach ($q->get() as $m) {
            if ($redo) $m->skin_path = null;
            $path = $this->modelSkinPath($m);
            $path ? $made++ : $failed++;
            if ($progress) $progress($m, $path !== null);
        }
        return [$made, $failed];
    }

    private function modelSkinPath(object $m): ?string
    {
        if ($m->skin_path) return $m->skin_path;
        $this->assertEnabled();
        // 가상 모델은 한 번 만들어 모두가 쓰니 처음부터 꼼꼼한 방식(세 판정 다수결)으로 만든다.
        // 분할만 쓰면 영역 상자 경계에서 일자로 잘리는 일이 있다(등 · 어깨).
        @ini_set('memory_limit', '512M');
        $bw = $this->buildSkinMaskStrict((string) Storage::disk('starttoo')->get($m->image_path));
        if (!$bw) return null;
        $path = $this->storePng($bw);
        $this->db()->table('starttoo_fit_models')->where('id', $m->id)->update(['skin_path' => $path]);
        return $path;
    }

    /**
     * 다시 찾기 · 가상 모델용 꼼꼼한 피부 마스크. 두 AI 판정을 칸마다 합친다.
     *  ① Gemini 분할(텍스트 모델) ② 나노바나나 마스크 그림(이미지 모델)
     * 둘 다 피부면 피부, 하나만 피부라고 하면 「확실히 피부가 아닌 색」(흰 · 회색 옷, 푸른 옷)이 아닐 때만 피부.
     * (색으로 「피부 같은지」를 묻지 않는다 — 그늘진 피부가 빠진다)
     * 사진도 더 크게(1024px) 보내 경계를 정확히 잡는다. 둘 다 실패하면 null(자르지 않음).
     */
    private function buildSkinMaskStrict(string $photo): ?string
    {
        $src = $this->fitImage($photo, 1024);
        if (!$src) return null;
        $w = imagesx($src);
        $h = imagesy($src);
        ob_start();
        imagejpeg($src, null, 90);
        $jpeg = (string) ob_get_clean();
        // ① 분할 · ② 이미지 마스크(색 검증 없이 AI 판단 그대로 — 색 검증은 ③이 따로 한다)
        $seg = $this->decodeMask($this->skinMaskBySegment($jpeg, $w, $h), $w, $h);
        $img = $this->decodeMask($this->skinMaskByImage($jpeg, $w, $h, false, true), $w, $h);
        if (!$seg && !$img) {
            imagedestroy($src);
            return null;
        }
        $out = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($out, 255, 255, 255);
        $count = 0;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $o = imagecolorat($src, $x, $y);
                $r = ($o >> 16) & 0xFF;
                $g = ($o >> 8) & 0xFF;
                $b = $o & 0xFF;
                // AI 두 판정: 둘 다 피부면 피부. 하나만 피부라고 하면 「확실히 피부가 아닌 색」(흰 · 회색 옷,
                // 푸른 옷)이 아닐 때만 피부. 그늘진 피부는 색이 옅고 어두워 「피부색 범위」 검사로는 빠지므로
                // 색은 걸러내는 데(거부)만 쓴다.
                $a = $seg && (imagecolorat($seg, $x, $y) & 0xFF) > 127;
                $c = $img && (imagecolorat($img, $x, $y) & 0xFF) > 127;
                if (($a && $c) || (($a || $c) && !$this->clearlyNotSkin($r, $g, $b))) {
                    imagesetpixel($out, $x, $y, $white);
                    $count++;
                }
            }
        }
        imagedestroy($src);
        if ($seg) imagedestroy($seg);
        if ($img) imagedestroy($img);
        $ratio = $count / max(1, $w * $h);
        if ($ratio < 0.01 || $ratio > 0.97) {
            imagedestroy($out);
            report(new \RuntimeException('Fit skin mask (retry) failed: ratio '.round($ratio, 2)));
            return null;
        }
        ob_start();
        imagepng($out);
        imagedestroy($out);
        // 그림자 진 피부 · 피부 안쪽 구멍을 형태로 메우고 경계를 부드럽게
        return $this->refineSkinMask((string) ob_get_clean(), $jpeg);
    }

    /**
     * 확실히 피부가 아닌 색: 밝은 무채색(흰 · 밝은 회색 옷, 흰 배경) 또는 푸른 기가 붉은 기보다 뚜렷한 색.
     * 그늘진 피부(어둡고 색이 옅음)는 여기에 걸리지 않는다.
     */
    private function clearlyNotSkin(int $r, int $g, int $b): bool
    {
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $brightNeutral = $max >= 150 && ($max - $min) < 14;
        $bluish = $b > $r + 12;
        return $brightNeutral || $bluish;
    }

    /** 흑백 마스크 PNG → 사진 크기 GD 이미지(없으면 null) */
    private function decodeMask(?string $png, int $w, int $h): ?\GdImage
    {
        return $png ? $this->resizedTo($png, $w, $h) : null;
    }

    /**
     * 사진 → 피부 영역 흑백 PNG(흰색 = 맨살). Gemini 분할이 준 영역들을 박스 위치에 합친다.
     * 실패하거나 피부가 없으면 null(자르지 않음).
     */
    private function buildSkinMask(string $photo): ?string
    {
        // 마스크는 대략적인 경계면 충분해 작게 보낸다(빠르고 저렴)
        $src = $this->fitImage($photo, 640);
        if (!$src) return null;
        $w = imagesx($src);
        $h = imagesy($src);
        ob_start();
        imagejpeg($src, null, 85);
        $jpeg = (string) ob_get_clean();
        imagedestroy($src);
        // 텍스트 모델이 영역을 주지 않으면(새 모델은 마스크를 안 줌) 이미지 모델에게 마스크 그림을 받는다
        $bw = $this->skinMaskBySegment($jpeg, $w, $h) ?? $this->skinMaskByImage($jpeg, $w, $h);
        // 그림자 진 피부 · 피부 안쪽 구멍을 형태로 메운다
        return $bw ? $this->refineSkinMask($bw, $jpeg) : null;
    }

    /**
     * 피부 마스크 다듬기(색만이 아니라 형태로): AI가 그림자 진 피부를 빼먹는 경우를 메운다.
     *  ① 찾은 피부의 색 성분(Cb · Cr)을 배운다. 그림자는 밝기만 바뀌고 색 성분은 거의 그대로다.
     *  ② 피부 경계에서 바깥으로 넓혀 가며, 색 성분이 피부와 같으면(어두워도) 피부로 넣는다.
     *     단 바로 옆 칸과 밝기 · 색이 부드럽게 이어질 때만(뚜렷한 경계 = 몸의 윤곽은 넘지 않음).
     *     멀리까지 번지지 않게 경계에서 짧은 거리(사진 긴 변의 10%)까지만 넓힌다.
     *     흰 옷 · 회색 옷처럼 색 성분이 다른 곳은 넘어가지 않는다.
     *  ③ 피부로 둘러싸인 작은 구멍(그림자 · 하이라이트 · 문신 자리)은 피부로 채운다.
     *  ④ 경계를 부드럽게 한다.
     * 실패하면 받은 마스크를 그대로 돌려준다.
     */
    private function refineSkinMask(string $bw, string $jpeg): string
    {
        try {
            $src = @imagecreatefromstring($jpeg);
            if (!$src) return $bw;
            $w = imagesx($src);
            $h = imagesy($src);
            $mask = $this->resizedTo($bw, $w, $h);
            if (!$mask) {
                imagedestroy($src);
                return $bw;
            }
            $n = $w * $h;
            // 칸마다 피부 여부 · 색 성분을 바이트 문자열로(메모리 절약)
            $skin = str_repeat("\0", $n);
            $cbS = str_repeat("\0", $n);
            $crS = str_repeat("\0", $n);
            $yS = str_repeat("\0", $n);
            $count = 0;
            $sumCb = $sumCr = $sumY = $sqCb = $sqCr = 0.0;
            for ($y = 0, $i = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++, $i++) {
                    $o = imagecolorat($src, $x, $y);
                    $r = ($o >> 16) & 0xFF;
                    $g = ($o >> 8) & 0xFF;
                    $b = $o & 0xFF;
                    $yy = (int) round(0.299 * $r + 0.587 * $g + 0.114 * $b);
                    $cb = (int) round(128 - 0.168736 * $r - 0.331264 * $g + 0.5 * $b);
                    $cr = (int) round(128 + 0.5 * $r - 0.418688 * $g - 0.081312 * $b);
                    $yS[$i] = chr(max(0, min(255, $yy)));
                    $cbS[$i] = chr(max(0, min(255, $cb)));
                    $crS[$i] = chr(max(0, min(255, $cr)));
                    if ((imagecolorat($mask, $x, $y) & 0xFF) > 127) {
                        $skin[$i] = "\1";
                        $count++;
                        $sumCb += $cb;
                        $sumCr += $cr;
                        $sumY += $yy;
                        $sqCb += $cb * $cb;
                        $sqCr += $cr * $cr;
                    }
                }
            }
            imagedestroy($src);
            imagedestroy($mask);
            if ($count < $n * 0.005) return $bw;
            $mCb = $sumCb / $count;
            $mCr = $sumCr / $count;
            $mY = max(1.0, $sumY / $count);
            // 피부 색 성분 허용 폭(너무 넓으면 비슷한 톤의 배경 · 옷으로 번진다)
            $tCb = max(5.0, 2.0 * sqrt(max(0.0, $sqCb / $count - $mCb * $mCb)));
            $tCr = max(5.0, 2.0 * sqrt(max(0.0, $sqCr / $count - $mCr * $mCr)));

            // ② 경계에서 넓히기(너비 우선, 거리 제한)
            // 분할 결과는 영역 상자 경계에서 일자로 잘리는 일이 있어(등 · 어깨) 넉넉히 넓힌다
            $limit = max(4, (int) round(max($w, $h) * 0.10));
            $queue = [];
            $depth = [];
            for ($i = 0; $i < $n; $i++) {
                if ($skin[$i] !== "\1") continue;
                $x = $i % $w;
                if (($x > 0 && $skin[$i - 1] === "\0") || ($x < $w - 1 && $skin[$i + 1] === "\0")
                    || ($i >= $w && $skin[$i - $w] === "\0") || ($i + $w < $n && $skin[$i + $w] === "\0")) {
                    $queue[] = $i;
                    $depth[] = 0;
                }
            }
            for ($head = 0; $head < count($queue); $head++) {
                $i = $queue[$head];
                $d = $depth[$head] + 1;
                if ($d > $limit) continue;
                $x = $i % $w;
                $yi = ord($yS[$i]);
                $cbi = ord($cbS[$i]);
                $cri = ord($crS[$i]);
                foreach ([$x > 0 ? $i - 1 : -1, $x < $w - 1 ? $i + 1 : -1, $i - $w, $i + $w] as $j) {
                    if ($j < 0 || $j >= $n || $skin[$j] !== "\0") continue;
                    $yy = ord($yS[$j]);
                    if ($yy < 12 || $yy > 250) continue;   // 아주 검거나 하얗게 날아간 곳은 판단하지 않는다
                    // 형태(경계) 검사: 바로 옆 칸과 밝기 · 색이 「부드럽게」 이어질 때만 넘어간다.
                    // 그림자는 피부 위에서 서서히 어두워지지만, 몸과 배경 · 옷 사이에는 뚜렷한 경계가 있다.
                    // → 비슷한 톤의 배경이라도 경계를 넘어 번지지 않는다.
                    if (abs($yy - $yi) > 10 || abs(ord($cbS[$j]) - $cbi) > 4 || abs(ord($crS[$j]) - $cri) > 4) continue;
                    // 어두운 곳은 색 성분이 회색 쪽으로 줄어드니 허용 폭을 그만큼 넓힌다(최대 1.6배)
                    $k = 1 + min(0.6, max(0.0, ($mY - $yy) / $mY));
                    if (abs(ord($cbS[$j]) - $mCb) <= $tCb * $k && abs(ord($crS[$j]) - $mCr) <= $tCr * $k
                        && ord($crS[$j]) > 128) {          // 붉은 기는 있어야 피부(회색 · 푸른 옷 제외)
                        $skin[$j] = "\2";                  // 넓혀서 넣은 칸
                        $queue[] = $j;
                        $depth[] = $d;
                    }
                }
            }
            unset($queue, $depth, $cbS, $crS, $yS);

            // ③ 피부로 둘러싸인 작은 구멍 채우기: 바깥 테두리와 이어진 비피부는 진짜 바깥
            $outside = str_repeat("\0", $n);
            $stack = [];
            for ($x = 0; $x < $w; $x++) {
                $stack[] = $x;
                $stack[] = $n - $w + $x;
            }
            for ($y = 0; $y < $h; $y++) {
                $stack[] = $y * $w;
                $stack[] = $y * $w + $w - 1;
            }
            while ($stack) {
                $i = array_pop($stack);
                if ($i < 0 || $i >= $n || $skin[$i] !== "\0" || $outside[$i] === "\1") continue;
                $outside[$i] = "\1";
                $x = $i % $w;
                if ($x > 0) $stack[] = $i - 1;
                if ($x < $w - 1) $stack[] = $i + 1;
                $stack[] = $i - $w;
                $stack[] = $i + $w;
            }
            // 작은 구멍만 채운다(팔과 몸 사이로 보이는 배경 · 옷 같은 큰 빈틈은 그대로)
            $holeMax = (int) ($n * 0.008);
            for ($s = 0; $s < $n; $s++) {
                if ($skin[$s] !== "\0" || $outside[$s] === "\1") continue;
                // 구멍 하나를 모아 크기를 잰다
                $part = [];
                $stack = [$s];
                $outside[$s] = "\1";
                while ($stack) {
                    $i = array_pop($stack);
                    $part[] = $i;
                    $x = $i % $w;
                    foreach ([$x > 0 ? $i - 1 : -1, $x < $w - 1 ? $i + 1 : -1, $i - $w, $i + $w] as $j) {
                        if ($j < 0 || $j >= $n || $skin[$j] !== "\0" || $outside[$j] === "\1") continue;
                        $outside[$j] = "\1";
                        $stack[] = $j;
                    }
                }
                if (count($part) <= $holeMax) foreach ($part as $i) $skin[$i] = "\3";
            }
            unset($outside, $stack, $part);

            // ④ 흑백 마스크로 + 경계 부드럽게
            $out = imagecreatetruecolor($w, $h);
            $white = imagecolorallocate($out, 255, 255, 255);
            for ($i = 0; $i < $n; $i++) {
                if ($skin[$i] !== "\0") imagesetpixel($out, $i % $w, intdiv($i, $w), $white);
            }
            imagefilter($out, IMG_FILTER_GAUSSIAN_BLUR);
            ob_start();
            imagepng($out);
            imagedestroy($out);
            return (string) ob_get_clean();
        } catch (Throwable $e) {
            report(new \RuntimeException('Fit skin refine failed: '.Str::limit($e->getMessage(), 80)));
            return $bw;
        }
    }

    /** Gemini 분할(텍스트 모델)로 피부 흑백 마스크. 영역이 없거나 실패하면 null. */
    private function skinMaskBySegment(string $jpeg, int $w, int $h): ?string
    {
        try {
            $items = app(NanoBanana::class)->segment($jpeg, 'image/jpeg',
                'ALL visible bare human skin without exception (face, ears, neck, shoulders, arms, forearms, hands, fingers, chest, torso, back, legs, feet). '
                .'Do NOT include clothing, sleeves, straps, fabric, hair, jewelry or background');
        } catch (Throwable $e) {
            report(new \RuntimeException('Fit skin mask failed: '.Str::limit($e->getMessage(), 80)));
            $items = [];
        }
        if (!$items) return null;
        $out = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($out, 255, 255, 255);
        $any = false;
        foreach ($items as [$box, $png]) {
            [$y0, $x0, $y1, $x1] = $box;
            $bx0 = (int) floor($x0 / 1000 * $w);
            $by0 = (int) floor($y0 / 1000 * $h);
            $bw = (int) ceil(($x1 - $x0) / 1000 * $w);
            $bh = (int) ceil(($y1 - $y0) / 1000 * $h);
            if ($bw < 2 || $bh < 2) continue;
            $m = $this->resizedTo($png, $bw, $bh);
            if (!$m) continue;
            for ($y = 0; $y < $bh; $y++) {
                for ($x = 0; $x < $bw; $x++) {
                    if ((imagecolorat($m, $x, $y) & 0xFF) > 127) {
                        $px = $bx0 + $x;
                        $py = $by0 + $y;
                        if ($px >= 0 && $py >= 0 && $px < $w && $py < $h) {
                            imagesetpixel($out, $px, $py, $white);
                            $any = true;
                        }
                    }
                }
            }
            imagedestroy($m);
        }
        if (!$any) {
            imagedestroy($out);
            return null;
        }
        ob_start();
        imagepng($out);
        imagedestroy($out);
        return (string) ob_get_clean();
    }

    /**
     * 이미지 모델(나노바나나)로 피부 마스크 그림을 받는다: 맨살 = 흰색, 나머지 = 검정.
     * 받은 그림을 사진 크기로 맞추고 밝기로 잘라 흑백 PNG 로 만든다. 실패하면 null.
     */
    private function skinMaskByImage(string $jpeg, int $w, int $h, bool $colorCheck = true, bool $retry = false): ?string
    {
        try {
            $prompt = implode("\n", array_filter([
                // 다시 찾기: 앞에서 틀렸다고 알려 더 꼼꼼히 칠하게 한다
                $retry ? 'IMPORTANT: a previous mask of this photo was WRONG (clothing was marked as skin, or skin areas such as the face were missed). Be extremely careful and precise this time.' : null,
                'Create a segmentation MASK of this photo. Output an image with exactly the same framing, size and aspect ratio.',
                '- Paint every pixel of ALL visible BARE HUMAN SKIN pure white (#FFFFFF), with no exception: face (forehead, cheeks, nose, chin, lips area), ears, neck, shoulders, arms, hands, fingers, chest, torso, back, legs and feet. Every skin area in the photo must be included — do not leave out the face or any small skin area.',
                '- Paint EVERYTHING ELSE pure black (#000000): clothing, sleeves, straps, fabric, underwear, hair, jewelry, objects and the background.',
                '- WHITE or light-colored clothing (white shirts, collars, bras, light fabric) and white/bright backgrounds are NOT skin: they must become black too, even though they are already bright in the photo.',
                '- Flat solid colors only: no gray, no shading, no texture, no outlines, no text. The silhouette of the skin must match the photo exactly — do not move, resize or simplify it.',
                $retry ? '- Trace the exact edges where skin meets clothing (necklines, sleeve hems, waistbands, straps). Tattoos, freckles, moles and shadows on skin are still skin.' : null,
            ]));
            $bytes = app(NanoBanana::class)->generate($prompt, [[$jpeg, 'image/jpeg']], $this->aspectOf($jpeg));
            $m = $this->resizedTo($this->matchSize($bytes, $jpeg), $w, $h);
            $src = $this->resizedTo($jpeg, $w, $h);
            if (!$m || !$src) return null;
            $out = imagecreatetruecolor($w, $h);
            $white = imagecolorallocate($out, 255, 255, 255);
            $count = 0;   // AI가 피부라고 칠한 칸
            $kept = 0;    // 그중 원본 색도 피부일 수 있는 칸
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    $c = imagecolorat($m, $x, $y);
                    $lum = ((($c >> 16) & 0xFF) * 299 + (($c >> 8) & 0xFF) * 587 + ($c & 0xFF) * 114) / 1000;
                    if ($lum <= 140) continue;
                    $count++;
                    // 원본 사진의 그 자리 색이 피부일 수 있는지: 흰 옷 · 회색 · 검정 같은 무채색은 피부가 아니다.
                    // (AI가 원래 흰 셔츠를 검정으로 안 바꾸고 흰색 그대로 두는 경우를 걸러낸다)
                    $o = imagecolorat($src, $x, $y);
                    $r = ($o >> 16) & 0xFF;
                    $g = ($o >> 8) & 0xFF;
                    $b = $o & 0xFF;
                    // 「확실히 피부가 아닌 색」만 뺀다(밝은 흰 · 회색 옷, 푸른 옷).
                    // 그늘진 피부는 색이 옅고 어두워서 「피부 같은 색」 검사로는 빠져 버린다.
                    $skinLike = !$colorCheck || !$this->clearlyNotSkin($r, $g, $b);
                    if ($skinLike) {
                        imagesetpixel($out, $x, $y, $white);
                        $kept++;
                    }
                }
            }
            imagedestroy($m);
            imagedestroy($src);
            // 색 검증으로 거의 다 빠지면(흑백 사진 · 특이한 조명) 검증을 믿지 않고 자르지 않는다
            if ($count > 0 && $kept / $count < 0.25) {
                imagedestroy($out);
                report(new \RuntimeException('Fit skin mask failed: color check kept '.round($kept / $count, 2)));
                return null;
            }
            // 하이라이트 같은 작은 구멍을 메우고 경계를 부드럽게
            imagefilter($out, IMG_FILTER_GAUSSIAN_BLUR);
            imagefilter($out, IMG_FILTER_GAUSSIAN_BLUR);
            $count = $kept;
            // 거의 다 흰색이거나 거의 없으면 마스크로 못 쓴다(AI가 사진을 그대로 돌려준 경우 등)
            $ratio = $count / max(1, $w * $h);
            if ($ratio < 0.02 || $ratio > 0.97) {
                imagedestroy($out);
                report(new \RuntimeException('Fit skin mask failed: image mask ratio '.round($ratio, 2)));
                return null;
            }
            ob_start();
            imagepng($out);
            imagedestroy($out);
            return (string) ob_get_clean();
        } catch (Throwable $e) {
            report(new \RuntimeException('Fit skin mask failed: '.Str::limit($e->getMessage(), 80)));
            return null;
        }
    }

    /** 흑백 마스크 → 흰색은 불투명 흰색, 검정은 투명(앱이 dstIn 으로 자를 때 씀) */
    private function alphaMask(string $bw): string
    {
        $img = @imagecreatefromstring($bw);
        if (!$img) return $bw;
        $w = imagesx($img);
        $h = imagesy($img);
        $out = imagecreatetruecolor($w, $h);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $v = imagecolorat($img, $x, $y) & 0xFF;
                imagesetpixel($out, $x, $y, imagecolorallocatealpha($out, $v, $v, $v, 127 - (int) round($v / 255 * 127)));
            }
        }
        imagedestroy($img);
        ob_start();
        imagepng($out);
        imagedestroy($out);
        return (string) ob_get_clean();
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
        $paths = $this->db()->table('starttoo_artwork_images')->where('artwork_id', $a->id)->orderBy('position')->limit(10)->pluck('path')->all();
        $urls = app(MediaUrls::class);
        return [
            'artwork_id' => $a->id, 'title' => $a->title, 'kind' => $a->kind, 'color' => $a->color,
            'artist' => ['id' => (int) $a->user_id, 'nickname' => $a->author_nickname ?? ''],
            'image_url' => $urls->url($paths[0] ?? null, 1200),
            // 도안 사진이 여러 장이면 앱에서 입힐 사진을 고른다
            'images' => array_values(array_filter(array_map(fn ($p) => $urls->url($p, 1200), $paths))),
        ];
    }

    // ── FIT-06 → 07 합성 ────────────────────────

    /** 화면에서 고르는 값(서버가 아는 것만 저장) */
    private const OPTIONS = [
        'extract' => ['extract_type' => ['line', 'shade', 'color'], 'line' => ['가늘게', '보통', '굵게'], 'background' => ['투명', '흰색']],
        'fill' => ['style' => ['점묘 · 도트', '기하 패턴', '연기 · 구름', '잎 · 덩굴', '별 · 우주', '바람 · 파도'], 'density' => ['가볍게', '보통', '빽빽하게'], 'follow_tone' => [true, false]],
        'coverup' => ['old_color' => ['블랙', '컬러', '오래돼서 흐려짐']],
        'blend' => [],
    ];

    /**
     * AI 작업 요청(multipart). 종류별 입력:
     * - blend(가상 피팅): composite(배치) · photo(내 사진) 또는 model_id · artwork_id 또는 stencil
     * - extract(도안 추출): photo(펴서 자른 타투) · options.extract_type shade|color. local=true면 기기에서 만든 result를 바로 보관(횟수 0)
     * - fill(필러 3안): photo · mask(채울 곳 흰색) · options.style · density · follow_tone. 하루 1번
     * - coverup(커버업): photo(기존) · composite(새 도안 배치) · mask(기존 타투) · options.old_color · meta(진단)
     *
     * @param  array<string, ?UploadedFile>  $files  composite · photo · stencil · mask · result
     */
    public function create(int $viewer, array $input, array $files): array
    {
        $type = in_array($input['type'] ?? 'blend', ['blend', 'extract', 'fill', 'coverup'], true) ? ($input['type'] ?? 'blend') : 'blend';
        $local = $type === 'extract' && filter_var($input['local'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (!$local) $this->assertEnabled();
        if (Validator::make($input, [
            'request_key' => ['required', 'uuid'],
            'consent' => ['required', 'accepted'],
            'source' => ['nullable', 'in:photo,virtual'],
            'part' => ['nullable', 'string', 'max:20'],
            'face' => ['nullable', 'string', 'max:20'],
            'artwork_id' => ['nullable', 'uuid'],
            'model_id' => ['nullable', 'integer'],
            'options' => ['nullable', 'array'],
            'meta' => ['nullable', 'array'],
        ])->fails()) {
            $this->fail(422, 'INVALID_FIT', '요청 내용을 확인해주세요.');
        }
        if (!empty($input['part']) && !isset(self::PARTS[$input['part']])) $this->fail(422, 'INVALID_FIT', '부위를 다시 골라주세요.');
        $options = [];
        foreach (self::OPTIONS[$type] as $key => $allowed) {
            if (array_key_exists($key, $input['options'] ?? [])) {
                $v = $input['options'][$key];
                if (!in_array($v, $allowed, true)) $this->fail(422, 'INVALID_FIT', '옵션을 다시 골라주세요.');
                $options[$key] = $v;
            }
        }
        // 가상 피팅: 배치 화면에서 고른 농도(0.2~1). AI에 「잉크 진하기」로 알려준다.
        if ($type === 'blend' && isset($input['opacity']) && is_numeric($input['opacity'])) {
            $options['opacity'] = round(max(0.2, min(1.0, (float) $input['opacity'])), 2);
        }
        // 가상 피팅: 도안을 돌리거나 뒤집은 정도. AI는 도안을 똑바로 세우려 하므로,
        // 합성 때 사진을 돌려 도안이 바로 선 상태로 보내고 결과를 다시 돌려놓는다.
        if ($type === 'blend' && isset($input['rotation']) && is_numeric($input['rotation'])) {
            $options['rotation'] = round(fmod((float) $input['rotation'], 2 * M_PI), 4);
        }
        if ($type === 'blend' && filter_var($input['flip_x'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $options['flip_x'] = true;
        }
        // 커버업 진단(기기 계산): 숫자 · 짧은 문자만
        $meta = [];
        foreach (['size_ratio', 'dark_ratio', 'covered_ratio'] as $k) {
            if (isset($input['meta'][$k]) && is_numeric($input['meta'][$k])) $meta[$k] = round((float) $input['meta'][$k], 3);
        }
        foreach (['grade', 'size_grade', 'dark_grade', 'color_grade'] as $k) {
            if (isset($input['meta'][$k]) && in_array($input['meta'][$k], ['good', 'warn', 'hard'], true)) $meta[$k] = $input['meta'][$k];
        }

        $image = ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192', 'dimensions:max_width=4096,max_height=4096'];
        $need = match ($type) {
            'blend' => ['composite'],
            'extract' => $local ? ['result', 'photo'] : ['photo'],
            'fill' => ['photo', 'mask'],
            'coverup' => ['photo', 'composite', 'mask'],
        };
        $rules = [];
        foreach (['composite', 'photo', 'stencil', 'mask', 'result', 'skin'] as $k) $rules[$k] = [in_array($k, $need, true) ? 'required' : 'nullable', ...$image];
        if (Validator::make($files, $rules)->fails()) $this->fail(422, 'INVALID_FIT', '사진은 8MB 이하의 JPG·PNG·WebP로 선택해주세요.');

        $db = $this->db();
        if ($existing = $db->table('starttoo_fit_results')->where('user_id', $viewer)->where('request_key', $input['request_key'])->first()) {
            return $this->format($existing) + ['remaining' => $this->remaining($viewer)];
        }
        $source = $input['source'] ?? 'photo';
        $modelId = null;
        $beforePath = null;
        if ($type === 'blend' && $source === 'virtual') {
            $model = $db->table('starttoo_fit_models')->where('id', (int) ($input['model_id'] ?? 0))->where('status', 'ready')->first();
            if (!$model) $this->fail(422, 'INVALID_FIT', '가상 모델 사진을 다시 골라주세요.');
            $modelId = (int) $model->id;
            $beforePath = $model->image_path;
        } elseif (empty($files['photo'])) {
            $this->fail(422, 'INVALID_FIT', '사진을 다시 선택해주세요.');
        }
        $artworkId = $input['artwork_id'] ?? null;
        if ($artworkId) {
            $ok = app(Artworks::class)->feedQuery($viewer, 'public')->where('a.id', $artworkId)->where('a.kind', 'design')->exists();
            if (!$ok) $this->fail(409, 'FIT_STENCIL_UNAVAILABLE', '이 도안은 지금 쓸 수 없어요. 다른 도안을 골라주세요.');
        } elseif ($type === 'blend' && empty($files['stencil'])) {
            $this->fail(422, 'INVALID_FIT', '입힐 도안을 골라주세요.');
        }
        if (!$local) {
            if ($type === 'fill' && $this->fillUsedToday($viewer)) {
                $this->fail(429, 'FIT_FILL_LIMIT', '타투 필러는 하루 '.(int) config('starttoo.fit_fill_daily_limit', 1).'번 만들 수 있어요. 내일 다시 이용해주세요.');
            }
            if ($this->remaining($viewer) <= 0) {
                $this->fail(429, 'FIT_DAILY_LIMIT', '오늘 AI 횟수를 모두 사용했어요. 내일 다시 이용해주세요.');
            }
        }

        $photos = app(ArtworkPhotos::class);
        $saved = [];
        try {
            // AI 입력: 피팅 · 커버업은 배치 이미지, 추출 · 필러는 사진 원본
            if (in_array($type, ['blend', 'coverup'], true)) $saved['input'] = $this->storeTemp($files['composite']);
            elseif (!$local) $saved['input'] = $this->storeTemp($files['photo']);
            if (!empty($files['stencil'])) $saved['stencil'] = $this->storeTemp($files['stencil']);
            // 마스크는 결과 화면(비침 보기)에서도 쓰므로 보관한다(PNG 그대로)
            if (!empty($files['mask'])) $saved['mask'] = $this->storeKeep($files['mask']);
            // 배치 화면에서 받은 피부 마스크(결과도 이 영역 밖은 원본으로 되돌린다)
            if (!empty($files['skin'])) $saved['skin'] = $this->storeKeep($files['skin']);
            if (!empty($files['photo'])) $saved['before'] = $photos->storeBytes((string) $files['photo']->get());
            if ($local) $saved['result'] = $this->storeKeep($files['result']);
        } catch (Throwable $e) {
            $photos->delete(array_values($saved));
            throw $e;
        }
        $id = (string) Str::uuid();
        try {
            $db->table('starttoo_fit_results')->insert([
                'id' => $id, 'user_id' => $viewer, 'request_key' => $input['request_key'], 'type' => $type, 'artwork_id' => $artworkId,
                'model_id' => $modelId, 'source' => $source, 'part' => $input['part'] ?? null, 'face' => $input['face'] ?? null,
                'input_path' => $saved['input'] ?? null, 'stencil_path' => $saved['stencil'] ?? null, 'mask_path' => $saved['mask'] ?? null,
                'skin_path' => $saved['skin'] ?? null,
                'before_path' => $saved['before'] ?? $beforePath, 'result_path' => $saved['result'] ?? null,
                'options' => $options ? json_encode($options, JSON_UNESCAPED_UNICODE) : null,
                'meta' => $meta ? json_encode($meta) : null, 'cost_units' => $local ? 0 : 1,
                'status' => $local ? 'ready' : 'processing',
                'consent_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (QueryException $e) {
            $photos->delete(array_values($saved));
            if ($existing = $db->table('starttoo_fit_results')->where('user_id', $viewer)->where('request_key', $input['request_key'])->first()) {
                return $this->format($existing) + ['remaining' => $this->remaining($viewer)];
            }
            throw $e;
        }
        if (!$local) {
            try {
                \App\Jobs\ProcessStarttooFitResult::dispatch($id);
            } catch (Throwable $e) {
                // 큐에 넣지 못해도 결과 조회가 직접 처리한다.
                report($e);
            }
        }
        return $this->format($db->table('starttoo_fit_results')->where('id', $id)->first()) + ['remaining' => $this->remaining($viewer)];
    }

    /** PNG(투명) 그대로 보관(마스크 · 기기에서 만든 추출 도안). 변환하지 않는다. */
    private function storeKeep(UploadedFile $file): string
    {
        $bytes = (string) $file->get();
        $info = @getimagesizefromstring($bytes);
        $ext = ($info['mime'] ?? '') === 'image/png' ? 'png' : 'jpg';
        $path = 'artwork/starttoo/fit/'.now('Asia/Seoul')->format('Y/m/d').'/'.Str::uuid().'.'.$ext;
        if (!Storage::disk('starttoo')->put($path, $bytes, ['visibility' => 'private', 'ContentType' => $ext === 'png' ? 'image/png' : 'image/jpeg'])) {
            $this->fail(503, 'FIT_FAILED', '사진을 저장하지 못했어요. 다시 시도해주세요.');
        }
        return $path;
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
        // 사진 여러 장 · AI 응답(base64)을 함께 다뤄 기본 128MB로는 모자랄 수 있다
        @ini_set('memory_limit', '512M');
        // 상위 모델은 오래 걸려(최대 140초) 잠금을 넉넉히 둔다
        $lock = Cache::lock('starttoo:fit-result:'.$id, 200);
        if (!$lock->get()) return;
        try {
            $r = $this->db()->table('starttoo_fit_results')->where('id', $id)->first();
            if (!$r || $r->status !== 'processing') return;
            $this->db()->table('starttoo_fit_results')->where('id', $id)->update(['updated_at' => now()]);
            try {
                if (($r->type ?? 'blend') === 'fill') {
                    // 필러는 한 번에 1안씩(큐 작업 시간 제한). 3안이 모이기 전에는 다음 작업을 다시 넣는다.
                    $paths = json_decode((string) $r->result_paths, true) ?: [];
                    $paths[] = $this->runFillOne($r, count($paths));
                    if (count($paths) < 3) {
                        $this->db()->table('starttoo_fit_results')->where('id', $id)->update(['result_paths' => json_encode($paths), 'result_path' => $paths[0], 'updated_at' => now()]);
                        try { \App\Jobs\ProcessStarttooFitResult::dispatch($id); } catch (Throwable $e) { report($e); }
                        return;
                    }
                    $values = ['result_path' => $paths[0], 'result_paths' => json_encode($paths)];
                } else {
                    $values = match ($r->type ?? 'blend') {
                        'extract' => $this->runExtract($r),
                        'coverup' => $this->runCoverup($r),
                        default => $this->runBlend($r),
                    };
                }
                $this->db()->table('starttoo_fit_results')->where('id', $id)->update($values + ['status' => 'ready', 'input_path' => null, 'stencil_path' => null, 'updated_at' => now()]);
            } catch (Throwable $e) {
                $code = str_starts_with($e->getMessage(), 'FIT_AI_NO_IMAGE') ? 'blocked' : 'ai_failed';
                report(new \RuntimeException('Fit result failed: '.Str::limit($e->getMessage(), 80)));
                $this->db()->table('starttoo_fit_results')->where('id', $id)->update(['status' => 'failed', 'error' => $code, 'input_path' => null, 'stencil_path' => null, 'updated_at' => now()]);
            }
            $this->deleteFiles(array_filter([$r->input_path, $r->stencil_path]));
            $this->announce($id);
        } finally {
            $lock->release();
        }
    }

    /** 큐 작업이 강제 종료(메모리 · 시간 초과)되면 처리 중으로 남지 않게 실패로 바꾸고 알린다(횟수 차감 없음). */
    public function markFailed(string $id): void
    {
        $r = $this->db()->table('starttoo_fit_results')->where('id', $id)->first();
        if (!$r || $r->status !== 'processing') return;
        $this->db()->table('starttoo_fit_results')->where('id', $id)->update(['status' => 'failed', 'error' => 'ai_failed', 'input_path' => null, 'stencil_path' => null, 'updated_at' => now()]);
        $this->deleteFiles(array_filter([$r->input_path, $r->stencil_path]));
        $this->announce($id);
    }

    /**
     * 끝난 작업 알림: 앱이 열려 있으면 소켓(개인 채널), 앱을 나갔으면 푸시(안내 알림 fit_result).
     * 소켓은 항상 보내고, 푸시는 기기가 알아서 앞에 떠 있을 땐 앱이 무시한다.
     */
    private function announce(string $id): void
    {
        $r = $this->db()->table('starttoo_fit_results')->where('id', $id)->first();
        if (!$r || $r->status === 'processing') return;
        try {
            event(new \App\Events\StarttooFitChanged((int) $r->user_id, $r->id, $r->type ?? 'blend', $r->status, $this->remaining((int) $r->user_id)));
        } catch (Throwable $e) {
            report($e);
        }
        try {
            app(Notifications::class)->notice((int) $r->user_id, 'fit_result', 'fit_result', $r->id, ($r->type ?? 'blend').':'.$r->status);
        } catch (Throwable $e) {
            report($e);
        }
    }

    // ── 종류별 AI 처리 ───────────────────────────

    private function where(object $r): string
    {
        if (!$r->part || !isset(self::PARTS[$r->part])) return '';
        $p = self::PARTS[$r->part];
        return ' on the '.$p['en'].($r->face && isset($p['faces'][$r->face]) ? ' ('.$p['faces'][$r->face].')' : '');
    }

    private function read(?string $path): string
    {
        $bytes = $path ? (string) Storage::disk('starttoo')->get($path) : '';
        if ($bytes === '') throw new \RuntimeException('FIT_INPUT_MISSING');
        return $bytes;
    }

    /** 가상 피팅: 배치 이미지 + 원본 도안 → 실제 시술처럼 */
    private function runBlend(object $r): array
    {
        $composite = $this->read($r->input_path);
        $stencilBytes = $r->stencil_path ? $this->read($r->stencil_path) : $this->artworkImage($r->artwork_id);
        if ($stencilBytes === '') throw new \RuntimeException('FIT_INPUT_MISSING');
        $opts = json_decode((string) ($r->options ?? ''), true) ?: [];
        // 도안을 돌리거나 뒤집어 놓았으면, AI가 「똑바로 세우지」 못하게 사진째 돌려서
        // 도안이 바로 선 상태로 보내고, 받은 결과를 다시 원래 방향으로 돌려놓는다.
        // (90° 단위로 가장 가까운 만큼 + 좌우 반전. 남는 작은 기울기는 그대로)
        $quarter = (int) round(((float) ($opts['rotation'] ?? 0)) / (M_PI / 2));
        $quarter = (($quarter % 4) + 4) % 4;
        $flip = (bool) ($opts['flip_x'] ?? false);
        $turned = $quarter !== 0 || $flip;
        $aiComposite = $turned ? $this->orient($composite, $quarter, $flip, false) : $composite;
        $aiStencil = $turned ? $this->orient($stencilBytes, $quarter, $flip, false) : $stencilBytes;
        // 농도(배치 화면 슬라이더) → 잉크 진하기 지시. 값이 없으면(이전 앱) 스티커 진하기를 따르라고만 한다.
        $opacity = $opts['opacity'] ?? null;
        $ink = match (true) {
            $opacity === null => 'Match the darkness of the sticker in image 1.',
            $opacity >= 0.85 => 'INK DARKNESS: about '.round($opacity * 100).'% — solid, fully saturated ink, like a fresh bold tattoo that has healed well.',
            $opacity >= 0.6 => 'INK DARKNESS: about '.round($opacity * 100).'% — medium strength: clearly visible but slightly softened, like a tattoo healed for a year.',
            $opacity >= 0.4 => 'INK DARKNESS: about '.round($opacity * 100).'% — light and soft: noticeably faded, the skin tone shows through the ink.',
            default => 'INK DARKNESS: about '.round($opacity * 100).'% — very light and faint, like an old, heavily faded tattoo; do not make it dark or bold.',
        };
        // 역할 → 입력 → 규칙(번호) → 다시 한 번 핵심. 가장 중요한 「피부에만 · 위치 그대로 · 나머지 그대로」를 처음과 끝에 둔다.
        $prompt = implode("\n", [
            'You are a professional photo retoucher adding a realistic tattoo to an existing photo. This is a small LOCAL EDIT of image 1, not a new image.',
            '',
            'INPUTS',
            '- Image 1: a photo of a body'.$this->where($r).'. A tattoo design is roughly pasted on it as a flat, semi-transparent sticker. The sticker shows exactly WHERE the tattoo goes, how BIG it is and its ANGLE.',
            '- Image 2: the tattoo design, already rotated and mirrored into the EXACT orientation the user chose (reference for its lines, shapes, shading and colors). It may be upside down, sideways or mirrored on purpose.',
            '',
            'TASK: turn the sticker into a real, fully healed tattoo inked in the skin.',
            '',
            'RULES (all are mandatory)',
            '1. SKIN ONLY. Ink can exist only on bare skin. Where the sticker covers clothing, straps, fabric, hair, background or goes past the edge of the body, delete that part completely and show the original clothing/background there. Cut the design off cleanly at the skin boundary.',
            '2. SAME PLACE, SAME SIZE. Keep the tattoo at exactly the sticker\'s position and scale. Do not move, shrink, enlarge, re-center or re-frame it to make it "fit" — cropping at the skin edge is the only allowed change.',
            '2b. SAME ORIENTATION. Never rotate, flip, mirror or turn the design upright. If the sticker (and image 2) is upside down, sideways or mirrored, the tattoo must stay upside down, sideways or mirrored exactly like that — top stays top, left stays left as shown in image 1. Do not "correct" the orientation so that faces, animals or text look upright.',
            '3. SAME DESIGN. Reproduce image 2 faithfully: same lines, details, shading and colors. Do not redesign, simplify, add or remove elements. Ignore the white/paper background of the design — only the ink goes on the skin.',
            '3b. NO BACKGROUND RESIDUE. The sticker may leave a faint rectangle, a slightly darker/lighter box, a hazy tint, smudges or semi-transparent paper color around the artwork. Remove all of that completely: the skin around and between the ink lines must look like the original untouched skin, with no visible box, patch, veil or edge of the sticker. Only the actual drawn ink (lines, shading, color) remains.',
            '4. REALISTIC INK. Bend the ink around the curve of the arm/leg/body, follow the skin\'s lighting, shadows, pores and texture. The ink sits under the skin: no sticker edges, outlines, white halo, glow or shine.',
            '4b. '.$ink.' Keep this strength for the whole design; do not darken, boost contrast or re-ink it beyond this.',
            '5. NOTHING ELSE CHANGES. The person, face, body shape, muscles, skin tone, hair, clothing, background, lighting, camera angle, framing and image size stay exactly as in image 1.',
            '5b. DO NOT DRAW ANY BODY. Never add, redraw or invent arms, hands, legs or any body part, and never copy the body or skin from image 2 into the photo. The only body in the result is the one already in image 1.',
            '6. No text, no watermark, no logo, no extra tattoos.',
            // 사진을 돌려 보낸 경우: 사진 방향을 바로잡지 말라고 알린다
            $turned ? '7. Image 1 may be rotated sideways, upside down or mirrored ON PURPOSE. Keep image 1 exactly in the orientation given: do not rotate, straighten or mirror the photo or the person.' : '',
            '',
            'REMEMBER: tattoo on bare skin only, exactly where the sticker is, in exactly the sticker\'s orientation (never turned upright), and the rest of the photo untouched.',
        ]);
        // 합성은 상위 모델(STARTTOO_GEMINI_BLEND_MODEL)이 있으면 그걸로
        $bytes = app(NanoBanana::class)->generate($prompt, [[$aiComposite, $this->mime($aiComposite)], [$aiStencil, $this->mime($aiStencil)]],
            $this->aspectOf($aiComposite), config('starttoo.gemini_blend_model') ?: null);
        // 돌려 보낸 사진이면 결과를 원래 방향으로 되돌린다(AI 결과 크기를 돌린 입력에 맞춘 뒤 되돌림)
        if ($turned) $bytes = $this->orient($this->matchSize($bytes, $aiComposite), $quarter, $flip, true);
        // 전후 비교가 맞도록 결과를 입력 사진과 같은 크기로 맞춘다(AI는 정해진 비율로만 돌려줌)
        $bytes = $this->matchSize($bytes, $composite);
        // 모델 · 사진 변조 방지: 도안을 올린 자리 밖은 원본 사진 픽셀로 되돌린다
        $bytes = $this->protectOriginal($r->before_path, $composite, $bytes);
        // 피부 밖(옷 · 끈 · 머리카락 · 배경)에 그려진 잉크는 원본으로 되돌린다
        $bytes = $this->clipToSkin($r, $bytes);
        if ($r->source === 'virtual') $bytes = $this->labelVirtual($bytes);
        return ['result_path' => app(ArtworkPhotos::class)->storeBytes($bytes)];
    }

    /**
     * 사진 방향 바꾸기(AI가 도안을 똑바로 세우지 못하게).
     * 앱은 도안을 「좌우 반전 → 시계 방향 회전」 순서로 그린다. 그래서
     *  - 보낼 때(inverse=false): 반시계로 quarter×90° 되돌린 뒤 좌우 반전 → 도안이 바로 선다.
     *  - 받을 때(inverse=true): 좌우 반전 뒤 시계로 quarter×90° → 원래 방향.
     * 실패하면 받은 그대로 돌려준다.
     */
    private function orient(string $bytes, int $quarter, bool $flip, bool $inverse): string
    {
        $img = @imagecreatefromstring($bytes);
        if (!$img) return $bytes;
        $png = $this->mime($bytes) === 'image/png';
        if ($png) {
            imagealphablending($img, false);
            imagesavealpha($img, true);
        }
        $rotate = function (\GdImage $src, int $degrees) use ($png): \GdImage {
            if ($degrees % 360 === 0) return $src;
            // GD: 양수 각도 = 반시계
            $bg = $png ? imagecolorallocatealpha($src, 0, 0, 0, 127) : 0;
            $out = imagerotate($src, $degrees, $bg);
            if (!$out) return $src;
            if ($png) {
                imagealphablending($out, false);
                imagesavealpha($out, true);
            }
            imagedestroy($src);
            return $out;
        };
        if (!$inverse) {
            $img = $rotate($img, 90 * $quarter);
            if ($flip) imageflip($img, IMG_FLIP_HORIZONTAL);
        } else {
            if ($flip) imageflip($img, IMG_FLIP_HORIZONTAL);
            $img = $rotate($img, (360 - 90 * $quarter) % 360);
        }
        ob_start();
        $png ? imagepng($img) : imagejpeg($img, null, 92);
        imagedestroy($img);
        return (string) ob_get_clean();
    }

    /**
     * 피부 마스크 밖은 원본 픽셀로. 마스크: 배치 화면에서 받은 것 → 가상 모델 저장본 → 없으면 지금 만든다.
     * 마스크를 못 만들면 자르지 않는다(결과는 그대로).
     */
    private function clipToSkin(object $r, string $generated): string
    {
        try {
            $original = $r->before_path ? (string) Storage::disk('starttoo')->get($r->before_path) : '';
            if ($original === '') return $generated;
            $skinPath = $r->skin_path;
            if (!$skinPath && $r->model_id) {
                $m = $this->db()->table('starttoo_fit_models')->where('id', $r->model_id)->first();
                if ($m) $skinPath = $this->modelSkinPath($m);
            }
            $skin = $skinPath ? (string) Storage::disk('starttoo')->get($skinPath) : $this->buildSkinMask($original);
            if (!$skin) return $generated;
            if (!$skinPath && $r->source === 'photo') {
                $path = $this->storePng($skin);
                $this->db()->table('starttoo_fit_results')->where('id', $r->id)->update(['skin_path' => $path]);
            }
            return $this->keepOutsideMask($original, $generated, $skin);
        } catch (Throwable $e) {
            report($e);
            return $generated;
        }
    }

    /**
     * 원본(도안 없는 사진)과 배치 이미지가 다른 곳 = 도안 자리. 그 둘레를 넉넉히 잡아(번짐 · 곡면 여유)
     * 그 밖은 원본 픽셀로 되돌린다. AI가 얼굴 · 몸 · 배경을 바꿔도 결과에 남지 않는다.
     */
    private function protectOriginal(?string $originalPath, string $composite, string $generated): string
    {
        try {
            $original = $originalPath ? (string) Storage::disk('starttoo')->get($originalPath) : '';
        } catch (Throwable $e) {
            $original = '';
        }
        if ($original === '') return $generated;
        $mask = $this->diffMask($original, $composite);
        return $mask === null ? $generated : $this->keepOutsideMask($original, $generated, $mask);
    }

    /** 두 사진이 다른 곳(흰색)을 작게 계산해 넓히고 부드럽게 한 마스크 PNG */
    private function diffMask(string $a, string $b): ?string
    {
        $ia = @imagecreatefromstring($a);
        $ib = @imagecreatefromstring($b);
        if (!$ia || !$ib) return null;
        try {
            $w = 320;
            $h = (int) max(1, round(imagesy($ia) * $w / imagesx($ia)));
            $sa = imagecreatetruecolor($w, $h);
            $sb = imagecreatetruecolor($w, $h);
            imagecopyresampled($sa, $ia, 0, 0, 0, 0, $w, $h, imagesx($ia), imagesy($ia));
            imagecopyresampled($sb, $ib, 0, 0, 0, 0, $w, $h, imagesx($ib), imagesy($ib));
            $m = imagecreatetruecolor($w, $h);
            $white = imagecolorallocate($m, 255, 255, 255);
            $any = false;
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    $ca = imagecolorat($sa, $x, $y);
                    $cb = imagecolorat($sb, $x, $y);
                    $d = abs((($ca >> 16) & 0xFF) - (($cb >> 16) & 0xFF)) + abs((($ca >> 8) & 0xFF) - (($cb >> 8) & 0xFF)) + abs(($ca & 0xFF) - ($cb & 0xFF));
                    if ($d > 24) {
                        imagesetpixel($m, $x, $y, $white);
                        $any = true;
                    }
                }
            }
            if (!$any) return null;
            // 넓히기(흐리게 → 조금이라도 밝으면 흰색) 두 번 + 경계 부드럽게
            for ($k = 0; $k < 2; $k++) {
                for ($i = 0; $i < 6; $i++) imagefilter($m, IMG_FILTER_GAUSSIAN_BLUR);
                for ($y = 0; $y < $h; $y++) {
                    for ($x = 0; $x < $w; $x++) {
                        if ((imagecolorat($m, $x, $y) & 0xFF) > 8) imagesetpixel($m, $x, $y, $white);
                    }
                }
            }
            for ($i = 0; $i < 3; $i++) imagefilter($m, IMG_FILTER_GAUSSIAN_BLUR);
            imagedestroy($sa);
            imagedestroy($sb);
            ob_start();
            imagepng($m);
            imagedestroy($m);
            return (string) ob_get_clean();
        } finally {
            imagedestroy($ia);
            imagedestroy($ib);
        }
    }

    /**
     * 도안 추출(라인 · 명암 · 컬러): 사진의 타투를 참고해 평면 도안으로 새로 그린다.
     * 피부 곡면 · 원근 때문에 휘거나 눌린 선은 원래 의도한 모양(곧은 선 · 둥근 원 · 대칭)으로 되돌린다.
     */
    private function runExtract(object $r): array
    {
        $photo = $this->read($r->input_path);
        $o = json_decode((string) $r->options, true) ?: [];
        $type = $o['extract_type'] ?? 'shade';
        $weight = ['가늘게' => 'thin, delicate', '굵게' => 'bold, thick'][$o['line'] ?? '보통'] ?? 'clean, even';
        $style = match ($type) {
            'line' => 'Output clean black LINE ART only: crisp, continuous black outlines of uniform '.$weight.' weight, no shading, no gray fill, no stippling — like a tattoo stencil. ',
            'color' => 'Keep the original ink colors, clean and saturated, with the same shading, and '.$weight.' outlines. ',
            default => 'Output in black ink only: crisp '.$weight.' black outlines with the original grayscale shading, stippling and dotwork. ',
        };
        $prompt = 'This is a photo of an existing tattoo on a curved body part (arm, leg or torso), possibly taken at an angle. '
            .'Use it only as a REFERENCE and redraw the same tattoo design from scratch as a perfectly FLAT, front-facing tattoo flash drawing on a pure white sheet of paper. '
            .'Undo all distortion caused by the body curvature and the camera angle: lines that should be straight must be straight, circles must be round, symmetric parts must be symmetric, and proportions must look as the artist originally drew them — not wrapped around a limb. '
            .'Remove all skin, skin texture, hair, pores, lighting, reflections, shadows and blur. '
            .'Keep the same subject, composition and details; do not add, remove or redesign elements, and fill in parts hidden by the curve consistently with the visible design. '
            .$style
            .'Center the design with a small white margin. No text, no watermark, no border, no extra decoration.';
        $bytes = app(NanoBanana::class)->generate($prompt, [[$photo, $this->mime($photo)]]);
        $png = ($o['background'] ?? '투명') === '투명' ? $this->whiteToAlpha($bytes) : $this->toPng($bytes);
        return ['result_path' => $this->storePng($png)];
    }

    /** 타투 필러 1안: 칠한 영역만 다시 그리기. 영역 밖은 원본 픽셀로 다시 덮는다. [$n]번째 안(0~2)은 구도를 바꾼다. */
    private function runFillOne(object $r, int $n): string
    {
        $photo = $this->read($r->input_path);
        $mask = $this->read($r->mask_path);
        $o = json_decode((string) $r->options, true) ?: [];
        $style = [
            '점묘 · 도트' => 'dotwork and stippling gradients', '기하 패턴' => 'geometric patterns (sacred geometry, lines and dots)',
            '연기 · 구름' => 'black-and-grey smoke and soft clouds', '잎 · 덩굴' => 'leaves, vines and botanical line work',
            '별 · 우주' => 'stars, small planets and cosmic dust', '바람 · 파도' => 'Japanese irezumi wind bars and waves',
        ][$o['style'] ?? '점묘 · 도트'] ?? 'dotwork';
        $density = ['가볍게' => 'light and airy, with plenty of skin showing', '빽빽하게' => 'dense, covering the area heavily'][$o['density'] ?? '보통'] ?? 'medium density';
        $tone = ($o['follow_tone'] ?? true) ? 'Match the line weight, ink darkness and style of the existing tattoos so it looks like one connected piece. ' : '';
        $twist = ['', 'Use a different, more open composition. ', 'Use a bolder, more flowing composition. '][$n] ?? '';
        $prompt = 'Image 1 is a photo of skin'.$this->where($r).' with existing tattoos. Image 2 is a black-and-white mask: the WHITE area is the empty skin to fill. '
            .'Add tattoo filler (background) work ONLY inside the white mask area: '.$style.', '.$density.'. '.$tone.$twist
            .'The filler must flow around and connect the existing tattoos without overlapping or changing them. '
            .'Render it as a real healed tattoo in the skin, following the body curvature and lighting. '
            .'Do not change anything outside the white area: existing tattoos, skin, background, framing and aspect ratio stay identical. No text, no watermark.';
        $bytes = app(NanoBanana::class)->generate($prompt, [[$photo, $this->mime($photo)], [$mask, 'image/png']], $this->aspectOf($photo));
        return app(ArtworkPhotos::class)->storeBytes($this->keepOutsideMask($photo, $this->matchSize($bytes, $photo), $mask));
    }

    /** 커버업: 기존 타투 위에 새 도안을 덮은 모습. 기존 선은 새 도안의 진한 부분 아래로 묻히게. */
    private function runCoverup(object $r): array
    {
        $composite = $this->read($r->input_path);
        $stencilBytes = $r->stencil_path ? (string) Storage::disk('starttoo')->get($r->stencil_path) : $this->artworkImage($r->artwork_id);
        $o = json_decode((string) $r->options, true) ?: [];
        $old = ['컬러' => 'colored', '오래돼서 흐려짐' => 'old, faded'][$o['old_color'] ?? '블랙'] ?? 'black';
        $prompt = 'Image 1 is a photo of skin'.$this->where($r).' that has an existing '.$old.' tattoo, with a NEW tattoo design roughly overlaid on top as a flat semi-transparent sticker to show the cover-up placement. '
            .($stencilBytes !== '' ? 'Image 2 is the new design artwork. ' : '')
            .'Edit image 1 to show a realistic, fully healed cover-up tattoo: the new design inked over the old tattoo at exactly the same position, size and rotation as the overlay. '
            .'The old tattoo\'s lines should be buried under the darkest, densest parts of the new design, as a skilled cover-up artist would do; where the new design is light, the old ink may faintly show through. '
            .'Keep the new design faithful (do not redesign it). Follow the skin\'s curvature, lighting and texture. '
            .'Ink exists only on bare skin: cut off any part of the design that falls on clothing, hair, the background or past the body\'s edge. '
            .'Do not change anything else: same skin, background, framing and aspect ratio. No text, no watermark.';
        $images = [[$composite, $this->mime($composite)]];
        if ($stencilBytes !== '') $images[] = [$stencilBytes, $this->mime($stencilBytes)];
        $bytes = app(NanoBanana::class)->generate($prompt, $images, $this->aspectOf($composite));
        // 새 도안 자리 밖은 원본 사진으로 되돌린다(변조 방지)
        $bytes = $this->protectOriginal($r->before_path, $composite, $this->matchSize($bytes, $composite));
        return ['result_path' => app(ArtworkPhotos::class)->storeBytes($bytes)];
    }

    /** 입력 사진과 가장 가까운 Gemini 지원 비율 */
    private function aspectOf(string $bytes): ?string
    {
        $info = @getimagesizefromstring($bytes);
        if (!$info || !$info[1]) return null;
        $r = $info[0] / $info[1];
        $best = null;
        $diff = INF;
        foreach (['1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '21:9'] as $a) {
            [$w, $h] = explode(':', $a);
            $d = abs(log($r / ($w / $h)));
            if ($d < $diff) {
                $diff = $d;
                $best = $a;
            }
        }
        return $best;
    }

    /**
     * AI 결과를 입력 사진과 같은 크기로 맞춘다. 비율이 조금 다르면 가운데 기준으로 잘라 맞춰
     * (AI는 입력을 비율에 맞춰 가운데 맞춤하므로) 전후 비교 · 마스크 위치가 어긋나지 않게 한다.
     */
    private function matchSize(string $generated, string $reference): string
    {
        $ref = @getimagesizefromstring($reference);
        $gen = @imagecreatefromstring($generated);
        if (!$ref || !$gen) return $generated;
        try {
            [$tw, $th] = $ref;
            $gw = imagesx($gen);
            $gh = imagesy($gen);
            if ($gw === $tw && $gh === $th) return $generated;
            // 결과에서 입력 비율만큼 가운데 영역을 잘라 입력 크기로 늘린다
            $target = $tw / $th;
            if ($gw / $gh > $target) {
                $cw = (int) round($gh * $target);
                $sx = (int) floor(($gw - $cw) / 2);
                $sy = 0;
                $ch = $gh;
            } else {
                $ch = (int) round($gw / $target);
                $sy = (int) floor(($gh - $ch) / 2);
                $sx = 0;
                $cw = $gw;
            }
            $out = imagecreatetruecolor($tw, $th);
            imagecopyresampled($out, $gen, 0, 0, $sx, $sy, $tw, $th, $cw, $ch);
            ob_start();
            imagepng($out);
            imagedestroy($out);
            return (string) ob_get_clean();
        } finally {
            imagedestroy($gen);
        }
    }

    // ── 이미지 처리(GD) ──────────────────────────

    /** AI 결과에서 마스크(흰색) 밖은 원본 사진 픽셀로 되돌린다. 경계는 살짝 부드럽게. */
    private function keepOutsideMask(string $original, string $generated, string $mask): string
    {
        // 큰 사진 여러 장을 한꺼번에 올리면 메모리가 모자라므로 한 장씩 만들고 바로 지운다.
        $src = $this->fitImage($original, 1600);
        if (!$src) return $generated;
        $w = imagesx($src);
        $h = imagesy($src);
        $g = $this->resizedTo($generated, $w, $h);
        $m = $this->resizedTo($mask, $w, $h);
        if (!$g || !$m) {
            imagedestroy($src);
            if ($g) imagedestroy($g);
            if ($m) imagedestroy($m);
            return $generated;
        }
        try {
            imagefilter($m, IMG_FILTER_GAUSSIAN_BLUR);
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    $a = ((imagecolorat($m, $x, $y) >> 8) & 0xFF) / 255; // 마스크 밝기(흰색 = AI)
                    if ($a >= 0.99) continue;
                    $o = imagecolorat($src, $x, $y);
                    if ($a <= 0.01) {
                        imagesetpixel($g, $x, $y, $o);
                        continue;
                    }
                    $n = imagecolorat($g, $x, $y);
                    $mix = fn ($s) => (int) round((($n >> $s) & 0xFF) * $a + (($o >> $s) & 0xFF) * (1 - $a));
                    imagesetpixel($g, $x, $y, ($mix(16) << 16) | ($mix(8) << 8) | $mix(0));
                }
            }
            ob_start();
            imagejpeg($g, null, 92);
            return (string) ob_get_clean();
        } finally {
            imagedestroy($src);
            imagedestroy($g);
            imagedestroy($m);
        }
    }

    /** 바이트 → 긴 변 [max] 이하로 줄인 이미지(원본은 바로 지운다) */
    private function fitImage(string $bytes, int $max): ?\GdImage
    {
        $img = @imagecreatefromstring($bytes);
        if (!$img) return null;
        $w = imagesx($img);
        $h = imagesy($img);
        $s = min(1, $max / max($w, $h));
        if ($s >= 1) return $img;
        $out = imagecreatetruecolor((int) round($w * $s), (int) round($h * $s));
        imagecopyresampled($out, $img, 0, 0, 0, 0, imagesx($out), imagesy($out), $w, $h);
        imagedestroy($img);
        return $out;
    }

    /** 바이트 → 정확히 w×h 로 늘린 이미지(원본은 바로 지운다) */
    private function resizedTo(string $bytes, int $w, int $h): ?\GdImage
    {
        $img = @imagecreatefromstring($bytes);
        if (!$img) return null;
        if (imagesx($img) === $w && imagesy($img) === $h) return $img;
        $out = imagecreatetruecolor($w, $h);
        imagecopyresampled($out, $img, 0, 0, 0, 0, $w, $h, imagesx($img), imagesy($img));
        imagedestroy($img);
        return $out;
    }

    /** 흰 배경 → 투명(밝을수록 투명). 선 · 명암은 남는다. */
    private function whiteToAlpha(string $bytes): string
    {
        $img = @imagecreatefromstring($bytes);
        if (!$img) return $bytes;
        try {
            $w = imagesx($img);
            $h = imagesy($img);
            $out = imagecreatetruecolor($w, $h);
            imagealphablending($out, false);
            imagesavealpha($out, true);
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    $c = imagecolorat($img, $x, $y);
                    $r = ($c >> 16) & 0xFF;
                    $g = ($c >> 8) & 0xFF;
                    $b = $c & 0xFF;
                    $max = max($r, $g, $b);
                    $min = min($r, $g, $b);
                    // 흰색에 가까울수록 투명. 채도가 있으면(컬러 잉크) 유지
                    $white = $min / 255;
                    $alpha = ($max - $min) > 40 ? 0 : (int) round(max(0, ($white - 0.55) / 0.45) * 127);
                    imagesetpixel($out, $x, $y, imagecolorallocatealpha($out, $r, $g, $b, min(127, $alpha)));
                }
            }
            ob_start();
            imagepng($out);
            imagedestroy($out);
            return (string) ob_get_clean();
        } finally {
            imagedestroy($img);
        }
    }

    private function toPng(string $bytes): string
    {
        $img = @imagecreatefromstring($bytes);
        if (!$img) return $bytes;
        ob_start();
        imagepng($img);
        imagedestroy($img);
        return (string) ob_get_clean();
    }

    private function storePng(string $png): string
    {
        $path = 'artwork/starttoo/fit/'.now('Asia/Seoul')->format('Y/m/d').'/'.Str::uuid().'.png';
        if (!Storage::disk('starttoo')->put($path, $png, ['visibility' => 'private', 'ContentType' => 'image/png'])) {
            throw new \RuntimeException('FIT_STORE_FAILED');
        }
        return $path;
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
        // (상위 모델은 큐에서 1~2분 걸릴 수 있어 3분이 지나도 그대로일 때만)
        if ($r->status === 'processing' && Carbon::parse($r->updated_at)->lt(now()->subSeconds(180))) {
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
        // 피부 마스크: 내 사진 것만 지운다(가상 모델 마스크는 공용이라 fit_models 에 있고 여기엔 없음)
        $paths = array_filter([$r->input_path, $r->stencil_path, $r->mask_path, $r->skin_path ?? null]);
        // 상담에 첨부한 결과 파일은 상담 기록과 함께 남긴다. 가상 모델 사진(before)은 공용이라 지우지 않는다.
        if (!$r->attached_at) {
            $paths[] = $r->result_path;
            foreach (json_decode((string) $r->result_paths, true) ?: [] as $p) $paths[] = $p;
        }
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
        $many = json_decode((string) ($r->result_paths ?? ''), true) ?: [];
        return [
            'id' => $r->id, 'type' => $r->type ?? 'blend', 'status' => $r->status, 'error' => $r->error, 'source' => $r->source,
            'part' => $r->part, 'face' => $r->face,
            'result_url' => $urls->url($r->result_path, 1600), 'before_url' => $urls->url($r->before_path, 1600),
            'result_urls' => array_values(array_map(fn ($p) => $urls->url($p, 1600), $many)),
            'mask_url' => $urls->url($r->mask_path ?? null, 1600),
            'options' => json_decode((string) ($r->options ?? ''), true) ?: (object) [],
            'meta' => json_decode((string) ($r->meta ?? ''), true) ?: (object) [],
            // 필러는 처리 중에도 몇 안이 만들어졌는지 보여준다
            'progress' => count($many),
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
