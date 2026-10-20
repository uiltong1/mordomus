<?php

use Mordomus\Identity\Services\JwtKeyService;

return [

    'algorithm' => 'RS256',

    'issuer' => env('JWT_ISSUER', 'mordomus'),

    'kid' => env('JWT_KID'),

    'private_key' => JwtKeyService::resolve(env('JWT_RSA_PRIVATE_KEY')),

    'public_key' => JwtKeyService::resolve(env('JWT_RSA_PUBLIC_KEY')),

    'ttl' => (int) env('JWT_TTL', 60),

    'refresh_ttl' => (int) env('JWT_REFRESH_TTL', 43200),

    'leeway' => (int) env('JWT_LEEWAY', 30),

];
