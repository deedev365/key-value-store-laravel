<?php

namespace Tests\Unit;

use App\Models\KvEntry;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Which version of a key is current, given each version's publish_time.
 *
 * The rule has two halves. A version is a candidate only once its publish time
 * has passed, or if it never had one; among the candidates, the one written
 * last wins. "Written last" means highest id, not greatest publish time — the
 * two differ whenever versions are saved in a different order than they are
 * scheduled to appear, which is exactly what the mixed cases below pin down.
 *
 * Every case is asserted against findLatest() *and* allLatest(), because the
 * rule belongs to the store rather than to one endpoint: if the two ever
 * disagreed, GET /object/{key} and the listing would show different content for
 * the same key at the same moment.
 */
class PublishTimeSelectionTest extends RepositoryTestCase
{
    private const NOW = 5000;

    /**
     * Publish times as offsets from NOW, so each case reads the way the rule is
     * stated: PAST_* are already published, FUTURE_* are not, null is
     * unscheduled.
     */
    private const PAST_1 = 1000;

    private const PAST_2 = 2000;

    private const PAST_3 = 3000;

    private const FUTURE_1 = 8000;

    private const FUTURE_2 = 9000;

    /**
     * Writes one version per publish time, in the order given, and returns the
     * value of the version the repository considers current.
     *
     * Each version's value is its own position, so the assertion names which
     * write won rather than comparing opaque payloads. recorded_at ascends with
     * insertion order, as it does in production where it comes from the clock.
     *
     * @param  list<int|null>  $publishTimes
     */
    private function currentValueAfterWriting(array $publishTimes): ?string
    {
        foreach ($publishTimes as $position => $publishTime) {
            KvEntry::create([
                'key' => 'mykey',
                'value' => 'v'.($position + 1),
                'recorded_at' => 100 + $position,
                'publish_time' => $publishTime,
            ]);
        }

        $viaKey = $this->repository->findLatest('mykey', self::NOW);
        $viaListing = $this->repository->allLatest(10, 0, self::NOW)->firstWhere('key', 'mykey');

        $this->assertSame(
            $viaKey?->value,
            $viaListing?->value,
            'findLatest() and allLatest() disagree about the current version'
        );

        return $viaKey?->value;
    }

    /**
     * The cases from the specification, keyed by the notation they were written
     * in. The array is the publish_time of each write in order; the string is
     * the value expected to be current at NOW.
     *
     * @return array<string, array{list<int|null>, string|null}>
     */
    public static function selectionCases(): array
    {
        return [
            // (time1,time2)
            '(t1,t2) t1<now, t2>now -> t1' => [[self::PAST_1, self::FUTURE_1], 'v1'],
            '(t1,t2) t1<now, t2<now -> t2' => [[self::PAST_1, self::PAST_2], 'v2'],

            // (time1,time2,time3)
            '(t1,t2,t3) t1<now, t2<now, t3>now -> t2' => [[self::PAST_1, self::PAST_2, self::FUTURE_1], 'v2'],
            '(t1,t2,t3) t1<now, t2<now, t3<now -> t3' => [[self::PAST_1, self::PAST_2, self::PAST_3], 'v3'],

            // (null,time1,time2)
            '(null,t1,t2) t1>now, t2>now -> null' => [[null, self::FUTURE_1, self::FUTURE_2], 'v1'],
            '(null,t1,t2) t1<now, t2>now -> t1' => [[null, self::PAST_1, self::FUTURE_1], 'v2'],
            '(null,t1,t2) t1<now, t2<now -> t2' => [[null, self::PAST_1, self::PAST_2], 'v3'],

            // (time1,null,time2)
            '(t1,null,t2) t1>now, t2>now -> null' => [[self::FUTURE_1, null, self::FUTURE_2], 'v2'],
            '(t1,null,t2) t1<now, t2>now -> null' => [[self::PAST_1, null, self::FUTURE_1], 'v2'],
            '(t1,null,t2) t1<now, t2<now -> t2' => [[self::PAST_1, null, self::PAST_2], 'v3'],

            // (time1,time2,null)
            '(t1,t2,null) t1>now, t2>now -> null' => [[self::FUTURE_1, self::FUTURE_2, null], 'v3'],
            '(t1,t2,null) t1<now, t2>now -> null' => [[self::PAST_1, self::FUTURE_1, null], 'v3'],
            '(t1,t2,null) t1<now, t2<now -> null' => [[self::PAST_1, self::PAST_2, null], 'v3'],
        ];
    }

    /**
     * @param  list<int|null>  $publishTimes
     */
    #[DataProvider('selectionCases')]
    public function test_the_current_version_is_the_last_published_write(array $publishTimes, ?string $expected): void
    {
        $this->assertSame($expected, $this->currentValueAfterWriting($publishTimes));
    }

    // ---------------------------------------------------------------
    // The two halves of the rule, stated on their own
    // ---------------------------------------------------------------

    public function test_a_key_with_no_published_version_has_no_current_value(): void
    {
        // Not an empty value and not the scheduled one either: there is nothing
        // to show, so the key reads as though it were never written.
        $this->assertNull($this->currentValueAfterWriting([self::FUTURE_1, self::FUTURE_2]));
    }

    public function test_a_version_is_published_the_second_after_the_one_it_names(): void
    {
        // The rule is publish_time < now, so the naming second itself is still
        // pending. A one-second boundary, pinned so it cannot drift silently.
        KvEntry::create(['key' => 'mykey', 'value' => 'campaign', 'recorded_at' => 100, 'publish_time' => self::NOW]);

        $this->assertNull($this->repository->findLatest('mykey', self::NOW));
        $this->assertSame('campaign', $this->repository->findLatest('mykey', self::NOW + 1)?->value);
    }

    public function test_an_unscheduled_version_is_published_whatever_the_clock_says(): void
    {
        KvEntry::create(['key' => 'mykey', 'value' => 'unscheduled', 'recorded_at' => 100, 'publish_time' => null]);

        $this->assertSame('unscheduled', $this->repository->findLatest('mykey', 0)?->value);
    }

    public function test_versions_sharing_a_publish_time_resolve_to_the_later_write(): void
    {
        KvEntry::create(['key' => 'mykey', 'value' => 'first', 'recorded_at' => 100, 'publish_time' => self::PAST_1]);
        KvEntry::create(['key' => 'mykey', 'value' => 'second', 'recorded_at' => 101, 'publish_time' => self::PAST_1]);

        $this->assertSame('second', $this->repository->findLatest('mykey', self::NOW)?->value);
    }

    // ---------------------------------------------------------------
    // The rule reaches every read, not just the two above
    // ---------------------------------------------------------------

    public function test_a_future_timestamp_cannot_be_used_to_read_a_version_early(): void
    {
        // findAtTimestamp compares recorded_at with the caller's timestamp but
        // publish_time with the real clock, so travelling forward reveals
        // nothing that is not already live.
        KvEntry::create(['key' => 'mykey', 'value' => 'live', 'recorded_at' => 100, 'publish_time' => null]);
        KvEntry::create(['key' => 'mykey', 'value' => 'campaign', 'recorded_at' => 101, 'publish_time' => self::FUTURE_1]);

        $this->assertSame(
            'live',
            $this->repository->findAtTimestamp('mykey', 999999, self::NOW)?->value
        );
    }

    public function test_history_omits_a_version_that_has_not_been_published(): void
    {
        KvEntry::create(['key' => 'mykey', 'value' => 'live', 'recorded_at' => 100, 'publish_time' => null]);
        KvEntry::create(['key' => 'mykey', 'value' => 'campaign', 'recorded_at' => 101, 'publish_time' => self::FUTURE_1]);

        $this->assertSame(
            ['live'],
            $this->repository->history('mykey', self::NOW)->pluck('value')->all()
        );
    }

    public function test_history_includes_the_version_once_it_is_published(): void
    {
        KvEntry::create(['key' => 'mykey', 'value' => 'live', 'recorded_at' => 100, 'publish_time' => null]);
        KvEntry::create(['key' => 'mykey', 'value' => 'campaign', 'recorded_at' => 101, 'publish_time' => self::PAST_1]);

        $this->assertSame(
            ['live', 'campaign'],
            $this->repository->history('mykey', self::NOW)->pluck('value')->all()
        );
    }
}
