<?php

namespace App\Observers;

use App\Models\Invoice;
use App\Services\DashboardCache;

/** Any change to a invoice can change the dashboard numbers: drop the tenant's cached stats after commit. */
class InvoiceObserver
{
    public function created(Invoice $invoice): void
    {
        DashboardCache::flushAfterCommit();
    }

    public function updated(Invoice $invoice): void
    {
        DashboardCache::flushAfterCommit();
    }

    public function deleted(Invoice $invoice): void
    {
        DashboardCache::flushAfterCommit();
    }
}
