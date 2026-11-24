<?php

namespace App\Models;

use App\Enums\InvoiceEmailEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

class InvoiceEmailLog extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'invoice_id',
        'event',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'event' => InvoiceEmailEvent::class,
            'sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
