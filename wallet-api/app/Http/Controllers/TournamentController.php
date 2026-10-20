<?php

namespace App\Http\Controllers;

use App\Http\Requests\TournamentRequest;
use App\Http\Resources\TournamentResource;
use App\Models\Tournament;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class TournamentController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): AnonymousResourceCollection
    {
        $tournaments = Tournament::all();

        return TournamentResource::collection($tournaments);

    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(TournamentRequest $request): TournamentResource
    {
        $tournament = Tournament::create($request->validated());

        return new TournamentResource($tournament);

    }

    /**
     * Display the specified resource.
     */
    public function show(Tournament $tournament): TournamentResource
    {
        return new TournamentResource($tournament);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(TournamentRequest $request, Tournament $tournament): TournamentResource
    {
        $tournament->update($request->validated());

        return new TournamentResource($tournament);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Tournament $tournament): Response
    {

        $tournament->delete();

        return response()->noContent();
    }
}
