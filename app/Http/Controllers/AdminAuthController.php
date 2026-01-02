<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\AdminLoginLog;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AdminAuthController extends Controller
{
    /**
     * 관리자 로그인 페이지
     *
     * @return \Illuminate\View\View
     */
    public function showLoginForm(Request $request)
    {
        // 이미 로그인된 경우 포트폴리오 관리 페이지로 리다이렉트
        if (Auth::guard('admin')->check()) {
            return redirect()->route('admin.portfolio.index');
        }
        return view('admin.auth.login');
    }

    /**
     * 관리자 로그인 처리
     *
     * @param Request $request
     * @return JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function login(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => '유효성 검사 실패',
                    'errors' => $validator->errors(),
                ], 422);
            }
            return back()->withErrors($validator)->withInput();
        }

        $credentials = $request->only('username', 'password');
        $remember = $request->boolean('remember');

        // 세션이 시작되었는지 확인
        if (!$request->hasSession()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => '세션 오류가 발생했습니다.',
                ], 500);
            }
            return back()->withErrors([
                'username' => '세션 오류가 발생했습니다. 페이지를 새로고침해주세요.',
            ])->withInput();
        }

        // 관리자 조회 (try-catch로 DB 연결 오류 처리)
        try {
            $admin = Admin::where('username', $credentials['username'])->first();
        } catch (\Illuminate\Database\QueryException $e) {
            $this->logAdminLogin(null, $credentials['username'], 'login', false, 'db_error', $request);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => '데이터베이스 연결 오류가 발생했습니다.',
                ], 500);
            }
            return redirect()->route('admin.login')
                ->withErrors([
                    'username' => '데이터베이스 연결 오류가 발생했습니다. 잠시 후 다시 시도해주세요.',
                ])
                ->withInput();
        }

        if (!$admin) {
            $this->logAdminLogin(null, $credentials['username'], 'login', false, 'invalid_username', $request);

            return redirect()->route('admin.login')
                ->withErrors([
                    'username' => '아이디 또는 비밀번호가 올바르지 않습니다.',
                ]);
        }

        if (!Hash::check($credentials['password'], $admin->password)) {
            $this->logAdminLogin($admin->id, $credentials['username'], 'password_mismatch', false, 'invalid_password', $request);

            return redirect()->route('admin.login')
                ->withErrors([
                    'username' => '아이디 또는 비밀번호가 올바르지 않습니다.',
                ]);
        }

        // 로그인 처리
        Auth::guard('admin')->login($admin, $remember);

        // 세션 재생성 (보안을 위해) - 세션 ID 변경
        $request->session()->regenerate();

        // regenerate 후 인증 정보가 사라질 수 있으므로 다시 로그인
        Auth::guard('admin')->login($admin, $remember);

        // 세션에 관리자 정보 저장 (디버깅용 및 만료 시간 체크용)
        $request->session()->put('admin_id', $admin->id);
        $request->session()->put('admin_username', $admin->username);
        $request->session()->put('admin_login_at', now()->toDateTimeString());

        // 세션 명시적 저장
        $request->session()->save();

        // 마지막 로그인 정보 업데이트
        $admin->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        $this->logAdminLogin($admin->id, $admin->username, 'login', true, null, $request);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => '로그인 성공',
                'data' => [
                    'admin' => [
                        'id' => $admin->id,
                        'username' => $admin->username,
                        'name' => $admin->name,
                        'role' => $admin->role,
                    ],
                ],
            ], 200);
        }

        return redirect()->route('admin.portfolio.index')->with('success', '로그인되었습니다.');
    }

    /**
     * 관리자 로그아웃
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function logout(Request $request)
    {
        $admin = Auth::guard('admin')->user();

        Auth::guard('admin')->logout();

        // 세션 무효화 및 재생성
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($admin) {
            $this->logAdminLogin($admin->id, $admin->username, 'logout', true, null, $request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => '로그아웃되었습니다.',
            ], 200);
        }

        return redirect()->route('admin.login');
    }

    /**
     * 관리자 계정 추가 페이지
     *
     * @return \Illuminate\View\View
     */
    public function showCreateForm()
    {
        $admin = Auth::guard('admin')->user();

        // 최고 관리자만 접근 가능
        if (!$admin || !$admin->isSuperAdmin()) {
            abort(403, '최고 관리자만 접근할 수 있습니다.');
        }

        return view('admin.auth.create');
    }

    /**
     * 관리자 계정 추가 처리
     *
     * @param Request $request
     * @return JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function create(Request $request)
    {
        $admin = Auth::guard('admin')->user();

        // 최고 관리자만 접근 가능
        if (!$admin || !$admin->isSuperAdmin()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => '최고 관리자만 접근할 수 있습니다.',
                ], 403);
            }
            abort(403, '최고 관리자만 접근할 수 있습니다.');
        }

        $validator = Validator::make($request->all(), [
            'username' => 'required|string|max:50|unique:admins,username',
            'password' => 'required|string|min:4|confirmed',
            'role' => 'required|string|in:super_admin,admin',
            'name' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => '유효성 검사 실패',
                    'errors' => $validator->errors(),
                ], 422);
            }
            return back()->withErrors($validator)->withInput();
        }

        $newAdmin = Admin::create([
            'username' => $request->username,
            'password' => $request->password, // setPasswordAttribute에서 암호화됨
            'role' => $request->role,
            'name' => $request->name,
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => '관리자 계정이 생성되었습니다.',
                'data' => [
                    'admin' => [
                        'id' => $newAdmin->id,
                        'username' => $newAdmin->username,
                        'name' => $newAdmin->name,
                        'role' => $newAdmin->role,
                    ],
                ],
            ], 201);
        }

        return redirect()->route('admin.auth.create')->with('success', '관리자 계정이 생성되었습니다.');
    }

    /**
     * 관리자 로그인/로그아웃 기록 저장
     */
    private function logAdminLogin($adminId, ?string $username, string $action, bool $isSuccess, ?string $failureReason, Request $request): void
    {
        try {
            AdminLoginLog::create([
                'admin_id' => $adminId,
                'username' => $username,
                'ip_address' => $request->ip(),
                'action' => $action,
                'is_success' => $isSuccess,
                'failure_reason' => $failureReason,
            ]);
        } catch (\Throwable $e) {
            \Log::error('관리자 로그인 로그 저장 실패', [
                'error' => $e->getMessage(),
                'admin_id' => $adminId,
                'username' => $username,
                'action' => $action,
            ]);
        }
    }
}

