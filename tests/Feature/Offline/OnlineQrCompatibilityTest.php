<?php

namespace Tests\Feature\Offline;

use Tests\Feature\Api\V1\Entries\QrEntryTest;
use Tests\Support\IsolatedOfflineDatabase;

// Execute the existing QR contract tests with non-destructive migrations on an isolated in-memory database.
class OnlineQrCompatibilityTest extends QrEntryTest
{
    use IsolatedOfflineDatabase;
}
