<?php

namespace App\Services;

use App\Enums\SettingType;
use App\Models\Setting;
use App\Support\BusinessContext;
use Illuminate\Support\Facades\Cache;

/**
 * Company-scoped settings engine with typed values.
 *
 * Values are stored as text and cast on read by `type`, so a boolean such as
 * `pos.allow_negative_stock` comes back as a real bool rather than "1".
 */
class SettingsService
{
    private const CACHE_TTL = 300;

    public function __construct(private BusinessContext $context) {}

    /**
     * @return array<string, mixed>
     */
    public function all(?int $companyId = null): array
    {
        $companyId ??= $this->context->companyId();

        return Cache::remember(
            $this->cacheKey($companyId),
            now()->addSeconds(self::CACHE_TTL),
            function () use ($companyId) {
                return Setting::query()
                    ->where('company_id', $companyId)
                    ->orWhereNull('company_id')
                    ->orderByRaw('company_id IS NULL DESC')
                    ->orderBy('group')
                    ->orderBy('key')
                    ->get()
                    ->mapWithKeys(fn (Setting $setting) => [
                        $setting->key => $this->castValue($setting->value, $setting->type),
                    ])
                    ->all();
            }
        );
    }

    public function get(string $key, mixed $default = null, ?int $companyId = null): mixed
    {
        $all = $this->all($companyId);

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public function set(string $key, mixed $value, ?int $companyId = null, ?string $group = null, ?string $type = null): Setting
    {
        $companyId ??= $this->context->companyId();
        $type ??= $this->inferType($value);

        $setting = Setting::updateOrCreate(
            ['company_id' => $companyId, 'key' => $key],
            [
                'value' => $this->serializeValue($value, $type),
                'type' => $type,
                'group' => $group ?? explode('.', $key)[0],
            ]
        );

        $this->flushCache($companyId);

        return $setting;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function setMany(array $values, ?int $companyId = null): void
    {
        $companyId ??= $this->context->companyId();

        foreach ($values as $key => $value) {
            $this->set($key, $value, $companyId);
        }
    }

    public function forget(string $key, ?int $companyId = null): void
    {
        Setting::query()
            ->where('key', $key)
            ->where('company_id', $companyId ?? $this->context->companyId())
            ->delete();

        $this->flushCache($companyId);
    }

    public function flushCache(?int $companyId = null): void
    {
        Cache::forget($this->cacheKey($companyId ?? $this->context->companyId()));
    }

    private function cacheKey(?int $companyId): string
    {
        return 'settings.'.($companyId ?? 'global');
    }

    private function castValue(?string $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match (SettingType::from($type)) {
            SettingType::Boolean => in_array($value, ['1', 'true', 'on', 'yes'], true),
            SettingType::Integer => (int) $value,
            SettingType::Decimal => (float) $value,
            SettingType::Json => json_decode($value, true),
            SettingType::String => $value,
        };
    }

    private function serializeValue(mixed $value, string $type): ?string
    {
        if ($value === null) {
            return null;
        }

        return match (SettingType::from($type)) {
            SettingType::Boolean => $value ? '1' : '0',
            SettingType::Integer => (string) ((int) $value),
            SettingType::Decimal => (string) ((float) $value),
            SettingType::Json => json_encode($value),
            SettingType::String => (string) $value,
        };
    }

    private function inferType(mixed $value): string
    {
        if (is_bool($value)) {
            return SettingType::Boolean->value;
        }

        if (is_int($value)) {
            return SettingType::Integer->value;
        }

        if (is_float($value)) {
            return SettingType::Decimal->value;
        }

        if (is_array($value)) {
            return SettingType::Json->value;
        }

        return SettingType::String->value;
    }
}
