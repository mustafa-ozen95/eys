<?php

declare(strict_types=1);

namespace App\Services\Settings;

use InvalidArgumentException;

/**
 * config/settings.php dosyasının tek okuma noktası.
 *
 * Ayarın var olup olmadığı, hangi gruba ait olduğu ve nasıl doğrulanacağı
 * her yerde buradan sorulur; config() çağrısı controller ve view içine
 * dağılmaz.
 */
class SettingsCatalog
{
    /** @return array<string, array<string, mixed>> */
    public function groups(): array
    {
        return config('settings.groups', []);
    }

    /** @return array<int, string> */
    public function groupKeys(): array
    {
        return array_keys($this->groups());
    }

    /** @return array<string, mixed> */
    public function group(string $key): array
    {
        $group = $this->groups()[$key] ?? null;

        if ($group === null) {
            throw new InvalidArgumentException("Tanımsız ayar grubu: {$key}");
        }

        return $group;
    }

    /**
     * Bir grubun ayar tanımları.
     *
     * @return array<int, SettingDefinition>
     */
    public function definitionsFor(string $group): array
    {
        return collect($this->group($group)['settings'] ?? [])
            ->map(fn (array $definition, string $key): SettingDefinition => SettingDefinition::fromArray($key, $definition))
            ->values()
            ->all();
    }

    /** @return array<string, SettingDefinition> */
    public function all(): array
    {
        $definitions = [];

        foreach ($this->groups() as $group) {
            foreach ($group['settings'] ?? [] as $key => $definition) {
                $definitions[$key] = SettingDefinition::fromArray($key, $definition);
            }
        }

        return $definitions;
    }

    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    public function definition(string $key): ?SettingDefinition
    {
        return $this->all()[$key] ?? null;
    }
}
