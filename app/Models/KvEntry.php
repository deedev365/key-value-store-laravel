<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A single immutable version of a key's value. No row is ever modified: a
 * write always inserts a new one, which is what makes the store
 * "version-controlled". There is no UPDATE anywhere in the application.
 *
 * Rows are only removed whole. `DELETE /object/{key}` drops every version of
 * a key, and `PUT /object/{key}` removes exactly the one version it appends a
 * correction for, in a single transaction.
 *
 * The properties below are what the model hands out, not the column types:
 * `value` is a json column cast to 'object', so it comes back as whatever
 * JSON type was stored, and 'object' rather than 'array' is what keeps
 * {"0":"a","1":"b"} from decoding into the list ["a","b"].
 *
 * `publish_time` is UNIX seconds like `recorded_at`, and null there is a
 * meaning rather than a gap: no schedule, so the version is live from the
 * moment it was written.
 *
 * @property int $id
 * @property string $key
 * @property mixed $value
 * @property int $recorded_at
 * @property int|null $publish_time
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
        'publish_time',
    ];

    protected $casts = [
        'value' => 'object',
        'recorded_at' => 'integer',
        'publish_time' => 'integer',
    ];
}
