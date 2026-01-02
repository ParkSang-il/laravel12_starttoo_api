<?php

namespace App\Http\Controllers;

use App\Services\BusinessVerificationService;
use App\Models\Portfolio;
use App\Models\PortfolioImage;
use App\Models\PortfolioTag;
use App\Models\PortfolioReport;
use App\Models\PortfolioComment;
use App\Models\PortfolioCommentReport;
use App\Models\AdminLoginLog;
use App\Models\Tag;
use App\Models\User;
use App\Models\UserLoginLog;
use App\Models\BusinessVerification;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class AdminController extends Controller
{
    public function __construct(
        private BusinessVerificationService $businessVerificationService
    ) {
    }

    /**
     * 관리자 로그인 로그 조회
     */
    public function getAdminLoginLogs(Request $request): JsonResponse
    {
        try {
            $query = AdminLoginLog::query()->with('admin');

            if ($request->filled('username')) {
                $query->where('username', 'like', '%' . $request->username . '%');
            }

            if ($request->filled('ip')) {
                $query->where('ip_address', 'like', '%' . $request->ip . '%');
            }

            if ($request->filled('date_from')) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }

            if ($request->filled('date_to')) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }

            $logs = $query->orderByDesc('created_at')->paginate($request->integer('per_page', 20));

            return response()->json([
                'success' => true,
                'data' => $logs,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '관리자 로그인 로그 조회 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
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

    /**
     * 포트폴리오 관리 페이지
     *
     * @return \Illuminate\View\View
     */
    public function portfolioIndex()
    {
        return view('admin.portfolio');
    }

    /**
     * 포트폴리오 목록 조회 (API)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getPortfolioList(Request $request): JsonResponse
    {
        try {
            $perPage = (int) $request->query('per_page', 15);
            $page = (int) $request->query('page', 1);
            $search = $request->query('search');
            $userId = $request->query('user_id');
            $hasReports = $request->query('has_reports');

            $query = Portfolio::withTrashed()
                ->with(['images', 'tags', 'user:id,username,profile_image'])
                ->withCount('reports as reports_count')
                ->withCount(['reports as pending_reports_count' => function ($q) {
                    $q->where('status', 'pending');
                }]);

            // 검색 조건
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
                });
            }

            // 사용자 필터
            if ($userId) {
                $query->where('user_id', $userId);
            }

            // 신고 여부 필터
            if ($hasReports === 'true' || $hasReports === '1') {
                $query->has('reports');
            }

            $portfolios = $query->orderBy('created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);

            $data = $portfolios->map(function ($portfolio) {
                $hasReports = $portfolio->reports_count > 0;
                $hasPendingReports = $portfolio->pending_reports_count > 0;

                return [
                    'id' => $portfolio->id,
                    'title' => $portfolio->title,
                    'description' => $portfolio->description,
                    'user' => [
                        'id' => $portfolio->user->id,
                        'username' => $portfolio->user->username,
                        'profile_image' => $portfolio->user->profile_image,
                    ],
                    'tags' => $portfolio->tags->map(function ($tag) {
                        return [
                            'id' => $tag->id,
                            'name' => $tag->name,
                        ];
                    }),
                    'images' => $portfolio->images->map(function ($image) {
                        return [
                            'id' => $image->id,
                            'image_url' => $image->image_url,
                            'image_order' => $image->image_order,
                        ];
                    }),
                    'created_at' => $portfolio->created_at->format('Y-m-d H:i:s'),
                    'reports_count' => $portfolio->reports_count,
                    'pending_reports_count' => $portfolio->pending_reports_count,
                    'has_reports' => $hasReports,
                    'has_pending_reports' => $hasPendingReports,
                    'is_sensitive' => $portfolio->is_sensitive,
                    'is_public' => $portfolio->is_public,
                    'deleted_at' => $portfolio->deleted_at ? $portfolio->deleted_at->format('Y-m-d H:i:s') : null,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'list' => $data,
                    'pagination' => [
                        'current_page' => $portfolios->currentPage(),
                        'last_page' => $portfolios->lastPage(),
                        'per_page' => $portfolios->perPage(),
                        'total' => $portfolios->total(),
                    ],
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '포트폴리오 목록 조회 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * 포트폴리오 상세 조회 (API)
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function getPortfolioDetail(Request $request, int $id): JsonResponse
    {
        try {
            $portfolio = Portfolio::withTrashed()
                ->with(['images', 'tags', 'user:id,username,profile_image'])
                ->withCount('reports as reports_count')
                ->withCount(['reports as pending_reports_count' => function ($q) {
                    $q->where('status', 'pending');
                }])
                ->find($id);

            if (!$portfolio) {
                return response()->json([
                    'success' => false,
                    'message' => '포트폴리오를 찾을 수 없습니다.',
                ], 404);
            }

            // 댓글 조회 (삭제된 것 포함, 대댓글 포함)
            // 고정된 댓글이 맨 위에 오도록 정렬
            $comments = PortfolioComment::withTrashed()
                ->where('portfolio_id', $id)
                ->whereNull('parent_id')
                ->with(['user:id,username,profile_image'])
                ->withCount('replies')
                ->orderBy('is_pinned', 'desc')
                ->orderBy('created_at', 'desc')
                ->get();

            $commentsData = $comments->map(function ($comment) {
                // 대댓글 조회 (삭제된 것 포함)
                $replies = PortfolioComment::withTrashed()
                    ->where('parent_id', $comment->id)
                    ->with(['user:id,username,profile_image'])
                    ->orderBy('created_at', 'asc')
                    ->get();

                $repliesData = $replies->map(function ($reply) {
                    return [
                        'id' => $reply->id,
                        'content' => $reply->content,
                        'user' => [
                            'id' => $reply->user->id,
                            'username' => $reply->user->username,
                            'profile_image' => $reply->user->profile_image,
                        ],
                        'is_deleted' => $reply->is_deleted,
                        'created_at' => $reply->created_at->format('Y-m-d H:i:s'),
                        'deleted_at' => $reply->deleted_at ? $reply->deleted_at->format('Y-m-d H:i:s') : null,
                    ];
                });

                return [
                    'id' => $comment->id,
                    'content' => $comment->content,
                    'user' => [
                        'id' => $comment->user->id,
                        'username' => $comment->user->username,
                        'profile_image' => $comment->user->profile_image,
                    ],
                    'replies_count' => $comment->replies_count,
                    'replies' => $repliesData,
                    'is_deleted' => $comment->is_deleted,
                    'is_pinned' => $comment->is_pinned,
                    'created_at' => $comment->created_at->format('Y-m-d H:i:s'),
                    'deleted_at' => $comment->deleted_at ? $comment->deleted_at->format('Y-m-d H:i:s') : null,
                ];
            });

            $hasReports = $portfolio->reports_count > 0;
            $hasPendingReports = $portfolio->pending_reports_count > 0;

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $portfolio->id,
                    'title' => $portfolio->title,
                    'description' => $portfolio->description,
                    'work_date' => $portfolio->work_date ? $portfolio->work_date->format('Y-m-d') : null,
                    'price' => $portfolio->price,
                    'user' => [
                        'id' => $portfolio->user->id,
                        'username' => $portfolio->user->username,
                        'profile_image' => $portfolio->user->profile_image,
                    ],
                    'tags' => $portfolio->tags->map(function ($tag) {
                        return [
                            'id' => $tag->id,
                            'name' => $tag->name,
                        ];
                    }),
                    'images' => $portfolio->images->map(function ($image) {
                        return [
                            'id' => $image->id,
                            'image_url' => $image->image_url,
                            'image_order' => $image->image_order,
                            'scale' => $image->scale,
                            'offset_x' => $image->offset_x,
                            'offset_y' => $image->offset_y,
                        ];
                    }),
                    'comments' => $commentsData,
                    'created_at' => $portfolio->created_at->format('Y-m-d H:i:s'),
                    'reports_count' => $portfolio->reports_count,
                    'pending_reports_count' => $portfolio->pending_reports_count,
                    'has_reports' => $hasReports,
                    'has_pending_reports' => $hasPendingReports,
                    'is_sensitive' => $portfolio->is_sensitive,
                    'is_public' => $portfolio->is_public,
                    'deleted_at' => $portfolio->deleted_at ? $portfolio->deleted_at->format('Y-m-d H:i:s') : null,
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '포트폴리오 상세 조회 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * 포트폴리오 수정 (API)
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function updatePortfolio(Request $request, int $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'title' => 'nullable|string|max:255',
                'description' => 'nullable|string|max:5000',
                'work_date' => 'nullable|date',
                'price' => 'nullable|numeric|min:0|max:99999999.99',
                'is_public' => 'nullable|boolean',
                'is_sensitive' => 'nullable|boolean',
                'images' => 'nullable|array|min:1',
                'images.*.image_url' => 'required|string|max:255',
                'images.*.image_order' => 'nullable|integer|min:0',
                'images.*.scale' => 'nullable|numeric|min:0|max:10',
                'images.*.offset_x' => 'nullable|numeric|min:0|max:1',
                'images.*.offset_y' => 'nullable|numeric|min:0|max:1',
                'tags' => 'nullable|array',
                'tags.*' => 'string|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => '유효성 검사 실패',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $portfolio = Portfolio::withTrashed()->find($id);

            if (!$portfolio) {
                return response()->json([
                    'success' => false,
                    'message' => '포트폴리오를 찾을 수 없습니다.',
                ], 404);
            }

            DB::beginTransaction();

            try {
                // 포트폴리오 기본 정보 수정
                $updateData = [];
                if ($request->has('title')) {
                    $updateData['title'] = $request->input('title');
                }
                if ($request->has('description')) {
                    $updateData['description'] = $request->input('description');
                }
                if ($request->has('work_date')) {
                    $updateData['work_date'] = $request->input('work_date');
                }
                if ($request->has('price')) {
                    $updateData['price'] = $request->input('price');
                }
                if ($request->has('is_public')) {
                    $updateData['is_public'] = $request->input('is_public');
                }
                if ($request->has('is_sensitive')) {
                    $updateData['is_sensitive'] = $request->input('is_sensitive');
                }

                if (!empty($updateData)) {
                    $portfolio->update($updateData);
                }

                // 이미지 수정 (전체 교체)
                if ($request->has('images')) {
                    // 기존 이미지 삭제
                    PortfolioImage::where('portfolio_id', $portfolio->id)->delete();

                    // 새 이미지 생성
                    $images = $request->input('images');
                    foreach ($images as $index => $image) {
                        PortfolioImage::create([
                            'portfolio_id' => $portfolio->id,
                            'image_url' => $image['image_url'],
                            'image_order' => $image['image_order'] ?? $index,
                            'scale' => $image['scale'] ?? null,
                            'offset_x' => $image['offset_x'] ?? null,
                            'offset_y' => $image['offset_y'] ?? null,
                        ]);
                    }
                }

                // 태그 수정 (전체 교체)
                if ($request->has('tags')) {
                    $tagNames = $request->input('tags');
                    $tagIds = [];

                    foreach ($tagNames as $tagName) {
                        $tag = Tag::firstOrCreate(
                            ['name' => $tagName],
                            ['usage_count' => 0]
                        );
                        $tagIds[] = $tag->id;
                    }

                    // 기존 태그 사용 횟수 감소
                    $oldTagIds = $portfolio->tags()->pluck('tags.id')->toArray();
                    if (!empty($oldTagIds)) {
                        Tag::whereIn('id', $oldTagIds)->decrement('usage_count');
                    }

                    // 포트폴리오와 태그 연결
                    $portfolio->tags()->sync($tagIds);

                    // 새 태그 사용 횟수 증가
                    Tag::whereIn('id', $tagIds)->increment('usage_count');
                }

                DB::commit();

                $portfolio->load(['images', 'tags', 'user:id,username,profile_image']);

                return response()->json([
                    'success' => true,
                    'message' => '포트폴리오가 수정되었습니다.',
                    'data' => [
                        'portfolio' => $portfolio,
                    ],
                ], 200);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '포트폴리오 수정 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * 포트폴리오 삭제 (Soft Delete) (API)
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function deletePortfolio(Request $request, int $id): JsonResponse
    {
        try {
            $portfolio = Portfolio::withTrashed()->find($id);

            if (!$portfolio) {
                return response()->json([
                    'success' => false,
                    'message' => '포트폴리오를 찾을 수 없습니다.',
                ], 404);
            }

            if ($portfolio->trashed()) {
                return response()->json([
                    'success' => false,
                    'message' => '이미 삭제된 포트폴리오입니다.',
                ], 409);
            }

            $portfolio->delete();

            return response()->json([
                'success' => true,
                'message' => '포트폴리오가 삭제되었습니다.',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '포트폴리오 삭제 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * 포트폴리오 민감정보 처리 (API)
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function toggleSensitive(Request $request, int $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'is_sensitive' => 'required|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => '유효성 검사 실패',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $portfolio = Portfolio::withTrashed()->find($id);

            if (!$portfolio) {
                return response()->json([
                    'success' => false,
                    'message' => '포트폴리오를 찾을 수 없습니다.',
                ], 404);
            }

            $portfolio->update([
                'is_sensitive' => $request->input('is_sensitive'),
            ]);

            //민감정보 처리시 모든 신고건 완료처리
            if ($request->input('is_sensitive')) {
                PortfolioReport::where('portfolio_id', $portfolio->id)
                    ->where('status', 'pending')
                    ->update([
                        'status' => 'resolved',
                        'reviewed_at' => now(),
                    ]);
            }

            return response()->json([
                'success' => true,
                'message' => $request->input('is_sensitive')
                    ? '민감정보로 표시되었습니다.'
                    : '민감정보 표시가 해제되었습니다.',
                'data' => [
                    'id' => $portfolio->id,
                    'is_sensitive' => $portfolio->is_sensitive,
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '민감정보 처리 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * 댓글 관리 페이지
     *
     * @return \Illuminate\View\View
     */
    public function commentIndex()
    {
        return view('admin.comment');
    }

    /**
     * 댓글 목록 조회 (API)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getCommentList(Request $request): JsonResponse
    {
        try {
            $perPage = (int) $request->query('per_page', 15);
            $page = (int) $request->query('page', 1);
            $search = $request->query('search');
            $portfolioId = $request->query('portfolio_id');
            $commentType = $request->query('comment_type'); // 'comment' or 'reply'
            $isDeleted = $request->query('is_deleted');
            $isPinned = $request->query('is_pinned');
            $hasReports = $request->query('has_reports');

            $query = PortfolioComment::withTrashed()
                ->with(['user:id,username,profile_image', 'portfolio:id,title'])
                ->withCount('reports as reports_count')
                ->withCount(['reports as pending_reports_count' => function ($q) {
                    $q->where('status', 'pending');
                }])
                ->withCount('replies');

            // 검색 조건
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('content', 'like', "%{$search}%")
                      ->orWhereHas('user', function ($userQuery) use ($search) {
                          $userQuery->where('username', 'like', "%{$search}%");
                      });
                });
            }

            // 포트폴리오 필터
            if ($portfolioId) {
                $query->where('portfolio_id', $portfolioId);
            }

            // 댓글 타입 필터 (댓글/대댓글)
            if ($commentType === 'comment') {
                $query->whereNull('parent_id');
            } elseif ($commentType === 'reply') {
                $query->whereNotNull('parent_id');
            }

            // 삭제 여부 필터
            if ($isDeleted !== null) {
                if ($isDeleted === 'true' || $isDeleted === '1') {
                    $query->whereNotNull('deleted_at');
                } else {
                    $query->whereNull('deleted_at');
                }
            }

            // 고정 여부 필터
            if ($isPinned !== null) {
                $query->where('is_pinned', $isPinned === 'true' || $isPinned === '1');
            }

            // 신고 여부 필터
            if ($hasReports === 'true' || $hasReports === '1') {
                $query->has('reports');
            }

            $comments = $query->orderBy('created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);

            $data = $comments->map(function ($comment) {
                $hasReports = $comment->reports_count > 0;
                $hasPendingReports = $comment->pending_reports_count > 0;

                return [
                    'id' => $comment->id,
                    'type' => $comment->parent_id ? '대댓글' : '댓글',
                    'parent_id' => $comment->parent_id,
                    'content' => $comment->content,
                    'user' => [
                        'id' => $comment->user->id,
                        'username' => $comment->user->username,
                        'profile_image' => $comment->user->profile_image,
                    ],
                    'portfolio' => [
                        'id' => $comment->portfolio->id,
                        'title' => $comment->portfolio->title,
                    ],
                    'replies_count' => $comment->replies_count ?? 0,
                    'is_deleted' => $comment->is_deleted,
                    'is_pinned' => $comment->is_pinned,
                    'reports_count' => $comment->reports_count,
                    'pending_reports_count' => $comment->pending_reports_count,
                    'has_reports' => $hasReports,
                    'has_pending_reports' => $hasPendingReports,
                    'created_at' => $comment->created_at->format('Y-m-d H:i:s'),
                    'updated_at' => $comment->updated_at->format('Y-m-d H:i:s'),
                    'deleted_at' => $comment->deleted_at ? $comment->deleted_at->format('Y-m-d H:i:s') : null,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'list' => $data,
                    'pagination' => [
                        'current_page' => $comments->currentPage(),
                        'last_page' => $comments->lastPage(),
                        'per_page' => $comments->perPage(),
                        'total' => $comments->total(),
                    ],
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '댓글 목록 조회 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * 댓글 상세 조회 (API)
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function getCommentDetail(Request $request, int $id): JsonResponse
    {
        try {
            $comment = PortfolioComment::withTrashed()
                ->with(['user:id,username,profile_image', 'portfolio:id,title,user_id'])
                ->withCount('reports as reports_count')
                ->withCount(['reports as pending_reports_count' => function ($q) {
                    $q->where('status', 'pending');
                }])
                ->find($id);

            if (!$comment) {
                return response()->json([
                    'success' => false,
                    'message' => '댓글을 찾을 수 없습니다.',
                ], 404);
            }

            // 대댓글 조회 (댓글인 경우)
            $replies = [];
            if (!$comment->parent_id) {
                $replies = PortfolioComment::withTrashed()
                    ->where('parent_id', $comment->id)
                    ->with(['user:id,username,profile_image'])
                    ->orderBy('created_at', 'asc')
                    ->get()
                    ->map(function ($reply) {
                        return [
                            'id' => $reply->id,
                            'content' => $reply->content,
                            'user' => [
                                'id' => $reply->user->id,
                                'username' => $reply->user->username,
                                'profile_image' => $reply->user->profile_image,
                            ],
                            'is_deleted' => $reply->is_deleted,
                            'created_at' => $reply->created_at->format('Y-m-d H:i:s'),
                            'deleted_at' => $reply->deleted_at ? $reply->deleted_at->format('Y-m-d H:i:s') : null,
                        ];
                    });
            }

            // 신고 내역 조회
            $reports = PortfolioCommentReport::where('comment_id', $id)
                ->with(['user:id,username'])
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($report) {
                    return [
                        'id' => $report->id,
                        'report_type' => $report->report_type,
                        'reason' => $report->reason,
                        'status' => $report->status,
                        'user' => [
                            'id' => $report->user->id,
                            'username' => $report->user->username,
                        ],
                        'created_at' => $report->created_at->format('Y-m-d H:i:s'),
                    ];
                });

            $hasReports = $comment->reports_count > 0;
            $hasPendingReports = $comment->pending_reports_count > 0;

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $comment->id,
                    'type' => $comment->parent_id ? '대댓글' : '댓글',
                    'parent_id' => $comment->parent_id,
                    'content' => $comment->content,
                    'user' => [
                        'id' => $comment->user->id,
                        'username' => $comment->user->username,
                        'profile_image' => $comment->user->profile_image,
                    ],
                    'portfolio' => [
                        'id' => $comment->portfolio->id,
                        'title' => $comment->portfolio->title,
                    ],
                    'replies' => $replies,
                    'replies_count' => $comment->replies_count ?? 0,
                    'is_deleted' => $comment->is_deleted,
                    'is_pinned' => $comment->is_pinned,
                    'reports' => $reports,
                    'reports_count' => $comment->reports_count,
                    'pending_reports_count' => $comment->pending_reports_count,
                    'has_reports' => $hasReports,
                    'has_pending_reports' => $hasPendingReports,
                    'created_at' => $comment->created_at->format('Y-m-d H:i:s'),
                    'updated_at' => $comment->updated_at->format('Y-m-d H:i:s'),
                    'deleted_at' => $comment->deleted_at ? $comment->deleted_at->format('Y-m-d H:i:s') : null,
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '댓글 상세 조회 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * 댓글 수정 (API)
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function updateComment(Request $request, int $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'content' => 'required|string|max:1000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => '유효성 검사 실패',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $comment = PortfolioComment::withTrashed()->find($id);

            if (!$comment) {
                return response()->json([
                    'success' => false,
                    'message' => '댓글을 찾을 수 없습니다.',
                ], 404);
            }

            $comment->update([
                'content' => $request->input('content'),
            ]);

            return response()->json([
                'success' => true,
                'message' => '댓글이 수정되었습니다.',
                'data' => [
                    'comment' => [
                        'id' => $comment->id,
                        'content' => $comment->content,
                    ],
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '댓글 수정 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * 댓글 삭제 (Soft Delete) (API)
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function deleteComment(Request $request, int $id): JsonResponse
    {
        try {
            $comment = PortfolioComment::withTrashed()->find($id);

            if (!$comment) {
                return response()->json([
                    'success' => false,
                    'message' => '댓글을 찾을 수 없습니다.',
                ], 404);
            }

            if ($comment->is_deleted) {
                return response()->json([
                    'success' => false,
                    'message' => '이미 삭제된 댓글입니다.',
                ], 409);
            }

            DB::beginTransaction();

            try {
                $parentId = $comment->parent_id;
                $isTopLevel = !$parentId;

                // 댓글 삭제 플래그 변경
                $comment->update([
                    'is_deleted' => true,
                    'deleted_at' => now(),
                ]);

                // 대댓글인 경우 부모 댓글의 replies_count 감소
                if ($parentId) {
                    PortfolioComment::where('id', $parentId)->decrement('replies_count');
                }

                // 포트폴리오 댓글 수 감소 (상위 댓글만 카운트)
                if ($isTopLevel) {
                    $portfolio = Portfolio::find($comment->portfolio_id);
                    if ($portfolio) {
                        $portfolio->decrement('comments_count');
                    }

                    // 통계 업데이트
                    if ($portfolio && $portfolio->is_public) {
                        $stats = $portfolio->getOrCreateStats();
                        $stats->decrementComments();
                    }
                }

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => '댓글이 삭제되었습니다.',
                ], 200);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '댓글 삭제 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * 댓글 복원 (API)
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function restoreComment(Request $request, int $id): JsonResponse
    {
        try {
            $comment = PortfolioComment::withTrashed()->find($id);

            if (!$comment) {
                return response()->json([
                    'success' => false,
                    'message' => '댓글을 찾을 수 없습니다.',
                ], 404);
            }

            if (!$comment->is_deleted) {
                return response()->json([
                    'success' => false,
                    'message' => '삭제되지 않은 댓글입니다.',
                ], 409);
            }

            DB::beginTransaction();

            try {
                $parentId = $comment->parent_id;
                $isTopLevel = !$parentId;

                // 댓글 복원
                $comment->update([
                    'is_deleted' => false,
                    'deleted_at' => null,
                ]);

                // 대댓글인 경우 부모 댓글의 replies_count 증가
                if ($parentId) {
                    PortfolioComment::where('id', $parentId)->increment('replies_count');
                }

                // 포트폴리오 댓글 수 증가 (상위 댓글만 카운트)
                if ($isTopLevel) {
                    $portfolio = Portfolio::find($comment->portfolio_id);
                    if ($portfolio) {
                        $portfolio->increment('comments_count');
                    }

                    // 통계 업데이트
                    if ($portfolio && $portfolio->is_public) {
                        $stats = $portfolio->getOrCreateStats();
                        $stats->incrementComments();
                    }
                }

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => '댓글이 복원되었습니다.',
                ], 200);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '댓글 복원 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * 신고 카운트 조회 (API)
     * 사이드바 메뉴에 표시할 신고 카운트
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getReportCounts(Request $request): JsonResponse
    {
        try {
            // 포트폴리오 신고 카운트 (대기중)
            $portfolioPendingReports = PortfolioReport::where('status', 'pending')->count();

            // 댓글 신고 카운트 (대기중)
            $commentPendingReports = PortfolioCommentReport::where('status', 'pending')->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'portfolio_reports' => $portfolioPendingReports,
                    'comment_reports' => $commentPendingReports,
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '신고 카운트 조회 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * 포트폴리오 신고 리스트 조회 (API)
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function getPortfolioReports(Request $request, int $id): JsonResponse
    {
        try {
            $portfolio = Portfolio::find($id);

            if (!$portfolio) {
                return response()->json([
                    'success' => false,
                    'message' => '포트폴리오를 찾을 수 없습니다.',
                ], 404);
            }

            $reports = PortfolioReport::where('portfolio_id', $id)
                ->with(['user:id,username'])
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($report) {
                    return [
                        'id' => $report->id,
                        'report_type' => $report->report_type,
                        'reason' => $report->reason,
                        'status' => $report->status,
                        'admin_note' => $report->admin_note,
                        'user' => [
                            'id' => $report->user->id,
                            'username' => $report->user->username,
                        ],
                        'created_at' => $report->created_at->format('Y-m-d H:i:s'),
                        'reviewed_at' => $report->reviewed_at ? $report->reviewed_at->format('Y-m-d H:i:s') : null,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => [
                    'portfolio_id' => $portfolio->id,
                    'portfolio_title' => $portfolio->title,
                    'reports' => $reports,
                    'total_count' => $reports->count(),
                    'pending_count' => $reports->where('status', 'pending')->count(),
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '신고 리스트 조회 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * 댓글 신고 리스트 조회 (API)
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function getCommentReports(Request $request, int $id): JsonResponse
    {
        try {
            $comment = PortfolioComment::withTrashed()->find($id);

            if (!$comment) {
                return response()->json([
                    'success' => false,
                    'message' => '댓글을 찾을 수 없습니다.',
                ], 404);
            }

            $reports = PortfolioCommentReport::where('comment_id', $id)
                ->with(['user:id,username'])
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($report) {
                    return [
                        'id' => $report->id,
                        'report_type' => $report->report_type,
                        'reason' => $report->reason,
                        'status' => $report->status,
                        'admin_note' => $report->admin_note,
                        'user' => [
                            'id' => $report->user->id,
                            'username' => $report->user->username,
                        ],
                        'created_at' => $report->created_at->format('Y-m-d H:i:s'),
                        'reviewed_at' => $report->reviewed_at ? $report->reviewed_at->format('Y-m-d H:i:s') : null,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => [
                    'comment_id' => $comment->id,
                    'comment_content' => $comment->content,
                    'reports' => $reports,
                    'total_count' => $reports->count(),
                    'pending_count' => $reports->where('status', 'pending')->count(),
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '신고 리스트 조회 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * 포트폴리오 신고 상태 변경 (API)
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function updatePortfolioReportStatus(Request $request, int $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'status' => 'required|string|in:resolved,rejected',
                'admin_note' => 'nullable|string|max:1000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => '유효성 검사 실패',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $report = PortfolioReport::find($id);

            if (!$report) {
                return response()->json([
                    'success' => false,
                    'message' => '신고를 찾을 수 없습니다.',
                ], 404);
            }

            $report->update([
                'status' => $request->input('status'),
                'admin_note' => $request->input('admin_note'),
                'reviewed_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => $request->input('status') === 'resolved'
                    ? '신고가 처리완료되었습니다.'
                    : '신고가 거절되었습니다.',
                'data' => [
                    'id' => $report->id,
                    'status' => $report->status,
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '신고 상태 변경 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * 댓글 신고 상태 변경 (API)
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function updateCommentReportStatus(Request $request, int $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'status' => 'required|string|in:resolved,rejected',
                'admin_note' => 'nullable|string|max:1000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => '유효성 검사 실패',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $report = PortfolioCommentReport::find($id);

            if (!$report) {
                return response()->json([
                    'success' => false,
                    'message' => '신고를 찾을 수 없습니다.',
                ], 404);
            }

            $report->update([
                'status' => $request->input('status'),
                'admin_note' => $request->input('admin_note'),
                'reviewed_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => $request->input('status') === 'resolved'
                    ? '신고가 처리완료되었습니다.'
                    : '신고가 거절되었습니다.',
                'data' => [
                    'id' => $report->id,
                    'status' => $report->status,
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '신고 상태 변경 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * 회원 관리 페이지
     *
     * @return \Illuminate\View\View
     */
    public function userIndex()
    {
        return view('admin.user');
    }

    /**
     * 관리자 로그인 로그 페이지
     */
    public function adminLoginLogIndex()
    {
        return view('admin.admin-login-log');
    }

    /**
     * 회원 리스트 조회 (API)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getUserList(Request $request): JsonResponse
    {
        try {
            $perPage = (int) $request->query('per_page', 15);
            $page = (int) $request->query('page', 1);
            $search = $request->query('search');
            $userType = $request->query('user_type');
            $suspensionStatus = $request->query('suspension_status'); // all, suspended, not_suspended

            $query = User::withTrashed()
                ->with(['suspendedByUser:id,username'])
                ->select('users.*');

            // 검색 필터
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('username', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            }

            // 회원 유형 필터
            if ($userType !== null && $userType !== '') {
                $query->where('user_type', $userType);
            }

            // 정지 상태 필터
            if ($suspensionStatus === 'suspended') {
                $query->where(function ($q) {
                    $q->whereNotNull('suspended_until')
                        ->where(function ($q2) {
                            $q2->where('suspended_until', '>=', now())
                                ->orWhere('suspended_until', '>=', '9999-12-31 00:00:00');
                        });
                });
            } elseif ($suspensionStatus === 'not_suspended') {
                $query->where(function ($q) {
                    $q->whereNull('suspended_until')
                        ->orWhere('suspended_until', '<', now());
                });
            }

            $users = $query->orderBy('created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);

            $list = $users->map(function ($user) {
                $isSuspended = $user->isSuspended();
                $suspensionText = $user->getSuspensionStatusText();

                return [
                    'id' => $user->id,
                    'username' => $user->username,
                    'phone' => $user->phone,
                    'user_type' => $user->user_type,
                    'user_type_text' => $user->user_type === 1 ? '일반회원' : '사업자',
                    'profile_image' => $user->profile_image,
                    'phone_verified_at' => $user->phone_verified_at?->format('Y-m-d H:i:s'),
                    'is_suspended' => $isSuspended,
                    'suspension_status_text' => $suspensionText,
                    'suspension_type' => $user->suspension_type,
                    'suspension_reason' => $user->suspension_reason,
                    'suspended_until' => $user->suspended_until?->format('Y-m-d H:i:s'),
                    'suspended_at' => $user->suspended_at?->format('Y-m-d H:i:s'),
                    'suspended_by' => $user->suspendedByUser ? [
                        'id' => $user->suspendedByUser->id,
                        'username' => $user->suspendedByUser->username,
                    ] : null,
                    'created_at' => $user->created_at->format('Y-m-d H:i:s'),
                    'deleted_at' => $user->deleted_at?->format('Y-m-d H:i:s'),
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'list' => $list,
                    'pagination' => [
                        'current_page' => $users->currentPage(),
                        'last_page' => $users->lastPage(),
                        'per_page' => $users->perPage(),
                        'total' => $users->total(),
                    ],
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '회원 목록 조회 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * 회원 상세 정보 조회 (API)
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function getUserDetail(Request $request, int $id): JsonResponse
    {
        try {
            $user = User::withTrashed()
                ->with(['suspendedByUser:id,username', 'artistProfile'])
                ->find($id);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => '회원을 찾을 수 없습니다.',
                ], 404);
            }

            $isSuspended = $user->isSuspended();
            $suspensionText = $user->getSuspensionStatusText();

            // 포트폴리오 수, 댓글 수 등 통계
            $portfoliosCount = $user->portfolios()->count();
            $commentsCount = PortfolioComment::where('user_id', $user->id)->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $user->id,
                    'username' => $user->username,
                    'phone' => $user->phone,
                    'user_type' => $user->user_type,
                    'user_type_text' => $user->user_type === 1 ? '일반회원' : '사업자',
                    'profile_image' => $user->profile_image,
                    'phone_verified_at' => $user->phone_verified_at?->format('Y-m-d H:i:s'),
                    'is_suspended' => $isSuspended,
                    'suspension_status_text' => $suspensionText,
                    'suspension_type' => $user->suspension_type,
                    'suspension_reason' => $user->suspension_reason,
                    'suspended_until' => $user->suspended_until?->format('Y-m-d H:i:s'),
                    'suspended_at' => $user->suspended_at?->format('Y-m-d H:i:s'),
                    'suspended_by' => $user->suspendedByUser ? [
                        'id' => $user->suspendedByUser->id,
                        'username' => $user->suspendedByUser->username,
                    ] : null,
                    'portfolios_count' => $portfoliosCount,
                    'comments_count' => $commentsCount,
                    'created_at' => $user->created_at->format('Y-m-d H:i:s'),
                    'updated_at' => $user->updated_at->format('Y-m-d H:i:s'),
                    'deleted_at' => $user->deleted_at?->format('Y-m-d H:i:s'),
                    'artist_profile' => $user->artistProfile,
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '회원 정보 조회 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * 회원 정지 처리 (API)
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function suspendUser(Request $request, int $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'suspension_type' => 'required|string|in:5days,10days,15days,30days,permanent',
                'suspension_reason' => 'required|string|max:1000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => '유효성 검사 실패',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $admin = Auth::guard('admin')->user();
            if (!$admin) {
                return response()->json([
                    'success' => false,
                    'message' => '인증되지 않은 사용자입니다.',
                ], 401);
            }

            $user = User::find($id);
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => '회원을 찾을 수 없습니다.',
                ], 404);
            }

            $suspensionType = $request->input('suspension_type');
            $suspensionReason = $request->input('suspension_reason');

            // 정지 해제 일시 계산
            $suspendedUntil = null;
            if ($suspensionType === 'permanent') {
                $suspendedUntil = \Carbon\Carbon::create(9999, 12, 31, 0, 0, 0);
            } else {
                $days = (int) str_replace('days', '', $suspensionType);
                $suspendedUntil = now()->addDays($days);
            }

            DB::beginTransaction();

            try {
                $user->update([
                    'suspended_until' => $suspendedUntil,
                    'suspension_type' => $suspensionType,
                    'suspension_reason' => $suspensionReason,
                    'suspended_by' => $admin->id,
                    'suspended_at' => now(),
                ]);

                DB::commit();

                $suspensionText = $suspensionType === 'permanent'
                    ? '영구정지'
                    : str_replace('days', '일', $suspensionType);

                return response()->json([
                    'success' => true,
                    'message' => "회원이 {$suspensionText}로 정지되었습니다.",
                    'data' => [
                        'user_id' => $user->id,
                        'suspension_type' => $suspensionType,
                        'suspended_until' => $suspendedUntil->format('Y-m-d H:i:s'),
                    ],
                ], 200);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '회원 정지 처리 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * 회원 정지 해제 (API)
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function unsuspendUser(Request $request, int $id): JsonResponse
    {
        try {
            $user = User::find($id);
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => '회원을 찾을 수 없습니다.',
                ], 404);
            }

            DB::beginTransaction();

            try {
                $user->update([
                    'suspended_until' => null,
                    'suspension_type' => null,
                    'suspension_reason' => null,
                    'suspended_by' => null,
                    'suspended_at' => null,
                ]);

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => '회원 정지가 해제되었습니다.',
                    'data' => [
                        'user_id' => $user->id,
                    ],
                ], 200);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '회원 정지 해제 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * 회원 로그인 기록 조회 (API)
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function getUserLoginLogs(Request $request, int $id): JsonResponse
    {
        try {
            $user = User::find($id);
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => '회원을 찾을 수 없습니다.',
                ], 404);
            }

            $perPage = (int) $request->query('per_page', 50);
            $page = (int) $request->query('page', 1);

            $logs = UserLoginLog::where('user_id', $id)
                ->orderBy('created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);

            $list = $logs->map(function ($log) {
                return [
                    'id' => $log->id,
                    'ip_address' => $log->ip_address,
                    'user_agent' => $log->user_agent,
                    'device_type' => $log->device_type,
                    'device_model' => $log->device_model,
                    'os' => $log->os,
                    'browser' => $log->browser,
                    'login_type' => $log->login_type,
                    'is_success' => $log->is_success,
                    'failure_reason' => $log->failure_reason,
                    'created_at' => $log->created_at->format('Y-m-d H:i:s'),
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'user_id' => $user->id,
                    'username' => $user->username,
                    'logs' => $list,
                    'pagination' => [
                        'current_page' => $logs->currentPage(),
                        'last_page' => $logs->lastPage(),
                        'per_page' => $logs->perPage(),
                        'total' => $logs->total(),
                    ],
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '로그인 기록 조회 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * 사용자별 사업자 가입신청 정보 조회 (API)
     *
     * @param Request $request
     * @param int $id 사용자 ID
     * @return JsonResponse
     */
    public function getUserBusinessVerification(Request $request, int $id): JsonResponse
    {
        try {
            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => '회원을 찾을 수 없습니다.',
                ], 404);
            }

            // 사업자 가입신청 정보 조회
            $verification = BusinessVerification::where('user_id', $id)
                ->orderBy('created_at', 'desc')
                ->first();

            if (!$verification) {
                return response()->json([
                    'success' => false,
                    'message' => '사업자 가입신청 정보가 없습니다.',
                ], 404);
            }

            $statusText = match($verification->status) {
                'pending' => '대기중',
                'approved' => '승인됨',
                'rejected' => '거절됨',
                default => $verification->status,
            };

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $verification->id,
                    'user_id' => $verification->user_id,
                    'username' => $user->username,
                    'business_name' => $verification->business_name,
                    'business_number' => $verification->business_number,
                    'business_certificate' => $verification->business_certificate,
                    'license_certificate' => $verification->license_certificate,
                    'safety_education_certificate' => $verification->safety_education_certificate,
                    'address' => $verification->address,
                    'address_detail' => $verification->address_detail,
                    'contact_phone_public' => $verification->contact_phone_public,
                    'available_regions' => $verification->available_regions ?? [],
                    'main_styles' => $verification->main_styles ?? [],
                    'status' => $verification->status,
                    'status_text' => $statusText,
                    'rejected_reason' => $verification->rejected_reason,
                    'approved_at' => $verification->approved_at?->format('Y-m-d H:i:s'),
                    'created_at' => $verification->created_at->format('Y-m-d H:i:s'),
                    'updated_at' => $verification->updated_at->format('Y-m-d H:i:s'),
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '사업자 가입신청 정보 조회 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}

