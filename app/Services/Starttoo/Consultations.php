<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\RateLimiter;
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
    /** 아티스트가 종료할 수 있는(진행 중) 상태 */
    public const OPEN = ['requested', 'consulting', 'quoted'];
    /** 새 요청서를 막는(아직 끝나지 않은) 상태. 예약 이후는 Phase 4에서 완료·취소로 닫힌다. */
    public const ACTIVE = ['requested', 'consulting', 'quoted', 'booked'];
    public const CLOSE_REASONS = [
        'schedule' => '일정이 맞지 않아요',
        'budget' => '예산이 맞지 않아요',
        'other_artist' => '다른 아티스트와 진행해요',
        'inquiry_done' => '문의만 하고 마쳤어요',
        'other' => '기타',
    ];
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
        $room = $this->db()->table('starttoo_consultations')->where('customer_id', $viewer)->where('artist_id', $artist)->first();
        // consultation_id: 대화방(있으면 채팅 이어가기) · active_case: 진행 중인 건이 있어 새 요청서는 그 건에 덧붙는다
        return ['enabled' => $this->enabled(), 'can_request' => $reason === null, 'reason' => $reason,
            'consultation_id' => $room ? (int) $room->id : null, 'active_case' => $room && $this->activeCase($room) !== null];
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
            // 필수: 부위·크기·참고(작품 또는 이미지 1장 이상)
            'body_part' => ['required', Rule::in($parts)],
            'size' => ['required', Rule::in(self::SIZES)],
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
        if (empty($in['artwork_id']) && empty($in['media_ids'])) {
            $this->fail(422, 'INVALID_CONSULTATION', '참고 작품이나 참고 이미지를 1장 이상 넣어주세요.');
        }
        // 같은 전송 키로 이미 만든 요청이면 그대로 돌려준다(응답 유실 후 재시도).
        $room = $this->db()->table('starttoo_consultations')->where('customer_id', $viewer)->where('artist_id', $artist)->first();
        if ($room) {
            $done = $this->db()->table('starttoo_messages')->where('consultation_id', $room->id)->where('sender_id', $viewer)
                ->where('client_id', $in['client_id'])->first();
            if ($done) return ['consultation' => $this->summary($viewer, (int) $room->id), 'message' => $this->format($done, $viewer)];
        }
        if ($reason = $this->blockReason($viewer, $artist)) $this->fail(403, $reason, $this->reasonMessage($reason));
        $this->limit('start', $viewer);
        // 진행 중인 상담 건이 있으면 새 건을 만들지 않고, 그 건에 요청서를 덧붙일지 묻는다.
        // 종료·완료된 건만 있으면 같은 대화방에 새 건을 연다.
        if ($room && empty($in['append']) && $this->activeCase($room)) $this->caseExists($room);
        if (!empty($in['artwork_id']) && !$this->visibleArtwork($viewer, $in['artwork_id'])) {
            $this->fail(422, 'INVALID_CONSULTATION', '참고 작품을 확인할 수 없어요. 다시 선택해주세요.');
        }
        // 도안 상담: 예산 · 희망 시기 · 요청 내용 · 참고 이미지까지 모두 필수
        if (!empty($in['artwork_id']) && $this->db()->table('starttoo_artworks')->where('id', $in['artwork_id'])->value('kind') === 'design') {
            foreach (['budget' => '예산을', 'preferred_time' => '희망 시기를', 'memo' => '요청 내용을'] as $field => $label) {
                if (trim((string) ($in[$field] ?? '')) === '') $this->fail(422, 'INVALID_CONSULTATION', "도안 상담은 {$label} 꼭 적어주세요.");
            }
            if (empty($in['media_ids'])) $this->fail(422, 'INVALID_CONSULTATION', '도안 상담은 참고 이미지를 1장 이상 넣어주세요.');
        }
        $id = $this->db()->transaction(function () use ($viewer, $artist, $in) {
            $now = now();
            $this->db()->table('starttoo_consultations')->insertOrIgnore([
                'customer_id' => $viewer, 'artist_id' => $artist, 'status' => 'requested', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $room = $this->db()->table('starttoo_consultations')->where('customer_id', $viewer)->where('artist_id', $artist)->lockForUpdate()->first();
            // 동시에 두 번 눌러도 append가 아니면 진행 중인 건은 하나만 생긴다.
            $case = $this->activeCase($room, true);
            if ($case && empty($in['append'])) $this->caseExists($room);
            if (!$case) {
                $caseId = $this->db()->table('starttoo_consultation_cases')->insertGetId([
                    'consultation_id' => $room->id, 'customer_id' => $room->customer_id, 'artist_id' => $room->artist_id,
                    'status' => 'requested', 'created_at' => $now, 'updated_at' => $now,
                ]);
                $this->db()->table('starttoo_consultations')->where('id', $room->id)->update(['current_case_id' => $caseId, 'status' => 'requested']);
                $room->current_case_id = $caseId;
                $room->status = 'requested';
            } else {
                $caseId = (int) $case->id;
            }
            $paths = app(ArtworkUploads::class)->claim($viewer, array_values($in['media_ids'] ?? []));
            $requestId = $this->db()->table('starttoo_consultation_requests')->insertGetId([
                'consultation_id' => $room->id, 'case_id' => $caseId, 'artwork_id' => $in['artwork_id'] ?? null, 'body_part' => $in['body_part'] ?? null,
                'size' => $in['size'] ?? null, 'budget' => ($in['budget'] ?? '') ?: null, 'preferred_time' => ($in['preferred_time'] ?? '') ?: null,
                'memo' => ($in['memo'] ?? '') ?: null, 'created_at' => $now,
            ]);
            $messageId = $this->insertMessage($room, $viewer, $in['client_id'], 'request', null,
                ['request_id' => $requestId, 'case_id' => $caseId, 'images' => array_values($paths)]);
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
            // 견적 수정 요청 등: 이 방의 견적을 메시지에 첨부
            'quote_id' => ['nullable', 'integer', 'min:1'],
        ]);
        if ($v->fails()) $this->fail(422, 'INVALID_MESSAGE', '메시지를 확인해주세요.');
        $in = $v->validated();
        $room = $this->room($viewer, $id);
        $done = $this->db()->table('starttoo_messages')->where('consultation_id', $room->id)->where('sender_id', $viewer)
            ->where('client_id', $in['client_id'])->first();
        if ($done) return $this->format($done, $viewer);
        $this->assertCanSend($viewer, $room);
        $this->limit('message', $viewer);
        $body = trim((string) ($in['body'] ?? ''));
        if ($in['kind'] === 'text' && $body === '') $this->fail(422, 'INVALID_MESSAGE', '메시지를 입력해주세요.');
        if ($in['kind'] === 'image' && empty($in['media_ids'])) $this->fail(422, 'INVALID_MESSAGE', '보낼 사진을 선택해주세요.');
        if ($in['kind'] === 'artwork' && (empty($in['artwork_id']) || !$this->visibleArtwork($viewer, $in['artwork_id']))) {
            $this->fail(422, 'INVALID_MESSAGE', '보낼 작품을 확인할 수 없어요.');
        }
        if (!empty($in['quote_id']) && !$this->db()->table('starttoo_quotes')->where('id', $in['quote_id'])->where('consultation_id', $room->id)->exists()) {
            $this->fail(422, 'INVALID_MESSAGE', '첨부한 견적을 찾을 수 없어요.');
        }
        $messageId = $this->db()->transaction(function () use ($viewer, $room, $in, $body) {
            $locked = $this->db()->table('starttoo_consultations')->where('id', $room->id)->lockForUpdate()->first();
            $payload = match ($in['kind']) {
                'image' => ['images' => array_values(app(ArtworkUploads::class)->claim($viewer, array_values($in['media_ids'])))],
                'artwork' => ['artwork_id' => $in['artwork_id']],
                default => null,
            };
            if (!empty($in['quote_id'])) $payload = [...($payload ?? []), 'quote_id' => (int) $in['quote_id']];
            $messageId = $this->insertMessage($locked, $viewer, $in['client_id'], $in['kind'], $body ?: null, $payload);
            // 작가가 처음 답하면 현재 건이 「상담 중」
            if ((int) $locked->artist_id === $viewer && $locked->status === 'requested') $this->setCaseStatus($locked, 'consulting');
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
            'status' => ['nullable', Rule::in(['all', 'requested', 'consulting', 'quoted', 'booked', 'unread', 'ongoing', 'ended'])],
            'cursor' => ['nullable', 'string', 'max:200']])->fails()) $this->fail(422, 'INVALID_MESSAGE_QUERY', '목록 조건을 확인해주세요.');
        $role = $input['role'] ?? 'all';
        $q = $this->baseList($viewer, $role);
        $status = $input['status'] ?? 'all';
        $this->applyStatus($q, $viewer, $status);
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

    /** 내가 참여한 방(role) 중 차단 관계가 아닌 방. */
    private function baseList(int $viewer, string $role)
    {
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
        return $q;
    }

    private function applyStatus($q, int $viewer, string $status): void
    {
        if ($status === 'unread') {
            $q->whereRaw('c.last_message_id > CASE WHEN c.customer_id = ? THEN c.customer_last_read_id ELSE c.artist_last_read_id END', [$viewer]);
            return;
        }
        if ($status === 'all') return;
        $otherActive = fn ($b) => $b->selectRaw('1')->from('starttoo_users as ou')->where('ou.status', 'active')
            ->whereRaw('ou.id = CASE WHEN c.customer_id = ? THEN c.artist_id ELSE c.customer_id END', [$viewer]);
        if ($status === 'ended') {
            $q->whereNotExists($otherActive);
            return;
        }
        $q->whereExists($otherActive);
        if ($status === 'ongoing') $q->whereIn('c.status', ['requested', 'consulting', 'quoted']);
        else $q->where('c.status', $status);
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
        // 종료·완료된 상담: 고객이 새 요청서를 보내 새 건이 열릴 때까지 양쪽 모두 보낼 수 없다.
        if ($room->status === 'closed') $this->fail(403, 'CONSULTATION_CLOSED', '종료된 상담이에요. 새 상담 요청서를 보내면 다시 대화할 수 있어요.');
        if ($room->status === 'completed') $this->fail(403, 'CONSULTATION_COMPLETED', '완료된 상담이에요. 새 상담 요청서를 보내면 다시 대화할 수 있어요.');
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
        // 진행 중인 건은 대화가 오갈 때마다 관리 목록 위로 올라간다.
        if (!empty($room->current_case_id)) {
            $this->db()->table('starttoo_consultation_cases')->where('id', $room->current_case_id)->whereIn('status', self::ACTIVE)->update(['updated_at' => $now]);
        }
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

    // ── 견적 (P3-4) ──────────────────────────────

    public const QUOTE_DAYS = [3, 7, 14];

    /**
     * 작가가 견적을 보낸다. 새 버전을 내면 이전 버전은 대체된다. 채팅에 견적 카드 메시지로 올라간다.
     * client_id가 같으면 다시 보내도 한 건만 만든다.
     */
    public function quote(int $viewer, int $id, array $input): array
    {
        $v = Validator::make($input, [
            'client_id' => ['required', 'uuid'],
            'subject' => ['required', 'string', 'min:1', 'max:100'],
            'items' => ['required', 'array', 'min:1', 'max:10'],
            'items.*.label' => ['required', 'string', 'min:1', 'max:30'],
            'items.*.amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            'deposit' => ['required', 'integer', 'min:0'],
            'valid_days' => ['required', 'integer', Rule::in(self::QUOTE_DAYS)],
            'duration' => ['nullable', 'string', 'max:40'],
            'includes' => ['nullable', 'string', 'max:300'],
            'memo' => ['nullable', 'string', 'max:500'],
        ]);
        if ($v->fails()) $this->fail(422, 'INVALID_QUOTE', '견적 내용을 확인해주세요.');
        $in = $v->validated();
        $room = $this->room($viewer, $id);
        if ((int) $room->artist_id !== $viewer) $this->fail(403, 'QUOTE_ARTIST_ONLY', '상담 받은 아티스트만 견적을 보낼 수 있어요.');
        $done = $this->db()->table('starttoo_messages')->where('consultation_id', $room->id)->where('sender_id', $viewer)
            ->where('client_id', $in['client_id'])->first();
        if ($done) return $this->format($done, $viewer);
        $this->assertCanSend($viewer, $room);
        $this->limit('quote', $viewer);
        $items = array_map(fn ($i) => ['label' => trim($i['label']), 'amount' => (int) $i['amount']], $in['items']);
        $total = array_sum(array_column($items, 'amount'));
        if ($total > 100000000) $this->fail(422, 'INVALID_QUOTE', '견적 총액이 너무 커요.');
        if ((int) $in['deposit'] > $total) $this->fail(422, 'INVALID_QUOTE_DEPOSIT', '예약금은 총액보다 클 수 없어요.');
        $messageId = $this->db()->transaction(function () use ($viewer, $room, $in, $items, $total) {
            $locked = $this->db()->table('starttoo_consultations')->where('id', $room->id)->lockForUpdate()->first();
            // 견적은 진행 중인 상담 건에만 보낼 수 있다.
            $case = $this->activeCase($locked, true);
            if (!$case || !in_array($case->status, self::OPEN, true)) {
                $this->fail(409, 'NO_OPEN_CONSULTATION', '진행 중인 상담이 없어요. 고객이 새 요청서를 보내면 견적을 보낼 수 있어요.');
            }
            $now = now();
            $version = (int) $this->db()->table('starttoo_quotes')->where('consultation_id', $locked->id)->max('version') + 1;
            // 이전 버전은 대체 처리(이력은 남긴다)
            $this->db()->table('starttoo_quotes')->where('consultation_id', $locked->id)->whereNull('superseded_at')->update(['superseded_at' => $now]);
            $quoteId = $this->db()->table('starttoo_quotes')->insertGetId([
                'consultation_id' => $locked->id, 'case_id' => $case->id, 'version' => $version, 'subject' => trim($in['subject']),
                'items' => json_encode($items, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'total' => $total,
                'deposit' => (int) $in['deposit'], 'duration' => ($in['duration'] ?? '') ?: null,
                'includes' => ($in['includes'] ?? '') ?: null, 'memo' => ($in['memo'] ?? '') ?: null,
                // 마지막 날 자정까지 유효
                'valid_until' => $now->copy()->addDays((int) $in['valid_days'])->endOfDay(), 'created_at' => $now,
            ]);
            $messageId = $this->insertMessage($locked, $viewer, $in['client_id'], 'quote', null, ['quote_id' => $quoteId, 'case_id' => (int) $case->id]);
            $this->setCaseStatus($locked, 'quoted');
            return $messageId;
        }, 3);
        return $this->format($this->db()->table('starttoo_messages')->where('id', $messageId)->first(), $viewer);
    }

    /** 3-05 견적 상세: 해당 버전 + 같은 방의 모든 버전 요약(최신 먼저). */
    public function quoteDetail(int $viewer, int $id, int $quoteId): array
    {
        $room = $this->room($viewer, $id);
        $quote = $this->db()->table('starttoo_quotes')->where('consultation_id', $room->id)->where('id', $quoteId)->first();
        if (!$quote) $this->fail(404, 'QUOTE_NOT_FOUND', '견적을 찾을 수 없어요.');
        $versions = $this->db()->table('starttoo_quotes')->where('consultation_id', $room->id)->orderByDesc('version')
            ->get(['id', 'version', 'total', 'created_at', 'superseded_at', 'valid_until']);
        return ['quote' => $this->quoteView($quote), 'consultation' => $this->summary($viewer, (int) $room->id),
            'versions' => $versions->map(fn ($q) => ['id' => (int) $q->id, 'version' => (int) $q->version, 'total' => (int) $q->total,
                'state' => $this->quoteState($q), 'created_at' => $this->time($q->created_at)])->all()];
    }

    /** 가장 최근 견적(작가가 수정할 때 불러오기). 없으면 null. */
    public function latestQuote(int $viewer, int $id): ?array
    {
        $room = $this->room($viewer, $id);
        $quote = $this->db()->table('starttoo_quotes')->where('consultation_id', $room->id)->orderByDesc('version')->first();
        return $quote ? $this->quoteView($quote) : null;
    }

    /** active 유효 · expired 기간 지남 · superseded 새 버전으로 대체 */
    private function quoteState(object $q): string
    {
        if ($q->superseded_at) return 'superseded';
        // 종료된 상담 건의 견적으로는 예약할 수 없다.
        if (!empty($q->case_id) && $this->db()->table('starttoo_consultation_cases')->where('id', $q->case_id)->value('status') === 'closed') return 'closed';
        return now()->greaterThan($q->valid_until) ? 'expired' : 'active';
    }

    private function quoteView(object $q): array
    {
        $latest = (int) $this->db()->table('starttoo_quotes')->where('consultation_id', $q->consultation_id)->max('id');
        return ['id' => (int) $q->id, 'version' => (int) $q->version, 'subject' => $q->subject,
            'items' => json_decode($q->items, true, 512, JSON_THROW_ON_ERROR), 'total' => (int) $q->total,
            'deposit' => (int) $q->deposit, 'balance' => (int) $q->total - (int) $q->deposit,
            'duration' => $q->duration, 'includes' => $q->includes, 'memo' => $q->memo,
            'valid_until' => $this->time($q->valid_until), 'state' => $this->quoteState($q),
            'latest_id' => $latest, 'created_at' => $this->time($q->created_at)];
    }

    // ── 실시간 ───────────────────────────────────

    /** 앱이 접속할 Reverb 정보. 키가 없거나 방송이 꺼져 있으면 enabled=false(앱은 HTTP 조회로만 동작). */
    public function realtime(int $viewer, ?string $requestHost = null): array
    {
        $key = config('broadcasting.connections.reverb.key');
        $host = config('starttoo.realtime_host');
        // 컨테이너 내부 이름(chat-reverb처럼 점이 없음)은 휴대폰에서 찾을 수 없다.
        // 그 경우 앱이 API를 호출한 공개 도메인을 쓴다(같은 nginx 443의 /app/ 경로로 Reverb 연결).
        if ((!$host || !str_contains($host, '.')) && $requestHost && str_contains($requestHost, '.')) $host = $requestHost;
        $on = config('broadcasting.default') === 'reverb' && $key && $host && str_contains($host, '.');
        return ['enabled' => (bool) $on, 'key' => $on ? $key : null, 'host' => $on ? $host : null,
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
        // 채팅방 상단: 현재 상담 건의 요청서
        $request = $r->current_case_id
            ? $this->db()->table('starttoo_consultation_requests')->where('case_id', $r->current_case_id)->orderByDesc('id')->first()
            : $this->db()->table('starttoo_consultation_requests')->where('consultation_id', $r->id)->orderByDesc('id')->first();
        $case = $r->current_case_id ? $this->db()->table('starttoo_consultation_cases')->where('id', $r->current_case_id)->first() : null;
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
            'can_send' => ($sendBlock = $this->sendBlock($viewer, $otherId, $other->status ?? null, $r->status)) === null,
            // 보낼 수 없는 이유: disabled · withdrawn · unavailable · blocked_by_me · blocked (상대가 차단했는지는 따로 알리지 않는다)
            'send_block' => $sendBlock,
            'ended' => ($other->status ?? null) !== 'active',
            'case' => $case ? $this->caseBrief($case, !$asCustomer) : null,
            'last_sender' => $r->last_message_id ? $this->lastSenderRole($r) : null,
        ];
    }

    /** 종료 사유는 아티스트에게만 보낸다. */
    private function caseBrief(object $k, bool $asArtist): array
    {
        $reason = $asArtist ? $k->close_reason : null;
        return ['id' => (int) $k->id, 'status' => $k->status, 'open' => in_array($k->status, self::OPEN, true),
            'closed_from' => $k->closed_from ?? null,
            'close_reason' => $reason, 'close_reason_label' => $reason ? (self::CLOSE_REASONS[$reason] ?? null) : null,
            'closed_at' => $this->time($k->closed_at), 'created_at' => $this->time($k->created_at)];
    }

    // ── 상담 건 ──────────────────────────────────

    /** 현재 건이 아직 끝나지 않았으면(ACTIVE) 그 건. */
    private function activeCase(object $room, bool $lock = false): ?object
    {
        if (empty($room->current_case_id)) return null;
        $q = $this->db()->table('starttoo_consultation_cases')->where('id', $room->current_case_id)->whereIn('status', self::ACTIVE);
        return ($lock ? $q->lockForUpdate() : $q)->first();
    }

    private function caseExists(object $room): never
    {
        throw new HttpResponseException(response()->json(['code' => 'CONSULTATION_EXISTS',
            'message' => '이 아티스트와 진행 중인 상담이 있어요. 요청서를 이어서 보낼까요?', 'consultation_id' => (int) $room->id], 409));
    }

    /** 현재 건과 대화방 상태를 함께 바꾼다(대화방 status = 현재 건 상태). */
    private function setCaseStatus(object $room, string $status): void
    {
        if (!empty($room->current_case_id)) {
            $this->db()->table('starttoo_consultation_cases')->where('id', $room->current_case_id)->update(['status' => $status, 'updated_at' => now()]);
        }
        $this->db()->table('starttoo_consultations')->where('id', $room->id)->update(['status' => $status]);
    }

    /**
     * 아티스트가 진행 중인 상담 건을 종료한다(사유 선택, 고객에게는 미노출).
     * 종료되면 양쪽 모두 메시지를 보낼 수 없고, 고객이 새 요청서를 보내면 같은 대화방에 새 건이 열려 다시 대화한다.
     * 이 건의 견적은 「종료」가 된다.
     */
    public function close(int $viewer, int $id, array $input): array
    {
        if (Validator::make($input, ['reason' => ['nullable', Rule::in(array_keys(self::CLOSE_REASONS))]])->fails()) {
            $this->fail(422, 'INVALID_CLOSE', '종료 사유를 확인해주세요.');
        }
        $room = $this->room($viewer, $id);
        if ((int) $room->artist_id !== $viewer) $this->fail(403, 'CLOSE_ARTIST_ONLY', '상담을 받은 아티스트만 종료할 수 있어요.');
        $reason = $input['reason'] ?? null;
        $this->db()->transaction(function () use ($viewer, $room, $reason) {
            $locked = $this->db()->table('starttoo_consultations')->where('id', $room->id)->lockForUpdate()->first();
            $case = $this->activeCase($locked, true);
            if (!$case || !in_array($case->status, self::OPEN, true)) {
                $this->fail(409, 'NO_OPEN_CONSULTATION', '진행 중인 상담이 없어요.');
            }
            $now = now();
            $this->db()->table('starttoo_consultation_cases')->where('id', $case->id)
                ->update(['status' => 'closed', 'close_reason' => $reason, 'closed_from' => $case->status, 'closed_at' => $now, 'updated_at' => $now]);
            $this->db()->table('starttoo_consultations')->where('id', $locked->id)->update(['status' => 'closed']);
            $locked->status = 'closed';
            // 종료 사유는 고객에게 보여주지 않는다(아티스트 기록용).
            $body = '아티스트가 상담을 종료했어요';
            $this->insertMessage($locked, $viewer, null, 'system', $body, ['case_id' => (int) $case->id, 'event' => 'closed']);
        }, 3);
        return $this->summary($viewer, (int) $room->id);
    }

    /**
     * 3-06 회원 · 3-07 스튜디오 상담 관리: 상담 건마다 한 줄(같은 상대와 여러 건이면 여러 줄).
     * status: 3-06 ongoing(진행 중) · converted(예약 전환: 예약·완료) · ended(종료: 종료 건 또는 상대 탈퇴·정지) · all
     *         3-07 requested · consulting · quoted · all
     */
    public function cases(int $viewer, array $input): array
    {
        if (Validator::make($input, ['role' => ['required', Rule::in(['customer', 'artist'])],
            'status' => ['nullable', Rule::in(['all', 'requested', 'consulting', 'quoted', 'booked', 'ongoing', 'converted', 'ended'])],
            'with_counts' => ['sometimes', 'boolean'], 'cursor' => ['nullable', 'string', 'max:200']])->fails()) {
            $this->fail(422, 'INVALID_MESSAGE_QUERY', '목록 조건을 확인해주세요.');
        }
        $role = $input['role'];
        $q = $this->baseCases($viewer, $role);
        $this->applyCaseStatus($q, $viewer, $input['status'] ?? 'all');
        if (!empty($input['cursor'])) {
            [$at, $cid] = array_pad(explode('|', base64_decode($input['cursor'], true) ?: ''), 2, null);
            if (!$at || !ctype_digit((string) $cid)) $this->fail(422, 'INVALID_MESSAGE_QUERY', '목록을 다시 불러와주세요.');
            $q->where(fn ($w) => $w->where('k.updated_at', '<', $at)->orWhere(fn ($s) => $s->where('k.updated_at', $at)->where('k.id', '<', (int) $cid)));
        }
        $rows = $q->orderByDesc('k.updated_at')->orderByDesc('k.id')->limit(21)->get(['k.*']);
        $page = $rows->take(20);
        $last = $page->last();
        $out = ['items' => $page->map(fn ($k) => $this->caseRow($viewer, $k))->values()->all(),
            'next_cursor' => $rows->count() > 20 ? base64_encode($last->updated_at.'|'.$last->id) : null];
        if (filter_var($input['with_counts'] ?? false, FILTER_VALIDATE_BOOLEAN) && empty($input['cursor'])) {
            $out['counts'] = $this->caseCounts($viewer, $role);
        }
        return $out;
    }

    public function caseCounts(int $viewer, string $role): array
    {
        $out = [];
        foreach (['all', 'requested', 'consulting', 'quoted', 'booked', 'ongoing', 'converted', 'ended'] as $status) {
            $q = $this->baseCases($viewer, $role);
            $this->applyCaseStatus($q, $viewer, $status);
            $out[$status] = $q->count();
        }
        return $out;
    }

    /** 스튜디오 대시보드 「새 상담」: 아직 답하지 않은 상담 건 수. */
    public function newRequestCount(int $artist): int
    {
        $q = $this->baseCases($artist, 'artist');
        $this->applyCaseStatus($q, $artist, 'requested');
        return $q->count();
    }

    private function baseCases(int $viewer, string $role)
    {
        $q = $this->db()->table('starttoo_consultation_cases as k')->where($role === 'artist' ? 'k.artist_id' : 'k.customer_id', $viewer);
        $other = $role === 'artist' ? 'k.customer_id' : 'k.artist_id';
        // 차단한·차단당한 상대와의 건은 숨긴다.
        return $q->whereNotExists(fn ($b) => $b->selectRaw('1')->from('starttoo_user_blocks as sb')
            ->where(fn ($w) => $w->where(fn ($x) => $x->where('sb.user_id', $viewer)->whereColumn('sb.blocked_user_id', $other))
                ->orWhere(fn ($x) => $x->where('sb.blocked_user_id', $viewer)->whereColumn('sb.user_id', $other))));
    }

    private function applyCaseStatus($q, int $viewer, string $status): void
    {
        if ($status === 'all') return;
        $otherActive = fn ($b) => $b->selectRaw('1')->from('starttoo_users as ou')->where('ou.status', 'active')
            ->whereRaw('ou.id = CASE WHEN k.customer_id = ? THEN k.artist_id ELSE k.customer_id END', [$viewer]);
        if ($status === 'ended') {
            $q->where(fn ($w) => $w->where('k.status', 'closed')->orWhereNotExists($otherActive));
            return;
        }
        $q->whereExists($otherActive);
        match ($status) {
            'ongoing' => $q->whereIn('k.status', self::OPEN),
            'converted' => $q->whereIn('k.status', ['booked', 'completed']),
            default => $q->where('k.status', $status),
        };
    }

    /** 관리 목록 한 줄: 대화방 요약(상대·안 읽음·보낼 수 있는지) + 이 건의 요청서·견적·종료 정보. */
    private function caseRow(int $viewer, object $k): array
    {
        $room = $this->db()->table('starttoo_consultations')->where('id', $k->consultation_id)->first();
        $row = $this->summaryRow($viewer, $room);
        $current = (int) $room->current_case_id === (int) $k->id;
        $request = $this->db()->table('starttoo_consultation_requests')->where('case_id', $k->id)->orderBy('id')->first();
        $quote = $this->db()->table('starttoo_quotes')->where('case_id', $k->id)->orderByDesc('version')->first();
        $quoteMessage = $quote ? (int) $this->db()->table('starttoo_messages')->where('consultation_id', $room->id)->where('kind', 'quote')
            ->where('payload->quote_id', (int) $quote->id)->value('id') : 0;
        $requestMessage = $request ? $this->db()->table('starttoo_messages')->where('consultation_id', $room->id)->where('kind', 'request')
            ->where('payload->request_id', (int) $request->id)->first(['payload']) : null;
        $images = $requestMessage && $requestMessage->payload ? (json_decode($requestMessage->payload, true)['images'] ?? []) : [];
        return array_merge($row, [
            'case_id' => (int) $k->id, 'status' => $k->status, 'case' => $this->caseBrief($k, (int) $k->artist_id === $viewer), 'current' => $current,
            'case_updated_at' => $this->time($k->updated_at),
            // 지난 건에는 안 읽은 수를 붙이지 않는다(대화방 하나를 함께 쓰므로 현재 건에만).
            'unread' => $current ? $row['unread'] : 0,
            'request' => $request ? $this->requestView($request, $viewer) : null,
            'latest_quote' => $quote ? ['id' => (int) $quote->id, 'version' => (int) $quote->version, 'subject' => $quote->subject,
                'total' => (int) $quote->total, 'state' => $this->quoteState($quote), 'valid_until' => $this->time($quote->valid_until),
                // 견적은 작가가 보내므로 고객이 그 메시지까지 읽었는지
                'seen' => $quoteMessage > 0 && (int) $room->customer_last_read_id >= $quoteMessage] : null,
            'request_image_count' => count($images),
            'request_image_url' => $images ? app(MediaUrls::class)->url($images[0], 300) : null,
        ]);
    }


    private function sendBlock(int $viewer, int $other, ?string $otherStatus, ?string $roomStatus = null): ?string
    {
        if (!$this->enabled()) return 'disabled';
        if ($otherStatus === 'withdrawn') return 'withdrawn';
        if ($otherStatus !== 'active') return 'unavailable';
        if (!app(Blocks::class)->between($viewer, $other)) return match ($roomStatus) { 'closed' => 'closed', 'completed' => 'completed', default => null };
        $mine = $this->db()->table('starttoo_user_blocks')->where('user_id', $viewer)->where('blocked_user_id', $other)->exists();
        return $mine ? 'blocked_by_me' : 'blocked';
    }

    /**
     * 회원별 횟수 제한(IP 제한과 별도). 넘으면 429 TOO_MANY_REQUESTS.
     * start: 하루 20건 · message: 1분 40건 · quote: 1시간 30건
     */
    private function limit(string $action, int $viewer): void
    {
        [$max, $decay, $message] = match ($action) {
            'start' => [20, 86400, '오늘은 상담 요청을 더 보낼 수 없어요. 내일 다시 시도해주세요.'],
            'quote' => [30, 3600, '견적을 너무 자주 보내고 있어요. 잠시 후 다시 시도해주세요.'],
            default => [40, 60, '메시지를 너무 빠르게 보내고 있어요. 잠시 후 다시 보내주세요.'],
        };
        $key = 'starttoo:consult:'.$action.':'.$viewer;
        if (RateLimiter::tooManyAttempts($key, $max)) $this->fail(429, 'TOO_MANY_REQUESTS', $message);
        RateLimiter::hit($key, $decay);
    }

    /** 마지막 메시지를 보낸 쪽: customer · artist · system */
    private function lastSenderRole(object $r): string
    {
        $sender = $this->db()->table('starttoo_messages')->where('id', $r->last_message_id)->value('sender_id');
        if ($sender === null) return 'system';
        return (int) $sender === (int) $r->customer_id ? 'customer' : 'artist';
    }

    private function preview(object $m): string
    {
        if (!empty($m->moderation_hidden)) return '운영 정책에 따라 가려진 메시지예요';
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
        // 운영자가 가린 메시지: 내용·첨부 없이 표시만 남긴다.
        if (!empty($m->moderation_hidden)) return ['kind' => 'hidden', 'body' => null] + $item;
        if (!empty($payload['images'])) $item['images'] = array_map(fn ($p) => app(MediaUrls::class)->url($p, 1080), $payload['images']);
        if (!empty($payload['artwork_id'])) $item['artwork'] = $this->artworkCard($payload['artwork_id'], $viewer);
        if (!empty($payload['quote_id'])) {
            $quote = $this->db()->table('starttoo_quotes')->where('id', $payload['quote_id'])->first();
            if ($quote) $item['quote'] = $this->quoteView($quote);
        }
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
