<?php

namespace App\Console\Commands;

use App\Enums\InvoiceEmailEvent;
use App\Jobs\SendInvoiceEmail;
use App\Models\Invoice;
use App\Services\DashboardCache;
use Illuminate\Console\Command;

/**
 * Daily job: records that an overdue invoice has been noticed and queues its reminder email once.
 *
 * It NEVER changes `status` (overdue is computed, see docs/ARCHITECTURE.md) and `overdue_notified_at` is only
 * a "reminder queued" marker. It must run inside a tenant: schedule it through `tenants:run`, which initialises
 * each tenant in turn so Invoice::overdue() only sees that tenant's invoices.
 */
class MarkOverdueInvoices extends Command
{
    protected $signature = 'invoices:mark-overdue';

    protected $description = 'Queue the overdue reminder for the current tenant\'s newly overdue invoices (run via tenants:run)';

    public function handle(): int
    {
        if (! tenancy()->initialized) {
            $this->error('No tenant context. Run this command through: php artisan tenants:run invoices:mark-overdue');

            return self::FAILURE;
        }

        $count = 0;

        Invoice::query()
            ->overdue()
            ->whereNull('overdue_notified_at')
            ->chunkById(500, function ($invoices) use (&$count) {
                foreach ($invoices as $invoice) {
                    $invoice->forceFill(['overdue_notified_at' => now()])->saveQuietly();

                    SendInvoiceEmail::dispatch($invoice->id, InvoiceEmailEvent::Overdue);
                    $count++;
                }
            });

        // saveQuietly() skips the observers, so drop the cached dashboard explicitly.
        DashboardCache::flush();

        $this->info('Tenant '.tenant('id').": {$count} overdue invoice(s) queued for a reminder.");

        return self::SUCCESS;
    }
}
