<?php

namespace App\Models;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use Database\Factories\CallFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'customer_id',
    'user_id',
    'phone',
    'caller_number_raw',
    'caller_number_normalized',
    'caller_name',
    'extension',
    'extension_number',
    'extension_id',
    'direction',
    'status',
    'unique_id',
    'asterisk_linkedid',
    'asterisk_uniqueid',
    'started_at',
    'answered_at',
    'ended_at',
    'duration',
    'duration_seconds',
])]
class Call extends Model
{
    /** @use HasFactory<CallFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => CallDirection::class,
            'status' => CallStatus::class,
            'started_at' => 'datetime',
            'answered_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration' => 'integer',
            'duration_seconds' => 'integer',
        ];
    }

    public function extensionRecord(): BelongsTo
    {
        return $this->belongsTo(Extension::class, 'extension_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(CallEvent::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function formattedDuration(): string
    {
        $duration = $this->duration_seconds ?? $this->duration;

        if ($duration === null) {
            return '—';
        }

        $hours = intdiv($duration, 3600);
        $minutes = intdiv($duration % 3600, 60);
        $seconds = $duration % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $seconds);
        }

        return sprintf('%d:%02d', $minutes, $seconds);
    }
}
