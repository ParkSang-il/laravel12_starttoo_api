<?php

namespace App\Http\Controllers;

use App\Services\Starttoo\Artworks;
use App\Services\Starttoo\AuthSessions;
use App\Services\Starttoo\Fittings;
use Illuminate\Http\Request;

/** 가상 타투 피팅(FIT-01~08). 가입을 마친 회원만 이용한다. */
class StarttooFitController extends Controller
{
    public function home(Request $r, Fittings $f, AuthSessions $s, Artworks $a) { return $this->json($f->home($this->viewer($r, $s, $a))); }

    public function models(Request $r, Fittings $f, AuthSessions $s, Artworks $a)
    {
        $this->viewer($r, $s, $a);
        return $this->json($f->models($r->query()));
    }

    public function modelSkin(Request $r, int $model, Fittings $f, AuthSessions $s, Artworks $a)
    {
        // retry=1: 저장된 마스크가 틀렸다 → 꼼꼼한 방식으로 다시 만든다
        return $this->json($f->skinForModel($this->viewer($r, $s, $a), $model, $r->query('retry') === '1'));
    }

    /** multipart: photo(내 사진) → 피부 마스크. retry=1이면 꼼꼼한 방식 */
    public function skin(Request $r, Fittings $f, AuthSessions $s, Artworks $a)
    {
        return $this->json($f->skinForPhoto($this->viewer($r, $s, $a), $r->file('photo'), filter_var($r->input('retry', false), FILTER_VALIDATE_BOOLEAN)));
    }

    public function stencils(Request $r, Fittings $f, AuthSessions $s, Artworks $a) { return $this->json($f->stencils($this->viewer($r, $s, $a), $r->query())); }
    public function list(Request $r, Fittings $f, AuthSessions $s, Artworks $a) { return $this->json($f->list($this->viewer($r, $s, $a), $r->query())); }
    public function show(Request $r, string $result, Fittings $f, AuthSessions $s, Artworks $a) { return $this->json($f->show($this->viewer($r, $s, $a), $result)); }
    public function delete(Request $r, string $result, Fittings $f, AuthSessions $s, Artworks $a) { return $this->json($f->delete($this->viewer($r, $s, $a), $result)); }

    /** multipart: payload(JSON) + composite · photo · stencil 파일 */
    public function create(Request $r, Fittings $f, AuthSessions $s, Artworks $a)
    {
        $viewer = $this->viewer($r, $s, $a);
        $payload = $r->input('payload');
        try {
            $input = is_string($payload) ? json_decode($payload, true, 512, JSON_THROW_ON_ERROR) : [];
        } catch (\JsonException $e) {
            $input = null;
        }
        if (!is_array($input)) return response()->json(['code' => 'INVALID_FIT', 'message' => '피팅 요청을 확인해주세요.'], 422);
        $files = [];
        foreach (['composite', 'photo', 'stencil', 'mask', 'result', 'skin'] as $k) $files[$k] = $r->file($k);
        return $this->json($f->create($viewer, $input, $files));
    }

    private function viewer(Request $request, AuthSessions $sessions, Artworks $artworks): int
    {
        return (int) $artworks->ready((string) $request->bearerToken(), $sessions)['account']['id'];
    }

    private function json(array $data)
    {
        return response()->json(['data' => $data])->header('Cache-Control', 'no-store');
    }
}
