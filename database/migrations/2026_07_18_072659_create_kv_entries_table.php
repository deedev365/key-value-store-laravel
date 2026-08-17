<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kv_entries', function (Blueprint $table) {
            $table->id();
            $table->string('key');
            $table->json('value');
            $table->unsignedBigInteger('recorded_at');
            $table->timestamps();

            // A key can be written many times (version history); (key, recorded_at)
            // covers "latest value" and "value as of timestamp" lookups, and the
            // trailing id makes the id-based "latest" query below deterministic
            // when two writes for the same key land on the same UNIX second.
            $table->index(['key', 'recorded_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kv_entries');
    }
};
