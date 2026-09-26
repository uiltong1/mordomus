<?php

namespace Mordomus\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'Mordomus API',
    description: 'API do Mordomus — gestão doméstica. Todo o conteúdo vive sob `/api/v1`.',
)]
#[OA\Server(url: '/', description: 'Origem do gateway (nginx) que faz proxy para o monólito')]
#[OA\SecurityScheme(
    securityScheme: 'jwtBearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'JWT',
    description: 'Access token devolvido por `/identity/auth/login` e demais rotas de sessão.',
)]
#[OA\Tag(name: 'system', description: 'Saúde do serviço, raiz do host e documentação')]
#[OA\Tag(name: 'identity', description: 'Autenticação, residências, convites e membros')]
#[OA\Tag(name: 'maintenance', description: 'Cômodos e inventário do imóvel')]
final class OpenApi {}
