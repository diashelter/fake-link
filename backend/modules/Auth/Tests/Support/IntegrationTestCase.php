<?php

declare(strict_types=1);

namespace Modules\Auth\Tests\Support;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class IntegrationTestCase extends TestCase
{
    use DatabaseSafety;
    use RefreshDatabase;
}
