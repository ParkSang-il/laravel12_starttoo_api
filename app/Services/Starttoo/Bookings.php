<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Phase 4 예약(4-01~4-06).
 * - 고객이 상담 건의 유효한 최신 견적으로 날짜·시간을 골라 요청 → 작가 확정(= 예약 확정, 예약금 결제는 Phase 6)
 * - 확정된 예약만 그 시간을 잡는다. 같은 시간 요청은 여러 개 받을 수 있고, 하나를 확정하면 나머지는 자동 거절.
 * - 고객: 일정 변경 요청 · 취소 / 작가: 확정 · 거절 · 변경 수락·거절 · 시술 완료
 * - 규정(작가 설정, 요청 시점 값을 예약에 저장): 시술 N일 전까지 무료 변경·취소, 일정 변경 M회.
 *   N일 이내에는 일정 변경 불가(채팅으로 상의), 취소는 가능하지만 작가 환불 규정 안내.
 * - 시술일이 지나면 다음날 자동 완료(스케줄러). 완료되면 상담 건도 완료로 닫힌다.
 * - 상태가 바뀔 때마다 대화방에 안내 메시지(상대에게 푸시)를 남긴다.
 */
class Bookings
{
    public const DEFAULT_TIMES = ['11:00', '13:00', '15:00', '17:00'];
    public const DEFAULT_CLOSED = [1]; // 월요일
    public const MAX_DAYS = 90;
    /** 작가가 설정하지 않았을 때의 규정 */
    public const FREE_DAYS = 3;
    public const MAX_CHANGES = 1;
    public const ACTIVE = ['pending', 'confirmed', 'change_requested'];
    /** 시간을 잡는 상태 */
    public const HOLDING = ['confirmed', 'change_requested'];
    public const CANCEL_REASONS = [
        'schedule' => '일정이 생겼어요',
        'other_artist' => '다른 작가와 진행해요',
        'rethink' => '도안을 다시 고민할래요',
        'other' => '기타',
    ];
    private const WEEKDAYS = [1 => '월', 2 => '화', 3 => '수', 4 => '목', 5 => '금', 6 => '토', 7 => '일'];

    private function db() { return app(ProjectDatabase::class)->database(); }
    private function consult(): Consultations { return app(Consultations::class); }

    // ── 가용 시간 ────────────────────────────────

    public function settings(int $artist): array
    {
        $row = $this->db()->table('starttoo_booking_settings')->where('user_id', $artist)->first();
        return [
            'closed_weekdays' => $row ? json_decode($row->closed_weekdays, true) : self::DEFAULT_CLOSED,
            'start_times' => $row ? json_decode($row->start_times, true) : self::DEFAULT_TIMES,
            'free_days' => $row ? (int) ($row->free_days ?? self::FREE_DAYS) : self::FREE_DAYS,
            'max_changes' => $row ? (int) ($row->max_changes ?? self::MAX_CHANGES) : self::MAX_CHANGES,
        ];
    }

    /** 아티스트 가용 시간 설정 화면: 설정 + 앞으로의 특정 휴무일 */
    public function mySettings(int $viewer): array
    {
        $this->assertArtist($viewer);
        return $this->settings($viewer) + ['closures' => $this->db()->table('starttoo_booking_closures')->where('user_id', $viewer)
            ->where('date', '>=', today()->toDateString())->orderBy('date')->pluck('date')->map(fn ($d) => substr((string) $d, 0, 10))->all()];
    }

    public function updateSettings(int $viewer, array $input): array
    {
        $this->assertArtist($viewer);
        $v = Validator::make($input, [
            'closed_weekdays' => ['present', 'array', 'max:6'],
            'closed_weekdays.*' => ['integer', 'between:1,7', 'distinct'],
            'start_times' => ['required', 'array', 'min:1', 'max:12'],
            'start_times.*' => ['string', 'regex:/^([01]\d|2[0-3]):(00|30)$/', 'distinct'],
            'free_days' => ['required', 'integer', 'between:0,14'],
            'max_changes' => ['required', 'integer', 'between:0,3'],
        ]);
        if ($v->fails()) $this->fail(422, 'INVALID_BOOKING_SETTINGS', '휴무 요일과 시작 시간을 확인해주세요.');
        $days = array_values(array_unique(array_map('intval', $input['closed_weekdays'])));
        sort($days);
        $times = array_values(array_unique($input['start_times']));
        sort($times);
        $this->db()->table('starttoo_booking_settings')->updateOrInsert(['user_id' => $viewer], [
            'closed_weekdays' => json_encode($days), 'start_times' => json_encode($times),
            'free_days' => (int) $input['free_days'], 'max_changes' => (int) $input['max_changes'], 'updated_at' => now(),
        ]);
        return $this->mySettings($viewer);
    }

    /** 특정 날짜 휴무 켜기·끄기 */
    public function setClosure(int $viewer, array $input): array
    {
        $this->assertArtist($viewer);
        if (Validator::make($input, ['date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'], 'closed' => ['required', 'boolean']])->fails()) {
            $this->fail(422, 'INVALID_BOOKING_SETTINGS', '휴무일을 확인해주세요.');
        }
        $q = $this->db()->table('starttoo_booking_closures');
        if (filter_var($input['closed'], FILTER_VALIDATE_BOOLEAN)) $q->insertOrIgnore(['user_id' => $viewer, 'date' => $input['date']]);
        else $q->where('user_id', $viewer)->where('date', $input['date'])->delete();
        return $this->mySettings($viewer);
    }

    /**
     * 4-01 · 4-04 일정 선택: 한 달의 휴무 요일 · 휴무일 · 마감 시간(확정 예약). 예약은 내일부터 90일까지.
     */
    public function availability(int $artist, ?string $month): array
    {
        $start = $month && preg_match('/^\d{4}-\d{2}$/', $month) ? Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay() : today()->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $s = $this->settings($artist);
        $taken = [];
        foreach ($this->db()->table('starttoo_bookings')->where('artist_id', $artist)->whereIn('status', self::HOLDING)
            ->whereBetween('starts_at', [$start, $end])->pluck('starts_at') as $at) {
            $at = Carbon::parse($at);
            $taken[$at->toDateString()][] = $at->format('H:i');
        }
        return $s + [
            'month' => $start->format('Y-m'),
            'closures' => $this->db()->table('starttoo_booking_closures')->where('user_id', $artist)->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                ->pluck('date')->map(fn ($d) => substr((string) $d, 0, 10))->all(),
            'taken' => (object) $taken,
            'min_date' => today()->addDay()->toDateString(),
            'max_date' => today()->addDays(self::MAX_DAYS)->toDateString(),
        ];
    }

    /** 그 시각에 새로 잡을 수 있는지(가용 시간 · 휴무 · 확정 예약). */
    private function assertSlot(int $artist, Carbon $at, ?int $ignore = null): void
    {
        $s = $this->settings($artist);
        $ok = $at->greaterThanOrEqualTo(today()->addDay()) && $at->lessThanOrEqualTo(today()->addDays(self::MAX_DAYS)->endOfDay())
            && !in_array($at->dayOfWeekIso, $s['closed_weekdays'], true) && in_array($at->format('H:i'), $s['start_times'], true)
            && !$this->db()->table('starttoo_booking_closures')->where('user_id', $artist)->where('date', $at->toDateString())->exists();
        if (!$ok) $this->fail(422, 'BOOKING_SLOT_UNAVAILABLE', '예약할 수 없는 날짜·시간이에요. 다른 시간을 골라주세요.');
        $q = $this->db()->table('starttoo_bookings')->where('artist_id', $artist)->whereIn('status', self::HOLDING)->where('starts_at', $at);
        if ($ignore) $q->where('id', '<>', $ignore);
        if ($q->exists()) $this->fail(409, 'BOOKING_SLOT_TAKEN', '이미 예약이 확정된 시간이에요. 다른 시간을 골라주세요.');
    }

    private function parseSlot(array $input): Carbon
    {
        if (Validator::make($input, ['date' => ['required', 'date_format:Y-m-d'], 'time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/']])->fails()) {
            $this->fail(422, 'INVALID_BOOKING', '날짜와 시간을 골라주세요.');
        }
        return Carbon::createFromFormat('Y-m-d H:i', $input['date'].' '.$input['time']);
    }

    // ── 고객 ─────────────────────────────────────

    /** 4-01 예약 요청: 상담 건의 유효한 최신 견적 + 날짜·시간. client_id가 같으면 같은 예약을 돌려준다. */
    public function request(int $viewer, array $input): array
    {
        if (Validator::make($input, ['consultation_id' => ['required', 'integer'], 'quote_id' => ['required', 'integer'], 'client_id' => ['required', 'uuid']])->fails()) {
            $this->fail(422, 'INVALID_BOOKING', '예약 요청 내용을 확인해주세요.');
        }
        $done = $this->db()->table('starttoo_bookings')->where('customer_id', $viewer)->where('client_id', $input['client_id'])->first();
        if ($done) return $this->format($done, $viewer);
        $at = $this->parseSlot($input);
        $room = $this->consult()->room($viewer, (int) $input['consultation_id']);
        if ((int) $room->customer_id !== $viewer) $this->fail(403, 'BOOKING_CUSTOMER_ONLY', '상담을 요청한 회원만 예약할 수 있어요.');
        $this->consult()->assertCanSend($viewer, $room);
        $quote = $this->db()->table('starttoo_quotes')->where('id', $input['quote_id'])->where('consultation_id', $room->id)->first();
        if (!$quote || (int) $quote->case_id !== (int) $room->current_case_id || $this->consult()->quoteState($quote) !== 'active') {
            $this->fail(409, 'QUOTE_NOT_BOOKABLE', '이 견적으로는 예약할 수 없어요. 최신 견적을 확인해주세요.');
        }
        $id = $this->db()->transaction(function () use ($viewer, $room, $quote, $at, $input) {
            // 같은 건에 진행 중인 예약은 하나만
            $this->db()->table('starttoo_consultation_cases')->where('id', $room->current_case_id)->lockForUpdate()->first();
            $active = $this->db()->table('starttoo_bookings')->where('case_id', $room->current_case_id)->whereIn('status', self::ACTIVE)->first();
            if ($active) {
                throw new HttpResponseException(response()->json(['code' => 'BOOKING_EXISTS',
                    'message' => '이 상담에는 진행 중인 예약이 있어요.', 'booking_id' => (int) $active->id], 409));
            }
            $this->assertSlot((int) $room->artist_id, $at);
            $policy = $this->settings((int) $room->artist_id);
            $id = $this->db()->table('starttoo_bookings')->insertGetId([
                'free_days' => $policy['free_days'], 'max_changes' => $policy['max_changes'],
                'consultation_id' => $room->id, 'case_id' => $room->current_case_id, 'quote_id' => $quote->id,
                'customer_id' => $viewer, 'artist_id' => $room->artist_id, 'client_id' => $input['client_id'], 'starts_at' => $at,
                'status' => 'pending', 'subject' => $quote->subject, 'total' => $quote->total, 'deposit' => $quote->deposit,
                'duration' => $quote->duration, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->consult()->systemMessage((int) $room->id, $viewer, '예약을 요청했어요 · '.$this->label($at), ['booking_id' => $id, 'event' => 'requested',
                'when' => $this->label($at), 'summary' => $this->summary($quote->subject, $quote->duration, (int) $room->current_case_id)]);
            return $id;
        }, 3);
        return $this->format($this->find($id), $viewer);
    }

    /** 4-04 일정 변경 요청(확정된 예약, 작가 규정의 횟수 · 무료 변경 기간 안에서). 작가가 수락하면 바뀐다. */
    public function change(int $viewer, int $id, array $input): array
    {
        $at = $this->parseSlot($input);
        return $this->mutate($viewer, $id, 'customer', function ($b) use ($viewer, $at) {
            if ($b->status !== 'confirmed') $this->fail(409, 'BOOKING_STATE', '확정된 예약만 일정을 바꿀 수 있어요.');
            if ((int) $b->change_count >= (int) $b->max_changes) {
                $this->fail(409, 'BOOKING_CHANGE_LIMIT', (int) $b->max_changes === 0
                    ? '이 작가는 일정 변경을 받지 않아요. 채팅으로 상의해주세요.'
                    : "일정 변경은 {$b->max_changes}회까지 할 수 있어요. 채팅으로 작가와 상의해주세요.");
            }
            if ($this->daysLeft($b) < (int) $b->free_days) {
                $this->fail(409, 'BOOKING_CHANGE_DEADLINE', "시술 {$b->free_days}일 전부터는 일정을 바꿀 수 없어요. 채팅으로 작가와 상의해주세요.");
            }
            if ($at->equalTo(Carbon::parse($b->starts_at))) $this->fail(422, 'INVALID_BOOKING', '지금 예약과 다른 시간을 골라주세요.');
            $this->assertSlot((int) $b->artist_id, $at, (int) $b->id);
            $this->update($b, ['status' => 'change_requested', 'change_starts_at' => $at, 'change_by' => 'customer']);
            $this->message($b, $viewer, '예약 일정 변경을 요청했어요 · '.$this->label(Carbon::parse($b->starts_at)).' → '.$this->label($at), 'change_requested', null,
                $this->whenPayload($b, $at, true));
        });
    }

    /** 4-04 예약 취소(확정 대기·확정·변경 요청). 확정됐던 예약이면 상담이 다시 「상담 중」이 된다. */
    public function cancel(int $viewer, int $id, array $input): array
    {
        if (Validator::make($input, ['reason' => ['required', Rule::in(array_keys(self::CANCEL_REASONS))]])->fails()) {
            $this->fail(422, 'INVALID_BOOKING', '취소 사유를 골라주세요.');
        }
        return $this->mutate($viewer, $id, 'customer', function ($b) use ($viewer, $input) {
            if (!in_array($b->status, self::ACTIVE, true)) $this->fail(409, 'BOOKING_STATE', '취소할 수 없는 예약이에요.');
            $held = in_array($b->status, self::HOLDING, true);
            $this->update($b, ['status' => 'cancelled', 'cancel_reason' => $input['reason'], 'cancelled_by' => 'customer', 'cancelled_at' => now(), 'change_starts_at' => null, 'change_by' => null]);
            $this->message($b, $viewer, '예약을 취소했어요 · '.$this->label(Carbon::parse($b->starts_at)), 'cancelled', $held ? 'consulting' : null);
        });
    }

    // ── 아티스트 ─────────────────────────────────

    /** 4-06 확정. 같은 시간의 다른 요청은 자동 거절된다. */
    public function confirm(int $viewer, int $id): array
    {
        return $this->mutate($viewer, $id, 'artist', function ($b) use ($viewer) {
            if ($b->status !== 'pending') $this->fail(409, 'BOOKING_STATE', '확정 대기 중인 요청만 확정할 수 있어요.');
            $at = Carbon::parse($b->starts_at);
            if ($at->isPast()) $this->fail(409, 'BOOKING_STATE', '이미 지난 시간이에요. 고객과 일정을 다시 정해주세요.');
            $this->hold($b, $at, $viewer, '예약이 확정됐어요 · '.$this->label($at));
        });
    }

    /** 확정 대기 → 확정(그 시간을 잡고, 같은 시간의 다른 확정 대기 요청은 자동 거절) */
    private function hold(object $b, Carbon $at, int $actor, string $body): void
    {
        $this->assertHoldFree($b, $at);
        $this->update($b, ['status' => 'confirmed', 'starts_at' => $at, 'confirmed_at' => now(), 'change_starts_at' => null, 'change_by' => null]);
        $this->message($b, $actor, $body, 'confirmed', 'booked', $this->whenPayload($b, $at));
        foreach ($this->db()->table('starttoo_bookings')->where('artist_id', $b->artist_id)->where('status', 'pending')->where('starts_at', $at)
            ->where('id', '<>', $b->id)->lockForUpdate()->get() as $other) {
            $this->update($other, ['status' => 'rejected', 'reject_reason' => 'slot_taken', 'cancelled_at' => now(), 'change_starts_at' => null, 'change_by' => null]);
            $this->message($other, $actor, '요청한 시간에 다른 예약이 확정돼 예약 요청이 거절됐어요. 다른 시간으로 다시 요청해주세요.', 'rejected');
        }
    }

    /**
     * 일정 조율: 작가가 다른 시간을 제안(확정 대기 · 확정 예약). 고객이 수락하면 그 시간으로 확정된다.
     * 고객의 변경 횟수 · 무료 기간 규정과 상관없다.
     */
    public function propose(int $viewer, int $id, array $input): array
    {
        $at = $this->parseSlot($input);
        return $this->mutate($viewer, $id, 'artist', function ($b) use ($viewer, $at) {
            if (!in_array($b->status, ['pending', 'confirmed'], true)) $this->fail(409, 'BOOKING_STATE', '확정 대기 · 확정 예약만 일정을 조율할 수 있어요.');
            if ($b->change_starts_at) $this->fail(409, 'BOOKING_STATE', '이미 응답을 기다리는 일정 제안이 있어요.');
            if ($at->equalTo(Carbon::parse($b->starts_at))) $this->fail(422, 'INVALID_BOOKING', '지금 예약과 다른 시간을 골라주세요.');
            $this->assertSlot((int) $b->artist_id, $at, (int) $b->id);
            $this->update($b, ['status' => $b->status === 'confirmed' ? 'change_requested' : 'pending', 'change_starts_at' => $at, 'change_by' => 'artist']);
            $this->message($b, $viewer, '작가가 다른 시간을 제안했어요 · '.$this->label(Carbon::parse($b->starts_at)).' → '.$this->label($at), 'change_proposed', null,
                $this->whenPayload($b, $at, true));
        });
    }

    public function reject(int $viewer, int $id): array
    {
        return $this->mutate($viewer, $id, 'artist', function ($b) use ($viewer) {
            if ($b->status !== 'pending') $this->fail(409, 'BOOKING_STATE', '확정 대기 중인 요청만 거절할 수 있어요.');
            $this->update($b, ['status' => 'rejected', 'reject_reason' => 'artist', 'cancelled_at' => now(), 'change_starts_at' => null, 'change_by' => null]);
            $this->message($b, $viewer, '예약 요청이 거절됐어요 · '.$this->label(Carbon::parse($b->starts_at)), 'rejected');
        });
    }

    /** 변경 요청(고객) · 일정 제안(작가)을 상대가 수락 */
    public function acceptChange(int $viewer, int $id): array
    {
        return $this->mutate($viewer, $id, 'any', function ($b) use ($viewer) {
            $this->assertResponder($b, $viewer);
            $at = Carbon::parse($b->change_starts_at);
            if ($at->isPast()) $this->fail(409, 'BOOKING_STATE', '제안된 시간이 이미 지났어요.');
            if ($b->status === 'pending') {
                // 작가 제안을 고객이 수락 → 그 시간으로 바로 확정
                $this->hold($b, $at, $viewer, '제안한 시간으로 예약이 확정됐어요 · '.$this->label($at));
                return;
            }
            $this->assertHoldFree($b, $at);
            $byCustomer = ($b->change_by ?? 'customer') === 'customer';
            $this->update($b, ['status' => 'confirmed', 'starts_at' => $at, 'change_starts_at' => null, 'change_by' => null,
                // 고객 변경 요청만 변경 횟수에 센다
                'change_count' => (int) $b->change_count + ($byCustomer ? 1 : 0)]);
            $this->message($b, $viewer, '예약 일정이 변경됐어요 · '.$this->label($at), 'change_accepted', null, $this->whenPayload($b, $at));
        });
    }

    public function rejectChange(int $viewer, int $id): array
    {
        return $this->mutate($viewer, $id, 'any', function ($b) use ($viewer) {
            $this->assertResponder($b, $viewer);
            $byArtist = $b->change_by === 'artist';
            $this->update($b, ['status' => $b->status === 'pending' ? 'pending' : 'confirmed', 'change_starts_at' => null, 'change_by' => null]);
            $this->message($b, $viewer, ($byArtist ? '제안한 시간이 거절됐어요. ' : '일정 변경 요청이 거절됐어요. ')
                .'기존 일정('.$this->label(Carbon::parse($b->starts_at)).')'.($b->status === 'pending' ? '으로 확정을 기다려요.' : '으로 진행해요.'), 'change_rejected');
        });
    }

    /** 변경 희망 시간에 응답할 사람: 고객 요청이면 작가, 작가 제안이면 고객 */
    private function assertResponder(object $b, int $viewer): void
    {
        if (!$b->change_starts_at || !in_array($b->status, ['pending', 'change_requested'], true)) $this->fail(409, 'BOOKING_STATE', '응답할 일정 변경이 없어요.');
        $responder = $b->change_by === 'artist' ? (int) $b->customer_id : (int) $b->artist_id;
        if ($responder !== $viewer) $this->fail(403, 'BOOKING_FORBIDDEN', '상대방이 응답할 일정이에요.');
    }

    /** 시술 완료(시작 시각이 지난 확정 예약). 상담 건이 완료로 닫힌다. */
    public function complete(int $viewer, int $id): array
    {
        return $this->mutate($viewer, $id, 'artist', function ($b) use ($viewer) {
            if (!in_array($b->status, self::HOLDING, true)) $this->fail(409, 'BOOKING_STATE', '확정된 예약만 완료할 수 있어요.');
            // 테스트용 설정(STARTTOO_BOOKING_COMPLETE_ANYTIME=true)이면 시술 시각 전에도 완료할 수 있다.
            if (!config('starttoo.booking_complete_anytime', false) && Carbon::parse($b->starts_at)->isFuture()) {
                $this->fail(409, 'BOOKING_STATE', '시술 시간이 지난 뒤에 완료할 수 있어요.');
            }
            $this->finish($b, $viewer);
        });
    }

    private function finish(object $b, int $actor): void
    {
        $this->update($b, ['status' => 'completed', 'completed_at' => now(), 'change_starts_at' => null, 'change_by' => null]);
        $this->message($b, $actor, '시술이 완료됐어요. 이용해주셔서 감사해요!', 'completed', 'completed');
    }

    /**
     * 스케줄러(매시): 시술일이 지난 확정 예약 → 다음날 자동 완료, 시간이 지난 확정 대기 요청 → 만료.
     */
    public function sweep(): array
    {
        $done = 0;
        $expired = 0;
        foreach ($this->db()->table('starttoo_bookings')->whereIn('status', self::HOLDING)->where('starts_at', '<', today())->pluck('id') as $id) {
            $this->db()->transaction(function () use ($id, &$done) {
                $b = $this->db()->table('starttoo_bookings')->where('id', $id)->lockForUpdate()->first();
                if (!$b || !in_array($b->status, self::HOLDING, true)) return;
                $this->finish($b, (int) $b->artist_id);
                $done++;
            }, 3);
        }
        foreach ($this->db()->table('starttoo_bookings')->where('status', 'pending')->where('starts_at', '<', now())
            ->where(fn ($q) => $q->whereNull('change_starts_at')->orWhere('change_starts_at', '<', now()))->pluck('id') as $id) {
            $this->db()->transaction(function () use ($id, &$expired) {
                $b = $this->db()->table('starttoo_bookings')->where('id', $id)->lockForUpdate()->first();
                if (!$b || $b->status !== 'pending') return;
                $this->update($b, ['status' => 'expired', 'cancelled_by' => 'system', 'cancelled_at' => now()]);
                $this->message($b, (int) $b->customer_id, '작가 확정 전에 예약 시간이 지나 요청이 만료됐어요.', 'expired');
                $expired++;
            }, 3);
        }
        return compact('done', 'expired');
    }

    // ── 조회 ─────────────────────────────────────

    /**
     * 4-03 내 예약(customer): tab upcoming · past · cancelled
     * 4-06 요청·상태(artist): filter action(처리 필요) · confirmed · cancelled · all
     */
    public function list(int $viewer, array $input): array
    {
        if (Validator::make($input, ['role' => ['required', Rule::in(['customer', 'artist'])],
            'tab' => ['nullable', Rule::in(['upcoming', 'past', 'cancelled', 'action', 'confirmed', 'all'])],
            'page' => ['nullable', 'integer', 'min:1']])->fails()) $this->fail(422, 'INVALID_BOOKING', '목록 조건을 확인해주세요.');
        $role = $input['role'];
        $tab = $input['tab'] ?? ($role === 'artist' ? 'action' : 'upcoming');
        if ($role === 'artist') $this->assertArtist($viewer);
        $page = (int) ($input['page'] ?? 1);
        $q = $this->scoped($viewer, $role, $tab);
        $asc = in_array($tab, ['upcoming', 'action', 'confirmed'], true);
        $rows = $q->orderBy('starts_at', $asc ? 'asc' : 'desc')->orderByDesc('id')->offset(($page - 1) * 20)->limit(21)->get();
        $counts = [];
        foreach ($role === 'artist' ? ['action', 'confirmed', 'cancelled', 'all'] : ['upcoming', 'past', 'cancelled'] as $t) {
            $counts[$t] = $this->scoped($viewer, $role, $t)->count();
        }
        return ['items' => $rows->take(20)->map(fn ($b) => $this->format($b, $viewer))->values()->all(),
            'has_more' => $rows->count() > 20, 'page' => $page, 'counts' => $counts];
    }

    private function scoped(int $viewer, string $role, string $tab)
    {
        $q = $this->db()->table('starttoo_bookings')->where($role === 'artist' ? 'artist_id' : 'customer_id', $viewer);
        match ($tab) {
            'upcoming' => $q->whereIn('status', self::ACTIVE),
            'past' => $q->where('status', 'completed'),
            'cancelled' => $q->whereIn('status', ['cancelled', 'rejected', 'expired']),
            // 작가가 처리할 것: 확정 대기 · 고객 변경 요청(작가가 낸 제안은 고객 응답 대기라 뺀다)
            'action' => $q->whereIn('status', ['pending', 'change_requested'])
                ->where(fn ($w) => $w->whereNull('change_by')->orWhere('change_by', '<>', 'artist')),
            'confirmed' => $q->where('status', 'confirmed'),
            default => null,
        };
        return $q;
    }

    public function detail(int $viewer, int $id): array
    {
        $b = $this->find($id);
        if (!$b || ((int) $b->customer_id !== $viewer && (int) $b->artist_id !== $viewer)) $this->fail(404, 'BOOKING_NOT_FOUND', '예약을 찾을 수 없어요.');
        return $this->format($b, $viewer);
    }

    /** 대화방의 현재 상담 건 예약(진행 중이면). 채팅방·견적 상세의 「예약 요청」 버튼 상태. */
    public function forRoom(int $viewer, int $roomId): ?array
    {
        $room = $this->consult()->room($viewer, $roomId);
        $b = $this->db()->table('starttoo_bookings')->where('case_id', $room->current_case_id)->orderByDesc('id')->first();
        return $b ? $this->format($b, $viewer) : null;
    }

    /** 4-05 캘린더: 날짜별 확정·대기 수 + 휴무 */
    public function calendar(int $viewer, ?string $month): array
    {
        $this->assertArtist($viewer);
        $base = $this->availability($viewer, $month);
        $start = Carbon::createFromFormat('Y-m-d', $base['month'].'-01')->startOfDay();
        $days = [];
        foreach ($this->db()->table('starttoo_bookings')->where('artist_id', $viewer)->whereIn('status', [...self::ACTIVE, 'completed'])
            ->whereBetween('starts_at', [$start, $start->copy()->endOfMonth()])->get(['starts_at', 'status']) as $r) {
            $d = Carbon::parse($r->starts_at)->toDateString();
            $days[$d] ??= ['booked' => 0, 'pending' => 0];
            $days[$d]['booked']++;
            if (in_array($r->status, ['pending', 'change_requested'], true)) $days[$d]['pending']++;
        }
        return $base + ['days' => (object) $days];
    }

    /** 4-05 하루: 그날 예약(확정·대기·완료) + 빈 시간 */
    public function day(int $viewer, string $date): array
    {
        $this->assertArtist($viewer);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $this->fail(422, 'INVALID_BOOKING', '날짜를 확인해주세요.');
        $d = Carbon::createFromFormat('Y-m-d', $date)->startOfDay();
        $s = $this->settings($viewer);
        $closed = in_array($d->dayOfWeekIso, $s['closed_weekdays'], true)
            || $this->db()->table('starttoo_booking_closures')->where('user_id', $viewer)->where('date', $date)->exists();
        $rows = $this->db()->table('starttoo_bookings')->where('artist_id', $viewer)->whereIn('status', [...self::ACTIVE, 'completed'])
            ->whereBetween('starts_at', [$d, $d->copy()->endOfDay()])->orderBy('starts_at')->get();
        $held = $rows->filter(fn ($b) => in_array($b->status, [...self::HOLDING, 'completed'], true))->map(fn ($b) => Carbon::parse($b->starts_at)->format('H:i'))->all();
        $open = $closed || $d->lessThan(today()->addDay()) ? [] : array_values(array_diff($s['start_times'], $held));
        return ['date' => $date, 'closed' => $closed, 'bookings' => $rows->map(fn ($b) => $this->format($b, $viewer))->values()->all(), 'open_times' => $open];
    }

    /** 스튜디오 대시보드: 처리 필요 수 · 이번 달 예약 수 · 처리 필요 3건 */
    public function dashboard(int $artist): array
    {
        $month = [today()->startOfMonth(), today()->endOfMonth()];
        return [
            'action' => $this->scoped($artist, 'artist', 'action')->count(),
            'month_bookings' => $this->db()->table('starttoo_bookings')->where('artist_id', $artist)->whereIn('status', ['confirmed', 'change_requested', 'completed'])
                ->whereBetween('starts_at', $month)->count(),
            'month' => (int) today()->month,
            'pending' => $this->scoped($artist, 'artist', 'action')->orderBy('starts_at')->limit(3)->get()->map(fn ($b) => $this->format($b, $artist))->all(),
        ];
    }

    /** MY 「다가오는 예약」: 오늘 이후 진행 중 예약을 가까운 순으로 최대 3건 */
    public function upcoming(int $viewer, int $limit = 3): array
    {
        // 고객으로 예약한 것 + 작가로 받은 시술 예약을 함께, 시술일 가까운 순
        return $this->db()->table('starttoo_bookings')
            ->where(fn ($q) => $q->where('customer_id', $viewer)->orWhere('artist_id', $viewer))->whereIn('status', self::ACTIVE)
            ->where('starts_at', '>=', today())->orderBy('starts_at')->limit($limit)->get()
            ->map(fn ($b) => $this->format($b, $viewer))->all();
    }

    // ── 내부 ─────────────────────────────────────

    /** 잠금 → 권한(고객/작가) 확인 → 변경 → 갱신된 예약 반환 */
    private function mutate(int $viewer, int $id, string $who, callable $fn): array
    {
        $this->db()->transaction(function () use ($viewer, $id, $who, $fn) {
            $b = $this->db()->table('starttoo_bookings')->where('id', $id)->first();
            if (!$b || ((int) $b->customer_id !== $viewer && (int) $b->artist_id !== $viewer)) $this->fail(404, 'BOOKING_NOT_FOUND', '예약을 찾을 수 없어요.');
            // 같은 작가의 시간 다툼을 막기 위해 작가 회원 줄을 먼저 잠근다.
            $this->db()->table('starttoo_users')->where('id', $b->artist_id)->lockForUpdate()->first();
            $b = $this->db()->table('starttoo_bookings')->where('id', $id)->lockForUpdate()->first();
            $mine = match ($who) { 'artist' => (int) $b->artist_id === $viewer, 'customer' => (int) $b->customer_id === $viewer, default => true };
            if (!$mine) $this->fail(403, 'BOOKING_FORBIDDEN', $who === 'artist' ? '아티스트만 처리할 수 있어요.' : '예약한 회원만 처리할 수 있어요.');
            $fn($b);
        }, 3);
        return $this->format($this->find($id), $viewer);
    }

    /** 오늘부터 시술일까지 남은 날 수 */
    private function daysLeft(object $b): int
    {
        return (int) today()->diffInDays(Carbon::parse($b->starts_at)->startOfDay(), false);
    }

    private function assertHoldFree(object $b, Carbon $at): void
    {
        if ($this->db()->table('starttoo_bookings')->where('artist_id', $b->artist_id)->whereIn('status', self::HOLDING)
            ->where('starts_at', $at)->where('id', '<>', $b->id)->exists()) {
            $this->fail(409, 'BOOKING_SLOT_TAKEN', '그 시간에는 이미 확정된 예약이 있어요.');
        }
    }

    private function update(object $b, array $values): void
    {
        $this->db()->table('starttoo_bookings')->where('id', $b->id)->update($values + ['updated_at' => now()]);
    }

    /** 채팅 예약 카드(3-03B)용: 일정 · (변경 전 일정) · 작업 요약 */
    private function whenPayload(object $b, Carbon $at, bool $withPrevious = false): array
    {
        return array_filter([
            'when' => $this->label($at),
            'previous_when' => $withPrevious ? $this->label(Carbon::parse($b->starts_at)) : null,
            'summary' => $this->summary($b->subject, $b->duration, (int) $b->case_id),
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** 「블랙워크 뱀 · 팔뚝 · M · 약 5시간」(작업 대상 · 요청서 부위 · 크기 · 예상 소요) */
    private function summary(?string $subject, ?string $duration, int $caseId): ?string
    {
        $request = $this->db()->table('starttoo_consultation_requests')->where('case_id', $caseId)->orderBy('id')->first(['body_part', 'size']);
        $part = null;
        if ($request && $request->body_part) {
            $part = collect(app(Artworks::class)->options()['body_parts'])->pluck('label', 'id')[$request->body_part] ?? null;
        }
        $parts = array_values(array_filter([$subject, $part, $request->size ?? null, $duration], fn ($v) => is_string($v) && trim($v) !== ''));
        return $parts ? implode(' · ', array_map('trim', $parts)) : null;
    }

    private function message(object $b, int $actor, string $body, string $event, ?string $caseStatus = null, array $extra = []): void
    {
        $this->consult()->systemMessage((int) $b->consultation_id, $actor, $body, ['booking_id' => (int) $b->id, 'event' => $event] + $extra,
            (int) $b->case_id, $caseStatus);
        // 알림함 「예약 · 상담」에 남긴다(상대에게). 푸시는 위 채팅 메시지 푸시가 이미 가서 보내지 않는다.
        $customer = (int) $b->customer_id;
        $artist = (int) $b->artist_id;
        $pairs = $actor === $customer ? [[$artist, $customer]] : ($actor === $artist ? [[$customer, $artist]] : [[$customer, $artist], [$artist, $customer]]);
        foreach ($pairs as [$to, $from]) {
            try {
                // variant = 이벤트(최대 16자), 같은 이벤트가 또 와도 따로 쌓이게 시각을 키에 더한다
                app(Notifications::class)->emit($to, $from, 'booking_event', 'booking', (string) $b->id, null, null,
                    mb_substr($event, 0, 20), false, now()->format('YmdHisu'));
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * 시술 24시간 안으로 다가온 확정 예약에 리마인더(고객 · 작가 둘 다). 매시간 실행.
     * 같은 시작 시각에는 한 번만(variant = 시작 시각). 예약 시각이 바뀌면 다시 보낸다.
     */
    public function remind(): int
    {
        $sent = 0;
        $rows = $this->db()->table('starttoo_bookings')->where('status', 'confirmed')
            ->whereBetween('starts_at', [now(), now()->addDay()])->get();
        foreach ($rows as $b) {
            $at = Carbon::parse($b->starts_at)->format('Y-m-d H:i');
            foreach ([[(int) $b->customer_id, (int) $b->artist_id], [(int) $b->artist_id, (int) $b->customer_id]] as [$to, $from]) {
                try {
                    app(Notifications::class)->emit($to, $from, 'booking_remind', 'booking', (string) $b->id, null, null, $at);
                    $sent++;
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }
        return $sent;
    }

    private function find(int $id): ?object
    {
        return $this->db()->table('starttoo_bookings')->where('id', $id)->first();
    }

    /** 12.12 (토) 13:00 */
    private function label(Carbon $at): string
    {
        return $at->format('n.j').' ('.self::WEEKDAYS[$at->dayOfWeekIso].') '.$at->format('H:i');
    }

    private function assertArtist(int $viewer): void
    {
        if ($this->db()->table('starttoo_users')->where('id', $viewer)->value('artist_status') !== 'approved') {
            $this->fail(403, 'ARTIST_REQUIRED', '아티스트 승인 후 이용할 수 있어요.');
        }
    }

    public function format(object $b, int $viewer): array
    {
        $asArtist = (int) $b->artist_id === $viewer;
        $otherId = $asArtist ? (int) $b->customer_id : (int) $b->artist_id;
        $other = $this->db()->table('starttoo_users')->where('id', $otherId)->first(['nickname', 'avatar_path', 'status']);
        $shop = $this->db()->table('starttoo_shops')->where('user_id', $b->artist_id)->first(['studio_name', 'region', 'address']);
        $regions = collect(app(Profiles::class)->options()['regions'])->pluck('label', 'id');
        $request = $this->db()->table('starttoo_consultation_requests')->where('case_id', $b->case_id)->orderBy('id')->first();
        $image = null;
        if ($request && $request->artwork_id) {
            $image = $this->db()->table('starttoo_artwork_images')->where('artwork_id', $request->artwork_id)->orderBy('position')->value('path');
        }
        $at = Carbon::parse($b->starts_at);
        $withdrawn = ($other->status ?? null) === 'withdrawn';
        $active = in_array($b->status, self::ACTIVE, true);
        $daysLeft = $this->daysLeft($b);
        $freeDays = (int) ($b->free_days ?? self::FREE_DAYS);
        $maxChanges = (int) ($b->max_changes ?? self::MAX_CHANGES);
        $parts = collect(app(Artworks::class)->options()['body_parts'])->pluck('label', 'id');
        // Phase 5 인증 리뷰: 시술 완료 예약의 고객만 쓸 수 있고 예약당 한 건
        $reviewId = $b->status === 'completed' ? app(Reviews::class)->idForBooking((int) $b->id) : null;
        return [
            'review_id' => $reviewId,
            'can_review' => !$asArtist && $b->status === 'completed' && $reviewId === null,
            'id' => (int) $b->id, 'role' => $asArtist ? 'artist' : 'customer', 'status' => $b->status,
            'starts_at' => $at->toIso8601String(), 'change_starts_at' => $b->change_starts_at ? Carbon::parse($b->change_starts_at)->toIso8601String() : null,
            'change_count' => (int) $b->change_count, 'free_days' => $freeDays, 'max_changes' => $maxChanges,
            // 변경 희망 시간을 낸 쪽(customer 변경 요청 · artist 일정 제안)과, 지금 보는 사람이 응답할 차례인지
            'change_by' => $b->change_starts_at ? ($b->change_by ?? 'customer') : null,
            'can_respond' => (bool) $b->change_starts_at && in_array($b->status, ['pending', 'change_requested'], true)
                && (($b->change_by ?? 'customer') === 'artist' ? !$asArtist : $asArtist),
            'can_propose' => $asArtist && in_array($b->status, ['pending', 'confirmed'], true) && !$b->change_starts_at && $at->isFuture(),
            'can_change' => !$asArtist && $b->status === 'confirmed' && (int) $b->change_count < $maxChanges && $daysLeft >= $freeDays,
            'can_cancel' => !$asArtist && $active,
            // 작가 규정: 시술 N일 전까지 무료 변경·취소(예약금 결제는 Phase 6)
            'free_cancel' => $daysLeft >= $freeDays,
            'can_complete' => $asArtist && in_array($b->status, self::HOLDING, true)
                && ($at->isPast() || config('starttoo.booking_complete_anytime', false)),
            'subject' => $b->subject, 'total' => (int) $b->total, 'deposit' => (int) $b->deposit, 'duration' => $b->duration,
            'body_part_label' => $request && $request->body_part ? ($parts[$request->body_part] ?? null) : null, 'size' => $request->size ?? null,
            'image_url' => app(MediaUrls::class)->url($image, 300),
            'artist_id' => (int) $b->artist_id, 'consultation_id' => (int) $b->consultation_id, 'case_id' => (int) $b->case_id, 'quote_id' => (int) $b->quote_id,
            'other' => ['id' => $otherId, 'nickname' => $withdrawn ? '탈퇴한 회원' : ($other->nickname ?? ''),
                'avatar_url' => $withdrawn ? null : app(MediaUrls::class)->url($other->avatar_path ?? null, 300)],
            'shop' => ['studio_name' => $shop->studio_name ?? null, 'region_label' => $shop && $shop->region ? ($regions[$shop->region] ?? null) : null,
                // 주소는 확정 이후 고객에게만
                'address' => !$asArtist && in_array($b->status, [...self::HOLDING, 'completed'], true) ? ($shop->address ?? null) : null],
            'cancel_reason' => $b->cancel_reason, 'cancel_reason_label' => $b->cancel_reason ? (self::CANCEL_REASONS[$b->cancel_reason] ?? null) : null,
            'cancelled_by' => $b->cancelled_by, 'reject_reason' => $b->reject_reason,
            'created_at' => Carbon::parse($b->created_at)->toIso8601String(),
            'confirmed_at' => $b->confirmed_at ? Carbon::parse($b->confirmed_at)->toIso8601String() : null,
            'completed_at' => $b->completed_at ? Carbon::parse($b->completed_at)->toIso8601String() : null,
            'cancelled_at' => $b->cancelled_at ? Carbon::parse($b->cancelled_at)->toIso8601String() : null,
        ];
    }

    private function fail(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(compact('code', 'message'), $status));
    }
}
