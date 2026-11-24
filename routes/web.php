<?php

use App\Http\Middleware\InitializeTenancyByUser;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Application routes (one site: http://localhost:8000)
|--------------------------------------------------------------------------
| Guests only see the login page. Every other route sits behind `auth` and
| InitializeTenancyByUser, which switches tenancy on from the logged-in user.
*/

Route::redirect('/', '/dashboard')->name('home');

// Login/logout/confirm-password; the login page itself runs without tenancy.
require __DIR__.'/auth.php';

Route::middleware(['auth', InitializeTenancyByUser::class])->group(function () {
    Route::get('dashboard', fn () => Inertia::render('Dashboard'))->name('dashboard');

    require __DIR__.'/settings.php';
});
