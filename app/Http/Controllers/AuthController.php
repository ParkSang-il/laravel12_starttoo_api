<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\BusinessVerification;
use App\Services\PhoneVerificationService;
use App\Services\UsernameGeneratorService;
use App\Services\BusinessVerificationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    protected PhoneVerificationService $phoneVerificationService;
    protected UsernameGeneratorService $usernameGeneratorService;
    protected BusinessVerificationService $businessVerificationService;

    public function __construct(
        PhoneVerificationService $phoneVerificationService,
        UsernameGeneratorService $usernameGeneratorService,
        BusinessVerificationService $businessVerificationService
    ) {
        $this->phoneVerificationService = $phoneVerificationService;
        $this->usernameGeneratorService = $usernameGeneratorService;
        $this->businessVerificationService = $businessVerificationService;
    }

    /**
     * 휴대폰 번호로 로그인 (/verify 성공 후 호출)
     * - 인증번호 검증은 이미 완료된 상태
     * - 휴대폰 번호로 사용자 조회 후 JWT 토큰 발급
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function login(Request $request): JsonResponse
    {
        try {
            $phone = $request->phone;

            // 휴대폰 번호 유효성 검사
            $this->phoneVerificationService->validatePhoneNumber($phone);

            // 휴대폰 번호로 사용자 조회
            $user = $this->phoneVerificationService->findUserByPhone($phone);

            // 최근 5분이내 인증된 로그가 있는지 확인
            if (!$this->phoneVerificationService->hasRecentVerification($phone)) {
                return response()->json([
                    'success' => false,
                    'message' => '인증된 로그인이 아닙니다. 인증번호를 다시 발송해주세요.',
                ], 403);
            }

            if (!$user) {
                // 기존 계정이 없으면 가입 플로우로 진행
                return response()->json([
                    'success' => true,
                    'message' => '존재하지 않는 휴대폰 번호 입니다.',
                    'data' => [
                        'is_new_user' => true,
                        'phone' => $phone,
                        'phone_verified' => true,
                    ],
                ], 200);
            }

            // 기존 계정이 있으면 로그인 처리
            // 휴대폰 인증 완료 업데이트
            if (!$user->phone_verified_at) {
                $user->update([
                    'phone_verified_at' => now(),
                ]);
            }

            // JWT 토큰 발급
            $token = JWTAuth::fromUser($user);

            return response()->json([
                'success' => true,
                'message' => '로그인 성공',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'phone' => $user->phone,
                        'username' => $user->username,
                        'user_type' => $user->user_type,
                        'phone_verified_at' => $user->phone_verified_at?->toDateTimeString(),
                    ],
                    'token' => $token,
                    'token_type' => 'Bearer',
                ],
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => '유효성 검사 실패',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '로그인 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * 사업자 추가 정보 등록
     * - user_type이 2(사업자)인 사용자만 사용 가능
     * - business_verifications 테이블에 사업자 정보 저장
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function bizAdditionalInfo(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => '인증되지 않은 사용자입니다.',
                ], 401);
            }

            // 이미 등록된 사업자 정보가 있는지 확인
            $existingVerification = BusinessVerification::where('user_id', $user->id)->first();
            if ($existingVerification) {
                return response()->json([
                    'success' => false,
                    'message' => '이미 신청된 사업자 정보가 있습니다. 관리자 승인을 기다려주세요.',
                ], 409);
            }

            // 유효성 검사
            $validator = Validator::make($request->all(), [
                'business_name' => 'required|string|max:100',
                'business_number' => 'nullable|string|max:20',
                'business_certificate' => 'nullable|string|max:255',
                'license_certificate' => 'nullable|string|max:255',
                'safety_education_certificate' => 'nullable|string|max:255',
                'address' => 'nullable|string|max:255',
                'address_detail' => 'nullable|string|max:255',
                'contact_phone_public' => 'nullable|boolean',
                'available_regions' => 'nullable|array',
                'available_regions.*' => 'string',
                'main_styles' => 'nullable|array',
                'main_styles.*' => 'string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => '유효성 검사 실패',
                    'errors' => $validator->errors(),
                ], 422);
            }

            // 사업자 정보 저장
            $businessVerification = BusinessVerification::create([
                'user_id' => $user->id,
                'business_name' => $request->business_name,
                'business_number' => $request->business_number,
                'business_certificate' => $request->business_certificate,
                'license_certificate' => $request->license_certificate,
                'safety_education_certificate' => $request->safety_education_certificate,
                'address' => $request->address,
                'address_detail' => $request->address_detail,
                'contact_phone_public' => $request->contact_phone_public ?? false,
                'available_regions' => $request->available_regions,
                'main_styles' => $request->main_styles,
                'status' => 'pending', // 관리자 승인 대기 상태
            ]);

            return response()->json([
                'success' => true,
                'message' => '사업자 등록신청이 접수되었습니다. 관리자 승인 전까지는 일반 회원 권한으로 서비스 이용이 가능하며, 승인 시 알림으로 안내드릴게요.',
                'data' => [
                    'business_verification' => [
                        'id' => $businessVerification->id,
                        'business_name' => $businessVerification->business_name,
                        'business_number' => $businessVerification->business_number,
                        'business_certificate' => $businessVerification->business_certificate,
                        'license_certificate' => $businessVerification->license_certificate,
                        'safety_education_certificate' => $businessVerification->safety_education_certificate,
                        'address' => $businessVerification->address,
                        'address_detail' => $businessVerification->address_detail,
                        'contact_phone_public' => $businessVerification->contact_phone_public,
                        'available_regions' => $businessVerification->available_regions,
                        'main_styles' => $businessVerification->main_styles,
                        'status' => $businessVerification->status,
                        'created_at' => $businessVerification->created_at->toDateTimeString(),
                    ],
                ],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '사업자 정보 등록 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * 사업자 추가 정보 수정
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function updateBizAdditionalInfo(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => '인증되지 않은 사용자입니다.',
                ], 401);
            }

            // 유효성 검사
            $validator = Validator::make($request->all(), [
                'business_name' => 'nullable|string|max:100',
                'business_number' => 'nullable|string|max:20',
                'business_certificate' => 'nullable|string|max:255',
                'license_certificate' => 'nullable|string|max:255',
                'safety_education_certificate' => 'nullable|string|max:255',
                'address' => 'nullable|string|max:255',
                'address_detail' => 'nullable|string|max:255',
                'contact_phone_public' => 'nullable|boolean',
                'available_regions' => 'nullable|array',
                'available_regions.*' => 'string',
                'main_styles' => 'nullable|array',
                'main_styles.*' => 'string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => '유효성 검사 실패',
                    'errors' => $validator->errors(),
                ], 422);
            }

            // Service를 통한 수정 처리
            $updateData = array_filter($request->only([
                'business_name',
                'business_number',
                'business_certificate',
                'license_certificate',
                'safety_education_certificate',
                'address',
                'address_detail',
                'contact_phone_public',
                'available_regions',
                'main_styles',
            ]), function ($value) {
                return $value !== null;
            });

            $result = $this->businessVerificationService->updateVerification($user->id, $updateData);
            $businessVerification = $result['verification'];
            $changedFields = $result['changed_fields'];

            return response()->json([
                'success' => true,
                'message' => $businessVerification->status === 'pending'
                    ? '사업자 정보가 수정되었습니다. 관리자 승인을 기다려주세요.'
                    : '사업자 정보가 수정되었습니다.',
                'data' => [
                    'business_verification' => [
                        'id' => $businessVerification->id,
                        'business_name' => $businessVerification->business_name,
                        'business_number' => $businessVerification->business_number,
                        'business_certificate' => $businessVerification->business_certificate,
                        'license_certificate' => $businessVerification->license_certificate,
                        'safety_education_certificate' => $businessVerification->safety_education_certificate,
                        'address' => $businessVerification->address,
                        'address_detail' => $businessVerification->address_detail,
                        'contact_phone_public' => $businessVerification->contact_phone_public,
                        'available_regions' => $businessVerification->available_regions,
                        'main_styles' => $businessVerification->main_styles,
                        'status' => $businessVerification->status,
                        'rejected_reason' => $businessVerification->rejected_reason,
                        'updated_at' => $businessVerification->updated_at->toDateTimeString(),
                    ],
                    'changed_fields' => $changedFields,
                ],
            ], 200);
        } catch (\Exception $e) {
            $statusCode = (int) $e->getCode();
            $statusCode = ($statusCode >= 400 && $statusCode < 600) ? $statusCode : 500;

            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: '사업자 정보 수정 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], $statusCode);
        }
    }

    /**
     * 사업자 정보 수정요청 (승인된 사업자만 가능)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function bizEditInfo(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => '인증되지 않은 사용자입니다.',
                ], 401);
            }

            // 유효성 검사
            $validator = Validator::make($request->all(), [
                'business_name' => 'nullable|string|max:100',
                'business_number' => 'nullable|string|max:20',
                'business_certificate' => 'nullable|string|max:255',
                'license_certificate' => 'nullable|string|max:255',
                'safety_education_certificate' => 'nullable|string|max:255',
                'address' => 'nullable|string|max:255',
                'address_detail' => 'nullable|string|max:255',
                'contact_phone_public' => 'nullable|boolean',
                'available_regions' => 'nullable|array',
                'available_regions.*' => 'string',
                'main_styles' => 'nullable|array',
                'main_styles.*' => 'string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => '유효성 검사 실패',
                    'errors' => $validator->errors(),
                ], 422);
            }

            // Service를 통한 수정요청 처리
            $updateData = array_filter($request->only([
                'business_name',
                'business_number',
                'business_certificate',
                'license_certificate',
                'safety_education_certificate',
                'address',
                'address_detail',
                'contact_phone_public',
                'available_regions',
                'main_styles',
            ]), function ($value) {
                return $value !== null;
            });

            $result = $this->businessVerificationService->createEditRequest($user->id, $updateData);
            $editRequest = $result['request'];
            $changedFields = $result['changed_fields'];

            return response()->json([
                'success' => true,
                'message' => '사업자 정보 수정요청이 접수되었습니다. 관리자 승인을 기다려주세요.',
                'data' => [
                    'edit_request' => [
                        'id' => $editRequest->id,
                        'business_verification_id' => $editRequest->business_verification_id,
                        'business_name' => $editRequest->business_name,
                        'business_number' => $editRequest->business_number,
                        'business_certificate' => $editRequest->business_certificate,
                        'license_certificate' => $editRequest->license_certificate,
                        'safety_education_certificate' => $editRequest->safety_education_certificate,
                        'address' => $editRequest->address,
                        'address_detail' => $editRequest->address_detail,
                        'contact_phone_public' => $editRequest->contact_phone_public,
                        'available_regions' => $editRequest->available_regions,
                        'main_styles' => $editRequest->main_styles,
                        'status' => $editRequest->status,
                        'created_at' => $editRequest->created_at->toDateTimeString(),
                    ],
                    'changed_fields' => $changedFields,
                ],
            ], 201);
        } catch (\Exception $e) {
            $statusCode = (int) $e->getCode();
            $statusCode = ($statusCode >= 400 && $statusCode < 600) ? $statusCode : 500;

            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: '사업자 정보 수정요청 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], $statusCode);
        }
    }

    /**
     * 현재 인증된 사용자 정보 조회 (토큰 기반)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function me(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => '인증되지 않은 사용자입니다.',
                ], 401);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'user_type' => $user->user_type,
                        'phone' => $user->phone,
                        'phone_verified_at' => $user->phone_verified_at?->toDateTimeString(),
                        'username' => $user->username,
                        'profile_image' => $user->profile_image,
                        'created_at' => $user->created_at?->toDateTimeString(),
                        'updated_at' => $user->updated_at?->toDateTimeString(),
                    ],
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '사용자 정보 조회 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * 사용자 로그아웃
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            JWTAuth::invalidate(JWTAuth::getToken());

            return response()->json([
                'success' => true,
                'message' => '로그아웃되었습니다.',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '로그아웃 중 오류가 발생했습니다.',
            ], 500);
        }
    }

    /**
     * 토큰 새로고침
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function refresh(Request $request): JsonResponse
    {
        try {
            $token = JWTAuth::refresh(JWTAuth::getToken());

            return response()->json([
                'success' => true,
                'message' => '토큰이 새로고침되었습니다.',
                'data' => [
                    'token' => $token,
                    'token_type' => 'Bearer',
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '토큰 새로고침 중 오류가 발생했습니다.',
            ], 500);
        }
    }


    /**
     * 휴대폰 인증번호 발송
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function sendVerificationCode(Request $request): JsonResponse
    {
        try {
            $phone = $request->phone;

            //휴대폰 번호 유효성 검사
            $this->phoneVerificationService->validatePhoneNumber($phone);

            // 발송 횟수 제한 확인 (삭제 전에 확인하여 만료된 것도 포함)
            if ($this->phoneVerificationService->checkVerificationLimit($phone)) {
                return response()->json([
                    'success' => false,
                    'message' => '인증번호 발송 횟수를 초과했습니다. 잠시 후 다시 시도해주세요.',
                ], 429);
            }

            // 인증번호 생성 및 저장
            $verification = $this->phoneVerificationService->createVerificationCode($phone);

            // TODO: 실제 SMS 발송 로직 추가 예정
            // SMS 발송 로직은 나중에 추가

            return response()->json([
                'success' => true,
                'message' => '인증번호가 발송되었습니다.',
                'data' => [
                    'phone' => $phone,
                    'expires_at' => $verification['expires_at']->toDateTimeString(),
                ],
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => '유효성 검사 실패',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '인증번호 발송 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * 휴대폰 인증번호 검증
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function verifyCode(Request $request): JsonResponse
    {
        try {
            $phone = $request->phone;
            $verificationCode = $request->verification_code;

            // 유효성 검사
            $this->phoneVerificationService->validatePhoneNumber($phone);

            $validator = Validator::make($request->all(), [
                'verification_code' => 'required|string|size:6',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => '유효성 검사 실패',
                    'errors' => $validator->errors(),
                ], 422);
            }

            // 인증번호 검증 (Service에서 모든 검증 로직 처리)
            $phoneVerification = $this->phoneVerificationService->verifyCode($phone, $verificationCode);

            // 검증 성공 시 해당 번호의 모든 미인증번호 삭제
            $this->phoneVerificationService->deleteUnverifiedVerifications($phone);

            return response()->json([
                'success' => true,
                'message' => '인증번호가 확인되었습니다.',
                'data' => [
                    'phone' => $phone,
                    'verified_at' => $phoneVerification->verified_at->toDateTimeString(),
                ],
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => '유효성 검사 실패',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            $statusCode = (int) $e->getCode();
            $statusCode = ($statusCode >= 400 && $statusCode < 600) ? $statusCode : 500;
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: '인증번호 검증 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], $statusCode);
        }
    }

    /**
     * 휴대폰 인증번호 재전송
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function resendVerificationCode(Request $request): JsonResponse
    {
        try {
            $phone = $request->phone;
            $this->phoneVerificationService->validatePhoneNumber($phone);

            // 발송 횟수 제한 확인 (삭제 전에 확인하여 모든 발송 건수 포함)
            if ($this->phoneVerificationService->checkVerificationLimit($phone)) {
                return response()->json([
                    'success' => false,
                    'message' => '인증번호 발송 횟수를 초과했습니다. 잠시 후 다시 시도해주세요.',
                ], 429);
            }

            // 인증번호 생성 및 저장
            $verification = $this->phoneVerificationService->createVerificationCode($phone);

            // TODO: 실제 SMS 발송 로직 추가 예정
            // SMS 발송 로직은 나중에 추가

            return response()->json([
                'success' => true,
                'message' => '인증번호가 재전송되었습니다.',
                'data' => [
                    'phone' => $phone,
                    'expires_at' => $verification['expires_at']->toDateTimeString(),
                ],
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => '유효성 검사 실패',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '인증번호 재전송 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * 휴대폰 번호 중복 체크 (기존 계정 확인)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function checkPhone(Request $request): JsonResponse
    {
        try {
            $phone = $request->phone;

            // 휴대폰 번호 유효성 검사
            $this->phoneVerificationService->validatePhoneNumber($phone);

            // 휴대폰 번호로 사용자 조회
            $user = $this->phoneVerificationService->findUserByPhone($phone);

            if ($user) {
                // 기존 계정이 있는 경우
                return response()->json([
                    'success' => true,
                    'exists' => true,
                    'data' => [
                        'user' => [
                            'id' => $user->id,
                            'phone' => $user->phone,
                            'username' => $user->username,
                            'user_type' => $user->user_type,
                        ],
                    ],
                ], 200);
            } else {
                // 신규 번호인 경우
                return response()->json([
                    'success' => true,
                    'exists' => false,
                ], 200);
            }
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => '유효성 검사 실패',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '휴대폰 번호 확인 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * 인증번호 만료 처리 (앱에서 시간 카운팅 후 만료 시 호출)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function expireVerification(Request $request): JsonResponse
    {
        try {
            $phone = $request->phone;

            // 휴대폰 번호 유효성 검사
            $this->phoneVerificationService->validatePhoneNumber($phone);

            // 만료된 인증번호 삭제
            $deletedCount = $this->phoneVerificationService->deleteExpiredVerificationsOnExpiry($phone);

            return response()->json([
                'success' => true,
                'message' => '만료된 인증번호가 삭제되었습니다.',
                'data' => [
                    'deleted_count' => $deletedCount,
                ],
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => '유효성 검사 실패',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '인증번호 만료 처리 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * 회원 가입
     * - user_type 1: 일반 회원 가입 완료
     * - user_type 2: 사업자 회원 가입 (추가 정보 입력은 추후 별도 메소드로 처리)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function registerType(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'phone' => 'required|string|regex:/^[0-9]{10,11}$/',
                'user_type' => 'required|integer|in:1,2',
                'username' => 'nullable|string|max:50|unique:users,username',
                'profile_image' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => '유효성 검사 실패',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $phone = $request->phone;

            // 기존 회원 확인
            $existingUser = $this->phoneVerificationService->findUserByPhone($phone);
            if ($existingUser) {
                return response()->json([
                    'success' => false,
                    'message' => '이미 등록된 휴대폰 번호입니다.',
                ], 409);
            }

            $userType = $request->user_type;
            $username = $request->username ?? $this->usernameGeneratorService->generateUniqueUsername(); //닉네임 명사+형용사 조합 생성
            $profileImage = $request->profile_image ? $request->profile_image : '기본이미지경로(추후 수정)';

            // 회원 가입 (user_type 그대로 저장)
            $user = User::create([
                'user_type' => $userType,
                'phone' => $phone,
                'phone_verified_at' => now(),
                'username' => $username,
                'profile_image' => $profileImage,
            ]);

            // JWT 토큰 발급
            $token = JWTAuth::fromUser($user);

            $message = $userType == 1
                ? '일반 회원 가입이 완료되었습니다.'
                : '사업자 회원 가입이 완료되었습니다. 추가 정보를 입력해주세요.';

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'user_type' => $user->user_type,
                        'phone' => $user->phone,
                        'phone_verified_at' => $user->phone_verified_at->toDateTimeString(),
                        'username' => $user->username,
                        'profile_image' => $user->profile_image,
                    ],
                    'token' => $token,
                    'token_type' => 'Bearer',
                ],
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => '유효성 검사 실패',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '회원가입 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}

