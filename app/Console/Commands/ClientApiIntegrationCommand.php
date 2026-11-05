<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\ClientApiIntegration;
use App\Services\ClientApiIntegrations;
use Illuminate\Console\Command;

class ClientApiIntegrationCommand extends Command
{
    protected $signature = 'client-api:integration
        {action : create, list, rotate, revoke, grant or withdraw}
        {target? : integration name (create) or key id (other actions)}
        {sb_number? : SB number of the client (grant, withdraw)}';

    protected $description = 'Manage external integrations for the client information API (credentials are shown once; only a hash is stored)';

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        $target = (string) $this->argument('target');

        if ($action === 'list') {
            $this->table(['Key id', 'Name', 'Active', 'Clients', 'Last used'], ClientApiIntegration::withCount('clients')->orderBy('id')->get()
                ->map(fn ($i) => [$i->key_id, $i->name, $i->isUsable() ? 'yes' : 'revoked', $i->clients_count, $i->last_used_at?->toDateTimeString() ?? 'never'])->all());

            return self::SUCCESS;
        }
        if ($action === 'create') {
            if ($target === '') {
                $this->error('Give the integration a name.');

                return self::FAILURE;
            }
            $made = ClientApiIntegrations::create($target);
            $this->warn('Store this credential now on the external server only. It is not shown again:');
            $this->line($made['credential']);

            return self::SUCCESS;
        }

        $integration = ClientApiIntegration::where('key_id', $target)->first();
        if ($integration === null) {
            $this->error('No integration with that key id.');

            return self::FAILURE;
        }
        switch ($action) {
            case 'rotate':
                $this->warn('New credential (the old one stopped working). Not shown again:');
                $this->line(ClientApiIntegrations::rotate($integration));

                return self::SUCCESS;
            case 'revoke':
                ClientApiIntegrations::revoke($integration);
                $this->info('Integration revoked; its open codes were expired.');

                return self::SUCCESS;
            case 'grant':
            case 'withdraw':
                $client = Client::where('sb_number', (string) $this->argument('sb_number'))->first();
                if ($client === null) {
                    $this->error('No client with that SB number.');

                    return self::FAILURE;
                }
                $action === 'grant' ? ClientApiIntegrations::grant($integration, $client) : ClientApiIntegrations::withdraw($integration, $client);
                $this->info(ucfirst($action).' recorded for '.$client->sb_number.'.');

                return self::SUCCESS;
        }
        $this->error('Unknown action.');

        return self::FAILURE;
    }
}
