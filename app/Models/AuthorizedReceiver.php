<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuthorizedReceiver extends Model
{
    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean'];

    public function child(): BelongsTo    { return $this->belongsTo(Child::class); }
    public function guardian(): BelongsTo { return $this->belongsTo(Guardian::class); }
}
