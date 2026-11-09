<?php

namespace Tests\Support;

trait IsolatedMobileDatabase
{
    use IsolatedOfflineDatabase { refreshDatabase as private migrateIsolated; }

    public function refreshDatabase(): void
    {
        $this->migrateIsolated();
        // Legacy web regression cases deliberately use file sessions. Keep every
        // file outside the application's real session directory and remove only ours.
        $directory = sys_get_temp_dir().'/eida-mobile-session-'.bin2hex(random_bytes(12));
        if (! mkdir($directory, 0700)) {
            throw new \RuntimeException('Cannot create isolated session directory.');
        }
        config(['session.files' => $directory, 'logging.default' => 'stderr']);
        $this->beforeApplicationDestroyed(function () use ($directory) {
            foreach (glob($directory.'/*') ?: [] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($directory);
        });
    }
}
