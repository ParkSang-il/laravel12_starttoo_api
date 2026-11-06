<?php

namespace App\Http\Controllers;

use App\Services\ProjectDatabase;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;

class SupportMessageController extends Controller
{
    public function __construct(private ProjectDatabase $database) {}

    private function records(int $projectId): Builder
    {
        return $this->database->database()->table('support_messages as m')
            ->join('project_accounts as a', function ($join) {
                $join->on('a.id', '=', 'm.account_id')->on('a.project_id', '=', 'm.project_id');
            })->where('m.project_id', $projectId)
            ->select('m.*', 'a.display_name', 'a.nickname_tag', 'a.status as account_status');
    }

    private function present(object $row): array
    {
        $anonymous = (bool) $row->anonymous;
        $name = $row->display_name ?: '돌보미';
        if ($row->nickname_tag !== null) $name .= '#'.$row->nickname_tag;

        // A response never contains an anonymous writer's account ID, tag or photo.
        return [
            'id' => (int) $row->id,
            'message' => $row->message,
            'anonymous' => $anonymous,
            'author_name' => $anonymous ? '익명의 응원' : ($row->account_status === 'active' ? $name : '돌보미'),
            'created_at' => CarbonImmutable::parse($row->created_at, config('app.timezone', 'UTC'))->toISOString(),
        ];
    }

    public function index(Request $request)
    {
        $data = $request->validate(['before_id' => ['sometimes', 'integer', 'min:1']]);
        $start = now()->startOfMonth();
        $end = $start->copy()->addMonth();
        $records = $this->records((int) $request->user()->project_id)
            ->where('m.created_at', '>=', $start)->where('m.created_at', '<', $end);
        $total = (clone $records)->count();
        if (isset($data['before_id'])) $records->where('m.id', '<', $data['before_id']);
        $rows = $records->orderByDesc('m.id')->limit(21)->get();
        $hasMore = $rows->count() > 20;
        $items = $rows->take(20);

        return response()->json(['data' => [
            'month' => $start->format('Y-m'), 'total' => $total,
            'items' => $items->map(fn ($row) => $this->present($row))->values(),
            'next_before_id' => $hasMore ? (int) $items->last()->id : null,
        ]]);
    }

    public function store(Request $request)
    {
        if (is_string($request->input('message'))) {
            $request->merge(['message' => preg_replace('/^\s+|\s+$/u', '', $request->input('message'))]);
        }
        $data = $request->validate([
            'request_id' => ['required', 'uuid'], 'message' => ['required', 'string', 'max:500'],
            'anonymous' => ['required', 'boolean'], 'account_id' => ['prohibited'],
            'author_name' => ['prohibited'], 'nickname_tag' => ['prohibited'],
            'amount' => ['prohibited'], 'is_paid' => ['prohibited'],
        ], ['message.required' => '응원 한마디를 입력해 주세요.',
            'message.max' => '응원 메시지는 500자까지 입력할 수 있어요.']);
        $actor = $request->user();
        $payloadHash = hash('sha256', json_encode([$data['message'], (bool) $data['anonymous']], JSON_THROW_ON_ERROR));
        [$id, $created] = $this->database->database()->transaction(function () use ($actor, $data, $payloadHash) {
            // Serialize this writer's duplicate requests, including concurrent retries.
            $account = $this->database->database()->table('project_accounts')
                ->where('project_id', $actor->project_id)->where('id', $actor->id)->lockForUpdate()->first();
            abort_unless($account && $account->status === 'active', 403);
            $table = $this->database->database()->table('support_messages');
            $existing = (clone $table)->where('project_id', $actor->project_id)
                ->where('account_id', $actor->id)->where('request_id', $data['request_id'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->payload_hash, $payloadHash), 409, '등록 내용이 변경됐어요. 새로 등록해 주세요.');
                return [$existing->id, false];
            }
            $now = now();
            $id = $table->insertGetId([
                'project_id' => $actor->project_id, 'account_id' => $actor->id,
                'request_id' => $data['request_id'], 'payload_hash' => $payloadHash,
                'message' => $data['message'], 'anonymous' => (bool) $data['anonymous'],
                'created_at' => $now, 'updated_at' => $now,
            ]);
            return [$id, true];
        });

        $row = $this->records((int) $actor->project_id)->where('m.id', $id)->first();
        return response()->json(['data' => $this->present($row)], $created ? 201 : 200);
    }
}
