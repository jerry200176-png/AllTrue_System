<?php

namespace Tests\Feature;

use Tests\TestCase;

class AssignRequestIdTest extends TestCase
{
    public function test_api_response_gets_generated_request_id(): void
    {
        $id = $this->getJson('/api/health')->assertOk()->headers->get('X-Request-Id');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9-]{8,64}$/', $id);
    }

    public function test_valid_incoming_id_is_echoed(): void
    {
        $this->withHeader('X-Request-Id', 'abc-12345678')->getJson('/api/health')
            ->assertHeader('X-Request-Id', 'abc-12345678');
    }

    public function test_invalid_incoming_id_is_replaced(): void
    {
        $id = $this->withHeader('X-Request-Id', "bad id\twith junk")->getJson('/api/health')
            ->headers->get('X-Request-Id');
        $this->assertNotSame("bad id\twith junk", $id);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9-]{8,64}$/', $id);
    }
}
