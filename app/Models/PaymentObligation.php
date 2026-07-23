<?php

namespace App\Models;

use Database\Factories\PaymentObligationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['enrollment_code', 'concept', 'amount', 'due_date'])]
class PaymentObligation extends Model
{
    /** @use HasFactory<PaymentObligationFactory> */
    use HasFactory, HasUuids;

    protected $primaryKey = 'code';

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class, 'enrollment_code', 'code');
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'due_date' => 'date',
        ];
    }
}
