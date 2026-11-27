<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AdvertiserWeb;
use App\Services\ProjectDatabase;
use App\Services\Starttoo\AdSales;
use App\Services\Starttoo\Advertisers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 광고주 계정(/ads/login · /ads/account). 견적서 비밀 링크나 비밀번호 설정 메일 링크(이메일 소유 확인)에서만 비밀번호를 만든다.
 * 계정에서는 내 견적 · 계약 · 문의를 한 곳에서 보고(각 견적서 · 성과 보고 링크로 이동), 회사 · 세금계산서 정보와 비밀번호를 바꾼다.
 */
class AdvertiserAccountController extends Controller
{
    private function accounts(): Advertisers { return app(Advertisers::class); }

    private function passwordRule(): array
    {
        // 영문 · 숫자를 포함 8자 이상(한국어 문구를 직접 주려고 Password 규칙 대신 정규식)
        return ['required', 'string', 'confirmed', 'min:8', 'max:72', 'regex:/^(?=.*[A-Za-z])(?=.*\d).+$/'];
    }

    private const PASSWORD_MESSAGES = [
        'password.required' => '비밀번호를 적어주세요.',
        'password.confirmed' => '비밀번호 확인이 달라요.',
        'password.min' => '비밀번호는 8자 이상으로 만들어주세요.',
        'password.max' => '비밀번호는 72자까지 쓸 수 있어요.',
        'password.regex' => '비밀번호는 영문과 숫자를 포함하여 만들어주세요.',
    ];

    public function login(Request $request): View|RedirectResponse
    {
        if ($request->attributes->get('advertiser')) return redirect()->route('advertiser.account');
        return view('advertiser.login');
    }

    public function authenticate(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:150'], 'password' => ['required', 'string', 'max:72']],
            [], ['email' => '이메일', 'password' => '비밀번호']);
        try {
            $me = $this->accounts()->attempt($data['email'], $data['password']);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['email' => $e->getMessage()])->onlyInput('email');
        }
        AdvertiserWeb::login($request, $me);
        return redirect()->intended(route('advertiser.account'));
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget(['advertiser_id', 'advertiser_seen_at', 'advertiser_pw']);
        $request->session()->regenerate();
        return redirect()->route('advertiser.login')->with('status', '로그아웃했어요.');
    }

    public function forgot(): View
    {
        return view('advertiser.forgot');
    }

    public function sendLink(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:150']], [], ['email' => '이메일']);
        $this->accounts()->sendPasswordLink($data['email']);
        // 가입 여부를 알려 주지 않게 항상 같은 답
        return back()->with('status', '광고주 계정이 있는 이메일이면 비밀번호 설정 링크를 보냈어요. 메일함을 확인해주세요.');
    }

    public function reset(string $token): View
    {
        $owner = $this->accounts()->linkOwner($token);
        return view('advertiser.reset', ['token' => $token, 'owner' => $owner]);
    }

    public function saveReset(Request $request, string $token): RedirectResponse
    {
        $data = $request->validate(['password' => $this->passwordRule()], self::PASSWORD_MESSAGES);
        $me = $this->accounts()->useLink($token, $data['password']);
        if (!$me) return back()->withErrors(['password' => '링크가 만료됐거나 이미 썼어요. 비밀번호 찾기에서 다시 받아주세요.']);
        AdvertiserWeb::login($request, $me);
        return redirect()->route('advertiser.account')->with('status', '비밀번호를 설정했어요.');
    }

    /** 견적서 비밀 링크에서 계정 만들기(견적 메일을 받은 사람 = 이메일 주인) */
    public function createFromQuote(Request $request, string $token): RedirectResponse
    {
        $c = app(AdSales::class)->byQuoteToken($token);
        abort_unless($c && $c->advertiser_id, 404);
        // 견적서에서 받은 이메일을 확인한 세션만 계정을 만들 수 있다
        if (!AdvertiserWeb::quoteUnlocked($request, $c)) return redirect()->route('advertiser.quote', $token);
        $owner = $this->accounts()->find((int) $c->advertiser_id);
        abort_unless($owner, 404);
        if ($owner->password) {
            return back()->withErrors(['account' => '이미 계정이 있어요. 로그인해주세요.']);
        }
        $data = $request->validate(['password' => $this->passwordRule()], self::PASSWORD_MESSAGES);
        $this->accounts()->setPassword((int) $owner->id, $data['password']);
        AdvertiserWeb::login($request, $this->accounts()->find((int) $owner->id));
        return back()->with('status', '광고주 계정을 만들었어요. 앞으로 ' . $owner->email . ' 로 로그인해 견적 · 성과 보고를 볼 수 있어요.');
    }

    public function home(Request $request): View
    {
        $me = $request->attributes->get('advertiser');
        $sales = app(AdSales::class);
        $db = app(ProjectDatabase::class)->database();
        $contracts = array_map(fn ($r) => $sales->hydrate($r),
            $db->table('starttoo_ad_contracts')->where('advertiser_id', $me->id)->orderByDesc('id')->limit(100)->get()->all());
        $inquiries = array_map(fn ($r) => $sales->hydrateInquiry($r),
            $db->table('starttoo_ad_inquiries')->where('advertiser_id', $me->id)->orderByDesc('id')->limit(50)->get()->all());
        return view('advertiser.account', [
            'me' => $me,
            'contracts' => array_map(fn ($c) => [
                'c' => $c,
                'state' => $c->expired ? ['유효 기한 지남', 'gray'] : AdSales::STATUS[$c->status],
                'quoteUrl' => $sales->quoteUrl($c),
                // 성과 보고는 광고가 나가기 시작한 계약만(아직 없으면 링크를 만들지 않는다)
                'reportUrl' => in_array($c->status, ['live', 'ended'], true) ? $sales->reportUrl($c) : null,
                'imps' => array_sum(array_column($c->lines, 'imps')),
                'slots' => implode(' · ', $sales->slotNames(array_column($c->lines, 'slot'))),
            ], $contracts),
            'inquiries' => array_map(fn ($i) => [
                'i' => $i, 'slots' => implode(' · ', $sales->slotNames($i->slots)),
                'state' => AdSales::INQUIRY_STATUS[$i->status] ?? [$i->status, 'gray'],
            ], $inquiries),
        ]);
    }

    public function saveProfile(Request $request): RedirectResponse
    {
        $me = $request->attributes->get('advertiser');
        $data = $request->validate([
            'company' => ['required', 'string', 'max:100'],
            'contact_name' => ['nullable', 'string', 'max:60'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{8,}$/'],
            'biz_no' => ['nullable', 'regex:/^\d{3}-?\d{2}-?\d{5}$/'],
            'biz_name' => ['nullable', 'string', 'max:120'],
            'tax_email' => ['nullable', 'email:rfc', 'max:150'],
        ], ['phone.regex' => '연락처를 숫자로 적어주세요.', 'biz_no.regex' => '사업자등록번호 10자리를 확인해주세요.'],
            ['company' => '회사명', 'contact_name' => '담당자', 'biz_name' => '상호 · 대표자', 'tax_email' => '세금계산서 이메일']);
        if (!empty($data['biz_no'])) $data['biz_no'] = preg_replace('/^(\d{3})-?(\d{2})-?(\d{5})$/', '$1-$2-$3', $data['biz_no']);
        $this->accounts()->updateProfile((int) $me->id, $data);
        return back()->with('status', '내 정보를 저장했어요. 다음 입금 알림 때 세금계산서 정보가 미리 채워져요.');
    }

    public function changePassword(Request $request): RedirectResponse
    {
        $me = $request->attributes->get('advertiser');
        $data = $request->validate(['current_password' => ['required', 'string', 'max:72'], 'password' => $this->passwordRule()],
            self::PASSWORD_MESSAGES, ['current_password' => '지금 비밀번호']);
        if (!$this->accounts()->checkPassword($me, $data['current_password'])) {
            return back()->withErrors(['current_password' => '지금 비밀번호가 맞지 않아요.']);
        }
        $this->accounts()->setPassword((int) $me->id, $data['password']);
        AdvertiserWeb::login($request, $this->accounts()->find((int) $me->id));
        return back()->with('status', '비밀번호를 바꿨어요. 다른 기기에서는 다시 로그인해야 해요.');
    }
}
