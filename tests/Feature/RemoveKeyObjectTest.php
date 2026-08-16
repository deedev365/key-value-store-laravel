<?php

namespace Tests\Feature;

use App\Models\KvEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DELETE /object/{key}
 */
class RemoveKeyObjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_deletes_every_version_of_a_key(): void
    {
        KvEntry::create(['key' => 'mykey', 'value' => 'value1', 'recorded_at' => 1000]);
        KvEntry::create(['key' => 'mykey', 'value' => 'value2', 'recorded_at' => 2000]);

        $this->deleteJson('/object/mykey')->assertNoContent();

        $this->assertSame(0, KvEntry::where('key', 'mykey')->count());
        $this->getJson('/object/mykey')->assertNotFound();
    }

    public function test_deleting_an_unknown_key_returns_404(): void
    {
        $this->deleteJson('/object/does-not-exist')
            ->assertNotFound()
            ->assertJsonStructure(['message']);
    }
}
