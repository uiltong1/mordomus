<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Filas do módulo
    |--------------------------------------------------------------------------
    | Convenção `mordomus:<módulo>:<fila>`, a mesma do Scheduling. As três são
    | distintas de propósito: `events` é o consumo (o envelope publicado pelo
    | módulo dono chega aqui), e `push`/`emails` são as saídas. Um e-mail
    | lento não pode segurar o envelope do próximo evento.
    |
    */

    'queues' => [
        'events' => env('NOTIFICATION_EVENTS_QUEUE', 'mordomus:notification:events'),
        'push' => env('NOTIFICATION_PUSH_QUEUE', 'mordomus:notification:push'),
        'emails' => env('NOTIFICATION_EMAILS_QUEUE', 'mordomus:notification:emails'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Chaves VAPID (ADR-001)
    |--------------------------------------------------------------------------
    | Par EC P-256 do ambiente, em base64url. A privada assina o JWT que
    | autentica o envio; a pública é a `applicationServerKey` que o navegador
    | assina e o `VITE_VAPID_PUBLIC_KEY` do frontend.
    |
    | Trocar a chave invalida toda assinatura já feita: o navegador recusa e a
    | fila limpa os tokens mortos. Por isso as chaves são do ambiente e nunca
    | de um tenant.
    |
    */

    'vapid' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        // `mailto:` ou `https:` — o que o serviço de push usa para avisar o
        // dono da chave que algo deu errado.
        'subject' => env('VAPID_SUBJECT', 'mailto:suporte@mordomus.app'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Padrão de silêncio
    |--------------------------------------------------------------------------
    | O que vale quando o morador não configurou nada e a casa também não.
    | 22:00–07:00 é a janela que não acorda ninguém, e 09:00 é o horário em que
    | o digest diário sai.
    |
    */

    'defaults' => [
        'quiet_start' => env('NOTIFICATION_QUIET_START', '22:00'),
        'quiet_end' => env('NOTIFICATION_QUIET_END', '07:00'),
        'digest' => env('NOTIFICATION_DIGEST', 'instant'),
        'preferred_hour' => env('NOTIFICATION_PREFERRED_HOUR', '09:00'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Envio
    |--------------------------------------------------------------------------
    | `ttl` é o prazo que o serviço de push respeita antes de descartar a
    | mensagem: passado ele, o aviso de uma tarefa de amanhã não tem mais
    | sentido. `timeout` é o que o monólito espera do serviço antes de dar o
    | envio por perdido — e perdido aqui é retry, não descarte.
    |
    */

    'delivery' => [
        'ttl' => (int) env('NOTIFICATION_TTL_SECONDS', 86400),
        'timeout' => (int) env('NOTIFICATION_TIMEOUT_SECONDS', 10),
        'tries' => (int) env('NOTIFICATION_TRIES', 3),
        'backoff' => [10, 60, 300],
    ],

];
