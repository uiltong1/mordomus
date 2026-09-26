<?php

use Mordomus\Identity\Services\JwtKey;

return [

    /*
    |--------------------------------------------------------------------------
    | JWT de usuário (RS256 — TECHSPEC §10.1)
    |--------------------------------------------------------------------------
    | Chaves locais montadas pelo docker-compose em /run/secrets (T1.1.8).
    | O `kid` publicado no JWKS do gateway deve ser o mesmo usado aqui.
    */

    'algorithm' => 'RS256',

    'issuer' => env('JWT_ISSUER', 'mordomus'),

    'kid' => env('JWT_KID'),

    // compose monta /run/secrets/rsa_*.pem e injeta o caminho; aceitamos também PEM direto
    'private_key' => JwtKey::resolve(env('JWT_RSA_PRIVATE_KEY')),

    'public_key' => JwtKey::resolve(env('JWT_RSA_PUBLIC_KEY')),

    /** validade do access token, em minutos */
    'ttl' => (int) env('JWT_TTL', 60),

    /** validade do refresh token, em minutos (30 dias) */
    'refresh_ttl' => (int) env('JWT_REFRESH_TTL', 43200),

    /** tolerância de relógio (segundos) na validação de exp/iat */
    'leeway' => (int) env('JWT_LEEWAY', 30),

];
