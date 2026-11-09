<?php

namespace Tests\Feature\Mobile;

use Tests\Feature\Auth\InitialPasswordSessionContinuityTest;
use Tests\Support\IsolatedMobileDatabase;

class LegacyPasswordContinuityTest extends InitialPasswordSessionContinuityTest
{
    use IsolatedMobileDatabase;
}
