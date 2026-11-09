<?php

namespace Tests\Feature\Mobile;

use Tests\Feature\Auth\SingleSessionTest;
use Tests\Support\IsolatedMobileDatabase;

class LegacySingleSessionCompatibilityTest extends SingleSessionTest
{
    use IsolatedMobileDatabase;
}
