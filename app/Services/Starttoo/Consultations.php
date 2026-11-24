<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Phase 3 상담·채팅(3-01~3-03, 3-06, 3-07).
 * - 고객-작가 한 쌍당 상담방 하나. 종료 없이 이어서 대화한다.
 * - 이미 방이 있으면 새 상담을 만들지 않고, 요청서를 그 방에 이어서 올린다(append).
 * - 일반 1:1 채팅은 없다. 방은 상담 요청으로만 생긴다(이후 예약도 같은 방).
 * - 서버 설정(consultation_enabled)이 꺼지면 상담 시작·메시지 전송을 막는다. 조회는 허용.
 */
class Consultations
{
    public const SIZES = ['S', 'M', 'L'];
    private const PAGE = 30;
    private const UNREAD_CAP = 99;

    private function db() { return app(ProjectDatabase::class)->database(); }

    public function enabled(): bool
    {
        return (bool) config('starttoo.consultation_enabled', true);
    }

    // ── 시작 ─────────────────────────────────────

    /** 3-01 진입 전 확인: 상담 가능 여부와 기존 방. */
    public function availability(int $viewer, int $artist): array
    {
        $reason = $this->blockReason($viewer, $artist);
        $existing = $this->db()->table('starttoo_consultations')->where('customer_id', $viewer)->where('artist_id', $artist)->value('id');
        return ['enabled' => $this->enabled(), 'can_request' => $reason === null, 'reason' => $reason,
            'consultation_id' => $existing ? (int) $existing : null];
    }

    /** 새 요청을 받을 수 없는 이유 코드. 없으면 null. */
    private function blockReason(int $viewer, int $artist): ?string
    {
        if (!$this->enabled()) return 'CONSULTATION_DISABLED';
        if ($viewer === $artist) return 'CONSULTATION_SELF';
        $owner = $this->db()->table('starttoo_users')->where('id', $artist)->first(['status', 'artist_status', 'moderation_hidden']);
        if (!$owner || $owner->status !== 'active' || $owner->artist_status !== 'approved' || $owner->moderation_hidden) return 'ARTIST_UNAVAILABLE';
        if (app(Blocks::class)->between($viewer, $artist)) return 'ARTIST_UNAVAILABLE';
        $status = $this->db()->table('starttoo_shops')->where('user_id', $artist)->value('consult_status');
        if ($status !== 'open') return 'CONSULT_CLOSED';
        return null;
    }

    /**
     * 3-01 상담 요청서 보내기. 방이 있으면 append=true일 때만 그 방에 이어서 올린다.
     * client_id가 같으면 다시 보내도 같은 결과를 돌려준다.
     */
    public function start(int $viewer, array $input): array
    {
        $parts = array_column(app(Artworks::class)->options()['body_parts'], 'id');
        $v = Validator::make($input, [
            'artist_id' => ['required', 'integer', 'min:1'],
            'client_id' => ['required', 'uuid'],
            'artwork_id' => ['nullable', 'uuid'],
            'body_part' => ['nullable', Rule::in($parts)],
            'size' => ['nullable', Rule::in(self::SIZES)],
            'budget' => ['nullable', 'string', 'max:40'],
            'preferred_time' => ['nullable', 'string', 'max:40'],
            'memo' => ['nullable', 'string', 'max:1000'],
            'media_ids' => ['sometimes', 'array', 'max:5'],
            'media_ids.*' => ['uuid', 'distinct'],
            'append' => ['sometimes', 'boolean'],
        ]);
        if ($v->fails()) $this->fail(422, 'INVALID_CONSULTATION', '상담 요청 내용을 확인해주세요.');
        $in = $v->validated();
        $artist = (int) $in['artist_id'];
        if (empty($in['memo']) && empty($in['artwork_id']) && empty($in['media_ids'])) {
            $this->fail(422, 'INVALID_CONSULTATION', '요청 내용이나 참고 작품·이미지를 하나 이상 넣어주세요.');
        }
        // 같은 전송 키로 이미 만든 요청이면 그대로 돌려준다(응답 유실 후 재시도).
        $room = $this->db()->table('starttoo_consultations')->where('customer_id', $viewer)->where('artist_id', $artist)->first();
        if ($room) {
            $done = $this->db()->table('starttoo_messages')->where('consultation_id', $room->id)->where('sender_id', $viewer)
                ->where('client_id', $in['client_id'])->first();
            if ($done) return ['consultation' => $this->summary($viewer, (int) $room->id), 'message' => $this->format($done, $viewer)];
        }
        if ($reason = $this->blockReason($viewer, $artist)) $this->fail(403, $reason, $this->reasonMessage($reason));
        if ($room && empty($in['append'])) {
            throw new HttpResponseException(response()->json(['code' => 'CONSULTATION_EXISTS',
                'message' => '이미 이 아티스트와 상담 중인 대화가 있어요. 이어서 보낼까요?', 'consultation_id' => (int) $room->id], 409));
        }
        if (!empty($in['artwork_id']) && !$this->visibleArtwork($viewer, $in['artwork_id'])) {
            $this->fail(422, 'INVALID_CONSULTATION', '참고 작품을 확인할 수 없어요. 다시 선택해주세요.');
        }
        $id = $this->db()->transaction(function () use ($viewer, $artist, $in) {
            $now = now();
            $this->db()->table('starttoo_consultations')->insertOrIgnore([
                'customer_id' => $viewer, 'artist_id' => $artist, 'status' => 'requested', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $room = $this->db()->table('starttoo_consultations')->where('customer_id', $viewer)->where('artist_id', $artist)->lockForUpdate()->first();
            // 동시에 두 번 눌러도 append가 아니면 요청서는 한 번만 들어간다.
            if (empty($in['append']) && $this->db()->table('starttoo_messages')->where('consultation_id', $room->id)->where('kind', 'request')->exists()) {
                throw new HttpResponseException(response()->json(['code' => 'CONSULTATION_EXISTS',
                    'message' => '이미 이 아티스트와 상담 중인 대화가 있어요. 이어서 보낼까요?', 'consultation_id' => (int) $room->id], 409));
            }
            $paths = app(ArtworkUploads::class)->claim($viewer, array_values($in['media_ids'] ?? []));
            $requestId = $this->db()->table('starttoo_consultation_requests')->insertGetId([
                'consultation_id' => $room->id, 'artwork_id' => $in['artwork_id'] ?? null, 'body_part' => $in['body_part'] ?? null,
                'size' => $in['size'] ?? null, 'budget' => ($in['budget'] ?? '') ?: null, 'preferred_time' => ($in['preferred_time'] ?? '') ?: null,
                'memo' => ($in['memo'] ?? '') ?: null, 'created_at' => $now,
            ]);
            $messageId = $this->insertMessage($room, $viewer, $in['client_id'], 'request', null,
                ['request_id' => $requestId, 'images' => array_values($paths)]);
            return [$room->id, $messageId];
        }, 3);
        [$roomId, $messageId] = $id;
        return ['consultation' => $this->summary($viewer, (int) $roomId),
            'message' => $this->format($this->db()->table('starttoo_messages')->where('id', $messageId)->first(), $viewer)];
    }

    // ── 메시지 ───────────────────────────────────

    /** 3-03 메시지 보내기. client_id가 같으면 한 건만 저장하고 같은 메시지를 돌려준다. */
    public function send(int $viewer, int $id, array $input): array
    {
        $v = Validator::make($input, [
            'client_id' => ['required', 'uuid'],
            'kind' => ['required', Rule::in(['text', 'image', 'artwork'])],
            'body' => ['nullable', 'string', 'max:2000'],
            'media_ids' => ['sometimes', 'array', 'max:5'],
            'media_ids.*' => ['uuid', 'distinct'],
            'artwork_id' => ['nullable', 'uuid'],
        ]);
        if ($v->fails()) $this->fail(422, 'INVALID_MESSAGE', '메시지를 확인해주세요.');
        $in = $v->validated();
        $room = $this->room($viewer, $id);
        $done = $this->db()->table('starttoo_messages')->where('consultation_id', $room->id)->where('sender_id', $viewer)
            ->where('client_id', $in['client_id'])->first();
        if ($done) return $this->format($done, $viewer);
        $this->assertCanSend($viewer, $room);
        $body = trim((string) ($in['body'] ?? ''));
        if ($in['kind'] === 'text' && $body === '') $this->fail(422, 'INVALID_MESSAGE', '메시지를 입력해주세요.');
        if ($in['kind'] === 'image' && empty($in['media_ids'])) $this->fail(422, 'INVALID_MESSAGE', '보낼 사진을 선택해주세요.');
        if ($in['kind'] === 'artwork' && (empty($in['artwork_id']) || !$this->visibleArtwork($viewer, $in['artwork_id']))) {
            $this->fail(422, 'INVALID_MESSAGE', '보낼 작품을 확인할 수 없어요.');
        }
        $messageId = $this->db()->transaction(function () use ($viewer, $room, $in, $body) {
            $locked = $this->db()->table('starttoo_consultations')->where('id', $room->id)->lockForUpdate()->first();
            $payload = match ($in['kind']) {
                'image' => ['images' => array_values(app(ArtworkUploads::class)->claim($viewer, array_values($in['media_ids'])))],
                'artwork' => ['artwork_id' => $in['artwork_id']],
                default => null,
            };
            $messageId = $this->insertMessage($locked, $viewer, $in['client_id'], $in['kind'], $body ?: null, $payload);
            // 작가가 처음 답하면 「상담 중」
            if ((int) $locked->artist_id === $viewer && $locked->status === 'requested') {
                $this->db()->table('starttoo_consultations')->where('id', $locked->id)->update(['status' => 'consulting']);
            }
            return $messageId;
        }, 3);
        return $this->format($this->db()->table('starttoo_messages')->where('id', $messageId)->first(), $viewer);
    }

    /**
     * 3-03 메시지 페이지. before: 과거(위로 스크롤), after: 놓친 메시지 복구(소켓 재연결).
     * 결과는 항상 오래된 순.
     */
    public function messages(int $viewer, int $id, array $input): array
    {
        if (Validator::make($input, ['before' => ['nullable', 'integer', 'min:1'], 'after' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100']])->fails()) $this->fail(422, 'INVALID_MESSAGE_QUERY', '메시지를 다시 불러와주세요.');
        $room = $this->room($viewer, $id);
        $limit = (int) ($input['limit'] ?? self::PAGE);
        $q = $this->db()->table('starttoo_messages')->where('consultation_id', $room->id);
        if (isset($input['after'])) {
            $rows = $q->where('id', '>', (int) $input['after'])->orderBy('id')->limit($limit + 1)->get();
            $more = $rows->count() > $limit;
            $rows = $rows->take($limit);
        } else {
            if (isset($input['before'])) $q->where('id', '<', (int) $input['before']);
            $rows = $q->orderByDesc('id')->limit($limit + 1)->get();
            $more = $rows->count() > $limit;
            $rows = $rows->take($limit)->reverse()->values();
        }
        $mine = (int) $room->customer_id === $viewer;
        return ['items' => $this->formatMany($rows, $viewer), 'has_more' => $more,
            // 상대가 읽은 위치: 내 메시지 「읽음」 표시
            'other_last_read_id' => (int) ($mine ? $room->artist_last_read_id : $room->customer_last_read_id),
            'consultation' => $this->summary($viewer, (int) $room->id)];
    }

    /** 읽음 위치는 앞으로만 이동한다. */
    public function read(int $viewer, int $id, array $input): array
    {
        if (Validator::make($input, ['message_id' => ['required', 'integer', 'min:1']])->fails()) {
            $this->fail(422, 'INVALID_MESSAGE_QUERY', '읽음 위치를 확인해주세요.');
        }
        $room = $this->room($viewer, $id);
        $column = (int) $room->customer_id === $viewer ? 'customer_last_read_id' : 'artist_last_read_id';
        $target = min((int) $input['message_id'], (int) ($room->last_message_id ?? 0));
        $moved = $this->db()->table('starttoo_consultations')->where('id', $room->id)->where($column, '<', $target)->update([$column => $target]);
        // 상대의 「읽음」 표시와 내 다른 기기의 배지를 맞춘다.
        if ($moved) {
            try { event(new \App\Events\StarttooConsultationChanged([(int) $room->customer_id, (int) $room->artist_id], (int) $room->id, 'read', $target, $viewer)); }
            catch (\Throwable $e) { report($e); }
        }
        return ['last_read_id' => (int) $this->db()->table('starttoo_consultations')->where('id', $room->id)->value($column),
            'unread_total' => $this->unreadTotal($viewer)];
    }

    // ── 목록 ─────────────────────────────────────

    /**
     * 3-02 채팅 목록 · 3-06 회원 상담 관리 · 3-07 스튜디오 상담 관리.
     * role: all(내가 고객이거나 작가) · customer · artist / status 필터 / 최신 메시지 순 커서.
     */
    public function list(int $viewer, array $input): array
    {
        if (Validator::make($input, ['role' => ['nullable', Rule::in(['all', 'customer', 'artist'])],
            'status' => ['nullable', Rule::in(['all', 'requested', 'consulting', 'quoted', 'booked', 'unread'])],
            'cursor' => ['nullable', 'string', 'max:200']])->fails()) $this->fail(422, 'INVALID_MESSAGE_QUERY', '목록 조건을 확인해주세요.');
        $role = $input['role'] ?? 'all';
        $q = $this->db()->table('starttoo_consultations as c');
        $q->where(function ($w) use ($viewer, $role) {
            if ($role !== 'artist') $w->orWhere('c.customer_id', $viewer);
            if ($role !== 'customer') $w->orWhere('c.artist_id', $viewer);
        });
        // 차단한·차단당한 상대와의 방은 목록에서 숨긴다.
        $q->whereNotExists(fn ($b) => $b->selectRaw('1')->from('starttoo_user_blocks as sb')->where(function ($w) use ($viewer) {
            $w->where(fn ($x) => $x->where('sb.user_id', $viewer)->whereRaw('sb.blocked_user_id = CASE WHEN c.customer_id = ? THEN c.artist_id ELSE c.customer_id END', [$viewer]))
                ->orWhere(fn ($x) => $x->where('sb.blocked_user_id', $viewer)->whereRaw('sb.user_id = CASE WHEN c.customer_id = ? THEN c.artist_id ELSE c.customer_id END', [$viewer]));
        }));
        $status = $input['status'] ?? 'all';
        if (!in_array($status, ['all', 'unread'], true)) $q->where('c.status', $status);
        if ($status === 'unread') {
            $q->whereRaw('c.last_message_id > CASE WHEN c.customer_id = ? THEN c.customer_last_read_id ELSE c.artist_last_read_id END', [$viewer]);
        }
        if (!empty($input['cursor'])) {
            [$at, $cid] = array_pad(explode('|', base64_decode($input['cursor'], true) ?: ''), 2, null);
            if (!$at || !ctype_digit((string) $cid)) $this->fail(422, 'INVALID_MESSAGE_QUERY', '목록을 다시 불러와주세요.');
            $q->where(fn ($w) => $w->where('c.last_message_at', '<', $at)->orWhere(fn ($s) => $s->where('c.last_message_at', $at)->where('c.id', '<', (int) $cid)));
        }
        $rows = $q->orderByDesc('c.last_message_at')->orderByDesc('c.id')->limit(21)->get(['c.*']);
        $page = $rows->take(20);
        $last = $page->last();
        return ['items' => $page->map(fn ($r) => $this->summaryRow($viewer, $r))->values()->all(),
            'next_cursor' => $rows->count() > 20 ? base64_encode($last->last_message_at.'|'.$last->id) : null,
            'unread_total' => $this->unreadTotal($viewer)];
    }

    public function detail(int $viewer, int $id): array
    {
        $room = $this->room($viewer, $id);
        return $this->summary($viewer, (int) $room->id);
    }

    /** 하단 채팅 탭 배지: 안 읽은 메시지 수(최대 999). 내가 보낸 것은 세지 않는다. */
    public function unreadTotal(int $viewer): int
    {
        $count = $this->db()->table('starttoo_messages as m')->join('starttoo_consultations as c', 'c.id', '=', 'm.consultation_id')
            ->where(fn ($w) => $w->where(fn ($x) => $x->where('c.customer_id', $viewer)->whereColumn('m.id', '>', 'c.customer_last_read_id'))
                ->orWhere(fn ($x) => $x->where('c.artist_id', $viewer)->whereColumn('m.id', '>', 'c.artist_last_read_id')))
            ->where(fn ($w) => $w->whereNull('m.sender_id')->orWhere('m.sender_id', '<>', $viewer))
            ->whereNotExists(fn ($b) => $b->selectRaw('1')->from('starttoo_user_blocks as sb')
                ->where(fn ($w) => $w->where(fn ($x) => $x->where('sb.user_id', $viewer)->whereIn('sb.blocked_user_id', [$this->db()->raw('c.customer_id'), $this->db()->raw('c.artist_id')]))
                    ->orWhere(fn ($x) => $x->where('sb.blocked_user_id', $viewer)->whereIn('sb.user_id', [$this->db()->raw('c.customer_id'), $this->db()->raw('c.artist_id')]))))
            ->limit(1000)->count();
        return min($count, 999);
    }

    // ── 내부 ─────────────────────────────────────

    /** 참여자만 방을 볼 수 있다. 아니면 404(방 존재 여부를 드러내지 않는다). */
    public function room(int $viewer, int $id): object
    {
        $room = $this->db()->table('starttoo_consultations')->where('id', $id)
            ->where(fn ($w) => $w->where('customer_id', $viewer)->orWhere('artist_id', $viewer))->first();
        if (!$room) $this->fail(404, 'CONSULTATION_NOT_FOUND', '대화를 찾을 수 없어요.');
        return $room;
    }

    private function assertCanSend(int $viewer, object $room): void
    {
        if (!$this->enabled()) $this->fail(403, 'CONSULTATION_DISABLED', $this->reasonMessage('CONSULTATION_DISABLED'));
        $other = (int) $room->customer_id === $viewer ? (int) $room->artist_id : (int) $room->customer_id;
        if (app(Blocks::class)->between($viewer, $other)) $this->fail(403, 'CONVERSATION_BLOCKED', '차단한 회원과는 메시지를 주고받을 수 없어요.');
        $status = $this->db()->table('starttoo_users')->where('id', $other)->value('status');
        if ($status !== 'active') $this->fail(403, 'CONVERSATION_UNAVAILABLE', '대화 상대가 메시지를 받을 수 없는 상태예요.');
    }

    private function insertMessage(object $room, ?int $sender, ?string $clientId, string $kind, ?string $body, ?array $payload): int
    {
        $now = now();
        $id = $this->db()->table('starttoo_messages')->insertGetId([
            'consultation_id' => $room->id, 'sender_id' => $sender, 'client_id' => $clientId, 'kind' => $kind,
            'body' => $body, 'payload' => $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => $now,
        ]);
        $update = ['last_message_id' => $id, 'last_message_at' => $now, 'updated_at' => $now];
        // 보낸 사람은 자기 메시지까지 읽은 것으로 본다.
        if ($sender !== null) $update[(int) $room->customer_id === $sender ? 'customer_last_read_id' : 'artist_last_read_id'] = $id;
        $this->db()->table('starttoo_consultations')->where('id', $room->id)->update($update);
        $this->notify($room, $id, $sender);
        return $id;
    }

    /**
     * 커밋 후: 두 참여자 개인 채널에 변경 알림, 받는 사람에게 푸시(채팅 설정·읽음 여부는 발송 직전에 다시 확인).
     * 실시간·푸시 실패가 메시지 저장을 실패시키지 않는다.
     */
    private function notify(object $room, int $messageId, ?int $sender): void
    {
        $users = [(int) $room->customer_id, (int) $room->artist_id];
        $roomId = (int) $room->id;
        $this->db()->afterCommit(function () use ($users, $roomId, $messageId) {
            try { event(new \App\Events\StarttooConsultationChanged($users, $roomId, 'message', $messageId)); }
            catch (\Throwable $e) { report($e); }
        });
        if (!config('starttoo.push_enabled') || $sender === null) return;
        $this->db()->table('starttoo_push_outbox')->insertOrIgnore(['message_id' => $messageId, 'created_at' => now()]);
        $outboxId = (int) $this->db()->table('starttoo_push_outbox')->where('message_id', $messageId)->value('id');
        $this->db()->afterCommit(function () use ($outboxId) {
            try { \App\Jobs\QueueStarttooPush::dispatch($outboxId); }
            catch (\Throwable $e) { report($e); }
        });
    }

    // ── 실시간 ───────────────────────────────────

    /** 앱이 접속할 Reverb 정보. 키가 없거나 방송이 꺼져 있으면 enabled=false(앱은 HTTP 조회로만 동작). */
    public function realtime(int $viewer): array
    {
        $key = config('broadcasting.connections.reverb.key');
        $on = config('broadcasting.default') === 'reverb' && $key && config('starttoo.realtime_host');
        return ['enabled' => (bool) $on, 'key' => $on ? $key : null, 'host' => $on ? config('starttoo.realtime_host') : null,
            'port' => (int) config('starttoo.realtime_port'), 'scheme' => config('starttoo.realtime_scheme'),
            'channel' => "private-starttoo.user.{$viewer}"];
    }

    /** 개인 채널 구독 서명(Pusher 프로토콜). 본인 채널만 서명한다. */
    public function authorizeChannel(int $viewer, array $input): array
    {
        if (Validator::make($input, ['socket_id' => ['required', 'string', 'regex:/^\d+\.\d+$/', 'max:64'],
            'channel_name' => ['required', 'string', 'max:100']])->fails()) $this->fail(422, 'INVALID_REALTIME', '실시간 연결 정보를 확인해주세요.');
        if ($input['channel_name'] !== "private-starttoo.user.{$viewer}") $this->fail(403, 'REALTIME_FORBIDDEN', '구독할 수 없는 채널이에요.');
        $key = config('broadcasting.connections.reverb.key');
        $secret = config('broadcasting.connections.reverb.secret');
        if (!$key || !$secret) $this->fail(503, 'REALTIME_UNAVAILABLE', '실시간 연결을 준비하고 있어요.');
        return ['auth' => $key.':'.hash_hmac('sha256', $input['socket_id'].':'.$input['channel_name'], $secret)];
    }

    private function visibleArtwork(int $viewer, string $artworkId): bool
    {
        return app(Artworks::class)->feedQuery($viewer, 'public')->where('a.id', $artworkId)->exists()
            || $this->db()->table('starttoo_artworks')->where('id', $artworkId)->where('user_id', $viewer)->exists();
    }

    private function reasonMessage(string $code): string
    {
        return match ($code) {
            'CONSULTATION_DISABLED' => '지금은 상담을 이용할 수 없어요. 잠시 후 다시 시도해주세요.',
            'CONSULTATION_SELF' => '본인에게는 상담을 요청할 수 없어요.',
            'CONSULT_CLOSED' => '이 아티스트는 지금 새 상담을 받지 않아요.',
            default => '상담을 요청할 수 없는 아티스트예요.',
        };
    }

    public function summary(int $viewer, int $id): array
    {
        return $this->summaryRow($viewer, $this->db()->table('starttoo_consultations')->where('id', $id)->first());
    }

    private function summaryRow(int $viewer, object $r): array
    {
        $asCustomer = (int) $r->customer_id === $viewer;
        $otherId = $asCustomer ? (int) $r->artist_id : (int) $r->customer_id;
        $other = $this->db()->table('starttoo_users')->where('id', $otherId)->first(['id', 'nickname', 'avatar_path', 'status']);
        $shop = $this->db()->table('starttoo_shops')->where('user_id', $r->artist_id)->first(['studio_name', 'consult_status']);
        $myRead = (int) ($asCustomer ? $r->customer_last_read_id : $r->artist_last_read_id);
        $unread = $this->db()->table('starttoo_messages')->where('consultation_id', $r->id)->where('id', '>', $myRead)
            ->where(fn ($w) => $w->whereNull('sender_id')->orWhere('sender_id', '<>', $viewer))->limit(self::UNREAD_CAP + 1)->count();
        $last = $r->last_message_id ? $this->db()->table('starttoo_messages')->where('id', $r->last_message_id)->first() : null;
        $request = $this->db()->table('starttoo_consultation_requests')->where('consultation_id', $r->id)->orderByDesc('id')->first();
        $withdrawn = ($other->status ?? null) === 'withdrawn';
        return [
            'id' => (int) $r->id, 'role' => $asCustomer ? 'customer' : 'artist', 'status' => $r->status,
            'other' => ['id' => $otherId, 'nickname' => $withdrawn ? '탈퇴한 회원' : ($other->nickname ?? ''),
                'avatar_url' => $withdrawn ? null : app(MediaUrls::class)->url($other->avatar_path ?? null, 300),
                'studio_name' => $asCustomer ? ($shop->studio_name ?? null) : null, 'withdrawn' => $withdrawn],
            'artist_consult_status' => $shop->consult_status ?? null,
            'unread' => min($unread, self::UNREAD_CAP),
            'last_message' => $last ? ['id' => (int) $last->id, 'mine' => (int) ($last->sender_id ?? 0) === $viewer,
                'preview' => $this->preview($last), 'created_at' => $this->time($last->created_at)] : null,
            'last_message_at' => $this->time($r->last_message_at),
            'request' => $request ? $this->requestView($request, $viewer) : null,
            'can_send' => $this->enabled() && !$withdrawn && ($other->status ?? null) === 'active' && !app(Blocks::class)->between($viewer, $otherId),
        ];
    }

    private function preview(object $m): string
    {
        return match ($m->kind) {
            'text' => mb_substr((string) $m->body, 0, 80),
            'image' => '사진을 보냈어요',
            'artwork' => '작품을 보냈어요',
            'request' => '상담 요청서를 보냈어요',
            'quote' => '견적을 보냈어요',
            default => mb_substr((string) $m->body, 0, 80),
        };
    }

    private function requestView(object $req, int $viewer): array
    {
        $parts = collect(app(Artworks::class)->options()['body_parts'])->pluck('label', 'id');
        return ['id' => (int) $req->id, 'body_part' => $req->body_part, 'body_part_label' => $req->body_part ? ($parts[$req->body_part] ?? null) : null,
            'size' => $req->size, 'budget' => $req->budget, 'preferred_time' => $req->preferred_time, 'memo' => $req->memo,
            'artwork' => $req->artwork_id ? $this->artworkCard($req->artwork_id, $viewer) : null];
    }

    /** 참고 작품 카드. 지금 볼 수 없는 작품(삭제·비공개)은 unavailable로 둔다. */
    private function artworkCard(string $artworkId, int $viewer): array
    {
        $row = $this->db()->table('starttoo_artworks as a')->join('starttoo_users as u', 'u.id', '=', 'a.user_id')
            ->where('a.id', $artworkId)->first(['a.id', 'a.kind', 'a.title', 'a.description', 'a.price', 'a.design_status', 'a.user_id', 'a.visibility', 'a.moderation_hidden', 'u.nickname']);
        $visible = $row && ((int) $row->user_id === $viewer || ($row->visibility === 'public' && !$row->moderation_hidden))
            && !app(Blocks::class)->between($viewer, (int) $row->user_id);
        if (!$visible) return ['id' => $artworkId, 'unavailable' => true];
        $image = $this->db()->table('starttoo_artwork_images')->where('artwork_id', $artworkId)->orderBy('position')->value('path');
        $title = trim((string) $row->title) !== '' ? $row->title : mb_substr(trim(strtok((string) $row->description, "\n") ?: ''), 0, 40);
        return ['id' => $row->id, 'kind' => $row->kind, 'title' => $title, 'author' => $row->nickname,
            'price' => $row->price === null ? null : (int) $row->price,
            'design_status' => $row->kind === 'design' ? $row->design_status : null,
            'image_url' => app(MediaUrls::class)->url($image, 500), 'unavailable' => false];
    }

    private function formatMany($rows, int $viewer): array
    {
        return $rows->map(fn ($m) => $this->format($m, $viewer))->values()->all();
    }

    public function format(object $m, int $viewer): array
    {
        $payload = $m->payload ? json_decode($m->payload, true, 512, JSON_THROW_ON_ERROR) : [];
        $mine = (int) ($m->sender_id ?? 0) === $viewer;
        $item = ['id' => (int) $m->id, 'client_id' => $mine ? $m->client_id : null, 'sender_id' => $m->sender_id === null ? null : (int) $m->sender_id,
            'mine' => $mine, 'kind' => $m->kind, 'body' => $m->body, 'created_at' => $this->time($m->created_at)];
        if (!empty($payload['images'])) $item['images'] = array_map(fn ($p) => app(MediaUrls::class)->url($p, 1080), $payload['images']);
        if (!empty($payload['artwork_id'])) $item['artwork'] = $this->artworkCard($payload['artwork_id'], $viewer);
        if (!empty($payload['request_id'])) {
            $req = $this->db()->table('starttoo_consultation_requests')->where('id', $payload['request_id'])->first();
            if ($req) $item['request'] = $this->requestView($req, $viewer);
        }
        return $item;
    }

    private function time($value): ?string
    {
        return $value ? \Illuminate\Support\Carbon::parse($value)->toIso8601String() : null;
    }

    private function fail(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(compact('code', 'message'), $status));
    }
}
