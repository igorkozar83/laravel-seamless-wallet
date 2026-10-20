<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CRUD API for tournaments.
 * Run: docker compose run --rm app php artisan test --filter=TournamentCrud
 */
class TournamentCrudTest extends TestCase
{
    use RefreshDatabase;

    private array $valid = [
        'name' => 'Friday Slots Race',
        'prize_pool_cents' => 500000,
        'starts_at' => '2026-10-10 18:00:00',
    ];

    public function test_it_creates_a_tournament(): void
    {
        $this->postJson('/api/tournaments', $this->valid)
            ->assertCreated()
            ->assertJsonPath('data.name', 'Friday Slots Race')
            ->assertJsonPath('data.prize_pool_cents', 500000);

        $this->assertDatabaseHas('tournaments', ['name' => 'Friday Slots Race']);
    }

    public function test_it_rejects_invalid_data(): void
    {
        $this->postJson('/api/tournaments', ['prize_pool_cents' => -1, 'starts_at' => 'not a date'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'prize_pool_cents', 'starts_at']);
    }

    public function test_it_lists_tournaments(): void
    {
        $this->postJson('/api/tournaments', $this->valid);
        $this->postJson('/api/tournaments', [...$this->valid, 'name' => 'Live Roulette Cup']);

        $this->getJson('/api/tournaments')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_it_shows_one_tournament_or_404(): void
    {
        $id = $this->postJson('/api/tournaments', $this->valid)->json('data.id');

        $this->getJson("/api/tournaments/{$id}")
            ->assertOk()
            ->assertJsonPath('data.id', $id);

        $this->getJson('/api/tournaments/999999')->assertNotFound();
    }

    public function test_it_updates_a_tournament(): void
    {
        $id = $this->postJson('/api/tournaments', $this->valid)->json('data.id');

        $this->putJson("/api/tournaments/{$id}", [...$this->valid, 'name' => 'Renamed Race'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed Race');

        $this->assertDatabaseHas('tournaments', ['id' => $id, 'name' => 'Renamed Race']);
    }

    public function test_it_deletes_a_tournament(): void
    {
        $id = $this->postJson('/api/tournaments', $this->valid)->json('data.id');

        $this->deleteJson("/api/tournaments/{$id}")->assertNoContent();

        $this->assertSame(0, DB::table('tournaments')->count());
    }
}
