<?php

namespace App\Console\Commands;

use App\Models\NessusServer;
use App\Services\Nessus\NessusServerService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nessus:test {server? : Nessus server ID (default: all servers)}')]
#[Description('Test the connection from Laravel to one or all registered Nessus servers')]
class NessusTestCommand extends Command
{
    public function handle(NessusServerService $service): int
    {
        $servers = $this->argument('server')
            ? NessusServer::query()->whereKey($this->argument('server'))->get()
            : NessusServer::query()->orderBy('id')->get();

        if ($servers->isEmpty()) {
            $this->error('No Nessus server found. Add one under "Nessus Servers" in the app.');

            return self::FAILURE;
        }

        $failed = false;

        foreach ($servers as $server) {
            $result = $service->testConnection($server);
            $failed = $failed || ! $result->success;

            $this->line(sprintf('<options=bold>#%d %s</> (%s)', $server->id, $server->name, $server->base_url));
            $this->line(sprintf(
                '  %s  %s',
                $result->success ? '<fg=green>OK</>' : '<fg=red>FAIL</>',
                $result->message,
            ));
            $this->line(sprintf(
                '  status=%s nessus=%s version=%s latency=%sms',
                $result->status->value,
                $result->nessusStatus ?? '-',
                trim(($result->edition ?? '').' '.($result->version ?? '')) ?: '-',
                $result->latencyMs ?? '-',
            ));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
