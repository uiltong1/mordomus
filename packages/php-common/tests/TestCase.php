<?php

namespace Mordomus\Common\Tests;

use Mordomus\Common\Support\TenantContext;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }
}
