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

    protected $casts = [
        'value' => 'array',
        'recorded_at' => 'integer',
    ];
}
