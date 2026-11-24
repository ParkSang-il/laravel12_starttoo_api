<?php

namespace App\Http\Controllers;

use App\Services\GroupSnapshotService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class GroupSnapshotController extends Controller
{
    public function __construct(private GroupSnapshotService $snapshots) {}

    public function show(Request $request)
    {
        if ($request->all() !== []) {
            throw ValidationException::withMessages(['request' => ['허용되지 않은 입력 항목이 있습니다.']]);
        }

        return response()->json(['data' => $this->snapshots->show($request->user(), (string) $request->route('group'))]);
    }
}
