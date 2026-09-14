<?php

namespace App\Http\Controllers;

use App\Http\Requests\NessusServerRequest;
use App\Http\Resources\NessusServerResource;
use App\Models\NessusServer;
use App\Services\Nessus\NessusServerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class NessusServerController extends Controller
{
    public function __construct(private readonly NessusServerService $servers) {}

    public function index(): Response
    {
        Gate::authorize('viewAny', NessusServer::class);

        return Inertia::render('NessusServers/Index', [
            'servers' => NessusServerResource::collection(
                NessusServer::query()->withCount('projects')->orderBy('name')->get(),
            )->resolve(),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', NessusServer::class);

        return Inertia::render('NessusServers/Create', [
            'defaults' => [
                'base_url' => config('nessus.bootstrap.url') ?: 'https://192.168.56.10:8834',
                'verify_ssl' => (bool) config('nessus.bootstrap.verify_ssl'),
            ],
            'allowedNetworks' => config('nessus.allowed_networks'),
        ]);
    }

    public function store(NessusServerRequest $request): RedirectResponse
    {
        $server = $this->servers->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Nessus server added. Run "Test Connection" to verify it.')]);

        return to_route('nessus-servers.index', ['highlight' => $server->id]);
    }

    public function edit(NessusServer $server): Response
    {
        Gate::authorize('update', $server);

        return Inertia::render('NessusServers/Edit', [
            'server' => (new NessusServerResource($server))->resolve(),
            'allowedNetworks' => config('nessus.allowed_networks'),
        ]);
    }

    public function update(NessusServerRequest $request, NessusServer $server): RedirectResponse
    {
        $this->servers->update($server, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Nessus server updated.')]);

        return to_route('nessus-servers.index');
    }

    public function destroy(NessusServer $server): RedirectResponse
    {
        Gate::authorize('delete', $server);

        $this->servers->delete($server);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Nessus server deleted.')]);

        return to_route('nessus-servers.index');
    }
}
