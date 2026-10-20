<?php

namespace App\Console\Commands;

use App\Models\Game;
use App\Models\Provider;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

/** php artisan demo:n-plus-one — counts the SQL queries of the same loop, lazy vs eager. */
class NPlusOneDemo extends Command
{
    protected $signature = 'demo:n-plus-one {--providers=5} {--games=20}';

    protected $description = 'Show the N+1 query problem and how eager loading fixes it';

    public function handle(): int
    {
        DB::beginTransaction();                            // everything below is rolled back at the end

        try {
            Provider::factory((int) $this->option('providers'))
                ->has(Game::factory((int) $this->option('games')))
                ->create();

            $count = Game::count();

            // ❌ lazy loading: 1 query for the games + 1 query PER game for its provider
            $queries = $this->countQueries(function () {
                foreach (Game::query()->get() as $game) {
                    $game->provider->name;                 // each access fires a new SELECT
                }
            });
            $this->error("lazy  : {$count} games → {$queries} queries");

            // ✅ eager loading: 1 query for games + 1 query for ALL their providers
            $queries = $this->countQueries(function () {
                foreach (Game::query()->with('provider')->get() as $game) {
                    $game->provider->name;                 // already loaded, no query
                }
            });
            $this->info("eager : {$count} games → {$queries} queries");

            // ✅ guard rail: make lazy loading throw in development, so N+1 is caught before production.
            // It only fires for models loaded as part of a collection (that's when N+1 happens),
            // so a single Game::first()->provider is still allowed.
            Model::preventLazyLoading();
            try {
                foreach (Game::query()->get() as $game) {
                    $game->provider->name;
                }
            } catch (Throwable $e) {
                $this->line('guard : '.class_basename($e).' — '.$e->getMessage());
            } finally {
                Model::preventLazyLoading(false);
            }
        } finally {
            DB::rollBack();                                // leave the database as it was
        }

        return self::SUCCESS;
    }

    private function countQueries(callable $work): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $work();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
