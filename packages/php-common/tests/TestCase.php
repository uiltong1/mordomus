<?php

namespace Mordomus\Common\Tests;

use Mordomus\Common\Support\TenantContext;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base leve (sem laravel/framework): o pacote só depende de Eloquent e do
 * formato de erro, e o TenantContext — o único com estado — é isolado aqui.
 */
abstract class TestCase extends BaseTestCase
{
    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }
}
