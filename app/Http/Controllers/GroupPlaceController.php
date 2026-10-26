<?php

namespace App\Http\Controllers;

use App\Services\GroupPlaceService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GroupPlaceController extends Controller
{
    public function __construct(private GroupPlaceService $places) {}

    private function input(Request $request, array $rules): array
    {
        if (array_diff(array_keys($request->all()), array_keys($rules))) {
            throw ValidationException::withMessages(['request' => ['허용되지 않은 입력 항목이 있습니다.']]);
        }

        return $request->validate($rules);
    }

    private function group(Request $request): string { return (string) $request->route('group'); }
    private function place(Request $request): string { return (string) $request->route('place'); }

    private function fields(Request $request, bool $editing): array
    {
        $rules = ['name' => [$editing ? 'sometimes' : 'required', 'required', 'string', 'max:80'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'interval_hours' => ['sometimes', 'integer', 'min:1', 'max:2160']];
        if ($editing) $rules['version'] = ['required', 'integer', 'min:1'];
        $data = $this->input($request, $rules);
        if (array_key_exists('latitude', $data) !== array_key_exists('longitude', $data)
            || (($data['latitude'] ?? null) === null) !== (($data['longitude'] ?? null) === null)) {
            throw ValidationException::withMessages(['latitude' => ['위도와 경도는 함께 지정하거나 함께 비워 주세요.']]);
        }
        if (array_key_exists('note', $data)) $data['note'] = $data['note'] ?? '';

        return $data;
    }

    public function index(Request $request)
    {
        $data = $this->input($request, ['page' => ['sometimes', 'integer', 'min:1', 'max:1000']]);

        return response()->json(['data' => $this->places->index($request->user(), $this->group($request), (int) ($data['page'] ?? 1))]);
    }

    public function show(Request $request)
    {
        return response()->json(['data' => $this->places->show($request->user(), $this->group($request), $this->place($request))]);
    }

    public function create(Request $request)
    {
        return response()->json(['data' => $this->places->create($request->user(), $this->group($request), $this->fields($request, false))], 201);
    }

    public function update(Request $request)
    {
        return response()->json(['data' => $this->places->update($request->user(), $this->group($request), $this->place($request), $this->fields($request, true))]);
    }

    public function archive(Request $request)
    {
        $data = $this->input($request, ['version' => ['required', 'integer', 'min:1']]);
        $this->places->archive($request->user(), $this->group($request), $this->place($request), (int) $data['version']);

        return response()->noContent();
    }

    public function permissions(Request $request)
    {
        $data = $this->input($request, ['page' => ['sometimes', 'integer', 'min:1', 'max:1000']]);

        return response()->json(['data' => $this->places->permissions($request->user(), $this->group($request), $this->place($request), (int) ($data['page'] ?? 1))]);
    }

    public function grant(Request $request)
    {
        $data = $this->input($request, ['version' => ['required', 'integer', 'min:1'],
            'access' => ['required', Rule::in(['none', 'view', 'care'])]]);

        return response()->json(['data' => $this->places->grant($request->user(), $this->group($request),
            $this->place($request), (int) $request->route('account'), $data['access'], (int) $data['version'])]);
    }
}
