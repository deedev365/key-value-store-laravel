<?php

namespace Database\Seeders;

use App\Repositories\EloquentKeyValueRepository;
use App\ValueObjects\Key;
use Illuminate\Database\Seeder;

/**
 * A starter set of records, so that a rebuilt database is not an empty one.
 *
 * Rows are written through the repository and their names through
 * Key::fromString(), the same two doors a write from the API goes through —
 * a seeded row is then indistinguishable from one a caller stored, and a name
 * that the API would reject cannot enter the table by the back way.
 *
 * Seeding is skipped for a key that already exists. Writes are append-only, so
 * a second run would otherwise record a fresh version of every value with
 * identical contents, padding each key's history with entries that never
 * changed anything.
 */
class KvEntrySeeder extends Seeder
{
    /**
     * @var array<string, array<string, int>>
     */
    private const RECORDS = [
        'bikeList' => ['honda' => 2, 'yamaha' => 7],
        'bookList' => ['fiction' => 12, 'history' => 5],
        'carList' => ['ford' => 3, 'ferrari' => 5],
        'cityList' => ['paris' => 1, 'tokyo' => 2, 'lima' => 3],
        'drinkList' => ['coffee' => 9, 'tea' => 4],
        'fruitList' => ['apple' => 6, 'banana' => 3, 'mango' => 8],
        'gameList' => ['chess' => 2, 'go' => 1],
        'herbList' => ['basil' => 5, 'mint' => 11],
        'instrumentList' => ['piano' => 1, 'guitar' => 4, 'drums' => 2],
        'metalList' => ['iron' => 14, 'copper' => 6],
        'petList' => ['cat' => 3, 'dog' => 5, 'fish' => 10],
        'planetList' => ['mars' => 1, 'venus' => 2],
        'toolList' => ['hammer' => 7, 'drill' => 2, 'saw' => 4],
        'treeList' => ['oak' => 8, 'pine' => 13, 'birch' => 3],
        'wineList' => ['merlot' => 4, 'shiraz' => 6],
    ];

    public function run(EloquentKeyValueRepository $repository): void
    {
        $recordedAt = now()->timestamp;

        foreach (self::RECORDS as $name => $counts) {
            $key = Key::fromString($name);

            if ($repository->findLatest($key->value) !== null) {
                continue;
            }

            // An object, not an associative array: the column is cast to
            // 'object', and the API's own contract stores {"honda":2} as an
            // object rather than a list.
            $repository->store($key->value, (object) $counts, $recordedAt);
        }
    }
}
