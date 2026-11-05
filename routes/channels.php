<?php

use App\Models\ProjectAccount;
use App\Services\CareGroupService;
use App\Services\ProjectDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Str;

Broadcast::channel(
    'project.{projectCode}.group.{groupId}',
    fn (ProjectAccount $account, string $projectCode, string $groupId): bool =>
        $projectCode === app(ProjectDatabase::class)->code()
        && Str::isUuid($groupId)
        && app(CareGroupService::class)->canSubscribe($account, $groupId),
    ['guards' => ['api']],
);
Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});
