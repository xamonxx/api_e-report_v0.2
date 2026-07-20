<?php

namespace Database\Seeders;

use App\Models\Account;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Assigns accounts to the NPP1 / NPP2 groups (stored in Account.account_group).
 *
 * Previously the group was written into `description`. That column is also the
 * account category filter (GET /accounts/categories), so the group now lives in
 * its own column and `description` is left alone.
 *
 * Idempotent + safe to re-run:
 *  - Matches an existing account by name (case-insensitive; optional aliases),
 *    and only updates its group â€” the existing name/casing and all other fields
 *    are left untouched.
 *  - Creates the account when no match is found.
 *
 * Run: php artisan db:seed --class=Database\\Seeders\\AccountGroupSeeder
 */
class AccountGroupSeeder extends Seeder
{
    /**
     * Desired grouping. Each entry is [name, ...optional aliases].
     * `name` is used verbatim when creating a brand-new account.
     */
    private const GROUPS = [
        'NPP1' => [
            ['name' => 'Home Putra Interior'],
            ['name' => 'Raysa Interior'],
            ['name' => 'Interior Kita'],
        ],
        'NPP2' => [
            ['name' => 'Home Interior Bandung'],
            ['name' => 'Putra Interior'],
            ['name' => 'Elvan Interior'],
            ['name' => 'Elvan Furniture'],
            // "Interhouse" in the source list is the existing "Interhouse ID".
            ['name' => 'Interhouse', 'aliases' => ['Interhouse ID']],
            ['name' => 'Fullhome ID'],
            ['name' => 'Heya Interior'],
            ['name' => 'Broto Interior'],
            ['name' => 'Ruma Furniture'],
            ['name' => 'Interior Modern'],
            ['name' => 'Median Interior'],
            ['name' => 'Nacira Studio'],
        ],
    ];

    public function run(): void
    {
        $created = 0;
        $updated = 0;
        $unchanged = 0;

        foreach (self::GROUPS as $group => $entries) {
            foreach ($entries as $entry) {
                $candidates = array_map(
                    fn (string $n) => Str::lower(trim($n)),
                    array_merge([$entry['name']], $entry['aliases'] ?? [])
                );

                // Case-insensitive match on name or any alias, including any
                // soft-deleted rows so we never create an accidental duplicate.
                $account = Account::withTrashed()
                    ->whereIn(DB::raw('LOWER(name)'), $candidates)
                    ->first();

                if ($account) {
                    // Keep the account's own name but enforce all-caps; never
                    // rename it to the source-list label (e.g. the existing
                    // "INTERHOUSE ID" must stay intact, not become "INTERHOUSE").
                    $upperName = Str::upper($account->name);
                    $nameChanged = $account->name !== $upperName;
                    $groupChanged = $account->account_group !== $group;

                    if (! $nameChanged && ! $groupChanged) {
                        $unchanged++;
                        $this->command?->line("  = {$account->name} already {$group}");
                        continue;
                    }

                    $old = $account->account_group ?: '(none)';
                    $account->name = $upperName;
                    $account->account_group = $group;
                    $account->save();
                    $updated++;
                    $this->command?->info("  ~ {$upperName}: {$old} -> {$group}");
                    continue;
                }

                $newName = Str::upper($entry['name']);
                Account::create([
                    'name' => $newName,
                    'account_group' => $group,
                ]);
                $created++;
                $this->command?->info("  + {$newName} ({$group}) [new]");
            }
        }

        $this->command?->info("AccountGroupSeeder done â€” created: {$created}, updated: {$updated}, unchanged: {$unchanged}");
    }
}
