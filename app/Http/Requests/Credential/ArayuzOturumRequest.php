<?php

declare(strict_types=1);

namespace App\Http\Requests\Credential;

/**
 * Cihaz arayüzünü vekilden açma isteği.
 *
 * PortalSessionRequest'ten TEK farkı yetenek adı — ama fark önemli: portal
 * fırlatma parolayı tarayıcıdan gönderiyor, vekil göndermiyor. Gerekçe
 * CredentialPolicy::arayuz'da uzun uzun yazılı.
 *
 * Gerekçe alanı ve kuralları aynı kalıyor: her ikisi de dış dünyaya etki
 * eden, kayda geçen işlemler.
 */
class ArayuzOturumRequest extends PortalSessionRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('arayuz', $this->route('credential'));
    }
}
