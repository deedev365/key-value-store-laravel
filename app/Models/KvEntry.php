<?php

namespace App\Models;

use App\Casts\AsKey;
use App\ValueObjects\Key;
use Illuminate\Database\Eloquent\Model;

/**
 * A single immutable version of a key's value. Rows are never updated or
 * deleted by the API — a write always inserts a new row, which is what
 * makes the store "version-controlled".
 *
 * $value is declared as mixed rather than left to the cast: 'object' decodes
 * with json_decode($json, false), which yields whatever JSON type was stored —
 * a string, a number, a bool, a list or a stdClass — not a stdClass every time.
 *
 * @property Key $key
 * @property mixed $value
 * @property int $recorded_at
 */
class KvEntry extends Model
{
    public $timestamps = true;

    protected $fillable = [
        'key',
        'value',
        'recorded_at',
    ];

    /**
     * 'object' rather than 'array': an associative decode collapses a JSON
     * object with consecutive numeric properties into a PHP list, so
     * {"0":"a","1":"b"} would come back out as ["a","b"]. Decoding to stdClass
     * keeps objects and arrays distinct on the way back.
     */
    protected $casts = [
        'key' => AsKey::class,
        'value' => 'object',
        'recorded_at' => 'integer',
    ];
}
