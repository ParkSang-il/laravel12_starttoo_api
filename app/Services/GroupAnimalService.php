<?php

namespace App\Services;

use App\Models\ProjectAccount;
use Closure;
use Illuminate\Support\Str;

class GroupAnimalService
{
    public function __construct(private ProjectDatabase $database, private GroupPlaceService $places) {}

    private function table(string $name) { return $this->database->database()->table($name); }

    private function locked(ProjectAccount $actor, string $group, string $place, Closure $action): mixed
    {
        return $this->database->database()->transaction(function () use ($actor, $group, $place, $action) {
            $this->table('care_groups')->where('project_id', $actor->project_id)->where('id', $group)->lockForUpdate()->firstOrFail();
            $context = $this->places->show($actor, $group, $place);
            abort_unless($context['can_manage'], 403, '친구 정보와 상태는 운영자만 변경할 수 있습니다.');

            return $action();
        }, 3);
    }

    private function summary(object $row): array
    {
        // No other place IDs, coordinates or private records are returned.
        return ['id' => $row->id, 'group_id' => $row->group_id, 'name' => $row->name,
            'coat' => $row->coat, 'age' => $row->age, 'sex' => $row->sex, 'neutered' => (bool) $row->neutered,
            'life_status' => $row->life_status, 'note' => $row->note, 'version' => (int) $row->version];
    }

    private function animal(ProjectAccount $actor, string $group, string $place, string $id): object
    {
        return $this->table('group_animals as a')->join('group_animal_places as link', 'link.animal_id', '=', 'a.id')
            ->where('a.project_id', $actor->project_id)->where('a.group_id', $group)->where('link.group_id', $group)
            ->where('link.place_id', $place)->where('a.id', $id)->select('a.*')->firstOrFail();
    }

    private function version(object $animal, int $version): void
    {
        abort_unless((int) $animal->version === $version, 409, '친구 정보나 장소 연결이 변경됐습니다. 새로 조회해 주세요.');
    }

    private function audit(ProjectAccount $actor, string $group, string $place, string $animal, string $event, array $extra = []): void
    {
        $this->table('group_audit_events')->insert(['project_id' => $actor->project_id, 'group_id' => $group,
            'actor_id' => $actor->id, 'event' => $event,
            'metadata' => json_encode(['place_id' => $place, 'animal_id' => $animal, ...$extra], JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    public function index(ProjectAccount $actor, string $group, string $place, int $page): array
    {
        $context = $this->places->show($actor, $group, $place);
        $rows = $this->table('group_animals as a')->join('group_animal_places as link', 'link.animal_id', '=', 'a.id')
            ->where('a.project_id', $actor->project_id)->where('a.group_id', $group)->where('link.group_id', $group)
            ->where('link.place_id', $place)->select('a.*')->orderBy('a.id')->offset(($page - 1) * 20)->limit(21)->get();

        return ['animals' => $rows->take(20)->map(fn ($row) => $this->summary($row))->all(),
            'has_more' => $rows->count() > 20, 'page' => $page, 'can_manage' => $context['can_manage']];
    }

    public function available(ProjectAccount $actor, string $group, string $place, int $page): array
    {
        $context = $this->places->show($actor, $group, $place);
        abort_unless($context['can_manage'], 403);
        $rows = $this->table('group_animals as a')->where('a.project_id', $actor->project_id)->where('a.group_id', $group)
            ->whereNotExists(function ($q) use ($place) {
                $q->selectRaw('1')->from('group_animal_places as link')->whereColumn('link.animal_id', 'a.id')->where('link.place_id', $place);
            })->orderBy('a.id')->offset(($page - 1) * 20)->limit(21)->get();

        return ['animals' => $rows->take(20)->map(fn ($row) => $this->summary($row))->all(),
            'has_more' => $rows->count() > 20, 'page' => $page, 'can_manage' => true];
    }

    public function create(ProjectAccount $actor, string $group, string $place, array $data): array
    {
        return $this->locked($actor, $group, $place, function () use ($actor, $group, $place, $data) {
            $id = (string) Str::uuid();
            $this->table('group_animals')->insert([...$data, 'id' => $id, 'project_id' => $actor->project_id,
                'group_id' => $group, 'created_by' => $actor->id, 'updated_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->table('group_animal_places')->insert(['group_id' => $group, 'animal_id' => $id, 'place_id' => $place, 'created_at' => now()]);
            $this->audit($actor, $group, $place, $id, 'animal_created');

            return $this->summary($this->animal($actor, $group, $place, $id));
        });
    }

    public function update(ProjectAccount $actor, string $group, string $place, string $id, array $data): array
    {
        return $this->locked($actor, $group, $place, function () use ($actor, $group, $place, $id, $data) {
            $animal = $this->animal($actor, $group, $place, $id);
            $this->version($animal, $data['version']);
            unset($data['version']);
            $this->table('group_animals')->where('id', $id)->update([...$data, 'version' => $animal->version + 1,
                'updated_by' => $actor->id, 'updated_at' => now()]);
            $this->audit($actor, $group, $place, $id, 'animal_updated', ['fields' => array_keys($data), 'version' => $animal->version + 1]);

            return $this->summary($this->animal($actor, $group, $place, $id));
        });
    }

    public function link(ProjectAccount $actor, string $group, string $place, string $id, int $version): array
    {
        return $this->locked($actor, $group, $place, function () use ($actor, $group, $place, $id, $version) {
            $animal = $this->table('group_animals')->where('project_id', $actor->project_id)->where('group_id', $group)->where('id', $id)->firstOrFail();
            $this->version($animal, $version);
            abort_if($this->table('group_animal_places')->where('animal_id', $id)->where('place_id', $place)->exists(), 409, '이미 연결된 친구입니다.');
            $this->table('group_animal_places')->insert(['group_id' => $group, 'animal_id' => $id, 'place_id' => $place, 'created_at' => now()]);
            $this->table('group_animals')->where('id', $id)->update(['version' => $animal->version + 1, 'updated_by' => $actor->id, 'updated_at' => now()]);
            $this->audit($actor, $group, $place, $id, 'animal_linked');

            return $this->summary($this->animal($actor, $group, $place, $id));
        });
    }

    public function unlink(ProjectAccount $actor, string $group, string $place, string $id, int $version): void
    {
        $this->locked($actor, $group, $place, function () use ($actor, $group, $place, $id, $version) {
            $animal = $this->animal($actor, $group, $place, $id);
            $this->version($animal, $version);
            $this->table('group_animal_places')->where('animal_id', $id)->where('place_id', $place)->delete();
            $this->table('group_animals')->where('id', $id)->update(['version' => $animal->version + 1, 'updated_by' => $actor->id, 'updated_at' => now()]);
            $this->audit($actor, $group, $place, $id, 'animal_unlinked');
        });
    }
}
