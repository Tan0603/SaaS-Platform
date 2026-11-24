<?php

namespace App\Models;

use App\Enums\InvoiceState;
use App\Enums\InvoiceStatus;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * `state`, `status`, totals, `amount_paid_cents` and the lifecycle timestamps are deliberately NOT
     * fillable: they are written only by dedicated actions / Invoice::recalculatePaymentState().
     */
    protected $fillable = [
        'customer_id',
        'issue_date',
        'due_date',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'due_date' => 'date',
            'state' => InvoiceState::class,
            'status' => InvoiceStatus::class,
            'sequence' => 'integer',
            'subtotal_cents' => 'integer',
            'total_cents' => 'integer',
            'amount_paid_cents' => 'integer',
            'issued_at' => 'datetime',
            'voided_at' => 'datetime',
            'overdue_notified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /** @return HasMany<InvoiceItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('position');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasMany<InvoiceEmailLog, $this> */
    public function emailLogs(): HasMany
    {
        return $this->hasMany(InvoiceEmailLog::class);
    }

    /**
     * Overdue = issued AND past due AND not fully paid. Computed on the fly, never stored.
     *
     * @param  Builder<Invoice>  $query
     */
    public function scopeOverdue(Builder $query): void
    {
        $query->where('state', InvoiceState::Issued)
            ->whereDate('due_date', '<', today())
            ->where('status', '!=', InvoiceStatus::Paid);
    }

    /** @param  Builder<Invoice>  $query */
    public function scopeIssued(Builder $query): void
    {
        $query->where('state', InvoiceState::Issued);
    }

    /** @param  Builder<Invoice>  $query */
    public function scopeOutstanding(Builder $query): void
    {
        $query->issued()->where('status', '!=', InvoiceStatus::Paid);
    }

    protected function isOverdue(): Attribute
    {
        return Attribute::get(fn (): bool => $this->state === InvoiceState::Issued
            && $this->status !== InvoiceStatus::Paid
            && $this->due_date->lt(today()));
    }

    /** What the UI shows: draft / void / overdue / unpaid / partial / paid. */
    protected function displayStatus(): Attribute
    {
        return Attribute::get(function (): string {
            if ($this->state !== InvoiceState::Issued) {
                return $this->state->value;
            }

            return $this->is_overdue ? 'overdue' : $this->status->value;
        });
    }

    protected function balanceCents(): Attribute
    {
        return Attribute::get(fn (): int => $this->total_cents - $this->amount_paid_cents);
    }
}
