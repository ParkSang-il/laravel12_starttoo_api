<?php

namespace App\Http\Controllers;

use App\Services\GroupAnimalService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GroupAnimalController extends Controller
{
    public function __construct(private GroupAnimalService $animals) {}

    private function input(Request $request, array $rules): array
    {
        if (array_diff(array_keys($request->all()), array_keys($rules))) {
            throw ValidationException::withMessages(['request' => ['허용되지 않은 입력 항목이 있습니다.']]);
        }
        return $request->validate($rules);
    }
    private function ids(Request $request): array
    {
        return [$request->user(), (string) $request->route('group'), (string) $request->route('place')];
    }
    private function fields(Request $request, bool $edit, bool $import = false): array
    {
        $rules = ['name' => [$edit ? 'sometimes' : 'required', 'required', 'string', 'max:80'],
            'coat' => ['sometimes', 'nullable', 'string', 'max:80'],
            'age' => ['sometimes', 'nullable', 'string', 'max:80'],
            'sex' => ['sometimes', Rule::in(['unknown', 'male', 'female'])],
            'neutered' => ['sometimes', 'boolean'],
            'life_status' => ['sometimes', Rule::in(['active', 'missing', 'rescued', 'moved', 'passedAway'])],
            'note' => ['sometimes', 'nullable', 'string', 'max:500']];
        if ($import) $rules['source_local_id'] = ['required', 'string', 'max:80'];
        if ($edit) $rules['version'] = ['required', 'integer', 'min:1'];
        $data = $this->input($request, $rules);
        foreach (['coat', 'age', 'note'] as $field) {
            if (array_key_exists($field, $data)) $data[$field] ??= '';
        }
        return $data;
    }
    public function index(Request $request)
    {
        $data = $this->input($request, ['page' => ['sometimes', 'integer', 'min:1', 'max:1000']]);
        return response()->json(['data' => $this->animals->index(...[...$this->ids($request), (int) ($data['page'] ?? 1)])]);
    }
    public function available(Request $request)
    {
        $data = $this->input($request, ['page' => ['sometimes', 'integer', 'min:1', 'max:1000']]);
        return response()->json(['data' => $this->animals->available(...[...$this->ids($request), (int) ($data['page'] ?? 1)])]);
    }
    public function create(Request $request)
    {
        return response()->json(['data' => $this->animals->create(...[...$this->ids($request), $this->fields($request, false)])], 201);
    }
    public function importLocal(Request $request)
    {
        $data = $this->fields($request, false, true);
        return response()->json(['data' => $this->animals->importLocal(...[...$this->ids($request), $data])]);
    }
    public function update(Request $request)
    {
        return response()->json(['data' => $this->animals->update(...[...$this->ids($request), (string) $request->route('animal'), $this->fields($request, true)])]);
    }
    public function link(Request $request)
    {
        $data = $this->input($request, ['animal_id' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:1']]);
        return response()->json(['data' => $this->animals->link(...[...$this->ids($request), $data['animal_id'], (int) $data['version']])]);
    }
    public function unlink(Request $request)
    {
        $data = $this->input($request, ['version' => ['required', 'integer', 'min:1']]);
        $this->animals->unlink(...[...$this->ids($request), (string) $request->route('animal'), (int) $data['version']]);
        return response()->noContent();
    }
}
