<?php

declare(strict_types=1);

namespace App\Services\Settings;

/**
 * config/settings.php içindeki tek bir ayar tanımı.
 *
 * Ekranın alan türü, doğrulama kuralı ve tip çevrimi hep buradan okunur;
 * üç yerde ayrı ayrı tanımlanıp birbirinden ayrışmaz.
 */
readonly class SettingDefinition
{
    /** @param  list<string>  $rules */
    private function __construct(
        public string $key,
        public string $label,
        public ?string $hint,
        public string $type,
        public ?string $unit,
        public bool $mono,
        public array $rules,
        /** @var array<string,string> Yalnızca 'select' için. */
        public array $options = [],
    ) {}

    /** @param  array<string, mixed>  $definition */
    public static function fromArray(string $key, array $definition): self
    {
        return new self(
            key: $key,
            label: (string) $definition['label'],
            hint: $definition['hint'] ?? null,
            type: (string) ($definition['type'] ?? 'string'),
            unit: $definition['unit'] ?? null,
            mono: (bool) ($definition['mono'] ?? false),
            rules: $definition['rules'] ?? [],
            options: $definition['options'] ?? [],
        );
    }

    /**
     * Form alanı adı.
     *
     * Nokta HTML'de dizi indeksi gibi ayrıştırıldığı için alt çizgiye
     * çevrilir; geri çevrim istek sınıfında yapılır.
     */
    public function field(): string
    {
        return str_replace('.', '__', $this->key);
    }

    /** Ekrandaki değeri metne çevirir. */
    public function display(mixed $value): string
    {
        return match ($this->type) {
            'bool' => $value ? '1' : '0',
            default => (string) $value,
        };
    }

    /**
     * Seçilebilir değerler.
     *
     * Kapalı liste olduğu için doğrulama da buradan beslenir: serbest metin
     * kabul edilseydi yazım hatası ayarı sessizce geçersiz kılardı.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        return $this->options;
    }

    /** Form değerini saklanacak tipe çevirir. */
    public function cast(mixed $value): mixed
    {
        return match ($this->type) {
            'bool' => (bool) $value,
            'int' => (int) $value,
            default => is_string($value) ? trim($value) : $value,
        };
    }
}
