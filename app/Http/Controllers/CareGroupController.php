<?php

namespace App\Http\Controllers;

use App\Services\CareGroupService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CareGroupController extends Controller
{
    public function __construct(private CareGroupService $groups) {}

    private function input(Request $request, array $rules): array
    {
        $unknown = array_diff(array_keys($request->all()), array_keys($rules));
        if ($unknown) {
            throw ValidationException::withMessages(['request' => ['허용되지 않은 입력 항목이 있습니다.']]);
        }

        return $request->validate($rules);
    }

    private function groupId(Request $request): string
    {
        return (string) $request->route('group');
    }

    private function discoveryCenter(array $data): array
    {
        if (array_key_exists('discovery_latitude', $data) !== array_key_exists('discovery_longitude', $data)
            || (($data['discovery_latitude'] ?? null) === null) !== (($data['discovery_longitude'] ?? null) === null)) {
            throw ValidationException::withMessages(['discovery_latitude' => ['활동 지역의 기준 위치는 두 좌표를 함께 지정해 주세요.']]);
        }
        foreach (['discovery_latitude', 'discovery_longitude'] as $field) {
            if (isset($data[$field])) $data[$field] = round((float) $data[$field], 2);
        }

        return $data;
    }

    public function create(Request $request)
    {
        $data = $this->input($request, [
            'name' => ['required', 'string', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'region_code' => ['required', 'string', 'regex:/^[0-9]{10}$/D'],
            'region_label' => ['required', 'string', 'max:80'],
            'is_discoverable' => ['sometimes', 'boolean'],
            'accepting_requests' => ['sometimes', 'boolean'],
            'discovery_latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'discovery_longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
        ]);

        $data['description'] = $data['description'] ?? '';

        return response()->json(['data' => $this->groups->create($request->user(), $this->discoveryCenter($data))], 201);
    }

    public function discover(Request $request)
    {
        $data = $this->input($request, [
            'region_code' => ['required', 'string', 'regex:/^[0-9]{10}$/D'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ]);

        return response()->json(['data' => $this->groups->discover($request->user(),
            $data['region_code'], (int) ($data['page'] ?? 1))]);
    }

    public function mine(Request $request)
    {
        return response()->json(['data' => $this->groups->mine($request->user())]);
    }

    public function nearby(Request $request)
    {
        $data = $this->input($request, [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'radius_m' => ['required', 'integer', 'min:500', 'max:2000'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ]);

        return response()->json(['data' => $this->groups->nearby($request->user(),
            (float) $data['latitude'], (float) $data['longitude'], (int) $data['radius_m'], (int) ($data['page'] ?? 1))]);
    }

    public function show(Request $request)
    {
        return response()->json(['data' => $this->groups->detail($request->user(), $this->groupId($request))]);
    }

    public function update(Request $request)
    {
        $data = $this->input($request, [
            'name' => ['sometimes', 'required', 'string', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_discoverable' => ['sometimes', 'boolean'],
            'accepting_requests' => ['sometimes', 'boolean'],
            'discovery_latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'discovery_longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
        ]);

        if (array_key_exists('description', $data)) {
            $data['description'] = $data['description'] ?? '';
        }

        return response()->json(['data' => $this->groups->update($request->user(), $this->groupId($request), $this->discoveryCenter($data))]);
    }

    public function createInvitation(Request $request)
    {
        $data = $this->input($request, [
            'expires_in_hours' => ['sometimes', 'integer', 'min:1', 'max:168'],
            'max_uses' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'phone' => ['sometimes', 'string', 'regex:/^010[0-9]{8}$/D'],
        ]);

        return response()->json(['data' => $this->groups->createInvitation($request->user(), $this->groupId($request), $data)], 201);
    }

    public function previewInvitation(Request $request)
    {
        $data = $this->input($request, ['token' => ['required', 'string', 'size:64', 'alpha_num:ascii']]);

        return response()->json(['data' => $this->groups->preview($request->user(), $data['token'])]);
    }

    public function revokeInvitation(Request $request)
    {
        $this->groups->revokeInvitation($request->user(), $this->groupId($request),
            (string) $request->route('invitation'));

        return response()->noContent();
    }

    public function apply(Request $request)
    {
        $data = $this->input($request, [
            'message' => ['required', 'string', 'max:500'],
            'availability' => ['sometimes', 'nullable', 'string', 'max:200'],
            'experience' => ['sometimes', 'nullable', 'string', 'max:300'],
            'invitation_token' => ['sometimes', 'string', 'size:64', 'alpha_num:ascii'],
        ]);

        // 200 also covers idempotent retries of an existing pending application.
        return response()->json(['data' => $this->groups->apply($request->user(), $this->groupId($request), $data)]);
    }

    public function myRequests(Request $request)
    {
        return response()->json(['data' => $this->groups->myRequests($request->user())]);
    }

    public function requests(Request $request)
    {
        return response()->json(['data' => $this->groups->requests($request->user(), $this->groupId($request))]);
    }

    public function review(Request $request)
    {
        $data = $this->input($request, [
            'version' => ['required', 'integer', 'min:1'],
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'role' => ['required_if:decision,approve', 'prohibited_if:decision,reject', Rule::in(['caregiver', 'viewer'])],
            'note' => ['sometimes', 'nullable', 'string', 'max:300'],
        ]);
        $data['version'] = (int) $data['version'];

        return response()->json(['data' => $this->groups->review($request->user(), $this->groupId($request),
            (int) $request->route('joinRequest'), $data)]);
    }

    public function withdraw(Request $request)
    {
        $data = $this->input($request, ['version' => ['required', 'integer', 'min:1']]);
        $this->groups->withdraw($request->user(), $this->groupId($request),
            (int) $request->route('joinRequest'), (int) $data['version']);

        return response()->noContent();
    }

    public function members(Request $request)
    {
        return response()->json(['data' => $this->groups->members($request->user(), $this->groupId($request))]);
    }

    public function leave(Request $request)
    {
        $this->groups->leave($request->user(), $this->groupId($request));

        return response()->noContent();
    }

    public function removeMember(Request $request)
    {
        $this->groups->leave($request->user(), $this->groupId($request), (int) $request->route('account'));

        return response()->noContent();
    }
}
