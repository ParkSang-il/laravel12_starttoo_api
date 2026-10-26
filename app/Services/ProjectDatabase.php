<?php

namespace App\Services;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use LogicException;

class ProjectDatabase
{
    private ?string $code = null;
    private ?string $connection = null;

    public function configuredConnection(string $code): string
    {
        $projects = config('platform.projects', []);
        abort_unless(isset($projects[$code]), 404, '등록되지 않은 프로젝트입니다.');
        $name = $projects[$code]['connection'] ?? null;
        $settings = is_string($name) ? config('database.connections.'.$name) : null;
        if (!$settings || empty($settings['database']) || !empty($settings['url'])) {
            throw new LogicException('프로젝트 전용 DB 연결을 명시적으로 설정해야 합니다.');
        }
        // Distinct project codes must never accidentally share a connection/database.
        foreach ($projects as $otherCode => $other) {
            if ($otherCode === $code) {
                continue;
            }
            $otherName = $other['connection'] ?? null;
            $otherSettings = $otherName ? config('database.connections.'.$otherName, []) : [];
            $sameDatabase = $settings['driver'] === ($otherSettings['driver'] ?? null)
                && $settings['database'] === ($otherSettings['database'] ?? null)
                && ($settings['host'] ?? null) === ($otherSettings['host'] ?? null)
                && ($settings['port'] ?? null) === ($otherSettings['port'] ?? null)
                && ($settings['unix_socket'] ?? null) === ($otherSettings['unix_socket'] ?? null);
            // Separate SQLite :memory: connections are isolated and used only in tests.
            if ($name === $otherName || ($sameDatabase
                && !($settings['driver'] === 'sqlite' && $settings['database'] === ':memory:'))) {
                throw new LogicException('서로 다른 프로젝트가 같은 DB를 사용할 수 없습니다.');
            }
        }

        return $name;
    }

    public function run(string $code, Closure $callback): mixed
    {
        $connection = $this->configuredConnection($code);
        $previous = [$this->code, $this->connection];
        $this->code = $code;
        $this->connection = $connection;
        try {
            return $callback();
        } finally {
            [$this->code, $this->connection] = $previous;
        }
    }

    public function connectionName(): string
    {
        return $this->connection ?? throw new LogicException('프로젝트 DB 문맥이 없습니다.');
    }

    public function code(): string
    {
        return $this->code ?? throw new LogicException('프로젝트 DB 문맥이 없습니다.');
    }

    public function database(): Connection
    {
        return DB::connection($this->connectionName());
    }
}
