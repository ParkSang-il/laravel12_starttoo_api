<?php

namespace App\Http\Controllers\Admin;

use App\Services\Starttoo\ArtistAds;
use App\Support\AdminUi as Ui;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * M-08 광고 · 프리미엄 안의 작가 광고(앱 6-05 ~ 6-09에서 작가가 신청).
 * - 심사: 신청 · 노출 중 광고의 문구 · 작품 변경을 승인(바로 노출 · 반영)하거나 사유와 함께 반려
 * - 상품: 위치별 신청 받기 · 단가(예상 광고비 안내용, 결제는 Phase 6) · 자리 수
 */
class AdminArtistAdsController extends AdminBaseController
{
    /** 광고 화면(AdminController::ads)에 붙일 값 */
    public static function viewData(bool $canManage): array
    {
        $ads = app(ArtistAds::class);
        return [
            'queue' => array_map(fn ($q) => $q + [
                'approve' => route('admin.ads.artist.approve', $q['id']),
                'reject' => route('admin.ads.artist.reject', $q['id']),
                'shopHref' => Ui::publicUrl('shop', $q['artist_id']),
                'when' => $q['requested_at'] ? Ui::date($q['requested_at'], 'm.d H:i') : '-',
            ], $ads->queue()),
            'products' => $ads->config(),
            'productsAction' => route('admin.ads.artist.products'),
            // 지역별 단가 입력 칸(「전국」 제외)
            'regionOptions' => array_values(array_filter(app(\App\Services\Starttoo\Profiles::class)->options()['regions'], fn ($r) => $r['id'] !== 'all')),
            'regionPriced' => ArtistAds::REGION_PRICED,
            'canReview' => $canManage,
            // 앱 「광고 안내」 페이지 섹션(저장된 섹션 + 새로 쓸 빈 칸 2개)
            'guide' => $ads->guide(),
            'guideAction' => route('admin.ads.artist.guide'),
            'guideLimits' => ['title' => ArtistAds::GUIDE_TITLE_MAX, 'body' => ArtistAds::GUIDE_BODY_MAX, 'sections' => ArtistAds::GUIDE_MAX_SECTIONS],
        ];
    }

    /** 앱 「광고 안내」 페이지 저장 */
    public function guide(Request $request): RedirectResponse
    {
        $this->need($request, 'ads.manage');
        $data = $request->validate([
            'sections' => ['required', 'array', 'max:' . (ArtistAds::GUIDE_MAX_SECTIONS + 5)],
            'sections.*.title' => ['nullable', 'string', 'max:' . ArtistAds::GUIDE_TITLE_MAX],
            'sections.*.body' => ['nullable', 'string', 'max:' . ArtistAds::GUIDE_BODY_MAX],
            'sections.*.order' => ['nullable', 'integer', 'min:0', 'max:999'],
        ], ['sections.*.title.max' => '제목은 ' . ArtistAds::GUIDE_TITLE_MAX . '자까지 적을 수 있어요.',
            'sections.*.body.max' => '본문은 ' . ArtistAds::GUIDE_BODY_MAX . '자까지 적을 수 있어요.']);
        $before = app(ArtistAds::class)->guide();
        try {
            $after = app(ArtistAds::class)->saveGuide($data['sections'], (int) $this->admin($request)->id);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['guide' => $e->getMessage()])->withInput();
        }
        $this->audit($request, '설정', '광고 안내 페이지 저장', '광고 · 프리미엄 · 섹션 ' . count($after['sections']) . '개',
            ['before' => $before['sections'], 'after' => $after['sections']]);
        return back()->with('status', '광고 안내를 저장했어요 · 앱에서 안내를 다시 열면 바로 보여요');
    }

    public function approve(Request $request, int $id): RedirectResponse
    {
        $this->need($request, 'ads.manage');
        try {
            $ad = app(ArtistAds::class)->approve($id, (int) $this->admin($request)->id);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['ad' => $e->getMessage()]);
        }
        $this->audit($request, '설정', '작가 광고 승인', "광고 #{$id} · {$ad->advertiser} · {$ad->title}");
        return back()->with('status', "광고 #{$id}를 승인했어요 · 작가에게 알림을 보냈어요");
    }

    public function reject(Request $request, int $id): RedirectResponse
    {
        $this->need($request, 'ads.manage');
        $data = $request->validate(['reason' => ['required', 'string', 'max:300']], ['reason.required' => '반려 사유를 적어주세요.']);
        try {
            $ad = app(ArtistAds::class)->reject($id, $data['reason'], (int) $this->admin($request)->id);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['ad' => $e->getMessage()]);
        }
        $this->audit($request, '설정', '작가 광고 반려', "광고 #{$id} · {$ad->advertiser}", ['reason' => $data['reason']]);
        return back()->with('status', "광고 #{$id}를 반려했어요 · 작가에게 사유와 함께 알림을 보냈어요");
    }

    public function products(Request $request): RedirectResponse
    {
        $this->need($request, 'ads.manage');
        $rules = [];
        foreach (array_keys(ArtistAds::PRODUCTS) as $id) {
            $rules["products.$id.on"] = ['nullable', 'boolean'];
            $rules["products.$id.price"] = ['required', 'integer', 'min:0', 'max:100000000'];
            $rules["products.$id.capacity"] = ['required', 'integer', 'min:0', 'max:1000'];
        }
        // 지역별 단가(비우면 기본 단가)
        $rules['products.' . ArtistAds::REGION_PRICED . '.region_prices'] = ['nullable', 'array'];
        $rules['products.' . ArtistAds::REGION_PRICED . '.region_prices.*'] = ['nullable', 'integer', 'min:0', 'max:100000000'];
        $data = $request->validate($rules, ['*.required' => '단가 · 자리 수를 모두 적어주세요.']);
        $before = app(ArtistAds::class)->config();
        $after = app(ArtistAds::class)->saveConfig($data['products'] ?? [], (int) $this->admin($request)->id);
        $this->audit($request, '설정', '작가 광고 상품 저장', '광고 · 프리미엄', [
            'before' => array_map(fn ($p) => ['on' => $p['on'], 'price' => $p['price'], 'capacity' => $p['capacity'], 'region_prices' => $p['region_prices']], $before),
            'after' => $after,
        ]);
        return back()->with('status', '작가 광고 상품을 저장했어요 · 앱의 광고 만들기 화면에 바로 반영돼요');
    }
}
