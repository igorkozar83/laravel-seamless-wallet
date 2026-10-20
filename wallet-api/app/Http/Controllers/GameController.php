<?php

namespace App\Http\Controllers;

use App\Http\Resources\GameResource;
use App\Models\Game;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GameController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $games = Game::query()
            ->active()                      // local scope from the model
            ->with('provider')              // eager loading: 2 queries total instead of 1 + N
            ->orderBy('id')
            ->cursorPaginate(50);

        return GameResource::collection($games);
    }
}
