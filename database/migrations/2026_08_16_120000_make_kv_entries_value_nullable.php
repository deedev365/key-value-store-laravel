<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A stored value may legitimately be JSON null — {"mykey": null} is a
     * valid write per the API contract. Eloquent's json cast leaves null
     * unencoded, so it reaches the column as a SQL NULL and the original
     * NOT NULL constraint turned that write into a 500.
     */
    public function up(): void
    {
        Schema::table('kv_entries', function (Blueprint $table) {
            $table->json('value')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('kv_entries', function (Blueprint $table) {
            $table->json('value')->nullable(false)->change();
        });
    }
};
