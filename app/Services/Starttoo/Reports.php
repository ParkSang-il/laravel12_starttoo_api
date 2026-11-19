<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class Reports
{
    public function submit(string $token, array $input, AuthSessions $sessions, Artworks $artworks): array
    {
        $viewer = (int) $artworks->ready($token, $sessions)['account']['id'];
        if (is_string($input['details'] ?? null)) $input['details'] = trim($input['details']);
        $validator = Validator::make($input, [
            'target_type' => ['required', Rule::in(['user', 'artwork', 'post', 'comment', 'post_comment'])], 'target_id' => ['required', 'string', 'max:36'],
            'reason' => ['required', Rule::in(['spam', 'harassment', 'sexual', 'violence', 'illegal', 'rights', 'fraud', 'privacy', 'other'])],
            'details' => ['required_if:reason,other', 'nullable', 'string', 'max:1000'], 'request_key' => ['required', 'uuid'],
        ]);
        if ($validator->fails()) $this->fail(422, 'INVALID_REPORT', '신고 대상·사유와 내용을 확인해주세요.');
        $input = $validator->validated();
        $type = $input['target_type'];
        if ((in_array($type, ['user', 'comment', 'post_comment'], true) && (!ctype_digit($input['target_id']) || (int) $input['target_id'] < 1))
            || (in_array($type, ['artwork', 'post'], true) && Validator::make(['id' => $input['target_id']], ['id' => ['uuid']])->fails())) {
            $this->fail(422, 'INVALID_REPORT', '신고 대상을 확인해주세요.');
        }
        if (in_array($type, ['user', 'comment', 'post_comment'], true)) $input['target_id'] = (string) (int) $input['target_id'];
        $db = app(ProjectDatabase::class)->database();
        $subject = match ($type) {
            'user' => (int) $input['target_id'],
            'comment' => $db->table('starttoo_artwork_comments')->where('id', $input['target_id'])->value('user_id'),
            'post_comment' => $db->table('starttoo_post_comments')->where('id', $input['target_id'])->value('user_id'),
            'post' => $db->table('starttoo_posts')->where('id', $input['target_id'])->value('user_id'),
            default => $db->table('starttoo_artworks')->where('id', $input['target_id'])->value('user_id'),
        };
        if ($subject === null) $this->fail(404, 'ARTWORK_NOT_FOUND', '작품을 찾을 수 없어요.');
        $subject = (int) $subject;
        if ($subject === $viewer) $this->fail(422, 'CANNOT_REPORT_SELF', '본인이나 본인의 작품은 신고할 수 없어요.');
        $hash = hash('sha256', json_encode(['report', $type, $input['target_id'], $input['reason'], $input['details'] ?? ''], JSON_THROW_ON_ERROR));
        return $db->transaction(function () use ($db, $token, $sessions, $artworks, $viewer, $subject, $input, $type, $hash) {
            $db->table('starttoo_users')->whereIn('id', [$viewer, $subject])->orderBy('id')->lockForUpdate()->get();
            $artworks->ready($token, $sessions);
            $requests = app(Blocks::class);
            if ($result = $requests->retry($viewer, $input['request_key'], $hash)) return $result;
            if ($type === 'user') {
                $user = app(PublicProfiles::class)->user($subject, $viewer);
                $snapshot = ['id' => $subject, 'nickname' => $user->nickname, 'bio' => $user->bio ?? ''];
            } elseif (in_array($type, ['comment', 'post_comment'], true)) {
                $postComment = $type === 'post_comment';
                $commentTable = $postComment ? 'starttoo_post_comments' : 'starttoo_artwork_comments';
                $contentId = $postComment ? 'post_id' : 'artwork_id';
                $comment = $db->table($commentTable)->where('id', $input['target_id'])->whereNull('deleted_at')->where('moderation_hidden', false)->lockForUpdate()->first();
                if (!$comment) $this->fail(404, 'COMMENT_NOT_FOUND', '댓글을 찾을 수 없어요.');
                $detail = $postComment ? app(Posts::class)->detail($comment->post_id, $viewer) + ['visibility' => 'public'] : $artworks->detail($comment->artwork_id, $viewer);
                app(PublicProfiles::class)->user($subject, $viewer);
                if ($detail['visibility'] !== 'public' || $detail['moderation_hidden']) $this->fail(404, 'COMMENT_NOT_FOUND', '댓글을 찾을 수 없어요.');
                if ($comment->parent_id !== null) {
                    $parent = $db->table($commentTable)->where('id', $comment->parent_id)->where('moderation_hidden', false)->first();
                    if (!$parent) $this->fail(404, 'COMMENT_NOT_FOUND', '댓글을 찾을 수 없어요.');
                    app(PublicProfiles::class)->user((int) $parent->user_id, $viewer);
                }
                $snapshot = ['id' => (int) $comment->id, $contentId => $comment->{$contentId}, 'content' => $comment->content, 'author_id' => $subject];
            } elseif ($type === 'post') {
                $db->table('starttoo_posts')->where('id', $input['target_id'])->lockForUpdate()->first();
                $detail = app(Posts::class)->detail($input['target_id'], $viewer);
                if ($detail['moderation_hidden']) $this->fail(404, 'POST_NOT_FOUND', '게시물을 찾을 수 없어요.');
                $snapshot = ['id' => $detail['id'], 'content' => $detail['content'], 'version' => $detail['version'], 'author_id' => $subject];
            } else {
                // 상세 조회와 같은 접근 제한을 적용한 뒤 접수 시점의 텍스트만 보관한다.
                $detail = $artworks->detail($input['target_id'], $viewer);
                if ($detail['visibility'] !== 'public' || $detail['moderation_hidden']) $this->fail(404, 'ARTWORK_NOT_FOUND', '작품을 찾을 수 없어요.');
                $snapshot = ['id' => $detail['id'], 'title' => $detail['title'], 'description' => $detail['description'],
                    'version' => $detail['version'], 'author_id' => $subject];
            }
            $openKey = hash('sha256', $viewer.':'.$type.':'.$input['target_id']);
            $existing = $db->table('starttoo_reports')->where('open_key', $openKey)->first();
            $id = $existing?->id;
            if (!$existing) {
                $id = $db->table('starttoo_reports')->insertGetId(['reporter_id' => $viewer, 'target_type' => $type,
                    'target_id' => $input['target_id'], 'subject_user_id' => $subject, 'reason' => $input['reason'],
                    'details' => $input['details'] ?? '', 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                    'status' => 'pending', 'open_key' => $openKey, 'created_at' => now(), 'updated_at' => now()]);
                $db->table('starttoo_safety_audits')->insert(['actor_type' => 'member', 'actor_user_id' => $viewer,
                    'report_id' => $id, 'target_type' => $type, 'target_id' => $input['target_id'], 'action' => 'report', 'reason' => $input['reason']]);
            }
            $result = ['report_id' => (int) $id, 'status' => 'pending', 'duplicate' => $existing !== null];
            $requests->remember($viewer, $input['request_key'], $hash, $result);
            $sessions->authenticate($token, true);
            return $result;
        }, 3);
    }

    /** 공개 HTTP 엔드포인트 없이 서버 운영 명령에서만 호출한다. */
    public function moderate(int $id, string $action, string $operator, string $reason): array
    {
        if (Validator::make(compact('id', 'action', 'operator', 'reason'), [
            'id' => ['required', 'integer', 'min:1'], 'action' => ['required', Rule::in(['hide', 'release', 'dismiss'])],
            'operator' => ['required', 'string', 'max:100', 'regex:/^[\p{L}\p{N}_.@-]+$/u'], 'reason' => ['required', 'string', 'max:1000'],
        ])->fails() || trim($reason) === '') throw new \InvalidArgumentException('처리 동작, 작업자 식별자와 사유를 확인해주세요.');
        $db = app(ProjectDatabase::class)->database();
        return $db->transaction(function () use ($db, $id, $action, $operator, $reason) {
            $record = $db->table('starttoo_reports')->where('id', $id)->first();
            if (!$record) throw new \InvalidArgumentException('신고를 찾을 수 없습니다.');
            // 앱 변경 작업과 회원 -> 작품 -> 신고 순서로 잠금을 맞춘다.
            $db->table('starttoo_users')->where('id', $record->subject_user_id)->lockForUpdate()->first();
            $table = match ($record->target_type) { 'user' => 'starttoo_users', 'post' => 'starttoo_posts', 'comment' => 'starttoo_artwork_comments', 'post_comment' => 'starttoo_post_comments', default => 'starttoo_artworks' };
            $target = $db->table($table)->where('id', $record->target_id)->lockForUpdate()->first();
            $report = $db->table('starttoo_reports')->where('id', $id)->lockForUpdate()->first();
            if (!$target) throw new \InvalidArgumentException('대상을 찾을 수 없습니다.');
            if ($action === 'dismiss' && $report->status !== 'pending') throw new \InvalidArgumentException('처리 대기 신고만 기각할 수 있습니다.');
            $before = (bool) $target->moderation_hidden;
            $hidden = $action === 'dismiss' ? $before : $action === 'hide';
            if ($action !== 'dismiss') {
                $values = ['moderation_hidden' => $hidden, 'updated_at' => now()];
                if (in_array($record->target_type, ['artwork', 'post'], true)) $values['version'] = $target->version + 1;
                $db->table($table)->where('id', $record->target_id)->update($values);
            }
            $status = $action === 'dismiss' ? 'dismissed' : 'resolved';
            $db->table('starttoo_reports')->where('id', $id)->update(['status' => $status, 'open_key' => null, 'updated_at' => now()]);
            $db->table('starttoo_safety_audits')->insert(['actor_type' => 'operator', 'operator' => $operator, 'report_id' => $id,
                'target_type' => $record->target_type, 'target_id' => $record->target_id, 'action' => $action, 'reason' => trim($reason),
                'changes' => json_encode(['moderation_hidden' => [$before, $hidden], 'status' => [$report->status, $status]], JSON_THROW_ON_ERROR)]);
            return ['report_id' => $id, 'status' => $status, 'moderation_hidden' => $hidden];
        }, 3);
    }

    private function fail(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(compact('code', 'message'), $status));
    }
}
