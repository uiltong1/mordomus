<?php

declare(strict_types=1);

namespace Mordomus\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Mordomus\Http\Responses\ErrorEnvelope;
use Mordomus\OpenApi\Schemas\Error;
use OpenApi\Attributes as OA;

/**
 * Publica a especificação gerada por `make openapi` e a interface de leitura.
 * O YAML versionado é a fonte de verdade; nada é gerado em tempo de execução.
 */
final class OpenApiDocsController
{
    private const SPEC_URL = '/api/v1/openapi.yaml';

    #[OA\Get(
        path: '/api/v1/openapi.yaml',
        operationId: 'openApiSpec',
        summary: 'Especificação OpenAPI 3.0 em YAML',
        tags: ['system'],
        responses: [
            new OA\Response(response: 200, description: 'Documento OpenAPI', content: new OA\MediaType(mediaType: 'application/yaml')),
            new OA\Response(response: 404, description: 'Artefato ainda não gerado', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function spec(Request $request): Response
    {
        $path = storage_path('openapi.yaml');

        if (! is_file($path)) {
            return ErrorEnvelope::make(
                $request,
                404,
                'openapi_missing',
                'Especificação ainda não gerada — rode `make openapi`.',
            );
        }

        return response((string) file_get_contents($path), 200, [
            'Content-Type' => 'application/yaml; charset=utf-8',
            'Cache-Control' => 'no-cache',
        ]);
    }

    #[OA\Get(
        path: '/api/v1/docs',
        operationId: 'openApiDocs',
        summary: 'Interface de leitura da documentação (Swagger UI)',
        tags: ['system'],
        responses: [
            new OA\Response(response: 200, description: 'Página HTML com a especificação carregada'),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function ui(): View
    {
        return view('openapi-docs', ['specUrl' => self::SPEC_URL]);
    }
}
