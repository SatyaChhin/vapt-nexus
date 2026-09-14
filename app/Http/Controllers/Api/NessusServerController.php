<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\NessusServerRequest;
use App\Http\Resources\NessusServerResource;
use App\Models\NessusServer;
use App\Services\Nessus\NessusServerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class NessusServerController extends Controller
{
    public function __construct(private readonly NessusServerService $servers) {}

    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', NessusServer::class);

        return NessusServerResource::collection(
            NessusServer::query()->withCount('projects')->orderBy('name')->get(),
        );
    }

    public function store(NessusServerRequest $request): JsonResponse
    {
        $server = $this->servers->create($request->validated());

        return (new NessusServerResource($server))->response()->setStatusCode(201);
    }

    public function show(NessusServer $server): NessusServerResource
    {
        Gate::authorize('view', $server);

        return new NessusServerResource($server->loadCount('projects'));
    }

    public function update(NessusServerRequest $request, NessusServer $server): NessusServerResource
    {
        return new NessusServerResource($this->servers->update($server, $request->validated()));
    }

    public function destroy(NessusServer $server): Response
    {
        Gate::authorize('delete', $server);

        $this->servers->delete($server);

        return response()->noContent();
    }

    /**
     * POST /api/nessus/servers/{server}/test
     */
    public function test(NessusServer $server): JsonResponse
    {
        Gate::authorize('testConnection', $server);

        $result = $this->servers->testConnection($server);

        return response()->json([
            ...$result->toArray(),
            'server' => new NessusServerResource($server->refresh()),
        ]);
    }
}
