<?php

namespace Tests\Feature;

use App\Models\KvEntry;
use Database\Seeders\TravelContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The travel content seeder, which exists so the publish_time rule can be
 * checked by hand against a rebuilt database.
 *
 * What is worth pinning is not the copy but the arrangement: each key must
 * still resolve to the version the rule says it should, or the seeded data
 * stops demonstrating anything.
 */
class TravelContentSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Named for the seeder rather than shadowing TestCase::seed(), which is
     * public and would clash.
     */
    private function seedTravelContent(): void
    {
        $this->seed(TravelContentSeeder::class);
    }

    private function currentMessage(string $key): ?string
    {
        return $this->getJson('/object/'.$key)->json('value.message');
    }

    public function test_it_seeds_three_versions_of_each_key(): void
    {
        $this->seedTravelContent();

        foreach ([
            'route.bangkok-chiang-mai.banner',
            'operator.srt.booking_notice',
            'country.th.payment_message',
        ] as $key) {
            $this->assertSame(3, KvEntry::where('key', $key)->count(), "{$key} was not seeded three times");
        }
    }

    public function test_every_seeded_value_is_an_object_carrying_a_string_message(): void
    {
        $this->seedTravelContent();

        foreach (KvEntry::all() as $row) {
            $this->assertIsObject($row->value, "{$row->key} stored something other than an object");
            $this->assertIsString($row->value->message ?? null, "{$row->key} has no string message");
        }
    }

    public function test_the_banner_shows_the_unscheduled_version_written_last(): void
    {
        // (past, past, null): both schedules are live, yet the version written
        // after them wins — the case where `id` decides rather than the
        // greatest publish time.
        $this->seedTravelContent();

        $this->assertStringContainsString(
            'overnight sleeper services',
            (string) $this->currentMessage('route.bangkok-chiang-mai.banner')
        );
    }

    public function test_the_booking_notice_shows_the_published_schedule(): void
    {
        // (null, past, future): the published schedule beats the earlier
        // unscheduled version, and the third is not due yet.
        $this->seedTravelContent();

        $this->assertStringContainsString(
            'now available',
            (string) $this->currentMessage('operator.srt.booking_notice')
        );
    }

    public function test_the_booking_notice_moves_on_by_itself_when_its_time_passes(): void
    {
        // Nothing runs in between — the read simply asks the clock. If the
        // seeder ever hard-coded absolute times, this would stop holding on a
        // database rebuilt later.
        $this->seedTravelContent();

        $this->travelTo(now()->addSeconds(3601));

        $this->assertStringContainsString(
            'bookings are now closed',
            (string) $this->currentMessage('operator.srt.booking_notice')
        );
    }

    public function test_the_payment_message_never_shows_the_pending_outage_notice(): void
    {
        // (past, future, null): the outage notice publishes in an hour and is
        // still superseded, because a later write already won.
        $this->seedTravelContent();

        $this->assertStringNotContainsString(
            'temporarily unavailable',
            (string) $this->currentMessage('country.th.payment_message')
        );

        $this->travelTo(now()->addSeconds(3601));

        $this->assertStringNotContainsString(
            'temporarily unavailable',
            (string) $this->currentMessage('country.th.payment_message')
        );
    }

    public function test_a_pending_version_is_withheld_from_the_history(): void
    {
        $this->seedTravelContent();

        $this->getJson('/object/operator.srt.booking_notice/history')
            ->assertOk()
            ->assertJsonCount(2);
    }

    public function test_seeding_twice_does_not_duplicate_a_key(): void
    {
        // The guard counts rows rather than reading the current value: a key
        // whose versions were all still pending would read as absent and be
        // written a second time.
        $this->seedTravelContent();
        $this->seedTravelContent();

        $this->assertSame(3, KvEntry::where('key', 'operator.srt.booking_notice')->count());
    }
}
