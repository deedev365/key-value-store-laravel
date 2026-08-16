<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A single immutable version of a key's value. Rows are never updated or
 * deleted by the API — a write always inserts a new row, which is what
 * makes the store "version-controlled".
 */
class KvEntry extends Model
{
    use HasFactory;

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
        'value' => 'object',
        'recorded_at' => 'integer',
    ];
}
