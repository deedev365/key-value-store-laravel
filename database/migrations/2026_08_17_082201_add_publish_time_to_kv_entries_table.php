<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When a version becomes publicly visible, as UNIX seconds.
     *
     * An integer rather than a SQL timestamp, matching `recorded_at`: the API
     * speaks UNIX seconds in both directions, and an integer cannot carry a
     * session timezone the way a DATETIME can.
     *
     * Nullable, and NULL is not "unset" but a meaning of its own — "no
     * schedule", i.e. live from the moment the row was written. That is what
     * every row predating this column is, and what a plain write stays, so no
     * backfill is needed and existing behaviour is unchanged by this migration
     * alone.
     */
    public function up(): void
    {
        Schema::table('kv_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('publish_time')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('kv_entries', function (Blueprint $table) {
            $table->dropColumn('publish_time');
        });
    }
};
