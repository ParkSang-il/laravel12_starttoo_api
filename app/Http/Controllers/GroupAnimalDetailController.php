<?php

namespace App\Http\Controllers;

use App\Services\GroupAnimalDetailService;
use Illuminate\Http\Request;

class GroupAnimalDetailController extends Controller
{
    public function show(Request $request, GroupAnimalDetailService $service)
    {
        $data = $request->validate(['cursor' => ['sometimes', 'string', 'max:1024']]);
        return response()->json(['data' => $service->show($request->user(), (string) $request->route('group'),
            (string) $request->route('animal'), $data['cursor'] ?? null)]);
    }
}
