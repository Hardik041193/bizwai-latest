<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiQueryLog extends Model
{
    protected $table = 'ai_query_logs';

    protected $fillable = [
        'user_id',
        'realm_id',
        'question',
        'provider',
        'tool_calls',
        'response',
        'execution_time_ms',
        'error',
    ];

    protected $casts = [
        'tool_calls' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
