<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use App\Jobs\FanoutStarttooPost;

class FollowerNotifications
{
    public function created(int $author, string $type, string $target): void
    {
        $db = app(ProjectDatabase::class)->database();
        $inserted = $db->table('starttoo_notification_fanouts')->insertOrIgnore([
            'author_id' => $author, 'target_type' => $type, 'target_id' => $target,
            'max_follow_id' => (int) ($db->table('starttoo_follows')->where('target_user_id', $author)->max('id') ?? 0), 'created_at' => now(),
        ]);
        if (!$inserted) return;
        $id = (int) $db->table('starttoo_notification_fanouts')->where('target_type', $type)->where('target_id', $target)->value('id');
        $db->afterCommit(function () use ($id) {
            try { FanoutStarttooPost::dispatch($id); } catch (\Throwable $e) { report($e); }
        });
    }

    public function batch(int $id): bool
    {
        $db = app(ProjectDatabase::class)->database();
        return $db->transaction(function () use ($db, $id) {
            $job = $db->table('starttoo_notification_fanouts')->where('id', $id)->lockForUpdate()->first();
            if (!$job || $job->complete) return false;
            $service = $job->target_type === 'post' ? app(Posts::class) : app(Artworks::class);
            $alias = $job->target_type === 'post' ? 'p' : 'a';
            if (!$service->feedQuery(null, 'public')->where($alias.'.id', $job->target_id)->exists()) {
                $db->table('starttoo_notification_fanouts')->where('id', $id)->update(['complete' => true]);
                return false;
            }
            $followers = $db->table('starttoo_follows as f')->join('starttoo_users as u', 'u.id', '=', 'f.user_id')
                ->where('f.target_user_id', $job->author_id)->where('f.id', '>', $job->last_follow_id)->where('f.id', '<=', $job->max_follow_id)
                ->where('f.created_at', '<=', $job->created_at)->where('u.status', 'active')->where('u.moderation_hidden', false)
                ->orderBy('f.id')->limit(25)->get(['f.id', 'f.user_id']);
            foreach ($followers as $f) app(Notifications::class)->emit((int) $f->user_id, (int) $job->author_id, 'new_post', $job->target_type, $job->target_id, null, $job->created_at);
            $more = $followers->count() === 25;
            $db->table('starttoo_notification_fanouts')->where('id', $id)->update([
                'last_follow_id' => $followers->last()->id ?? $job->max_follow_id, 'complete' => !$more,
            ]);
            return $more;
        }, 3);
    }
}
