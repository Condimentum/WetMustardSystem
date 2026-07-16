<?php

namespace App\Support;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Schema;

class FeatureSettings
{
    private static ?bool $settingsTableExists = null;

    public static function enabled(string $key, bool $default = false): bool
    {
        $value = static::get($key);

        if ($value !== null) {
            return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
        }

        return (bool) data_get(config('dbmts.features', []), $key, $default);
    }

    public static function get(string $key): ?string
    {
        if (! static::hasSettingsTable()) {
            return null;
        }

        try {
            return AppSetting::query()
                ->where('key', $key)
                ->value('value');
        } catch (\Throwable) {
            return null;
        }
    }

    public static function set(string $key, bool $value, ?int $updatedBy = null, ?string $description = null): void
    {
        if (! static::hasSettingsTable()) {
            return;
        }

        AppSetting::query()->updateOrCreate(
            ['key' => $key],
            [
                'value' => $value ? 'true' : 'false',
                'value_type' => 'boolean',
                'description' => $description,
                'updated_by' => $updatedBy,
            ]
        );
    }

    public static function clear(string $key): void
    {
        if (! static::hasSettingsTable()) {
            return;
        }

        AppSetting::query()->where('key', $key)->delete();
    }

    private static function hasSettingsTable(): bool
    {
        if (static::$settingsTableExists !== null) {
            return static::$settingsTableExists;
        }

        try {
            static::$settingsTableExists = Schema::hasTable('app_settings');
        } catch (\Throwable) {
            static::$settingsTableExists = false;
        }

        return static::$settingsTableExists;
    }
}
