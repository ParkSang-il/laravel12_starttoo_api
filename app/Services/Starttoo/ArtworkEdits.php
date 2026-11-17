<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ArtworkEdits
{
    public function save(string $id, string $token, array $input, array $files, AuthSessions $sessions, Artworks $artworks): array
    {
        $identity = $artworks->ready($token, $sessions);
        $userId = $identity['account']['id'];
        $db = app(ProjectDatabase::class)->database();
        $row = $db->table('starttoo_artworks')->where('id', $id)->first();
        if (!$row || (int) $row->user_id !== (int) $userId) $this->fail(403, 'ARTWORK_NOT_OWNER', '작성자만 작품을 편집할 수 있어요.');
        $options = $artworks->options();
        $input['title'] = is_string($input['title'] ?? null) ? trim($input['title']) : $input['title'] ?? null;
        $validator = Validator::make($input, [
            'request_key' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:1'],
            'kind' => ['required', Rule::in(['work', 'design'])], 'title' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'], 'size_text' => ['nullable', 'string', 'max:50'],
            'styles' => ['required', 'array', 'min:1', 'max:5'],
            'styles.*' => ['required', 'string', 'distinct', Rule::in(array_column($options['styles'], 'id'))],
            'body_part' => ['nullable', Rule::in(array_column($options['body_parts'], 'id'))],
            'color' => ['nullable', Rule::in(array_column($options['colors'], 'id'))],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:10080'],
            'price' => ['required_if:kind,design', 'nullable', 'integer', 'min:0', 'max:100000000'],
            'show_in_shop' => ['required', 'boolean'], 'visibility' => ['required', Rule::in(['public', 'hidden'])],
            'photo_count' => ['required', 'integer', 'min:0', 'max:10'],
            'image_order' => ['required', 'array', 'min:1', 'max:10'],
        ]);
        if ($validator->fails() || !array_is_list($input['styles'] ?? []) || !array_is_list($input['image_order'] ?? [])) {
            $this->fail(422, 'INVALID_ARTWORK', '작품 입력 정보를 확인해주세요.');
        }
        if (Validator::make(['photos' => $files], ['photos' => ['array', 'max:10'],
            'photos.*' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=4096,max_height=4096']])->fails()
            || !array_is_list($files) || count($files) !== (int) $input['photo_count']) {
            $this->fail(422, 'INVALID_ARTWORK_PHOTOS', '작품 사진을 확인해주세요.');
        }
        $input = $validator->validated();
        $hash = hash('sha256', json_encode([$id, $input, array_map(fn ($file) => hash_file('sha256', $file->getRealPath()), $files)], JSON_THROW_ON_ERROR));
        $findRetry = fn () => $db->table('starttoo_artwork_edits')->where('user_id', $userId)->where('request_key', $input['request_key'])->first();
        $existing = $findRetry();
        if ($existing) {
            if ($existing->payload_hash !== $hash) $this->fail(409, 'ARTWORK_REQUEST_CONFLICT', '편집 요청이 변경됐어요.');
            return $artworks->detail($id, $userId);
        }
        $oldImages = $db->table('starttoo_artwork_images')->where('artwork_id', $id)->get()->keyBy('id');
        $used = [];
        $newIndexes = [];
        foreach ($input['image_order'] as $entry) {
            if (!is_array($entry) || count($entry) !== 1) $this->fail(422, 'INVALID_ARTWORK_PHOTOS', '사진 순서를 확인해주세요.');
            if (isset($entry['existing_id']) && is_int($entry['existing_id']) && $oldImages->has($entry['existing_id'])) {
                $key = 'old:'.$entry['existing_id'];
            } elseif (isset($entry['new_index']) && is_int($entry['new_index']) && $entry['new_index'] >= 0 && $entry['new_index'] < count($files)) {
                $key = 'new:'.$entry['new_index']; $newIndexes[] = $entry['new_index'];
            } else $this->fail(422, 'INVALID_ARTWORK_PHOTOS', '사진 순서를 확인해주세요.');
            if (isset($used[$key])) $this->fail(422, 'INVALID_ARTWORK_PHOTOS', '같은 사진을 중복 등록할 수 없어요.');
            $used[$key] = true;
        }
        if (count($newIndexes) !== count($files)) $this->fail(422, 'INVALID_ARTWORK_PHOTOS', '사진 수를 확인해주세요.');
        $paths = []; $removed = []; $keep = false;
        $photos = app(ArtworkPhotos::class);
        try {
            foreach ($files as $file) $paths[] = $photos->store($userId, $file);
            $keep = $db->transaction(function () use ($db, $id, $userId, $token, $sessions, $artworks, $input, $hash, $findRetry, $paths, &$removed) {
                $db->table('starttoo_users')->where('id', $userId)->lockForUpdate()->first();
                $row = $db->table('starttoo_artworks')->where('id', $id)->lockForUpdate()->first();
                $artworks->ready($token, $sessions);
                if (!$row || (int) $row->user_id !== (int) $userId) $this->fail(403, 'ARTWORK_NOT_OWNER', '작성자만 작품을 편집할 수 있어요.');
                if ($existing = $findRetry()) {
                    if ($existing->payload_hash !== $hash) $this->fail(409, 'ARTWORK_REQUEST_CONFLICT', '편집 요청이 변경됐어요.');
                    return false;
                }
                if ((int) $row->version !== (int) $input['version']) $this->fail(409, 'ARTWORK_EDIT_CONFLICT', '다른 곳에서 작품이 변경됐어요. 다시 불러온 뒤 편집해주세요.');
                $images = $db->table('starttoo_artwork_images')->where('artwork_id', $id)->get()->keyBy('id');
                foreach ($input['image_order'] as $entry) {
                    if (isset($entry['existing_id']) && !$images->has($entry['existing_id'])) $this->fail(409, 'ARTWORK_EDIT_CONFLICT', '사진이 변경됐어요. 다시 불러와주세요.');
                }
                $retained = array_values(array_filter(array_column($input['image_order'], 'existing_id')));
                $removed = $images->filter(fn ($image) => !in_array((int) $image->id, $retained, true))->pluck('path')->all();
                $db->table('starttoo_artwork_images')->where('artwork_id', $id)->increment('position', 20);
                $db->table('starttoo_artwork_images')->where('artwork_id', $id)->whereNotIn('id', $retained)->delete();
                foreach ($input['image_order'] as $position => $entry) {
                    if (isset($entry['existing_id'])) $db->table('starttoo_artwork_images')->where('id', $entry['existing_id'])->update(['position' => $position]);
                    else $db->table('starttoo_artwork_images')->insert(['artwork_id' => $id, 'position' => $position, 'path' => $paths[$entry['new_index']]]);
                }
                $db->table('starttoo_artworks')->where('id', $id)->update([
                    'kind' => $input['kind'], 'title' => $input['title'], 'description' => $input['description'] ?? '', 'size_text' => $input['size_text'] ?? null,
                    'tags' => json_encode(ArtworkHashtags::extract($input['description'] ?? ''), JSON_THROW_ON_ERROR),
                    'styles' => json_encode($input['styles'], JSON_THROW_ON_ERROR), 'body_part' => $input['body_part'] ?? null, 'color' => $input['color'] ?? null,
                    'duration_minutes' => $input['kind'] === 'work' ? ($input['duration_minutes'] ?? null) : null,
                    'price' => $input['kind'] === 'design' ? $input['price'] : null, 'show_in_shop' => $input['show_in_shop'],
                    'visibility' => $input['visibility'], 'version' => $row->version + 1, 'updated_at' => now(),
                ]);
                $db->table('starttoo_artwork_edits')->insert(['artwork_id' => $id, 'user_id' => $userId, 'request_key' => $input['request_key'], 'payload_hash' => $hash]);
                return true;
            }, 3);
            if ($keep) $photos->delete($removed);
            return $artworks->detail($id, $userId);
        } finally { if (!$keep) $photos->delete($paths); }
    }

    private function fail(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(compact('code', 'message'), $status));
    }
}
