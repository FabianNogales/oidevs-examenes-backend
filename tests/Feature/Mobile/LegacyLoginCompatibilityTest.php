<?php

namespace Tests\Feature\Mobile;

use Tests\Feature\Auth\LoginTest;
use Tests\Support\IsolatedMobileDatabase;

class LegacyLoginCompatibilityTest extends LoginTest
{
    use IsolatedMobileDatabase;
}
