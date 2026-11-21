<?php

namespace Tests\Unit\Support;

use App\Support\BrowserOrigins;
use PHPUnit\Framework\TestCase;

class BrowserOriginsTest extends TestCase
{
    public function test_production_origins_are_explicit_and_never_include_empty_or_null_entries(): void
    {
        $this->assertSame([], BrowserOrigins::allowed(null, null, false));
        $this->assertSame(['https://portal.example.test'], BrowserOrigins::allowed(
            ' ,null,*,https://portal.example.test/,https://portal.example.test,https://*.example.test,http://user:pass@example.test',
            'https://portal.example.test', false,
        ));
        $this->assertSame([], BrowserOrigins::allowed('https://portal.example.test/path,https://portal.example.test?q=1', null, false));
    }

    public function test_loopback_defaults_are_only_added_for_local_development(): void
    {
        $this->assertSame(['http://127.0.0.1:5173', 'http://localhost:5173'], BrowserOrigins::allowed(null, null, true));
        $this->assertSame(['http://localhost:5173'], BrowserOrigins::allowed('http://localhost:5173', null, false));
    }

    public function test_stateful_domains_derive_from_authorized_origins_or_an_explicit_list(): void
    {
        $this->assertSame([], BrowserOrigins::stateful(null, []));
        $this->assertSame(['portal.example.test:8443'], BrowserOrigins::stateful(null, ['https://portal.example.test:8443']));
        $this->assertSame(['portal.example.test', '[::1]:5173'], BrowserOrigins::stateful(
            ' portal.example.test,*,null,https://bad.example.test,path/child,[::1]:5173', [],
        ));
    }
}
