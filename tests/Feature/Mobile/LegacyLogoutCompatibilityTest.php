<?php

namespace Tests\Feature\Mobile;

use Tests\Feature\Api\V1\Auth\RevokeSessionTokenTest;
use Tests\Support\IsolatedMobileDatabase;

class LegacyLogoutCompatibilityTest extends RevokeSessionTokenTest
{
    use IsolatedMobileDatabase;
}
