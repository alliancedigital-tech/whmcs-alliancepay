<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

namespace AlliancePay\Subscription;

use WHMCS\Database\Capsule;

/**
 * Class Repository.
 */
abstract class Repository
{
    abstract protected static function table(): string;

    /**
     * @return array<string,
     */
    abstract protected static function casts(): array;

    /**
     * @param int $id
     * @return array|null
     */
    public static function find(int $id): ?array
    {
        return self::first(static::query()->where('id', $id));
    }

    /**
     * @param array $data
     * @return int
     */
    public static function insert(array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $data += ['created_at' => $now];

        return (int)Capsule::table(static::table())->insertGetId(self::encode($data));
    }

    /**
     * @param int $id
     * @param array $data
     * @return void
     */
    public static function update(int $id, array $data): void
    {
        if (static::hasUpdatedAt()) {
            $data += ['updated_at' => date('Y-m-d H:i:s')];
        }

        static::query()->where('id', $id)->update(self::encode($data));
    }

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    protected static function query()
    {
        return Capsule::table(static::table());
    }

    /**
     * @return bool
     */
    protected static function hasUpdatedAt(): bool
    {
        return true;
    }

    /**
     * @param \Illuminate\Database\Query\Builder $query
     * @return array|null
     */
    protected static function first($query): ?array
    {
        $row = $query->first();

        return $row ? self::cast((array)$row) : null;
    }

    /**
     * @param \Illuminate\Database\Query\Builder $query
     * @return array[]
     */
    protected static function all($query): array
    {
        return array_map(static fn ($row) => self::cast((array)$row), $query->get()->all());
    }

    /**
     * @param array $row
     * @return array
     */
    private static function cast(array $row): array
    {
        foreach (static::casts() as $column => $type) {
            if (!array_key_exists($column, $row) || $row[$column] === null) {
                continue;
            }
            $row[$column] = match ($type) {
                'int' => (int)$row[$column],
                'float' => (float)$row[$column],
                'bool' => (bool)$row[$column],
                'json' => json_decode((string)$row[$column], true) ?? [],
                default => (string)$row[$column],
            };
        }

        return $row;
    }

    /**
     * @param array $data
     * @return array
     */
    private static function encode(array $data): array
    {
        foreach (static::casts() as $column => $type) {
            if ($type === 'json' && array_key_exists($column, $data) && !is_string($data[$column])) {
                $data[$column] = json_encode($data[$column], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        return $data;
    }
}
