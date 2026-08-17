<?php

namespace Database\Seeders;

use App\Models\KvEntry;
use App\Repositories\EloquentKeyValueRepository;
use App\ValueObjects\Key;
use Illuminate\Database\Seeder;

/**
 * The customer-facing travel content: a route banner, an operator notice and a
 * country payment message, each with three versions.
 *
 * These exist to make the publish_time rule checkable by hand, so the three
 * keys are arranged to answer it differently. A version is current once its
 * publish time has passed — or if it has none — and among those, the one
 * written last wins:
 *
 *   route.bangkok-chiang-mai.banner  (past, past, null)   -> the third
 *       Both schedules are live, yet the unscheduled version written after them
 *       is what shows: `id` decides, not the greatest publish time.
 *
 *   operator.srt.booking_notice      (null, past, future) -> the second
 *       A published schedule beats the earlier unscheduled version, and the
 *       third takes over on its own once its time passes. This is the key to
 *       watch: it changes with no worker involved.
 *
 *   country.th.payment_message       (past, future, null) -> the third
 *       The outage notice in the middle will publish later and still never
 *       show, because a later write already supersedes it.
 *
 * Publish times are offsets from the moment of seeding rather than fixed
 * instants, so a database rebuilt next month still has one schedule pending
 * instead of a set of times that have all long passed.
 *
 * Values are {"message": "..."} throughout, the shape the site reads.
 */
class TravelContentSeeder extends Seeder
{
    private const HOUR = 3600;

    public function run(EloquentKeyValueRepository $repository): void
    {
        $now = now()->timestamp;

        foreach ($this->records($now) as $name => $versions) {
            $key = Key::fromString($name);

            // Counted rather than read back through the repository: a read
            // hides versions that have not published yet, so a key seeded
            // entirely in the future would look absent and be seeded twice.
            if (KvEntry::where('key', $key->value)->exists()) {
                continue;
            }

            foreach ($versions as $position => [$publishTime, $message]) {
                $repository->store(
                    $key->value,
                    (object) ['message' => $message],
                    // Written in order, a second apart, so recorded_at ascends
                    // with insertion the way it does for real writes.
                    $now - (2 * self::HOUR) + $position,
                    $publishTime,
                );
            }
        }
    }

    /**
     * Each key's versions in the order they are written: [publish_time, message],
     * where a null publish time means the version is live from the moment it is
     * written.
     *
     * @return array<string, list<array{0: int|null, 1: string}>>
     */
    private function records(int $now): array
    {
        return [
            'route.bangkok-chiang-mai.banner' => [
                [$now - (2 * self::HOUR), 'Normal service.'],
                [$now - self::HOUR, 'Songkran timetable now available.'],
                [null, 'Songkran timetable in effect — overnight sleeper services are fully booked until 16 April.'],
            ],

            'operator.srt.booking_notice' => [
                [null, 'Trains are running to the normal timetable.'],
                [$now - self::HOUR, 'Songkran holiday timetable now available — book early, seats are limited.'],
                [$now + self::HOUR, 'Songkran holiday bookings are now closed. Normal timetable resumes 16 April.'],
            ],

            'country.th.payment_message' => [
                [$now - (2 * self::HOUR), 'PromptPay and credit cards are accepted.'],
                [$now + self::HOUR, 'Card payments are temporarily unavailable — please pay with PromptPay.'],
                [null, 'PromptPay, credit cards and cash are accepted at all counters.'],
            ],
        ];
    }
}
