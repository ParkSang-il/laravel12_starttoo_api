<?php

namespace App\Http\Controllers;

use App\Services\GroupMessageService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class GroupMessageController extends Controller
{
    public function __construct(private GroupMessageService $messages) {}

    private function input(Request $r, array $rules): array
    {
        if (array_diff(array_keys($r->all()), array_keys($rules))) {
            throw ValidationException::withMessages(['request' => ['허용되지 않은 입력입니다.']]);
        }
        return $r->validate($rules);
    }

    public function index(Request $r)
    {
        $d = $this->input($r, ['before' => ['sometimes', 'integer', 'min:0'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:50']]);
        return response()->json(['data' => $this->messages->index($r->user(), $r->route('group'), isset($d['before']) ? (int) $d['before'] : null, (int) ($d['limit'] ?? 30))]);
    }

    public function changes(Request $r)
    {
        $d = $this->input($r, ['since' => ['required', 'integer', 'min:0'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        return response()->json(['data' => $this->messages->changes($r->user(), $r->route('group'), (int) $d['since'], (int) ($d['limit'] ?? 50))]);
    }

    public function markRead(Request $r)
    {
        $d = $this->input($r, ['message_id' => ['required', 'integer', 'min:1']]);
        return response()->json(['data' => $this->messages->markRead($r->user(), $r->route('group'), (int) $d['message_id'])]);
    }

    public function unreadCounts(Request $r)
    {
        $d = $this->input($r, ['from' => ['required', 'integer', 'min:0']]);
        return response()->json(['data' => $this->messages->unreadCounts($r->user(), $r->route('group'), (int) $d['from'])]);
    }

    public function show(Request $r)
    {
        $this->input($r, []);
        return response()->json(['data' => $this->messages->show($r->user(), $r->route('group'), (int) $r->route('message'))]);
    }

    private function text(Request $r, bool $creating): array
    {
        if (is_string($r->input('body'))) $r->merge(['body' => trim($r->input('body'))]);
        $rules = ['body' => ['required', 'string', 'min:1', 'max:1000']];
        if ($creating) $rules['request_id'] = ['required', 'uuid'];
        $d = $this->input($r, $rules);
        if ($creating) $d['request_id'] = strtolower($d['request_id']);
        return $d;
    }

    public function create(Request $r)
    {
        return response()->json(['data' => $this->messages->create($r->user(), $r->route('group'), $this->text($r, true))], 201);
    }

    public function update(Request $r)
    {
        $d = $this->text($r, false);
        return response()->json(['data' => $this->messages->update($r->user(), $r->route('group'), (int) $r->route('message'), $d['body'])]);
    }

    public function delete(Request $r)
    {
        $this->input($r, []);
        return response()->json(['data' => $this->messages->delete($r->user(), $r->route('group'), (int) $r->route('message'))]);
    }
}
