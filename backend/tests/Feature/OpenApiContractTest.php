<?php

namespace Tests\Feature;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Contrato: la especificación OpenAPI (docs/api/openapi.yaml) y las rutas reales de la API
 * deben coincidir. Si alguien agrega o quita un endpoint sin documentarlo, esta prueba falla.
 */
class OpenApiContractTest extends TestCase
{
    private const SPEC = 'docs/api/openapi.yaml';

    /** @return array<string, mixed> */
    private function spec(): array
    {
        return Yaml::parseFile(base_path(self::SPEC));
    }

    /** "GET /padron/{entity}" por cada ruta de /api/v1. @return list<string> */
    private function apiRoutes(): array
    {
        return collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $r) => str_starts_with($r->uri(), 'api/v1/'))
            ->flatMap(fn (Route $r) => collect($r->methods())
                ->reject(fn ($m) => $m === 'HEAD')
                ->map(fn ($m) => $m.' '.substr($r->uri(), strlen('api/v1'))))
            ->unique()->sort()->values()->all();
    }

    /** @return list<string> */
    private function documented(): array
    {
        return collect($this->spec()['paths'])
            ->flatMap(fn (array $ops, string $path) => collect($ops)->keys()
                ->filter(fn ($k) => in_array($k, ['get', 'post', 'put', 'patch', 'delete'], true))
                ->map(fn ($m) => strtoupper($m).' '.$path))
            ->sort()->values()->all();
    }

    public function test_spec_is_openapi_3_1_with_jwt_security(): void
    {
        $spec = $this->spec();

        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertSame(['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'],
            array_intersect_key($spec['components']['securitySchemes']['bearerAuth'], array_flip(['type', 'scheme', 'bearerFormat'])));
        $this->assertSame([['bearerAuth' => []]], $spec['security']);
    }

    public function test_every_api_route_is_documented_and_every_documented_path_exists(): void
    {
        $routes = $this->apiRoutes();
        $documented = $this->documented();

        $this->assertSame([], array_values(array_diff($routes, $documented)), 'Rutas sin documentar en '.self::SPEC);
        $this->assertSame([], array_values(array_diff($documented, $routes)), 'Rutas documentadas que ya no existen');
    }

    public function test_public_endpoints_are_marked_without_security(): void
    {
        $paths = $this->spec()['paths'];

        $this->assertSame([], $paths['/auth/login']['post']['security']);
        $this->assertSame([], $paths['/health']['get']['security']);
    }

    public function test_every_operation_has_an_id_and_success_response(): void
    {
        foreach ($this->spec()['paths'] as $path => $ops) {
            foreach ($ops as $method => $op) {
                if ($method === 'parameters') {
                    continue;
                }
                $this->assertArrayHasKey('operationId', $op, "{$method} {$path}");
                $codes = array_map('strval', array_keys($op['responses']));
                $this->assertNotEmpty(array_filter($codes, fn ($c) => str_starts_with($c, '2')), "{$method} {$path} sin respuesta 2xx");
            }
        }
    }

    public function test_docs_pages_are_served(): void
    {
        $this->get('/docs')->assertOk()->assertSee('swagger-ui', false);
        $this->get('/docs/openapi.yaml')->assertOk()->assertHeader('Content-Type', 'application/yaml; charset=UTF-8');
    }
}
