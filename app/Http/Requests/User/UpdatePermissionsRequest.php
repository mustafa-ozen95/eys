<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Models\CredentialGroup;
use App\Services\Authorization\PermissionRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Yetki matrisinin kaydedilmesi.
 *
 * Gelen aksiyon ve kayıt kimlikleri SUNUCUDA doğrulanır: form tarayıcıda
 * kurulur, oradan gelen her ad kabul edilseydi tanımsız bir aksiyon adı
 * veritabanına yazılır ve hiçbir zaman eşleşmeyen bir yetki satırı oluşurdu.
 */
class UpdatePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('user'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $registry = app(PermissionRegistry::class);
        $resource = $this->string('resource')->value();
        $actions = $registry->supports($resource, 'view') ? $registry->actionsFor($resource) : [];

        return [
            'resource' => ['required', Rule::in($registry->resourceKeys())],
            'wildcard' => ['array'],
            'wildcard.*' => [Rule::in(['inherit', 'allow', 'deny'])],
            'cells' => ['array'],
            'cells.*' => ['array'],
            'cells.*.*' => [Rule::in(['inherit', 'allow', 'deny'])],
        ];
    }

    /**
     * Matris kutucuklarını çözümleyicinin beklediği biçime çevirir.
     *
     * @return array{wildcard: list<string>, wildcardDenied: list<string>, allowed: array<string, list<int>>, denied: array<string, list<int>>}
     */
    public function matrix(): array
    {
        $registry = app(PermissionRegistry::class);
        $resource = $this->string('resource')->value();
        $actions = $registry->actionsFor($resource);

        /* Ekran UUID gönderir: sıralı birincil anahtar DOM'a çıkmaz. */
        $gruplar = CredentialGroup::query()->pluck('id', 'uuid')->all();

        $allowed = [];
        $denied = [];

        foreach ((array) $this->input('cells', []) as $action => $cells) {
            if (! in_array($action, $actions, true)) {
                continue;
            }

            foreach ((array) $cells as $uuid => $state) {
                $id = $gruplar[(string) $uuid] ?? null;

                if ($id === null) {
                    continue;
                }

                match ($state) {
                    'allow' => $allowed[$action][] = $id,
                    'deny' => $denied[$action][] = $id,
                    default => null,
                };
            }
        }

        $genel = ['allow' => [], 'deny' => []];

        foreach ((array) $this->input('wildcard', []) as $action => $state) {
            if (in_array($action, $actions, true) && isset($genel[$state])) {
                $genel[$state][] = $action;
            }
        }

        return [
            'wildcard' => $genel['allow'],
            'wildcardDenied' => $genel['deny'],
            'allowed' => $allowed,
            'denied' => $denied,
        ];
    }
}
