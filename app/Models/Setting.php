<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Editable site settings (terms, rates). Falls back to config('company.*') when nothing has been saved. */
#[Fillable(['key', 'value', 'updated_by'])]
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    /** Per-request cache kept on the container, so a fresh application (each test, each request) starts empty. */
    private static function cache(): array
    {
        return app()->bound('settings.cache') ? app('settings.cache') : [];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $cache = self::cache();
        if (! array_key_exists($key, $cache)) {
            $row = static::find($key);
            $cache[$key] = $row ? $row->value : null;
            app()->instance('settings.cache', $cache);
        }

        return $cache[$key] ?? config("company.{$key}", $default);
    }

    public static function put(string $key, mixed $value, ?int $userId = null): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $userId]);
        static::forget();
    }

    public static function forget(): void
    {
        app()->instance('settings.cache', []);
    }
}
