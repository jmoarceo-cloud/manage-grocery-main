<?php

namespace Tests;

use App\Support\IsolatedDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        IsolatedDatabase::verify();
    }
}
