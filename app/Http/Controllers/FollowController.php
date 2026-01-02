<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserFollow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FollowController extends Controller
{
    /**
     * 팔로우 생성
     */
    public function follow(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => '인증이 필요합니다.',
            ], 401);
        }

        if ($user->id === $id) {
            return response()->json([
                'success' => false,
                'message' => '자기 자신은 팔로우할 수 없습니다.',
            ], 400);
        }

        $target = User::find($id);
        if (!$target) {
            return response()->json([
                'success' => false,
                'message' => '대상 사용자를 찾을 수 없습니다.',
            ], 404);
        }

        // 대상은 사업자만 가능
        if ((int) $target->user_type !== 2) {
            return response()->json([
                'success' => false,
                'message' => '일반회원은 팔로우할 수 없습니다.',
            ], 403);
        }

        // 사업자 ↔ 사업자, 일반 -> 사업자 허용 (즉, 대상은 반드시 사업자)
        // 팔로워는 일반/사업자 모두 가능하지만 대상은 사업자여야 함

        $exists = UserFollow::where('follower_id', $user->id)
            ->where('followee_id', $target->id)
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => true,
                'message' => '이미 팔로우 중입니다.',
            ], 200);
        }

        UserFollow::create([
            'follower_id' => $user->id,
            'followee_id' => $target->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => '팔로우했습니다.',
        ], 201);
    }

    /**
     * 언팔로우
     */
    public function unfollow(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => '인증이 필요합니다.',
            ], 401);
        }

        $deleted = UserFollow::where('follower_id', $user->id)
            ->where('followee_id', $id)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => $deleted ? '언팔로우했습니다.' : '이미 팔로우하지 않은 사용자입니다.',
        ], 200);
    }

    /**
     * 내 팔로워 목록 (사업자만)
     */
    public function followers(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => '인증이 필요합니다.',
            ], 401);
        }

        // 팔로워 목록은 사업자만 조회 가능
        if ((int) $user->user_type !== 2) {
            return response()->json([
                'success' => false,
                'message' => '일반 회원은 팔로워 목록을 조회할 수 없습니다.',
            ], 403);
        }

        $perPage = (int) $request->query('per_page', 20);
        $followers = UserFollow::where('followee_id', $user->id)
            ->with(['follower:id,username,user_type,profile_image'])
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $data = [
            'data' => $followers->getCollection()->map(function ($item) {
                return [
                    'id' => $item->follower?->id,
                    'username' => $item->follower?->username,
                    'user_type' => $item->follower?->user_type,
                    'profile_image' => $item->follower?->profile_image,
                    'followed_at' => optional($item->created_at)->format('Y-m-d H:i:s'),
                ];
            }),
            'pagination' => [
                'current_page' => $followers->currentPage(),
                'last_page' => $followers->lastPage(),
                'per_page' => $followers->perPage(),
                'total' => $followers->total(),
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => $data,
        ], 200);
    }

    /**
     * 내 팔로잉 목록 (모두)
     */
    public function followings(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => '인증이 필요합니다.',
            ], 401);
        }

        $perPage = (int) $request->query('per_page', 20);
        $followings = UserFollow::where('follower_id', $user->id)
            ->with(['followee:id,username,user_type,profile_image'])
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $data = [
            'data' => $followings->getCollection()->map(function ($item) {
                return [
                    'id' => $item->followee?->id,
                    'username' => $item->followee?->username,
                    'user_type' => $item->followee?->user_type,
                    'profile_image' => $item->followee?->profile_image,
                    'followed_at' => optional($item->created_at)->format('Y-m-d H:i:s'),
                ];
            }),
            'pagination' => [
                'current_page' => $followings->currentPage(),
                'last_page' => $followings->lastPage(),
                'per_page' => $followings->perPage(),
                'total' => $followings->total(),
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => $data,
        ], 200);
    }
}

