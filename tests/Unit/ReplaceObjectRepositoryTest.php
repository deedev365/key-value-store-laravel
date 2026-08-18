<?php

namespace Tests\Unit;

use App\Models\KvEntry;

/**
 * EloquentKeyValueRepository::replace() — the storage call behind
 * PUT /object/{key}, which corrects one version by removing it and appending
 * the corrected value.
 */
class ReplaceObjectRepositoryTest extends RepositoryTestCase
{
    public function test_the_replacement_is_a_new_row_and_the_replaced_one_is_gone(): void
    {
        $replaced = $this->repository->store('mykey', 'typo', 1000);

        $replacement = $this->repository->replace($replaced, 'fixed', 2000);

        $this->assertNotNull($replacement);
        $this->assertNotSame($replaced->id, $replacement->id);
        $this->assertSame('fixed', $replacement->value);
        $this->assertSame(2000, $replacement->recorded_at);
        $this->assertNull(KvEntry::query()->find($replaced->id));
    }

    public function test_the_replacement_becomes_the_current_version(): void
    {
        // Appended rather than written in place, so being current has to follow
        // from the same highest-id rule every read applies.
        $replaced = $this->repository->store('mykey', 'typo', 1000);

        $replacement = $this->repository->replace($replaced, 'fixed', 2000);

        $this->assertGreaterThan($replaced->id, $replacement?->id);
        $this->assertSame('fixed', $this->repository->findLatest('mykey', 3000)?->value);
    }

    public function test_only_the_named_version_is_removed(): void
    {
        // A correction is not a deletion of the key: the versions either side
        // of the one being corrected stay readable.
        $older = $this->repository->store('mykey', 'value1', 1000);
        $this->repository->store('mykey', 'value2', 2000);

        $this->repository->replace($older, 'value1-fixed', 3000);

        $this->assertSame(
            ['value2', 'value1-fixed'],
            $this->repository->history('mykey', 4000)->pluck('value')->all()
        );
    }

    public function test_correcting_an_older_version_still_makes_it_current(): void
    {
        // The honest consequence of appending: there is no way to write a
        // version that is not the newest one, so a correction to an older
        // version becomes the key's current value.
        $older = $this->repository->store('mykey', 'value1', 1000);
        $this->repository->store('mykey', 'value2', 2000);

        $this->repository->replace($older, 'value1-fixed', 3000);

        $this->assertSame('value1-fixed', $this->repository->findLatest('mykey', 4000)?->value);
    }

    public function test_the_schedule_of_the_replaced_version_is_carried_over(): void
    {
        // Correcting the wording of a scheduled item must not reschedule it.
        $replaced = $this->repository->store('mykey', 'typo', 1000, 1500);

        $replacement = $this->repository->replace($replaced, 'fixed', 2000);

        $this->assertSame(1500, $replacement?->publish_time);
    }

    public function test_a_given_schedule_wins_over_the_carried_over_one(): void
    {
        $replaced = $this->repository->store('mykey', 'typo', 1000, 1500);

        $replacement = $this->repository->replace($replaced, 'fixed', 2000, 1800);

        $this->assertSame(1800, $replacement?->publish_time);
    }

    public function test_an_unscheduled_version_stays_unscheduled(): void
    {
        $replaced = $this->repository->store('mykey', 'typo', 1000);

        $replacement = $this->repository->replace($replaced, 'fixed', 2000);

        $this->assertNull($replacement?->publish_time);
    }

    public function test_a_version_that_has_already_gone_is_not_replaced(): void
    {
        // The delete is the claim on the version: if it is already gone, the
        // correction is not appended and the transaction leaves no trace —
        // otherwise two callers editing one version would both "succeed" and
        // the store would keep two corrections of a version that had one.
        $replaced = $this->repository->store('mykey', 'typo', 1000);
        KvEntry::query()->whereKey($replaced->id)->delete();

        $this->assertNull($this->repository->replace($replaced, 'fixed', 2000));
        $this->assertCount(0, $this->repository->history('mykey', 3000));
    }

    public function test_other_keys_are_untouched(): void
    {
        $replaced = $this->repository->store('a', 'a1', 1000);
        $this->repository->store('b', 'b1', 1000);

        $this->repository->replace($replaced, 'a2', 2000);

        $this->assertCount(1, $this->repository->history('b', 3000));
    }
}
