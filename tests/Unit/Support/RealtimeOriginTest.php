<?php

namespace Tests\Unit\Support;

use App\Support\RealtimeOrigin;
use Illuminate\Http\Request;
use Tests\TestCase;

class RealtimeOriginTest extends TestCase
{
    public function test_tab_returns_x_origin_tab_header_value(): void
    {
        $request = Request::create('/', 'GET');
        $request->headers->set('X-Origin-Tab', 'tab-uuid-abc');

        $this->app->instance('request', $request);

        $this->assertSame('tab-uuid-abc', RealtimeOrigin::tab());
    }

    public function test_tab_returns_null_when_header_absent(): void
    {
        $request = Request::create('/', 'GET');
        $this->app->instance('request', $request);

        $this->assertNull(RealtimeOrigin::tab());
    }
}
