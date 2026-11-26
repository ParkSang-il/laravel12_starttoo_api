<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Starttoo\AdminAccounts;
use App\Services\Starttoo\AdminAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** M-01 관리자 로그인: 이메일 · 비밀번호 · 인증 앱 6자리(2단계 인증). 앱 회원 로그인과 분리된 세션. */
class AdminAuthController extends Controller
{
    public function login(Request $request): View|RedirectResponse
    {
        if ($request->session()->get('admin_id')) return redirect()->route('admin.dashboard');
        return view('admin.login');
    }

    public function authenticate(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email', 'max:191'],
            'password' => ['required', 'string', 'max:200'],
            'otp' => ['required', 'digits:6'],
        ], ['otp.digits' => '인증 앱 6자리 코드를 입력해주세요.', 'otp.required' => '인증 앱 6자리 코드를 입력해주세요.']);

        try {
            $admin = app(AdminAccounts::class)->attempt((string) $request->input('email'), (string) $request->input('password'),
                (string) $request->input('otp'), (string) $request->ip());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['email' => $e->getMessage()])->onlyInput('email');
        }

        $request->session()->regenerate();
        $request->session()->put(['admin_id' => (int) $admin->id, 'admin_seen_at' => time()]);
        app(AdminAudit::class)->record($admin, '접속', '로그인 (2단계 인증)');

        return redirect()->intended(route('admin.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        app(AdminAudit::class)->record($request->attributes->get('admin'), '접속', '로그아웃');
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
