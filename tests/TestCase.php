<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Base TestCase for all application tests.
 * Extend this class to gain access to Laravel's testing helpers.
 */
abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;
}
