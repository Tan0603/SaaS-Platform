<?php

use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\PaymentController;
use App\Http\Middleware\InitializeTenancyByUser;
use Illuminate\Support\Facades\Route;

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
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::resource('customers', CustomerController::class);
    Route::patch('customers/{customer}/restore', [CustomerController::class, 'restore'])->withTrashed()->name('customers.restore');
    Route::delete('customers/{customer}/permanent', [CustomerController::class, 'forceDestroy'])->withTrashed()->name('customers.force-destroy');

    Route::resource('invoices', InvoiceController::class);
    Route::post('invoices/{invoice}/issue', [InvoiceController::class, 'issue'])->name('invoices.issue');
    Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void'])->name('invoices.void');

    Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
    Route::post('invoices/{invoice}/payments', [PaymentController::class, 'store'])->name('invoices.payments.store');
    Route::post('payments/{payment}/void', [PaymentController::class, 'void'])->name('payments.void');

    require __DIR__.'/settings.php';
});
