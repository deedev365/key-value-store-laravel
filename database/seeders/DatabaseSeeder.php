<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * The entry point `db:seed` expects, and what `migrate:fresh --seed` runs.
 *
 * Seeding is a convenience for local work, not part of the store's contract:
 * nothing here runs on deploy (the pipeline calls `migrate --force` only) and
 * the test suites boot an empty database of their own, so the seeded rows
 * exist solely to give a rebuilt development database something to show.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(KvEntrySeeder::class);
    }
}
