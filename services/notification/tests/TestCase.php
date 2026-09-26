<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        $this->syncServerEnv();

        parent::setUp();
    }

    /**
     * O PHPUnit grava os `<env>` do phpunit.xml em $_ENV/getenv, mas não em
     * $_SERVER — e o repositório de env do Laravel lê $_SERVER primeiro (onde
     * seguem as variáveis do compose, ex.: DB_CONNECTION=pgsql). Copia $_ENV
     * para $_SERVER antes do boot para que os valores de teste valam de fato.
     */
    private function syncServerEnv(): void
    {
        foreach ($_ENV as $key => $value) {
            if (is_string($value)) {
                $_SERVER[$key] = $value;
            }
        }
    }
}
