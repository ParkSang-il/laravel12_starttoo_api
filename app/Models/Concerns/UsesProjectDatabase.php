<?php

namespace App\Models\Concerns;

use App\Services\ProjectDatabase;

trait UsesProjectDatabase
{
    public function getConnectionName()
    {
        return $this->connection ?? app(ProjectDatabase::class)->connectionName();
    }
}
