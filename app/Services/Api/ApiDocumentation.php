<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Services\Authorization\PermissionRegistry;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * API belgesini ROTALARDAN üretir.
 *
 * Adres, yöntem ve parametre listesi rotanın kendisinden okunur; açıklama ve
 * gereken yetki config/api.php'den gelir. Elle yazılmış ikinci bir uç listesi
 * YOKTUR — olsaydı gerçekle ayrışır ve belge yanlış bilgi verirdi.
 *
 * Belgesiz bir uç eklendiğinde test kırılır (bkz. ApiDocumentationTest).
 */
class ApiDocumentation
{
    private const PREFIX = 'api.v1.';

    public function __construct(private PermissionRegistry $registry) {}

    /**
     * @return list<array{
     *     name: string, method: string, uri: string, summary: string,
     *     description: string, ability: string, abilityLabel: string,
     *     parameters: array<string, string>, documented: bool
     * }>
     */
    public function endpoints(): array
    {
        $docs = (array) config('api.endpoints', []);
        $endpoints = [];

        foreach ($this->routes() as $route) {
            $key = substr((string) $route->getName(), strlen(self::PREFIX));
            $doc = $docs[$key] ?? null;

            $endpoints[] = [
                'name' => $key,
                'method' => $this->method($route),
                'uri' => '/'.ltrim($route->uri(), '/'),
                'summary' => $doc['summary'] ?? $key,
                'description' => $doc['description'] ?? '',
                'ability' => $doc['ability'] ?? '',
                'abilityLabel' => $this->abilityLabel($doc['ability'] ?? ''),
                'parameters' => $doc['parameters'] ?? [],
                'documented' => $doc !== null,
            ];
        }

        return $endpoints;
    }

    /**
     * Belgede olup rotası olmayan girdiler. Silinen bir uç config'te unutulmuş
     * olabilir; belge var olmayan bir adresi anlatmamalı.
     *
     * @return list<string>
     */
    public function orphans(): array
    {
        $names = array_map(
            fn (RoutingRoute $route): string => substr((string) $route->getName(), strlen(self::PREFIX)),
            $this->routes(),
        );

        return array_values(array_diff(array_keys((array) config('api.endpoints', [])), $names));
    }

    /**
     * @return list<RoutingRoute>
     */
    private function routes(): array
    {
        $routes = array_filter(
            Route::getRoutes()->getRoutes(),
            fn (RoutingRoute $route): bool => str_starts_with((string) $route->getName(), self::PREFIX),
        );

        usort($routes, fn (RoutingRoute $a, RoutingRoute $b): int => $a->uri() <=> $b->uri());

        return array_values($routes);
    }

    private function method(RoutingRoute $route): string
    {
        $methods = array_diff($route->methods(), ['HEAD']);

        return implode('|', $methods);
    }

    /**
     * "kaynak:eylem" → "Kaynağın etiketi · Eylemin etiketi".
     */
    private function abilityLabel(string $ability): string
    {
        if (! str_contains($ability, ':')) {
            return $ability;
        }

        [$resource, $action] = explode(':', $ability, 2);

        $actions = $this->registry->actions();

        return $this->registry->label($resource).' · '.($actions[$action] ?? $action);
    }
}
