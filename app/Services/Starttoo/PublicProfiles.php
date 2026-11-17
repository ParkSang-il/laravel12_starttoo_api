<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Storage;

class PublicProfiles
{
    public function user(int $id): object
    {
        $user = app(ProjectDatabase::class)->database()->table('starttoo_users')
            ->select('id', 'nickname', 'bio', 'avatar_path', 'interest_styles', 'interest_region')
            ->where('id', $id)->where('status', 'active')->whereNotNull('profile_completed_at')->first();
        if (!$user) {
            throw new HttpResponseException(response()->json([
                'code' => 'PROFILE_NOT_FOUND', 'message' => '프로필을 찾을 수 없어요.',
            ], 404));
        }
        return $user;
    }

    public function show(int $id, ?int $viewerId): array
    {
        $user = $this->user($id);
        $options = app(Profiles::class)->options();
        $styles = json_decode($user->interest_styles ?? '[]', true, 512, JSON_THROW_ON_ERROR);
        $count = app(ProjectDatabase::class)->database()->table('starttoo_artworks as a')
            ->where('a.user_id', $id)->where('a.visibility', 'public')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('starttoo_artwork_images as i')->whereColumn('i.artwork_id', 'a.id'))->count();
        return ['id' => (int) $user->id, 'nickname' => $user->nickname, 'bio' => $user->bio ?? '',
            'avatar_url' => $user->avatar_path ? Storage::disk('starttoo')->temporaryUrl($user->avatar_path, now()->addHour()) : null,
            'styles' => array_values(array_filter($options['styles'], fn ($s) => in_array($s['id'], $styles, true))),
            'region' => collect($options['regions'])->firstWhere('id', $user->interest_region)['label'] ?? null,
            'artwork_count' => $count, 'is_me' => $viewerId === (int) $user->id];
    }
}
