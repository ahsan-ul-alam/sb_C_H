<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientExpense;
use App\Models\ClientProfitDeclaration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Finance's confirmation that, for one scope (client or project) and one period, every applicable client-borne expense is recorded. It is refused
 * while anything is unresolved, requires every category with no approved expense to be explicitly confirmed as genuinely zero, and stores a fingerprint
 * of the data it covered: any later change to sales, costs, refunds, commission or expenses makes it out of date and profit reverts to "incomplete".
 */
class ClientProfitDeclarations
{
    /** @param  list<string>  $zeroCategories */
    public static function declare(Client $client, ?int $projectId, ?string $from, ?string $to, array $zeroCategories, ?string $note, User $user): ClientProfitDeclaration
    {
        return DB::transaction(function () use ($client, $projectId, $from, $to, $zeroCategories, $note, $user) {
            $calc = ClientProfitability::calculate($client, $projectId, $from, $to);
            // The only reason allowed to remain is the absence of this very declaration.
            $blocking = array_values(array_filter($calc['missing_data'], fn ($m) => ! str_starts_with($m, 'Finance has not declared') && ! str_starts_with($m, 'The Finance declaration is out of date')));
            if ($blocking !== []) {
                throw new RuntimeException('Cannot declare while data is unresolved: '.implode(' ', $blocking));
            }
            if ($calc['status'] === 'no_sales' || $calc['figures']['delivered_sales_minor'] <= 0) {
                throw new RuntimeException('There are no eligible sales in this period to declare.');
            }
            $unknown = array_diff($zeroCategories, array_keys(ClientExpense::CATEGORIES));
            $unconfirmed = array_diff($calc['zero_categories_needed'], $zeroCategories);
            if ($unknown !== [] || $unconfirmed !== []) {
                throw new RuntimeException('Confirm that these categories genuinely had no cost: '.implode(', ', array_map(fn ($c) => ClientExpense::CATEGORIES[$c], $unconfirmed)).'.');
            }
            ClientProfitDeclaration::where('client_id', $client->id)->whereNull('revoked_at')
                ->when($projectId, fn ($q, $v) => $q->where('project_id', $v), fn ($q) => $q->whereNull('project_id'))
                ->when($from, fn ($q, $v) => $q->whereDate('period_from', $v), fn ($q) => $q->whereNull('period_from'))
                ->when($to, fn ($q, $v) => $q->whereDate('period_to', $v), fn ($q) => $q->whereNull('period_to'))
                ->update(['revoked_at' => now(), 'revoked_by' => $user->id]);
            $declaration = ClientProfitDeclaration::create(['client_id' => $client->id, 'project_id' => $projectId, 'period_from' => $from, 'period_to' => $to,
                'zero_categories' => array_values($zeroCategories), 'fingerprint' => $calc['fingerprint'], 'note' => $note, 'declared_by' => $user->id, 'declared_at' => now()]);
            Audit::log('client_profit.declared', 'client '.$client->id, $client->sb_number.' '.($from ?? 'start').'..'.($to ?? 'now').' project '.($projectId ?? 'all'));

            return $declaration;
        });
    }

    public static function revoke(ClientProfitDeclaration $declaration, User $user): void
    {
        $declaration->update(['revoked_at' => now(), 'revoked_by' => $user->id]);
        Audit::log('client_profit.revoked', 'client '.$declaration->client_id, 'declaration '.$declaration->id);
    }
}
