<?php

namespace App\Http\Controllers;

use App\Services\DashboardCache;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        $data = DashboardCache::get();

        return Inertia::render('Dashboard', [
            'stats' => $data['stats'],
            'charts' => $data['charts'],
            'month' => ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->endOfMonth()->toDateString()],
        ]);
    }
}
