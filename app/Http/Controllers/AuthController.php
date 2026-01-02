<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserLoginLog;
use App\Models\BusinessVerification;
use App\Models\DeviceRegistration;
use App\Models\ArtistProfile;
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

            // 정지 여부 확인
            if ($user->isSuspended()) {
                // 로그인 실패 기록
                $this->logLoginAttempt($user->id, $request, false, '정지된 계정입니다.');

                return response()->json([
                    'success' => false,
                    'message' => $user->getSuspensionStatusText() ?? '정지된 계정입니다.',
                ], 403);
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

            // 로그인 성공 기록
            $this->logLoginAttempt($user->id, $request, true);

            // 디바이스 정보 업데이트는 로그인 후 registerDevice를 별도로 호출하도록 클라이언트에 안내
            // (토큰이 발급된 후 호출해야 하므로)

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
     * 프로필 수정
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function updateProfile(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => '인증되지 않은 사용자입니다.',
                ], 401);
            }

            $validator = Validator::make($request->all(), [
                'username' => [
                    'nullable',
                    'string',
                    'max:50',
                    'unique:users,username,' . $user->id,
                    'regex:/^[a-zA-Z0-9가-힣_]+$/',
                ],
                'profile_image' => 'nullable|string|max:255',
            ], [
                'username.unique' => '이미 사용 중인 닉네임입니다.',
                'username.regex' => '닉네임은 영문, 숫자, 한글, 언더스코어(_)만 사용할 수 있습니다.',
                'username.max' => '닉네임은 최대 50자까지 입력할 수 있습니다.',
                'profile_image.max' => '프로필 이미지 URL은 최대 255자까지 입력할 수 있습니다.',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => '유효성 검사 실패',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $updateData = [];

            if ($request->has('username')) {
                $updateData['username'] = $request->input('username');
            }

            if ($request->has('profile_image')) {
                $updateData['profile_image'] = $request->input('profile_image');
            }

            if (empty($updateData)) {
                return response()->json([
                    'success' => false,
                    'message' => '수정할 정보가 없습니다.',
                ], 400);
            }

            $user->update($updateData);
            $user->refresh();

            return response()->json([
                'success' => true,
                'message' => '프로필이 수정되었습니다.',
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
                'message' => '프로필 수정 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * 아티스트 프로필 수정
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function updateArtistProfile(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => '인증되지 않은 사용자입니다.',
                ], 401);
            }

            // 사업자 회원인지 확인
            if ($user->user_type !== 2) {
                return response()->json([
                    'success' => false,
                    'message' => '권한이 없습니다.',
                ], 403);
            }

            // 아티스트 프로필 확인
            $artistProfile = ArtistProfile::where('user_id', $user->id)->first();

            if (!$artistProfile) {
                return response()->json([
                    'success' => false,
                    'message' => '아티스트 프로필을 찾을 수 없습니다. 사업자 승인을 기다려주세요.',
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'cover_image' => 'nullable|string|max:255',
                'artist_name' => 'nullable|string|max:100',
                'email' => 'nullable|email|max:100',
                'instagram' => 'nullable|string|max:100',
                'website' => 'nullable|url|max:255',
                'studio_address' => 'nullable|string|max:255',
                'bio' => 'nullable|string|max:1000',
            ], [
                'cover_image.max' => '커버 이미지 URL은 최대 255자까지 입력할 수 있습니다.',
                'artist_name.max' => '아티스트명은 최대 100자까지 입력할 수 있습니다.',
                'email.email' => '올바른 이메일 형식이 아닙니다.',
                'email.max' => '이메일은 최대 100자까지 입력할 수 있습니다.',
                'instagram.max' => '인스타그램 계정은 최대 100자까지 입력할 수 있습니다.',
                'website.url' => '올바른 URL 형식이 아닙니다.',
                'website.max' => '웹사이트 URL은 최대 255자까지 입력할 수 있습니다.',
                'studio_address.max' => '스튜디오 주소는 최대 255자까지 입력할 수 있습니다.',
                'bio.max' => '샵 안내 메세지는 최대 1000자까지 입력할 수 있습니다.',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => '유효성 검사 실패',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $updateData = [];

            if ($request->has('cover_image')) {
                $updateData['cover_image'] = $request->input('cover_image');
            }

            if ($request->has('artist_name')) {
                $updateData['artist_name'] = $request->input('artist_name');
            }

            if ($request->has('email')) {
                $updateData['email'] = $request->input('email');
            }

            if ($request->has('instagram')) {
                $updateData['instagram'] = $request->input('instagram');
            }

            if ($request->has('website')) {
                $updateData['website'] = $request->input('website');
            }

            if ($request->has('studio_address')) {
                $updateData['studio_address'] = $request->input('studio_address');
            }

            if ($request->has('bio')) {
                $updateData['bio'] = $request->input('bio');
            }

            if (empty($updateData)) {
                return response()->json([
                    'success' => false,
                    'message' => '수정할 정보가 없습니다.',
                ], 400);
            }

            $artistProfile->update($updateData);
            $artistProfile->refresh();

            return response()->json([
                'success' => true,
                'message' => '아티스트 프로필이 수정되었습니다.',
                'data' => [
                    'artist_profile' => [
                        'id' => $artistProfile->id,
                        'user_id' => $artistProfile->user_id,
                        'cover_image' => $artistProfile->cover_image,
                        'artist_name' => $artistProfile->artist_name,
                        'email' => $artistProfile->email,
                        'instagram' => $artistProfile->instagram,
                        'website' => $artistProfile->website,
                        'studio_address' => $artistProfile->studio_address,
                        'bio' => $artistProfile->bio,
                        'created_at' => $artistProfile->created_at?->toDateTimeString(),
                        'updated_at' => $artistProfile->updated_at?->toDateTimeString(),
                    ],
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '아티스트 프로필 수정 중 오류가 발생했습니다.',
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
     * - 만료된 토큰도 refresh할 수 있어야 하므로 미들웨어 없이 처리
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function refresh(Request $request): JsonResponse
    {
        try {
            // Authorization 헤더에서 토큰 추출
            $token = $request->bearerToken();

            if (!$token) {
                // 쿼리 파라미터에서도 확인
                $token = $request->input('token');
            }

            if (!$token) {
                return response()->json([
                    'success' => false,
                    'message' => '토큰이 제공되지 않았습니다.',
                ], 401);
            }

            // 토큰을 설정하고 새로고침
            JWTAuth::setToken($token);

            // 이전 토큰을 blacklist에 추가하여 무효화
            $oldToken = JWTAuth::getToken();
            $newToken = JWTAuth::refresh();

            // 이전 토큰 무효화 (blacklist에 추가)
            try {
                JWTAuth::setToken($oldToken);
                JWTAuth::invalidate();
            } catch (\Exception $e) {
                // blacklist 추가 실패해도 새 토큰은 이미 발급되었으므로 계속 진행
            }

            return response()->json([
                'success' => true,
                'message' => '토큰이 새로고침되었습니다.',
                'data' => [
                    'token' => $newToken,
                    'token_type' => 'Bearer',
                ],
            ], 200);
        } catch (\PHPOpenSourceSaver\JWTAuth\Exceptions\TokenExpiredException $e) {
            return response()->json([
                'success' => false,
                'message' => '토큰이 만료되었습니다. 다시 로그인해주세요.',
            ], 401);
        } catch (\PHPOpenSourceSaver\JWTAuth\Exceptions\TokenInvalidException $e) {
            return response()->json([
                'success' => false,
                'message' => '유효하지 않은 토큰입니다.',
            ], 401);
        } catch (\PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException $e) {
            return response()->json([
                'success' => false,
                'message' => '토큰 처리 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 401);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '토큰 새로고침 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
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
     * 디바이스 등록/업데이트
     * - 앱 실행 시: 디바이스 정보만 등록 (없을 때만)
     * - 회원가입/로그인 시: 있으면 업데이트, 없으면 등록 (디바이스ID, 회원ID, 수신동의)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function registerDevice(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'device_id' => 'required|string|max:255',
                'marketing_notification_consent' => 'nullable|boolean',
                'service_notification_consent' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => '유효성 검사 실패',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $deviceId = $request->device_id;
            $userAgent = $request->header('User-Agent');

            // 토큰이 있으면 사용자 정보 가져오기 (인증 선택적)
            $user = null;
            $token = $request->bearerToken();
            if ($token) {
                try {
                    JWTAuth::setToken($token);
                    $user = JWTAuth::authenticate();
                } catch (\Exception $e) {
                    // 토큰이 유효하지 않아도 계속 진행 (인증 선택적)
                    $user = null;
                }
            }

            // 이미 등록된 디바이스인지 확인
            $deviceRegistration = DeviceRegistration::where('device_id', $deviceId)->first();

            if ($deviceRegistration) {
                // 이미 등록된 경우
                if ($user) {
                    // 회원가입/로그인 시: user_id와 수신동의 정보 업데이트
                    $updateData = [
                        'user_id' => $user->id,
                    ];

                    if ($request->has('marketing_notification_consent')) {
                        $updateData['marketing_notification_consent'] = $request->input('marketing_notification_consent');
                    }
                    if ($request->has('service_notification_consent')) {
                        $updateData['service_notification_consent'] = $request->input('service_notification_consent');
                    }

                    $deviceRegistration->update($updateData);
                    $deviceRegistration->refresh();
                }

                return response()->json([
                    'success' => true,
                    'message' => $user ? '디바이스 정보가 업데이트되었습니다.' : '이미 등록된 디바이스입니다.',
                    'data' => [
                        'device_id' => $deviceRegistration->device_id,
                        'user_id' => $deviceRegistration->user_id,
                        'marketing_notification_consent' => $deviceRegistration->marketing_notification_consent,
                        'service_notification_consent' => $deviceRegistration->service_notification_consent,
                    ],
                ], 200);
            }

            // 새로 등록
            $createData = [
                'device_id' => $deviceId,
                'user_agent' => $userAgent
            ];

            if ($user) {
                // 회원가입/로그인 시: user_id도 함께 저장
                $createData['user_id'] = $user->id;

                if ($request->has('marketing_notification_consent')) {
                    $createData['marketing_notification_consent'] = $request->input('marketing_notification_consent');
                }

                if ($request->has('service_notification_consent')) {
                    $createData['service_notification_consent'] = $request->input('service_notification_consent');
                }
            }

            $deviceRegistration = DeviceRegistration::create($createData);

            return response()->json([
                'success' => true,
                'message' => '디바이스가 등록되었습니다.',
                'data' => [
                    'device_id' => $deviceRegistration->device_id,
                    'user_id' => $deviceRegistration->user_id
                ],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '디바이스 등록 중 오류가 발생했습니다.',
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

            // 가입 후 로그인 성공 기록 (토큰이 발급되므로 로그인으로 간주)
            $this->logLoginAttempt($user->id, $request, true);

            // 디바이스가 등록되어 있다면 user_id와 수신동의 정보 업데이트
            $deviceId = $request->header('X-Device-ID');
            if ($deviceId) {
                $deviceRegistration = DeviceRegistration::where('device_id', $deviceId)
                    ->whereNull('user_id')
                    ->first();

                if ($deviceRegistration) {
                    $deviceRegistration->update([
                        'user_id' => $user->id,
                        'marketing_notification_consent' => $request->input('marketing_notification_consent', $deviceRegistration->marketing_notification_consent),
                        'service_notification_consent' => $request->input('service_notification_consent', $deviceRegistration->service_notification_consent),
                    ]);
                }
            }

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

    /**
     * 로그인 시도 기록 저장
     *
     * @param int|null $userId
     * @param Request $request
     * @param bool $isSuccess
     * @param string|null $failureReason
     * @return void
     */
    private function logLoginAttempt(?int $userId, Request $request, bool $isSuccess, ?string $failureReason = null): void
    {
        try {
            $userAgent = $request->header('User-Agent', '');
            $ipAddress = $request->ip();

            // User Agent 파싱 (간단한 버전)
            $deviceType = 'unknown';
            $deviceModel = null;
            $os = null;
            $browser = null;

            if ($userAgent) {
                // 모바일 디바이스 체크
                if (preg_match('/Mobile|Android|iPhone|iPad/i', $userAgent)) {
                    $deviceType = 'mobile';
                    if (preg_match('/iPhone/i', $userAgent)) {
                        $deviceModel = 'iPhone';
                    } elseif (preg_match('/iPad/i', $userAgent)) {
                        $deviceType = 'tablet';
                        $deviceModel = 'iPad';
                    } elseif (preg_match('/Android/i', $userAgent)) {
                        $deviceModel = 'Android';
                    }
                } else {
                    $deviceType = 'desktop';
                }

                // OS 체크
                if (preg_match('/Windows/i', $userAgent)) {
                    $os = 'Windows';
                } elseif (preg_match('/Mac OS/i', $userAgent)) {
                    $os = 'macOS';
                } elseif (preg_match('/Linux/i', $userAgent)) {
                    $os = 'Linux';
                } elseif (preg_match('/Android/i', $userAgent)) {
                    $os = 'Android';
                } elseif (preg_match('/iOS/i', $userAgent)) {
                    $os = 'iOS';
                }

                // 브라우저 체크
                if (preg_match('/Chrome/i', $userAgent) && !preg_match('/Edg/i', $userAgent)) {
                    $browser = 'Chrome';
                } elseif (preg_match('/Safari/i', $userAgent) && !preg_match('/Chrome/i', $userAgent)) {
                    $browser = 'Safari';
                } elseif (preg_match('/Firefox/i', $userAgent)) {
                    $browser = 'Firefox';
                } elseif (preg_match('/Edg/i', $userAgent)) {
                    $browser = 'Edge';
                }
            }

            UserLoginLog::create([
                'user_id' => $userId,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'device_type' => $deviceType,
                'device_model' => $deviceModel,
                'os' => $os,
                'browser' => $browser,
                'login_type' => 'phone',
                'is_success' => $isSuccess,
                'failure_reason' => $failureReason,
            ]);
        } catch (\Exception $e) {
            // 로그인 기록 저장 실패는 로그인 자체를 막지 않음
            \Log::error('로그인 기록 저장 실패: ' . $e->getMessage());
        }
    }
}

