<?php

namespace App\Observers;

use App\Models\Payment;
use App\Services\DashboardCache;

/** Any change to a payment can change the dashboard numbers: drop the tenant's cached stats after commit. */
class PaymentObserver
{
    public function created(Payment $payment): void
    {
        DashboardCache::flushAfterCommit();
    }

    public function updated(Payment $payment): void
    {
        DashboardCache::flushAfterCommit();
    }

    public function deleted(Payment $payment): void
    {
        DashboardCache::flushAfterCommit();
    }
}
