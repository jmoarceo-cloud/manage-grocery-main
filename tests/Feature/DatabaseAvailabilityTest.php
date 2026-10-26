<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class DatabaseAvailabilityTest extends TestCase
{
    public function test_connection_failure_is_a_private_retryable_response(): void
    {
        Route::get('/synthetic-connection-failure', function () {
            $previous = new \PDOException('Synthetic private database diagnostic');
            $previous->errorInfo = ['HY000', 2002, 'Synthetic private database diagnostic'];
            throw new QueryException('pos', 'select synthetic_secret from synthetic_table', [], $previous);
        });
        $this->get('/synthetic-connection-failure')->assertStatus(503)->assertHeader('Retry-After', '60')->assertSee('Taking a short pause.')->assertDontSee('synthetic_secret')->assertDontSee('diagnostic');
        $this->getJson('/synthetic-connection-failure')->assertStatus(503)->assertJsonStructure(['message'])->assertDontSee('synthetic_secret');
    }
}
