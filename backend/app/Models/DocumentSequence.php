<?php

namespace App\Models;

use App\Enums\SequenceResetPeriod;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentSequence extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'branch_id',
        'document_type',
        'prefix',
        'next_sequence',
        'padding',
        'reset_period',
        'period_start',
    ];

    protected function casts(): array
    {
        return [
            'next_sequence' => 'integer',
            'padding' => 'integer',
            'reset_period' => SequenceResetPeriod::class,
            'period_start' => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
