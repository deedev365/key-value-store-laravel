<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A single immutable version of a key's value. The API never updates or
 * deletes a row — a write always inserts a new one, which is what makes the
 * store "version-controlled".
 *
 * The properties below are what the model hands out, not the column types:
 * `value` is a json column cast to 'object', so it comes back as whatever
 * JSON type was stored, and 'object' rather than 'array' is what keeps
 * {"0":"a","1":"b"} from decoding into the list ["a","b"].
 *
 * @property int $id
 * @property string $key
 * @property mixed $value
 * @property int $recorded_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class KvEntry extends Model
{
    public $timestamps = true;

    protected $fillable = [
        'key',
        'value',
        'recorded_at',
    ];

    protected $casts = [
        'value' => 'object',
        'recorded_at' => 'integer',
    ];
}
