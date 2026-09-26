<?php

namespace Mordomus\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ServiceInfo',
    description: 'Identificação do serviço na raiz do host',
    required: ['service', 'status'],
    properties: [
        new OA\Property(property: 'service', type: 'string', example: 'Mordomus'),
        new OA\Property(property: 'status', type: 'string', enum: ['ok'], example: 'ok'),
    ],
)]
final class ServiceInfo {}
