<?php

namespace App\Http\Controllers;

use App\Models\Portfolio;
use App\Models\PortfolioImage;
use App\Models\PortfolioVideo;
use App\Models\PortfolioTag;
use App\Models\PortfolioLike;
use App\Models\PortfolioLikeLog;
use App\Models\PortfolioStat;
use App\Models\PortfolioComment;
use App\Models\PortfolioReport;
use App\Models\PortfolioCommentReport;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class PortfolioController extends Controller
{
    /**
     * 포트폴리오 목록 조회
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $userId = $request->input('user_id'); // 특정 아티스트의 포트폴리오 조회
            $isPublic = $request->input('is_public', true); // 기본값: 공개된 것만

            $query = Portfolio::with(['images', 'videos', 'tags', 'user:id,username,profile_image,user_type', 'businessVerification:id,user_id,business_name']);

            // 특정 사용자의 포트폴리오만 조회
            if ($userId) {
                $query->where('user_id', $userId);
            }

            // 사용자 deleted_at가 null이고 suspended_until(정지기간)이 현재보다 이후인 포트폴리오 가져오기
            $query->whereHas('user', function ($q) {
                $q->whereNull('deleted_at')
                  ->where(function ($subQ) {
                      $subQ->whereNull('suspended_until')
                           ->orWhere('suspended_until', '<=', now());
                  });
            });

            // 본인 포트폴리오가 아니면 공개된 것만 조회
            if (!$user || ($userId && $userId != $user->id)) {
                $query->where('is_public', true);
            } elseif ($isPublic !== null) {
                $query->where('is_public', $isPublic);
            }

            //본인이 좋아요를 했는지 여부 추가
            if ($user) {
                $query->withExists(['likes as is_liked' => function ($q) use ($user) {
                    $q->where('user_id', $user->id);
                }]);
            }

            //코멘트 수 가져오기
            $query->withCount('comments as comments_count');

            $portfolios = $query->orderBy('created_at', 'desc')
                ->paginate($request->input('per_page', 15));

            // 포트폴리오 데이터 변환 (is_liked, comments_count 포함, 이미지와 비디오 합치기)
            $portfoliosData = $portfolios->getCollection()->map(function ($portfolio) use ($user) {
                $portfolioData = $portfolio->toArray();

                // is_liked: 로그인한 경우 boolean, 로그인하지 않은 경우 false
                $portfolioData['is_liked'] = $user ? (bool) ($portfolio->is_liked ?? false) : false;

                // comments_count: 숫자로 변환
                $portfolioData['comments_count'] = (int) ($portfolio->comments_count ?? 0);

                // 이미지와 비디오를 하나의 media 배열로 합치기
                $media = collect();

                // 이미지를 media 형식으로 변환
                foreach ($portfolio->images as $image) {
                    $media->push([
                        'type' => 'image',
                        'id' => $image->id,
                        'image_url' => $image->image_url,
                        'image_order' => $image->image_order,
                        'scale' => $image->scale,
                        'offset_x' => $image->offset_x,
                        'offset_y' => $image->offset_y,
                        'order' => $image->image_order,
                        'created_at' => $image->created_at?->toDateTimeString(),
                    ]);
                }

                // 비디오를 media 형식으로 변환
                foreach ($portfolio->videos as $video) {
                    $media->push([
                        'type' => 'video',
                        'id' => $video->id,
                        'video_file_path' => $video->video_file_path,
                        'video_url' => $video->video_url,
                        'video_thumbnail_url' => $video->video_thumbnail_url,
                        'video_job_id' => $video->video_job_id,
                        'video_status' => $video->video_status,
                        'video_order' => $video->video_order,
                        'order' => $video->video_order,
                        'created_at' => $video->created_at?->toDateTimeString(),
                    ]);
                }

                // order 기준으로 정렬 (order가 같으면 created_at 기준)
                $media = $media->sortBy([
                    ['order', 'asc'],
                    ['created_at', 'asc'],
                ])->values();

                // 기존 images, videos 필드는 제거하고 media 배열로 대체
                unset($portfolioData['images']);
                unset($portfolioData['videos']);
                $portfolioData['media'] = $media->all();

                return $portfolioData;
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'portfolios' => $portfoliosData->values()->all(),
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
     * 포트폴리오 상세 조회
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $user = $request->user();

            $portfolio = Portfolio::with(['images', 'videos', 'tags', 'user:id,username,profile_image'])
                ->find($id);

            if (!$portfolio) {
                return response()->json([
                    'success' => false,
                    'message' => '포트폴리오를 찾을 수 없습니다.',
                ], 404);
            }

            // 본인 포트폴리오가 아니고 비공개면 접근 불가
            if (!$user || $portfolio->user_id != $user->id) {
                if (!$portfolio->is_public) {
                    return response()->json([
                        'success' => false,
                        'message' => '비공개 포트폴리오입니다.',
                    ], 403);
                }
            }

            // 조회수 증가 (본인 포트폴리오가 아닐 때만)
            if (!$user || $portfolio->user_id != $user->id) {
                $portfolio->increment('views');
                $portfolio->refresh();

                // 통계 업데이트
                if ($portfolio->is_public) {
                    $stats = $portfolio->getOrCreateStats();
                    $stats->incrementViews();
                }
            }

            // 이미지와 비디오를 하나의 media 배열로 합치기
            $media = collect();

            // 이미지를 media 형식으로 변환
            foreach ($portfolio->images as $image) {
                $media->push([
                    'type' => 'image',
                    'id' => $image->id,
                    'image_url' => $image->image_url,
                    'image_order' => $image->image_order,
                    'scale' => $image->scale,
                    'offset_x' => $image->offset_x,
                    'offset_y' => $image->offset_y,
                    'order' => $image->image_order,
                    'created_at' => $image->created_at?->toDateTimeString(),
                ]);
            }

            // 비디오를 media 형식으로 변환
            foreach ($portfolio->videos as $video) {
                $media->push([
                    'type' => 'video',
                    'id' => $video->id,
                    'video_file_path' => $video->video_file_path,
                    'video_url' => $video->video_url,
                    'video_thumbnail_url' => $video->video_thumbnail_url,
                    'video_job_id' => $video->video_job_id,
                    'video_status' => $video->video_status,
                    'video_order' => $video->video_order,
                    'order' => $video->video_order,
                    'created_at' => $video->created_at?->toDateTimeString(),
                ]);
            }

            // order 기준으로 정렬 (order가 같으면 created_at 기준)
            $media = $media->sortBy([
                ['order', 'asc'],
                ['created_at', 'asc'],
            ])->values();

            $portfolioData = $portfolio->toArray();
            unset($portfolioData['images']);
            unset($portfolioData['videos']);
            $portfolioData['media'] = $media->all();

            return response()->json([
                'success' => true,
                'data' => [
                    'portfolio' => $portfolioData,
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '포트폴리오 조회 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * 포트폴리오 생성
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => '인증되지 않은 사용자입니다.',
                ], 401);
            }

            // 사업자 회원만 포트폴리오 생성 가능
            if ($user->user_type !== 2) {
                return response()->json([
                    'success' => false,
                    'message' => '권한이 없습니다.',
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'title' => 'required|string|max:255',
                'description' => 'nullable|string|max:5000',
                'work_date' => 'nullable|date',
                'price' => 'nullable|numeric|min:0|max:99999999.99',
                'is_public' => 'nullable|boolean',
                'images' => 'nullable|array',
                'images.*.image_url' => 'required|string|max:255',
                'images.*.image_order' => 'nullable|integer|min:0',
                'images.*.scale' => 'nullable|numeric|min:0|max:10',
                'images.*.offset_x' => 'nullable|numeric|min:0|max:1',
                'images.*.offset_y' => 'nullable|numeric|min:0|max:1',
                'videos' => 'nullable|array',
                'videos.*.video_file_path' => 'required|string|max:500',
                'videos.*.video_order' => 'nullable|integer|min:0',
                'tags' => 'nullable|array',
                'tags.*' => 'string|max:100',
            ], [
                'title.required' => '제목은 필수입니다.',
                'title.max' => '제목은 최대 255자까지 입력할 수 있습니다.',
                'description.max' => '설명은 최대 5000자까지 입력할 수 있습니다.',
                'work_date.date' => '올바른 날짜 형식이 아닙니다.',
                'price.numeric' => '가격은 숫자여야 합니다.',
                'price.min' => '가격은 0 이상이어야 합니다.',
                'price.max' => '가격은 99,999,999.99 이하여야 합니다.',
                'images.array' => '이미지는 배열 형식이어야 합니다.',
                'images.*.image_url.required' => '이미지 URL은 필수입니다.',
                'videos.array' => '비디오는 배열 형식이어야 합니다.',
                'videos.*.video_file_path.required' => '비디오 파일 경로는 필수입니다.',
                'videos.*.video_file_path.max' => '비디오 파일 경로는 최대 500자까지 입력할 수 있습니다.',
            ]);

            // 이미지 또는 비디오 중 하나는 있어야 함
            $images = $request->input('images', []);
            $videos = $request->input('videos', []);
            
            if (empty($images) && empty($videos)) {
                $validator->errors()->add('media', '이미지 또는 비디오 중 최소 1개 이상 필요합니다.');
            }

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => '유효성 검사 실패',
                    'errors' => $validator->errors(),
                ], 422);
            }

            DB::beginTransaction();

            try {
                // 포트폴리오 생성
                $portfolio = Portfolio::create([
                    'user_id' => $user->id,
                    'title' => $request->input('title'),
                    'description' => $request->input('description'),
                    'work_date' => $request->input('work_date'),
                    'price' => $request->input('price'),
                    'is_public' => $request->input('is_public', true),
                    'is_sensitive' => false, // 관리자만 설정 가능, 기본값 false
                    'views' => 0,
                    'likes_count' => 0,
                    'comments_count' => 0,
                ]);

                // 이미지 생성
                $images = $request->input('images', []);
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

                // 비디오 생성
                $videos = $request->input('videos', []);
                foreach ($videos as $index => $video) {
                    PortfolioVideo::create([
                        'portfolio_id' => $portfolio->id,
                        'video_file_path' => $video['video_file_path'],
                        'video_order' => $video['video_order'] ?? $index,
                        'video_status' => 'pending', // 비디오가 있으면 pending 상태로 설정
                    ]);
                }

                // 태그 생성/연결
                $tagNames = $request->input('tags', []);
                $tagIds = [];

                foreach ($tagNames as $tagName) {
                    // 태그가 없으면 생성, 있으면 조회
                    $tag = Tag::firstOrCreate(
                        ['name' => $tagName],
                        ['usage_count' => 0]
                    );

                    $tagIds[] = $tag->id;
                }

                // 포트폴리오와 태그 연결
                $portfolio->tags()->sync($tagIds);

                // 태그 사용 횟수 업데이트
                Tag::whereIn('id', $tagIds)->increment('usage_count');

                // 통계 초기화 (공개 포트폴리오인 경우)
                if ($portfolio->is_public) {
                    PortfolioStat::initialize($portfolio->id, $portfolio->created_at);
                }

                DB::commit();

                // 관계 데이터 포함하여 반환
                $portfolio->load(['images', 'videos', 'tags', 'user:id,username,profile_image']);

                return response()->json([
                    'success' => true,
                    'message' => '포트폴리오가 생성되었습니다.',
                    'data' => [
                        'portfolio' => $portfolio,
                    ],
                ], 201);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '포트폴리오 생성 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * 포트폴리오 수정
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => '인증되지 않은 사용자입니다.',
                ], 401);
            }

            $portfolio = Portfolio::find($id);

            if (!$portfolio) {
                return response()->json([
                    'success' => false,
                    'message' => '포트폴리오를 찾을 수 없습니다.',
                ], 404);
            }

            // 본인 포트폴리오만 수정 가능
            if ($portfolio->user_id != $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => '본인의 포트폴리오만 수정할 수 있습니다.',
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'title' => 'nullable|string|max:255',
                'description' => 'nullable|string|max:5000',
                'work_date' => 'nullable|date',
                'price' => 'nullable|numeric|min:0|max:99999999.99',
                'is_public' => 'nullable|boolean',
                'images' => 'nullable|array',
                'images.*.image_url' => 'required_with:images|string|max:255',
                'images.*.image_order' => 'nullable|integer|min:0',
                'videos' => 'nullable|array',
                'videos.*.video_file_path' => 'required_with:videos|string|max:500',
                'videos.*.video_order' => 'nullable|integer|min:0',
                'tags' => 'nullable|array',
                'tags.*' => 'string|max:100',
            ]);

            // 이미지 또는 비디오 중 하나는 있어야 함 (기존 데이터가 없는 경우에만)
            $images = $request->input('images', []);
            $videos = $request->input('videos', []);
            $existingImagesCount = $portfolio->images()->count();
            $existingVideosCount = $portfolio->videos()->count();
            
            // 업데이트 시 images나 videos가 전달된 경우, 둘 다 비어있으면 안됨
            // 단, 기존에 이미지나 비디오가 있으면 괜찮음
            if ($request->has('images') || $request->has('videos')) {
                if (empty($images) && empty($videos) && $existingImagesCount === 0 && $existingVideosCount === 0) {
                    $validator->errors()->add('media', '이미지 또는 비디오 중 최소 1개 이상 필요합니다.');
                }
            }

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => '유효성 검사 실패',
                    'errors' => $validator->errors(),
                ], 422);
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
                // is_sensitive는 관리자만 수정 가능하므로 여기서는 제외

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

                // 비디오 수정 (전체 교체)
                if ($request->has('videos')) {
                    // 기존 비디오 삭제
                    PortfolioVideo::where('portfolio_id', $portfolio->id)->delete();

                    // 새 비디오 생성
                    $videos = $request->input('videos');
                    foreach ($videos as $index => $video) {
                        PortfolioVideo::create([
                            'portfolio_id' => $portfolio->id,
                            'video_file_path' => $video['video_file_path'],
                            'video_order' => $video['video_order'] ?? $index,
                            'video_status' => 'pending', // 비디오가 있으면 pending 상태로 설정
                        ]);
                    }
                }

                // 태그 수정 (전체 교체)
                if ($request->has('tags')) {
                    // 기존 태그 사용 횟수 감소
                    $oldTagIds = $portfolio->tags()->pluck('tags.id')->toArray();
                    if (!empty($oldTagIds)) {
                        Tag::whereIn('id', $oldTagIds)->decrement('usage_count');
                    }

                    // 새 태그 생성/조회
                    $tagNames = $request->input('tags');
                    $newTagIds = [];

                    foreach ($tagNames as $tagName) {
                        $tag = Tag::firstOrCreate(
                            ['name' => $tagName],
                            ['usage_count' => 0]
                        );
                        $newTagIds[] = $tag->id;
                    }

                    // 포트폴리오와 태그 연결 (sync는 자동으로 기존 연결 제거)
                    $portfolio->tags()->sync($newTagIds);

                    // 새 태그 사용 횟수 증가
                    Tag::whereIn('id', $newTagIds)->increment('usage_count');
                }

                DB::commit();

                // 관계 데이터 포함하여 반환
                $portfolio->refresh();
                $portfolio->load(['images', 'videos', 'tags', 'user:id,username,profile_image']);

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
     * 포트폴리오 삭제
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => '인증되지 않은 사용자입니다.',
                ], 401);
            }

            $portfolio = Portfolio::find($id);

            if (!$portfolio) {
                return response()->json([
                    'success' => false,
                    'message' => '포트폴리오를 찾을 수 없습니다.',
                ], 404);
            }

            // 본인 포트폴리오만 삭제 가능
            if ($portfolio->user_id != $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => '본인의 포트폴리오만 삭제할 수 있습니다.',
                ], 403);
            }

            DB::beginTransaction();

            try {
                // 태그 사용 횟수 감소
                $tagIds = $portfolio->tags()->pluck('tags.id')->toArray();
                if (!empty($tagIds)) {
                    Tag::whereIn('id', $tagIds)->decrement('usage_count');
                }

                // 관련 데이터 삭제 (외래키 cascade로 자동 삭제되지만 명시적으로 처리)
                PortfolioImage::where('portfolio_id', $portfolio->id)->delete();
                PortfolioTag::where('portfolio_id', $portfolio->id)->delete();

                // 포트폴리오 삭제 (soft delete)
                $portfolio->delete();

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => '포트폴리오가 삭제되었습니다.',
                ], 200);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '포트폴리오 삭제 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * 포트폴리오 좋아요
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function like(Request $request, int $id): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => '인증되지 않은 사용자입니다.',
                ], 401);
            }

            $portfolio = Portfolio::find($id);

            if (!$portfolio) {
                return response()->json([
                    'success' => false,
                    'message' => '포트폴리오를 찾을 수 없습니다.',
                ], 404);
            }

            // 본인 포트폴리오는 좋아요 불가
            if ($portfolio->user_id == $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => '본인의 포트폴리오에는 좋아요를 할 수 없습니다.',
                ], 403);
            }

            DB::beginTransaction();

            try {
                // 이미 좋아요한 경우 체크
                $existingLike = PortfolioLike::where('portfolio_id', $id)
                    ->where('user_id', $user->id)
                    ->first();

                if ($existingLike) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => '이미 좋아요한 포트폴리오입니다.',
                    ], 409);
                }

                // 좋아요 생성
                PortfolioLike::create([
                    'portfolio_id' => $id,
                    'user_id' => $user->id,
                    'created_at' => now(),
                ]);

                // 좋아요 로그 생성
                PortfolioLikeLog::create([
                    'portfolio_id' => $id,
                    'user_id' => $user->id,
                    'action' => 'like',
                    'created_at' => now(),
                ]);

                // 좋아요 수 증가
                $portfolio->increment('likes_count');
                $portfolio->refresh();

                // 통계 업데이트
                if ($portfolio->is_public) {
                    $stats = $portfolio->getOrCreateStats();
                    $stats->incrementLikes();
                }

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => '좋아요가 추가되었습니다.',
                    'data' => [
                        'likes_count' => $portfolio->likes_count,
                        'is_liked' => true,
                    ],
                ], 200);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '좋아요 처리 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * 포트폴리오 좋아요 취소
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function unlike(Request $request, int $id): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => '인증되지 않은 사용자입니다.',
                ], 401);
            }

            $portfolio = Portfolio::find($id);

            if (!$portfolio) {
                return response()->json([
                    'success' => false,
                    'message' => '포트폴리오를 찾을 수 없습니다.',
                ], 404);
            }

            DB::beginTransaction();

            try {
                // 좋아요 찾기
                $like = PortfolioLike::where('portfolio_id', $id)
                    ->where('user_id', $user->id)
                    ->first();

                if (!$like) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => '좋아요하지 않은 포트폴리오입니다.',
                    ], 404);
                }

                // 좋아요 삭제
                $like->delete();

                // 좋아요 취소 로그 생성
                PortfolioLikeLog::create([
                    'portfolio_id' => $id,
                    'user_id' => $user->id,
                    'action' => 'unlike',
                    'created_at' => now(),
                ]);

                // 좋아요 수 감소
                $portfolio->decrement('likes_count');
                $portfolio->refresh();

                // 통계 업데이트
                if ($portfolio->is_public) {
                    $stats = $portfolio->getOrCreateStats();
                    $stats->decrementLikes();
                }

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => '좋아요가 취소되었습니다.',
                    'data' => [
                        'likes_count' => $portfolio->likes_count,
                        'is_liked' => false,
                    ],
                ], 200);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '좋아요 취소 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * 포트폴리오 댓글 목록 조회 (상위 댓글만)
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function getComments(Request $request, int $id): JsonResponse
    {
        try {
            $portfolio = Portfolio::find($id);

            if (!$portfolio) {
                return response()->json([
                    'success' => false,
                    'message' => '포트폴리오를 찾을 수 없습니다.',
                ], 404);
            }

            // 본인 포트폴리오가 아니고 비공개면 접근 불가
            $user = $request->user();
            if (!$user || $portfolio->user_id != $user->id) {
                if (!$portfolio->is_public) {
                    return response()->json([
                        'success' => false,
                        'message' => '비공개 포트폴리오입니다.',
                    ], 403);
                }
            }

            // 상위 댓글만 조회 (대댓글 제외, 삭제된 것 포함)
            // 고정된 댓글이 맨 위에 오도록 정렬
            $comments = PortfolioComment::with(['user:id,username,profile_image'])
                ->withTrashed()
                ->where('portfolio_id', $id)
                ->whereNull('parent_id')
                ->orderBy('is_pinned', 'desc')
                ->orderBy('created_at', 'desc')
                ->paginate($request->input('per_page', 15));

            // 각 댓글에 대댓글 수 포함
            foreach ($comments->items() as $comment) {
                $comment->loadCount('replies');
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'comments' => $comments->items(),
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
     * 포트폴리오 대댓글 목록 조회
     *
     * @param Request $request
     * @param int $id 포트폴리오 ID
     * @param int $commentId 부모 댓글 ID
     * @return JsonResponse
     */
    public function getReplies(Request $request, int $id, int $commentId): JsonResponse
    {
        try {
            $portfolio = Portfolio::find($id);

            if (!$portfolio) {
                return response()->json([
                    'success' => false,
                    'message' => '포트폴리오를 찾을 수 없습니다.',
                ], 404);
            }

            // 부모 댓글 확인 (삭제된 것 포함)
            $parentComment = PortfolioComment::withTrashed()
                ->where('id', $commentId)
                ->where('portfolio_id', $id)
                ->whereNull('parent_id')
                ->first();

            if (!$parentComment) {
                return response()->json([
                    'success' => false,
                    'message' => '댓글을 찾을 수 없습니다.',
                ], 404);
            }

            // 본인 포트폴리오가 아니고 비공개면 접근 불가
            $user = $request->user();
            if (!$user || $portfolio->user_id != $user->id) {
                if (!$portfolio->is_public) {
                    return response()->json([
                        'success' => false,
                        'message' => '비공개 포트폴리오입니다.',
                    ], 403);
                }
            }

            // 대댓글만 조회 (삭제된 것 포함)
            $replies = PortfolioComment::with(['user:id,username,profile_image'])
                ->withTrashed()
                ->where('portfolio_id', $id)
                ->where('parent_id', $commentId)
                ->orderBy('created_at', 'asc')
                ->paginate($request->input('per_page', 20));

            return response()->json([
                'success' => true,
                'data' => [
                    'replies' => $replies->items(),
                    'pagination' => [
                        'current_page' => $replies->currentPage(),
                        'last_page' => $replies->lastPage(),
                        'per_page' => $replies->perPage(),
                        'total' => $replies->total(),
                    ],
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '대댓글 목록 조회 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * 포트폴리오 댓글 작성
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function createComment(Request $request, int $id): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => '인증되지 않은 사용자입니다.',
                ], 401);
            }

            $portfolio = Portfolio::find($id);

            if (!$portfolio) {
                return response()->json([
                    'success' => false,
                    'message' => '포트폴리오를 찾을 수 없습니다.',
                ], 404);
            }

            // 본인 포트폴리오가 아니고 비공개면 접근 불가
            if ($portfolio->user_id != $user->id && !$portfolio->is_public) {
                return response()->json([
                    'success' => false,
                    'message' => '비공개 포트폴리오입니다.',
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'content' => 'nullable|string|max:100',
                'gif_image_url' => 'nullable|string|max:500|url',
                'parent_id' => 'nullable|integer|exists:portfolio_comments,id',
            ], [
                'content.max' => '댓글은 최대 100자까지 입력할 수 있습니다.',
                'gif_image_url.url' => '올바른 URL 형식이 아닙니다.',
                'gif_image_url.max' => 'GIF 이미지 URL은 최대 500자까지 입력할 수 있습니다.',
                'parent_id.exists' => '존재하지 않는 댓글입니다.',
            ]);

            // content 또는 gif_image_url 중 하나는 필수
            if (empty($request->input('content')) && empty($request->input('gif_image_url'))) {
                $validator->errors()->add('content', '댓글 내용 또는 GIF 이미지 중 하나는 필수입니다.');
            }

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => '유효성 검사 실패',
                    'errors' => $validator->errors(),
                ], 422);
            }

            DB::beginTransaction();

            try {
                $parentId = $request->input('parent_id');

                // 대댓글인 경우 부모 댓글 확인
                if ($parentId) {
                    $parentComment = PortfolioComment::withTrashed()
                        ->where('id', $parentId)
                        ->where('portfolio_id', $id)
                        ->whereNull('parent_id') // 부모 댓글은 상위 댓글이어야 함
                        ->first();

                    if (!$parentComment) {
                        DB::rollBack();
                        return response()->json([
                            'success' => false,
                            'message' => '존재하지 않거나 대댓글에 답글을 달 수 없습니다.',
                        ], 404);
                    }

                    // 삭제된 댓글에는 대댓글을 달 수 없음
                    if ($parentComment->is_deleted) {
                        DB::rollBack();
                        return response()->json([
                            'success' => false,
                            'message' => '삭제된 댓글에는 답글을 달 수 없습니다.',
                        ], 403);
                    }
                }

                // 댓글 생성
                $comment = PortfolioComment::create([
                    'portfolio_id' => $id,
                    'gif_image_url' => $request->input('gif_image_url'),
                    'user_id' => $user->id,
                    'parent_id' => $parentId,
                    'content' => $request->input('content'),
                    'replies_count' => 0,
                ]);

                $comment->refresh();

                // 대댓글인 경우 부모 댓글의 replies_count 증가
                if ($parentId) {
                    PortfolioComment::where('id', $parentId)->increment('replies_count');
                }

                // 포트폴리오 댓글 수 증가 (상위 댓글만 카운트)
                if (!$parentId) {
                    $portfolio->increment('comments_count');
                }

                // 통계 업데이트
                if ($portfolio->is_public) {
                    $stats = $portfolio->getOrCreateStats();
                    // 상위 댓글만 통계에 반영
                    if (!$parentId) {
                        $stats->incrementComments();
                    }
                }

                DB::commit();

                // 작성자 정보 포함하여 반환
                $comment->load('user:id,username,profile_image');

                return response()->json([
                    'success' => true,
                    'message' => $parentId ? '대댓글이 작성되었습니다.' : '댓글이 작성되었습니다.',
                    'data' => [
                        'comment' => $comment,
                    ],
                ], 201);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '댓글 작성 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * 포트폴리오 댓글 수정
     *
     * @param Request $request
     * @param int $id 포트폴리오 ID
     * @param int $commentId 댓글 ID
     * @return JsonResponse
     */
    public function updateComment(Request $request, int $id, int $commentId): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => '인증되지 않은 사용자입니다.',
                ], 401);
            }

            $portfolio = Portfolio::find($id);

            if (!$portfolio) {
                return response()->json([
                    'success' => false,
                    'message' => '포트폴리오를 찾을 수 없습니다.',
                ], 404);
            }

            $comment = PortfolioComment::withTrashed()
                ->where('id', $commentId)
                ->where('portfolio_id', $id)
                ->first();

            if (!$comment) {
                return response()->json([
                    'success' => false,
                    'message' => '댓글을 찾을 수 없습니다.',
                ], 404);
            }

            // 작성된지 5분이내에만 수정 가능
            if ($comment->created_at->diffInMinutes(now()) > 5) {
                return response()->json([
                    'success' => false,
                    'message' => '댓글은 작성 후 5분 이내에만 수정할 수 있습니다.',
                ], 403);
            }

            // 삭제된 댓글은 수정 불가
            if ($comment->is_deleted) {
                return response()->json([
                    'success' => false,
                    'message' => '삭제된 댓글은 수정할 수 없습니다.',
                ], 403);
            }

            // 본인 댓글만 수정 가능
            if ($comment->user_id != $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => '본인의 댓글만 수정할 수 있습니다.',
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'content' => 'nullable|string|max:100',
                'gif_image_url' => 'nullable|string|max:500|url',
            ], [
                'content.max' => '댓글은 최대 100자까지 입력할 수 있습니다.',
                'gif_image_url.url' => '올바른 URL 형식이 아닙니다.',
                'gif_image_url.max' => 'GIF 이미지 URL은 최대 500자까지 입력할 수 있습니다.',
            ]);

            // content 또는 gif_image_url 중 하나는 필수
            if (empty($request->input('content')) && empty($request->input('gif_image_url'))) {
                $validator->errors()->add('content', '댓글 내용 또는 GIF 이미지 중 하나는 필수입니다.');
            }

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => '유효성 검사 실패',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $updateData = [];
            if ($request->has('content')) {
                $updateData['content'] = $request->input('content');
            }
            if ($request->has('gif_image_url')) {
                $updateData['gif_image_url'] = $request->input('gif_image_url');
            }

            $comment->update($updateData);

            $comment->load('user:id,username,profile_image');

            return response()->json([
                'success' => true,
                'message' => '댓글이 수정되었습니다.',
                'data' => [
                    'comment' => $comment,
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
     * 포트폴리오 댓글 삭제
     *
     * @param Request $request
     * @param int $id 포트폴리오 ID
     * @param int $commentId 댓글 ID
     * @return JsonResponse
     */
    public function deleteComment(Request $request, int $id, int $commentId): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => '인증되지 않은 사용자입니다.',
                ], 401);
            }

            $portfolio = Portfolio::find($id);

            if (!$portfolio) {
                return response()->json([
                    'success' => false,
                    'message' => '포트폴리오를 찾을 수 없습니다.',
                ], 404);
            }

            $comment = PortfolioComment::withTrashed()
                ->where('id', $commentId)
                ->where('portfolio_id', $id)
                ->first();

            if (!$comment) {
                return response()->json([
                    'success' => false,
                    'message' => '댓글을 찾을 수 없습니다.',
                ], 404);
            }

            // 이미 삭제된 댓글인지 확인
            if ($comment->is_deleted) {
                return response()->json([
                    'success' => false,
                    'message' => '이미 삭제된 댓글입니다.',
                ], 409);
            }

            // 본인 댓글만 삭제 가능
            if ($comment->user_id != $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => '댓글을 삭제할 권한이 없습니다.',
                ], 403);
            }

            DB::beginTransaction();

            try {
                $parentId = $comment->parent_id;
                $isTopLevel = $comment->isTopLevel();

                // 댓글 삭제 플래그만 변경 (실제 삭제하지 않음)
                $comment->update([
                    'is_deleted' => true
                ]);

                $comment->delete(); //Soft delete로 deleted_at 설정

                // 대댓글인 경우 부모 댓글의 replies_count 감소
                if ($parentId) {
                    PortfolioComment::where('id', $parentId)->decrement('replies_count');
                }

                // 포트폴리오 댓글 수 감소 (상위 댓글만 카운트)
                if ($isTopLevel) {
                    $portfolio->decrement('comments_count');
                }

                // 통계 업데이트 (상위 댓글만 카운트)
                if ($portfolio->is_public && $isTopLevel) {
                    $stats = $portfolio->getOrCreateStats();
                    $stats->decrementComments();
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
     * 포트폴리오 신고
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function reportPortfolio(Request $request, int $id): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => '인증되지 않은 사용자입니다.',
                ], 401);
            }

            $portfolio = Portfolio::find($id);

            if (!$portfolio) {
                return response()->json([
                    'success' => false,
                    'message' => '포트폴리오를 찾을 수 없습니다.',
                ], 404);
            }

            // 본인 포트폴리오는 신고 불가
            if ($portfolio->user_id == $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => '본인의 포트폴리오는 신고할 수 없습니다.',
                ], 403);
            }

//            $validator = Validator::make($request->all(), [
//                'report_type' => 'required|string|in:spam,inappropriate,violence,nudity,hate,copyright,other',
//                'reason' => 'nullable|string|max:1000',
//            ], [
//                'report_type.required' => '신고 유형은 필수입니다.',
//                'report_type.in' => '올바른 신고 유형이 아닙니다.',
//                'reason.max' => '신고 사유는 최대 1000자까지 입력할 수 있습니다.',
//            ]);
//
//            if ($validator->fails()) {
//                return response()->json([
//                    'success' => false,
//                    'message' => '유효성 검사 실패',
//                    'errors' => $validator->errors(),
//                ], 422);
//            }

            DB::beginTransaction();

            try {
                // 이미 신고한 경우 체크
                $existingReport = PortfolioReport::where('portfolio_id', $id)
                    ->where('user_id', $user->id)
                    ->first();

                if ($existingReport) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => '이미 신고한 포트폴리오입니다.',
                    ], 409);
                }

                // 신고 생성
                $report = PortfolioReport::create([
                    'portfolio_id' => $id,
                    'user_id' => $user->id,
                    'report_type' => $request->input('report_type'),
                    'reason' => $request->input('reason'),
                    'status' => 'pending',
                ]);

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => '신고가 접수되었습니다.',
                    'data' => [
                        'report' => $report,
                    ],
                ], 201);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '신고 처리 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * 포트폴리오 개인화 피드 조회 (우선 전체리스트 desc)
     * @return JsonResponse
     */
    public function feed()
    {
        try {
            $portfolios = Portfolio::where('is_public', true)
                ->orderBy('created_at', 'desc')
                ->with(['user:id,username,profile_image', 'tags', 'images', 'videos'])
                ->paginate(15);

            // 포트폴리오 데이터 변환 (이미지와 비디오를 media 배열로 합치기)
            $portfoliosData = collect($portfolios->items())->map(function ($portfolio) {
                $portfolioData = $portfolio->toArray();

                // 이미지와 비디오를 하나의 media 배열로 합치기
                $media = collect();

                // 이미지를 media 형식으로 변환
                foreach ($portfolio->images as $image) {
                    $media->push([
                        'type' => 'image',
                        'id' => $image->id,
                        'image_url' => $image->image_url,
                        'image_order' => $image->image_order,
                        'scale' => $image->scale,
                        'offset_x' => $image->offset_x,
                        'offset_y' => $image->offset_y,
                        'order' => $image->image_order,
                        'created_at' => $image->created_at?->toDateTimeString(),
                    ]);
                }

                // 비디오를 media 형식으로 변환
                foreach ($portfolio->videos as $video) {
                    $media->push([
                        'type' => 'video',
                        'id' => $video->id,
                        'video_file_path' => $video->video_file_path,
                        'video_url' => $video->video_url,
                        'video_thumbnail_url' => $video->video_thumbnail_url,
                        'video_job_id' => $video->video_job_id,
                        'video_status' => $video->video_status,
                        'video_order' => $video->video_order,
                        'order' => $video->video_order,
                        'created_at' => $video->created_at?->toDateTimeString(),
                    ]);
                }

                // order 기준으로 정렬 (order가 같으면 created_at 기준)
                $media = $media->sortBy([
                    ['order', 'asc'],
                    ['created_at', 'asc'],
                ])->values();

                // 기존 images, videos 필드는 제거하고 media 배열로 대체
                unset($portfolioData['images']);
                unset($portfolioData['videos']);
                $portfolioData['media'] = $media->all();

                return $portfolioData;
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'portfolios' => $portfoliosData->values()->all(),
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
                'message' => '피드 조회 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * 포트폴리오 댓글 신고
     *
     * @param Request $request
     * @param int $id 포트폴리오 ID
     * @param int $commentId 댓글 ID
     * @return JsonResponse
     */
    public function reportComment(Request $request, int $id, int $commentId): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => '인증되지 않은 사용자입니다.',
                ], 401);
            }

            $portfolio = Portfolio::find($id);

            if (!$portfolio) {
                return response()->json([
                    'success' => false,
                    'message' => '포트폴리오를 찾을 수 없습니다.',
                ], 404);
            }

            $comment = PortfolioComment::withTrashed()
                ->where('id', $commentId)
                ->where('portfolio_id', $id)
                ->first();

            if (!$comment) {
                return response()->json([
                    'success' => false,
                    'message' => '댓글을 찾을 수 없습니다.',
                ], 404);
            }

            // 본인 댓글은 신고 불가
            if ($comment->user_id == $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => '본인의 댓글은 신고할 수 없습니다.',
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'report_type' => 'required|string|in:spam,inappropriate,violence,hate,harassment,other',
                'reason' => 'nullable|string|max:1000',
            ], [
                'report_type.required' => '신고 유형은 필수입니다.',
                'report_type.in' => '올바른 신고 유형이 아닙니다.',
                'reason.max' => '신고 사유는 최대 1000자까지 입력할 수 있습니다.',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => '유효성 검사 실패',
                    'errors' => $validator->errors(),
                ], 422);
            }

            DB::beginTransaction();

            try {
                // 이미 신고한 경우 체크
                $existingReport = PortfolioCommentReport::where('comment_id', $commentId)
                    ->where('user_id', $user->id)
                    ->first();

                if ($existingReport) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => '이미 신고한 댓글입니다.',
                    ], 409);
                }

                // 신고 생성
                $report = PortfolioCommentReport::create([
                    'comment_id' => $commentId,
                    'user_id' => $user->id,
                    'report_type' => $request->input('report_type'),
                    'reason' => $request->input('reason'),
                    'status' => 'pending',
                ]);

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => '신고가 접수되었습니다.',
                    'data' => [
                        'report' => $report,
                    ],
                ], 201);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '신고 처리 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * 포트폴리오 댓글 고정/해제
     * 포트폴리오 작성자만 고정 가능, 한 개만 고정 가능
     *
     * @param Request $request
     * @param int $id 포트폴리오 ID
     * @param int $commentId 댓글 ID
     * @return JsonResponse
     */
    public function pinComment(Request $request, int $id, int $commentId): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => '인증되지 않은 사용자입니다.',
                ], 401);
            }

            $portfolio = Portfolio::find($id);

            if (!$portfolio) {
                return response()->json([
                    'success' => false,
                    'message' => '포트폴리오를 찾을 수 없습니다.',
                ], 404);
            }

            // 포트폴리오 작성자만 고정 가능
            if ($portfolio->user_id != $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => '포트폴리오 작성자만 댓글을 고정할 수 있습니다.',
                ], 403);
            }

            $comment = PortfolioComment::withTrashed()
                ->where('id', $commentId)
                ->where('portfolio_id', $id)
                ->whereNull('parent_id') // 상위 댓글만 고정 가능
                ->first();

            if (!$comment) {
                return response()->json([
                    'success' => false,
                    'message' => '댓글을 찾을 수 없습니다.',
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'is_pinned' => 'required|boolean',
            ], [
                'is_pinned.required' => '고정 여부는 필수입니다.',
                'is_pinned.boolean' => '고정 여부는 boolean 값이어야 합니다.',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => '유효성 검사 실패',
                    'errors' => $validator->errors(),
                ], 422);
            }

            DB::beginTransaction();

            try {
                $isPinned = $request->input('is_pinned');

                // 고정하려는 경우, 다른 댓글의 고정 해제
                if ($isPinned) {
                    PortfolioComment::where('portfolio_id', $id)
                        ->where('id', '!=', $commentId)
                        ->whereNull('parent_id')
                        ->update(['is_pinned' => false]);
                }

                // 댓글 고정/해제
                $comment->update([
                    'is_pinned' => $isPinned,
                ]);

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => $isPinned ? '댓글이 고정되었습니다.' : '댓글 고정이 해제되었습니다.',
                    'data' => [
                        'comment' => [
                            'id' => $comment->id,
                            'is_pinned' => $comment->is_pinned,
                        ],
                    ],
                ], 200);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '댓글 고정 처리 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}

