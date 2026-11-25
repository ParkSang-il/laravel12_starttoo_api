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
            'target_type' => ['required', Rule::in(['user', 'artwork', 'post', 'comment', 'post_comment', 'message', 'consultation', 'review', 'community', 'community_comment'])], 'target_id' => ['required', 'string', 'max:36'],
            'reason' => ['required', Rule::in(['spam', 'harassment', 'sexual', 'violence', 'illegal', 'rights', 'fraud', 'privacy', 'other'])],
            'details' => ['required_if:reason,other', 'nullable', 'string', 'max:1000'], 'request_key' => ['required', 'uuid'],
        ]);
        if ($validator->fails()) $this->fail(422, 'INVALID_REPORT', '신고 대상·사유와 내용을 확인해주세요.');
        $input = $validator->validated();
        $type = $input['target_type'];
        $numeric = ['user', 'comment', 'post_comment', 'message', 'consultation', 'review', 'community', 'community_comment'];
        if ((in_array($type, $numeric, true) && (!ctype_digit($input['target_id']) || (int) $input['target_id'] < 1))
            || (in_array($type, ['artwork', 'post'], true) && Validator::make(['id' => $input['target_id']], ['id' => ['uuid']])->fails())) {
            $this->fail(422, 'INVALID_REPORT', '신고 대상을 확인해주세요.');
        }
        if (in_array($type, $numeric, true)) $input['target_id'] = (string) (int) $input['target_id'];
        $db = app(ProjectDatabase::class)->database();
        // 상담 메시지·대화: 참여자만 신고할 수 있고, 대상은 메시지를 보낸 회원 · 대화 상대.
        $room = null;
        if ($type === 'message') {
            $message = $db->table('starttoo_messages')->where('id', $input['target_id'])->first();
            if (!$message || $message->sender_id === null) $this->fail(404, 'MESSAGE_NOT_FOUND', '메시지를 찾을 수 없어요.');
            $room = app(Consultations::class)->room($viewer, (int) $message->consultation_id);
        } elseif ($type === 'consultation') {
            $room = app(Consultations::class)->room($viewer, (int) $input['target_id']);
        }
        $subject = match ($type) {
            'message' => $message->sender_id,
            'consultation' => (int) $room->customer_id === $viewer ? (int) $room->artist_id : (int) $room->customer_id,
            'user' => (int) $input['target_id'],
            // 리뷰: 대상은 리뷰를 쓴 회원(작가도 자기 상점 리뷰를 신고할 수 있다)
            'review' => $db->table('starttoo_reviews')->where('id', $input['target_id'])->where('moderation_hidden', false)->value('customer_id'),
            // 커뮤니티 글 · 댓글
            'community' => $db->table('starttoo_community_posts')->where('id', $input['target_id'])->where('moderation_hidden', false)->value('user_id'),
            'community_comment' => $db->table('starttoo_community_comments')->where('id', $input['target_id'])->where('moderation_hidden', false)->value('user_id'),
            'comment' => $db->table('starttoo_artwork_comments')->where('id', $input['target_id'])->value('user_id'),
            'post_comment' => $db->table('starttoo_post_comments')->where('id', $input['target_id'])->value('user_id'),
            'post' => $db->table('starttoo_posts')->where('id', $input['target_id'])->value('user_id'),
            default => $db->table('starttoo_artworks')->where('id', $input['target_id'])->value('user_id'),
        };
        if ($subject === null) $this->fail(404, 'ARTWORK_NOT_FOUND', '작품을 찾을 수 없어요.');
        $subject = (int) $subject;
        if ($subject === $viewer) $this->fail(422, 'CANNOT_REPORT_SELF', '본인이나 본인의 작품은 신고할 수 없어요.');
        $hash = hash('sha256', json_encode(['report', $type, $input['target_id'], $input['reason'], $input['details'] ?? ''], JSON_THROW_ON_ERROR));
        return $db->transaction(function () use ($db, $token, $sessions, $artworks, $viewer, $subject, $input, $type, $hash, $room) {
            $db->table('starttoo_users')->whereIn('id', [$viewer, $subject])->orderBy('id')->lockForUpdate()->get();
            $artworks->ready($token, $sessions);
            $requests = app(Blocks::class);
            if ($result = $requests->retry($viewer, $input['request_key'], $hash)) return $result;
            if ($type === 'message') {
                $m = $db->table('starttoo_messages')->where('id', $input['target_id'])->first();
                $snapshot = ['id' => (int) $m->id, 'consultation_id' => (int) $m->consultation_id, 'kind' => $m->kind,
                    'body' => $m->body, 'payload' => $m->payload, 'created_at' => (string) $m->created_at, 'author_id' => $subject];
            } elseif ($type === 'consultation') {
                // 검토용: 접수 시점의 최근 메시지 30건(텍스트·종류만)
                $recent = $db->table('starttoo_messages')->where('consultation_id', $room->id)->orderByDesc('id')->limit(30)
                    ->get(['id', 'sender_id', 'kind', 'body', 'created_at'])->reverse()->values();
                $snapshot = ['id' => (int) $room->id, 'customer_id' => (int) $room->customer_id, 'artist_id' => (int) $room->artist_id,
                    'messages' => $recent->map(fn ($r) => ['id' => (int) $r->id, 'sender_id' => $r->sender_id === null ? null : (int) $r->sender_id,
                        'kind' => $r->kind, 'body' => $r->body, 'created_at' => (string) $r->created_at])->all(), 'author_id' => $subject];
            } elseif ($type === 'review') {
                $review = $db->table('starttoo_reviews')->where('id', $input['target_id'])->lockForUpdate()->first();
                if (!$review || $review->moderation_hidden) $this->fail(404, 'REVIEW_NOT_FOUND', '리뷰를 찾을 수 없어요.');
                $snapshot = ['id' => (int) $review->id, 'artist_id' => (int) $review->artist_id, 'rating' => (int) $review->rating,
                    'content' => $review->content, 'reply' => $review->reply, 'author_id' => $subject];
            } elseif ($type === 'community') {
                $post = $db->table('starttoo_community_posts')->where('id', $input['target_id'])->lockForUpdate()->first();
                if (!$post || $post->moderation_hidden) $this->fail(404, 'COMMUNITY_NOT_FOUND', '글을 찾을 수 없어요.');
                $snapshot = ['id' => (int) $post->id, 'category' => $post->category, 'title' => $post->title,
                    'content' => $post->content, 'author_id' => $subject];
            } elseif ($type === 'community_comment') {
                $comment = $db->table('starttoo_community_comments')->where('id', $input['target_id'])->lockForUpdate()->first();
                if (!$comment || $comment->moderation_hidden) $this->fail(404, 'COMMENT_NOT_FOUND', '댓글을 찾을 수 없어요.');
                $snapshot = ['id' => (int) $comment->id, 'post_id' => (int) $comment->post_id, 'content' => $comment->content, 'author_id' => $subject];
            } elseif ($type === 'user') {
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
            // 중복 신고 금지: 같은 회원이 같은 대상을 한 번만 신고할 수 있다(처리 · 기각된 뒤에도).
            // 같은 요청의 재전송(request_key 동일)은 위 retry에서 이미 성공으로 돌려준다.
            if ($db->table('starttoo_reports')->where('reporter_id', $viewer)->where('target_type', $type)->where('target_id', $input['target_id'])->exists()) {
                $this->fail(409, 'ALREADY_REPORTED', '이미 신고한 대상이에요. 운영팀이 확인하고 있어요.');
            }
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

    /**
     * 내가 신고한 내역(최신 순). 상태: pending 검토 중 · resolved 처리 완료 · dismissed 조치 없이 종료.
     * 대상 미리보기는 접수할 때 남긴 내용에서 가져온다(대상이 지워져도 보인다). 운영자 메모는 주지 않는다.
     */
    public function mine(string $token, array $input, AuthSessions $sessions, Artworks $artworks): array
    {
        $viewer = (int) $artworks->ready($token, $sessions)['account']['id'];
        $page = max(1, min(100000, (int) ($input['page'] ?? 1)));
        $db = app(ProjectDatabase::class)->database();
        $rows = $db->table('starttoo_reports as r')->leftJoin('starttoo_users as u', 'u.id', '=', 'r.subject_user_id')
            ->where('r.reporter_id', $viewer)->orderByDesc('r.id')->offset(($page - 1) * 20)->limit(21)
            ->get(['r.id', 'r.target_type', 'r.reason', 'r.status', 'r.snapshot', 'r.created_at', 'r.updated_at', 'u.nickname', 'u.status as user_status']);
        return [
            'total' => $db->table('starttoo_reports')->where('reporter_id', $viewer)->count(),
            'page' => $page, 'has_more' => $rows->count() > 20,
            'items' => $rows->take(20)->map(function ($r) {
                $snapshot = json_decode((string) $r->snapshot, true) ?: [];
                $preview = '';
                foreach (['title', 'content', 'body', 'nickname'] as $key) {
                    if (is_string($snapshot[$key] ?? null) && trim($snapshot[$key]) !== '') { $preview = trim($snapshot[$key]); break; }
                }
                return [
                    'id' => (int) $r->id, 'target_type' => $r->target_type, 'reason' => $r->reason, 'status' => $r->status,
                    'subject_name' => $r->user_status === 'active' ? ($r->nickname ?? '회원') : '이용할 수 없는 회원',
                    'preview' => mb_substr(preg_replace('/\s+/u', ' ', $preview) ?? '', 0, 80),
                    'created_at' => \Illuminate\Support\Carbon::parse($r->created_at)->toIso8601String(),
                    // 처리 · 종료된 시각(검토 중이면 null)
                    'closed_at' => $r->status === 'pending' ? null : \Illuminate\Support\Carbon::parse($r->updated_at)->toIso8601String(),
                ];
            })->values()->all(),
        ];
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
            $table = match ($record->target_type) { 'user' => 'starttoo_users', 'post' => 'starttoo_posts', 'comment' => 'starttoo_artwork_comments',
                'post_comment' => 'starttoo_post_comments', 'message' => 'starttoo_messages', 'consultation' => 'starttoo_consultations',
                'review' => 'starttoo_reviews', 'community' => 'starttoo_community_posts',
                'community_comment' => 'starttoo_community_comments', default => 'starttoo_artworks' };
            // 대화 전체 신고는 가릴 대상이 없다: 처리(resolved)·기각만 기록하고, 조치는 메시지·회원 단위로 한다.
            $conversation = $record->target_type === 'consultation';
            $target = $db->table($table)->where('id', $record->target_id)->lockForUpdate()->first();
            $report = $db->table('starttoo_reports')->where('id', $id)->lockForUpdate()->first();
            if (!$target) throw new \InvalidArgumentException('대상을 찾을 수 없습니다.');
            if ($action === 'dismiss' && $report->status !== 'pending') throw new \InvalidArgumentException('처리 대기 신고만 기각할 수 있습니다.');
            $before = (bool) ($target->moderation_hidden ?? false);
            $hidden = $action === 'dismiss' || $conversation ? $before : $action === 'hide';
            if ($action !== 'dismiss' && !$conversation) {
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
