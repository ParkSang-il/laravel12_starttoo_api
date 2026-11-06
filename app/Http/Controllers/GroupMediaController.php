<?php
namespace App\Http\Controllers;
use App\Services\GroupMediaService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
class GroupMediaController extends Controller {
    public function upload(Request $r, GroupMediaService $media) {
        $d = $r->validate(['upload_id' => ['required', 'uuid'], 'purpose' => ['required', Rule::in(['chat', 'animal', 'care_record'])],
            'place_id' => ['required_if:purpose,animal,care_record', 'nullable', 'uuid'],
            'photo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240', 'dimensions:max_width=4096,max_height=4096']]);
        if ($d['purpose'] === 'chat') $d['place_id'] = null;
        return response()->json(['data' => $media->upload($r->user(), $r->route('group'), $r->file('photo'), $d)], 201);
    }
}
