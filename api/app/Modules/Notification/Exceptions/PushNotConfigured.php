<?php

namespace Mordomus\Notification\Exceptions;

use Mordomus\Http\Exceptions\ApiException;

/**
 * O ambiente não tem par de chaves VAPID.
 *
 * A assinatura do navegador depende da chave pública do ambiente, e sem ela o
 * `pushManager.subscribe()` não roda. A resposta diz isso em vez de devolver
 * um erro genérico de configuração, porque a correção é do ambiente e não do
 * morador.
 */
final class PushNotConfigured extends ApiException
{
    public static function make(): self
    {
        return new self(
            503,
            'push_not_configured',
            'Este ambiente ainda não tem chave de push configurada (VAPID_PUBLIC_KEY).',
        );
    }
}
