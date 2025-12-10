<?php

namespace App\Services;

use App\Models\BusinessVerification;
use App\Models\BusinessVerificationRequest;
use Illuminate\Pagination\LengthAwarePaginator;

class BusinessVerificationService
{
    /**
     * 사업자 가입신청 리스트 조회
     *
     * @param string $status
     * @param int $perPage
     * @param int $page
     * @return LengthAwarePaginator
     */
    public function getVerificationList(string $status = 'pending', int $perPage = 15, int $page = 1): LengthAwarePaginator
    {
        $query = BusinessVerification::with('user')
            ->orderBy('created_at', 'desc');

        // status 필터링
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * 사업자 가입신청 데이터 포맷팅
     *
     * @param LengthAwarePaginator $verifications
     * @return array
     */
    public function formatVerificationList(LengthAwarePaginator $verifications): array
    {
        $data = $verifications->map(function ($verification) {
            return [
                'id' => $verification->id,
                'user_id' => $verification->user_id,
                'user' => [
                    'id' => $verification->user->id,
                    'username' => $verification->user->username,
                    'phone' => $verification->user->phone,
                ],
                'business_name' => $verification->business_name,
                'business_number' => $verification->business_number,
                'business_certificate' => $verification->business_certificate,
                'license_certificate' => $verification->license_certificate,
                'safety_education_certificate' => $verification->safety_education_certificate,
                'address' => $verification->address,
                'address_detail' => $verification->address_detail,
                'contact_phone_public' => $verification->contact_phone_public,
                'available_regions' => $verification->available_regions,
                'main_styles' => $verification->main_styles,
                'status' => $verification->status,
                'rejected_reason' => $verification->rejected_reason,
                'approved_at' => $verification->approved_at?->toDateTimeString(),
                'created_at' => $verification->created_at->toDateTimeString(),
                'updated_at' => $verification->updated_at->toDateTimeString(),
            ];
        });

        return [
            'list' => $data,
            'pagination' => [
                'current_page' => $verifications->currentPage(),
                'last_page' => $verifications->lastPage(),
                'per_page' => $verifications->perPage(),
                'total' => $verifications->total(),
            ],
        ];
    }

    /**
     * 사업자 가입신청 승인
     *
     * @param int $id
     * @return BusinessVerification
     * @throws \Exception
     */
    public function approveVerification(int $id): BusinessVerification
    {
        $verification = BusinessVerification::find($id);

        if (!$verification) {
            throw new \Exception('해당 사업자 신청을 찾을 수 없습니다.', 404);
        }

        if ($verification->status !== 'pending') {
            throw new \Exception('이미 처리된 신청입니다.', 400);
        }

        $verification->update([
            'status' => 'approved',
            'approved_at' => now(),
            'rejected_reason' => null,
        ]);

        return $verification;
    }

    /**
     * 사업자 가입신청 반려
     *
     * @param int $id
     * @param string $rejectedReason
     * @return BusinessVerification
     * @throws \Exception
     */
    public function rejectVerification(int $id, string $rejectedReason): BusinessVerification
    {
        $verification = BusinessVerification::find($id);

        if (!$verification) {
            throw new \Exception('해당 사업자 신청을 찾을 수 없습니다.', 404);
        }

        if ($verification->status !== 'pending') {
            throw new \Exception('이미 처리된 신청입니다.', 400);
        }

        $verification->update([
            'status' => 'rejected',
            'rejected_reason' => $rejectedReason,
            'approved_at' => null,
        ]);

        return $verification;
    }

    /**
     * 사업자 추가 정보 수정
     * - 상태가 pending 또는 rejected일 경우 수정 가능
     * - 상태가 approved일 경우 변경요청 필요 (향후 구현)
     *
     * @param int $userId
     * @param array $data
     * @return array ['verification' => BusinessVerification, 'has_changes' => bool, 'changed_fields' => array]
     * @throws \Exception
     */
    public function updateVerification(int $userId, array $data): array
    {
        $verification = BusinessVerification::where('user_id', $userId)->first();

        if (!$verification) {
            throw new \Exception('사업자 정보를 찾을 수 없습니다.', 404);
        }

        // 승인된 경우 변경요청으로 처리 (향후 구현)
        if ($verification->status === 'approved') {
            throw new \Exception('승인된 사업자 정보는 변경요청을 통해 수정할 수 있습니다.', 400);
        }

        // pending 또는 rejected 상태일 경우에만 수정 가능
        if (!in_array($verification->status, ['pending', 'rejected'])) {
            throw new \Exception('수정할 수 없는 상태입니다.', 400);
        }

        // 변경된 필드 확인
        $changedFields = [];
        $originalData = $verification->toArray();
        
        foreach ($data as $key => $value) {
            // 배열 필드는 JSON 비교
            if (in_array($key, ['available_regions', 'main_styles'])) {
                $originalValue = $originalData[$key] ?? [];
                $newValue = $value ?? [];
                
                // 배열을 정렬하여 비교
                sort($originalValue);
                sort($newValue);
                
                if (json_encode($originalValue) !== json_encode($newValue)) {
                    $changedFields[$key] = [
                        'old' => $originalValue,
                        'new' => $newValue,
                    ];
                }
            } else {
                $originalValue = $originalData[$key] ?? null;
                
                // null과 빈 문자열을 동일하게 처리
                $originalValue = $originalValue === '' ? null : $originalValue;
                $newValue = $value === '' ? null : $value;
                
                if ($originalValue != $newValue) {
                    $changedFields[$key] = [
                        'old' => $originalValue,
                        'new' => $newValue,
                    ];
                }
            }
        }

        // 변경된 내용이 없으면 예외 발생
        if (empty($changedFields)) {
            throw new \Exception('변경된 내용이 없습니다.', 400);
        }

        // 반려 상태에서 수정할 경우 pending으로 변경
        if ($verification->status === 'rejected') {
            $data['status'] = 'pending';
            $data['rejected_reason'] = null;
        }

        $verification->update($data);

        return [
            'verification' => $verification->fresh(),
            'has_changes' => true,
            'changed_fields' => $changedFields,
        ];
    }

    /**
     * 사업자 정보 수정요청 (승인된 사업자만 가능)
     *
     * @param int $userId
     * @param array $data
     * @return array ['request' => BusinessVerificationRequest, 'changed_fields' => array]
     * @throws \Exception
     */
    public function createEditRequest(int $userId, array $data): array
    {
        // 승인된 사업자 정보 확인
        $verification = BusinessVerification::where('user_id', $userId)
            ->where('status', 'approved')
            ->first();

        if (!$verification) {
            throw new \Exception('승인된 사업자 정보를 찾을 수 없습니다.', 404);
        }

        // 기존에 대기중인 수정요청이 있는지 확인
        $existingRequest = BusinessVerificationRequest::where('business_verification_id', $verification->id)
            ->where('status', 'pending')
            ->first();

        if ($existingRequest) {
            throw new \Exception('이미 대기중인 수정요청이 있습니다. 관리자 승인을 기다려주세요.', 400);
        }

        // 변경된 필드 확인
        $changedFields = [];
        $originalData = $verification->toArray();

        foreach ($data as $key => $value) {
            // 배열 필드는 JSON 비교
            if (in_array($key, ['available_regions', 'main_styles'])) {
                $originalValue = $originalData[$key] ?? [];
                $newValue = $value ?? [];

                // 배열을 정렬하여 비교
                $originalArray = is_array($originalValue) ? $originalValue : (is_string($originalValue) ? json_decode($originalValue, true) : []);
                $newArray = is_array($newValue) ? $newValue : (is_string($newValue) ? json_decode($newValue, true) : []);

                sort($originalArray);
                sort($newArray);

                if (json_encode($originalArray) !== json_encode($newArray)) {
                    $changedFields[$key] = [
                        'old' => $originalValue,
                        'new' => $newValue,
                    ];
                }
            } else {
                $originalValue = $originalData[$key] ?? null;

                // null과 빈 문자열을 동일하게 처리
                $originalValue = $originalValue === '' ? null : $originalValue;
                $newValue = $value === '' ? null : $value;

                if ($originalValue != $newValue) {
                    $changedFields[$key] = [
                        'old' => $originalValue,
                        'new' => $newValue,
                    ];
                }
            }
        }

        // 변경된 내용이 없으면 예외 발생
        if (empty($changedFields)) {
            throw new \Exception('변경된 내용이 없습니다.', 400);
        }

        // 수정요청 생성
        $requestData = array_merge($data, [
            'business_verification_id' => $verification->id,
            'user_id' => $userId,
            'status' => 'pending',
        ]);

        $editRequest = BusinessVerificationRequest::create($requestData);

        return [
            'request' => $editRequest->fresh(),
            'changed_fields' => $changedFields,
        ];
    }

    /**
     * 사업자 정보 수정요청 리스트 조회
     *
     * @param string $status
     * @param array $searchParams 검색 파라미터 (username, phone, business_name, business_number, request_date)
     * @param int $perPage
     * @param int $page
     * @return LengthAwarePaginator
     */
    public function getEditRequestList(string $status = 'pending', array $searchParams = [], int $perPage = 15, int $page = 1): LengthAwarePaginator
    {
        $query = BusinessVerificationRequest::with(['user', 'businessVerification'])
            ->orderBy('created_at', 'desc');

        // status 필터링
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        // 검색 조건 적용 (여러 조건이 있을 경우 AND 조건)
        $hasSearchParams = false;
        
        // 사용자명 검색
        if (!empty($searchParams['username'])) {
            $query->whereHas('user', function ($userQuery) use ($searchParams) {
                $userQuery->where('username', 'like', "%{$searchParams['username']}%");
            });
            $hasSearchParams = true;
        }

        // 전화번호 검색
        if (!empty($searchParams['phone'])) {
            $query->whereHas('user', function ($userQuery) use ($searchParams) {
                $userQuery->where('phone', 'like', "%{$searchParams['phone']}%");
            });
            $hasSearchParams = true;
        }

        // 상호명 검색
        if (!empty($searchParams['business_name'])) {
            $query->where('business_name', 'like', "%{$searchParams['business_name']}%");
            $hasSearchParams = true;
        }

        // 사업자 등록번호 검색
        if (!empty($searchParams['business_number'])) {
            $query->where('business_number', 'like', "%{$searchParams['business_number']}%");
            $hasSearchParams = true;
        }

        // 요청일시 검색 (년월일)
        if (!empty($searchParams['request_date'])) {
            $date = $searchParams['request_date'];
            $query->whereDate('created_at', $date);
            $hasSearchParams = true;
        }

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * 사업자 정보 수정요청 데이터 포맷팅
     *
     * @param LengthAwarePaginator $requests
     * @return array
     */
    public function formatEditRequestList(LengthAwarePaginator $requests): array
    {
        $data = $requests->map(function ($request) {
            return [
                'id' => $request->id,
                'business_verification_id' => $request->business_verification_id,
                'user_id' => $request->user_id,
                'user' => [
                    'id' => $request->user->id,
                    'username' => $request->user->username,
                    'phone' => $request->user->phone,
                ],
                'business_name' => $request->business_name,
                'business_number' => $request->business_number,
                'business_certificate' => $request->business_certificate,
                'license_certificate' => $request->license_certificate,
                'safety_education_certificate' => $request->safety_education_certificate,
                'address' => $request->address,
                'address_detail' => $request->address_detail,
                'contact_phone_public' => $request->contact_phone_public,
                'available_regions' => $request->available_regions,
                'main_styles' => $request->main_styles,
                'status' => $request->status,
                'rejected_reason' => $request->rejected_reason,
                'approved_at' => $request->approved_at?->toDateTimeString(),
                'created_at' => $request->created_at->toDateTimeString(),
                'updated_at' => $request->updated_at->toDateTimeString(),
            ];
        });

        return [
            'list' => $data,
            'pagination' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'per_page' => $requests->perPage(),
                'total' => $requests->total(),
            ],
        ];
    }

    /**
     * 사업자 정보 수정요청 승인
     *
     * @param int $id
     * @return BusinessVerificationRequest
     * @throws \Exception
     */
    public function approveEditRequest(int $id): BusinessVerificationRequest
    {
        $editRequest = BusinessVerificationRequest::find($id);

        if (!$editRequest) {
            throw new \Exception('해당 수정요청을 찾을 수 없습니다.', 404);
        }

        if ($editRequest->status !== 'pending') {
            throw new \Exception('이미 처리된 요청입니다.', 400);
        }

        // 원본 사업자 정보 업데이트
        $verification = BusinessVerification::find($editRequest->business_verification_id);
        if (!$verification) {
            throw new \Exception('원본 사업자 정보를 찾을 수 없습니다.', 404);
        }

        // 수정요청 데이터로 원본 업데이트
        $verification->update([
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
        ]);

        // 수정요청 상태 업데이트
        $editRequest->update([
            'status' => 'approved',
            'approved_at' => now(),
            'rejected_reason' => null,
        ]);

        return $editRequest;
    }

    /**
     * 사업자 정보 수정요청 반려
     *
     * @param int $id
     * @param string $rejectedReason
     * @return BusinessVerificationRequest
     * @throws \Exception
     */
    public function rejectEditRequest(int $id, string $rejectedReason): BusinessVerificationRequest
    {
        $editRequest = BusinessVerificationRequest::find($id);

        if (!$editRequest) {
            throw new \Exception('해당 수정요청을 찾을 수 없습니다.', 404);
        }

        if ($editRequest->status !== 'pending') {
            throw new \Exception('이미 처리된 요청입니다.', 400);
        }

        $editRequest->update([
            'status' => 'rejected',
            'rejected_reason' => $rejectedReason,
            'approved_at' => null,
        ]);

        return $editRequest;
    }
}

