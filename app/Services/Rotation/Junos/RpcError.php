<?php

declare(strict_types=1);

namespace App\Services\Rotation\Junos;

use SimpleXMLElement;

/**
 * Cihazın döndürdüğü TEK bir rpc-error.
 *
 * Yalnızca döndürmenin ihtiyacı olan alanlar tutulur: önem derecesi (uyarı mı
 * hata mı), mesaj ve yer. Alanlar ad alanından bağımsız okunur.
 */
final class RpcError
{
    public function __construct(
        public readonly string $message,
        public readonly string $severity = 'error',
        public readonly ?string $path = null,
    ) {}

    public static function fromXml(SimpleXMLElement $node): self
    {
        return new self(
            message: self::text($node, 'error-message') ?? 'bilinmeyen hata',
            severity: mb_strtolower(self::text($node, 'error-severity') ?? 'error'),
            path: self::text($node, 'error-path'),
        );
    }

    public function isWarning(): bool
    {
        return $this->severity === 'warning';
    }

    public function line(): string
    {
        $yer = $this->path !== null ? ' ('.trim(preg_replace('/\s+/', ' ', $this->path) ?? '').')' : '';

        return $this->message.$yer;
    }

    private static function text(SimpleXMLElement $node, string $element): ?string
    {
        $value = trim((string) ($node->xpath('.//*[local-name()="'.$element.'"]')[0] ?? ''));

        return $value === '' ? null : $value;
    }
}
