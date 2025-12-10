<?php

namespace App\Http\Controllers;

use App\Services\BusinessVerificationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class AdminController extends Controller
{
    public function __construct(
        private BusinessVerificationService $businessVerificationService
    ) {
    }
    /**
     * 사업자 가입신청 관리 페이지
     *
     * @return \Illuminate\View\View
     */
    public function businessVerificationIndex()
    {
        return view('admin.business-verification');
    }

    /**
     * 사업자 가입신청 리스트 조회 (API)
     * - status가 pending인 신청만 조회
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getBusinessVerificationList(Request $request): JsonResponse
    {
        try {
            $status = $request->query('status', 'pending');
            $perPage = (int) $request->query('per_page', 15);
            $page = (int) $request->query('page', 1);

            $verifications = $this->businessVerificationService->getVerificationList($status, $perPage, $page);
            $data = $this->businessVerificationService->formatVerificationList($verifications);

            return response()->json([
                'success' => true,
                'data' => $data,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '사업자 신청 리스트 조회 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * 사업자 가입신청 승인 (API)
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function approveBusinessVerification(Request $request, int $id): JsonResponse
    {
        try {
            $verification = $this->businessVerificationService->approveVerification($id);

            return response()->json([
                'success' => true,
                'message' => '사업자 신청이 승인되었습니다.',
                'data' => [
                    'id' => $verification->id,
                    'status' => $verification->status,
                    'approved_at' => $verification->approved_at->toDateTimeString(),
                ],
            ], 200);
        } catch (\Exception $e) {
            $statusCode = (int) $e->getCode();
            $statusCode = ($statusCode >= 400 && $statusCode < 600) ? $statusCode : 500;
            
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: '사업자 신청 승인 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], $statusCode);
        }
    }

    /**
     * 사업자 가입신청 반려 (API)
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function rejectBusinessVerification(Request $request, int $id): JsonResponse
    {
        try {
            // 유효성 검사
            $validator = Validator::make($request->all(), [
                'rejected_reason' => 'required|string|max:500',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => '유효성 검사 실패',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $verification = $this->businessVerificationService->rejectVerification($id, $request->rejected_reason);

            return response()->json([
                'success' => true,
                'message' => '사업자 신청이 반려되었습니다.',
                'data' => [
                    'id' => $verification->id,
                    'status' => $verification->status,
                    'rejected_reason' => $verification->rejected_reason,
                ],
            ], 200);
        } catch (\Exception $e) {
            $statusCode = (int) $e->getCode();
            $statusCode = ($statusCode >= 400 && $statusCode < 600) ? $statusCode : 500;
            
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: '사업자 신청 반려 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], $statusCode);
        }
    }

    /**
     * 사업자 정보 수정요청 관리 페이지
     *
     * @return \Illuminate\View\View
     */
    public function businessEditRequestIndex()
    {
        return view('admin.business-edit-request');
    }

    /**
     * 사업자 정보 수정요청 리스트 조회 (API)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getBusinessEditRequestList(Request $request): JsonResponse
    {
        try {
            $status = $request->query('status', 'pending');
            $perPage = (int) $request->query('per_page', 15);
            $page = (int) $request->query('page', 1);

            // 검색 파라미터 수집
            $searchParams = [];
            if ($request->has('username') && $request->query('username')) {
                $searchParams['username'] = $request->query('username');
            }
            if ($request->has('phone') && $request->query('phone')) {
                $searchParams['phone'] = $request->query('phone');
            }
            if ($request->has('business_name') && $request->query('business_name')) {
                $searchParams['business_name'] = $request->query('business_name');
            }
            if ($request->has('business_number') && $request->query('business_number')) {
                $searchParams['business_number'] = $request->query('business_number');
            }
            if ($request->has('request_date') && $request->query('request_date')) {
                $searchParams['request_date'] = $request->query('request_date');
            }

            $requests = $this->businessVerificationService->getEditRequestList($status, $searchParams, $perPage, $page);
            $data = $this->businessVerificationService->formatEditRequestList($requests);

            return response()->json([
                'success' => true,
                'data' => $data,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '수정요청 리스트 조회 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * 사업자 정보 수정요청 승인 (API)
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function approveBusinessEditRequest(Request $request, int $id): JsonResponse
    {
        try {
            $editRequest = $this->businessVerificationService->approveEditRequest($id);

            return response()->json([
                'success' => true,
                'message' => '수정요청이 승인되었습니다.',
                'data' => [
                    'id' => $editRequest->id,
                    'status' => $editRequest->status,
                    'approved_at' => $editRequest->approved_at->toDateTimeString(),
                ],
            ], 200);
        } catch (\Exception $e) {
            $statusCode = (int) $e->getCode();
            $statusCode = ($statusCode >= 400 && $statusCode < 600) ? $statusCode : 500;
            
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: '수정요청 승인 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], $statusCode);
        }
    }

    /**
     * 사업자 정보 수정요청 반려 (API)
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function rejectBusinessEditRequest(Request $request, int $id): JsonResponse
    {
        try {
            // 유효성 검사
            $validator = Validator::make($request->all(), [
                'rejected_reason' => 'required|string|max:500',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => '유효성 검사 실패',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $editRequest = $this->businessVerificationService->rejectEditRequest($id, $request->rejected_reason);

            return response()->json([
                'success' => true,
                'message' => '수정요청이 반려되었습니다.',
                'data' => [
                    'id' => $editRequest->id,
                    'status' => $editRequest->status,
                    'rejected_reason' => $editRequest->rejected_reason,
                ],
            ], 200);
        } catch (\Exception $e) {
            $statusCode = (int) $e->getCode();
            $statusCode = ($statusCode >= 400 && $statusCode < 600) ? $statusCode : 500;
            
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: '수정요청 반려 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], $statusCode);
        }
    }
}

