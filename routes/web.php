<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Client as Portal;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\PasswordController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => auth()->check() ? redirect()->route(auth()->user()->isAdmin() ? 'admin.dashboard' : 'portal.dashboard') : redirect()->route('login'))->name('home');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login.attempt');
    Route::get('/forgot-password', [PasswordController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordController::class, 'email'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordController::class, 'reset'])->name('password.reset');
    Route::post('/reset-password', [PasswordController::class, 'update'])->name('password.update');
    Route::get('/set-password/{token}', [PasswordController::class, 'invite'])->name('password.invite');
    Route::post('/set-password', [PasswordController::class, 'acceptInvite'])->middleware('throttle:10,1')->name('password.invite.update');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::post('/preview/stop', [Admin\ClientPreviewController::class, 'stop'])->name('preview.stop');
    Route::get('/account', [PasswordController::class, 'edit'])->name('account');
    Route::patch('/account/password', [PasswordController::class, 'change'])->name('account.password');

    // Printable documents and private files: both roles, access checked inside.
    Route::get('/documents/quotations/{quotation}/print', [DocumentController::class, 'quotation'])->name('documents.quotation');
    Route::get('/documents/invoices/{invoice}/print', [DocumentController::class, 'invoice'])->name('documents.invoice');
    Route::get('/documents/contracts/{contract}/print', [DocumentController::class, 'contract'])->name('documents.contract');
    Route::get('/documents/clients/{client}/statement', [DocumentController::class, 'statement'])->name('documents.statement');
    Route::get('/files/payments/{payment}/proof', [FileController::class, 'proof'])->name('files.proof');
    Route::get('/files/contracts/{contract}/document', [FileController::class, 'contract'])->name('files.contract');
});

/*
| Admin (Code Canvas) area.
*/
Route::middleware(['auth', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', Admin\DashboardController::class)->name('dashboard');

    Route::resource('clients', Admin\ClientController::class)->except('destroy');
    Route::post('clients/{client}/users', [Admin\ClientUserController::class, 'store'])->name('clients.users.store');
    Route::patch('clients/{client}/users/{user}', [Admin\ClientUserController::class, 'update'])->name('clients.users.update');
    Route::delete('clients/{client}/users/{user}', [Admin\ClientUserController::class, 'destroy'])->name('clients.users.destroy');
    Route::post('clients/{client}/preview', [Admin\ClientPreviewController::class, 'start'])->name('clients.preview');

    Route::resource('contracts', Admin\ContractController::class);
    Route::patch('contracts/{contract}/signature', [Admin\ContractController::class, 'signature'])->name('contracts.signature');
    Route::patch('contracts/{contract}/lifecycle', [Admin\ContractController::class, 'lifecycle'])->name('contracts.lifecycle');
    Route::post('contracts/{contract}/renew', [Admin\ContractController::class, 'renew'])->name('contracts.renew');
    Route::post('contracts/{contract}/remind', [Admin\ContractController::class, 'remind'])->middleware('throttle:20,1')->name('contracts.remind');
    Route::get('contracts/{contract}/versions/{version}', [Admin\ContractController::class, 'version'])->whereNumber('version')->name('contracts.version');
    Route::get('contracts/{contract}/activity', [Admin\ContractController::class, 'activity'])->name('contracts.activity');

    Route::get('reports/revenue', Admin\RevenueReportController::class)->name('reports.revenue');
    Route::get('settings', [Admin\SettingsController::class, 'terms'])->name('settings.terms');
    Route::put('settings/terms', [Admin\SettingsController::class, 'update'])->name('settings.terms.update');
    Route::put('settings/rates', [Admin\SettingsController::class, 'rates'])->name('settings.rates');
    Route::delete('settings/terms', [Admin\SettingsController::class, 'reset'])->name('settings.terms.reset');

    Route::get('reports/banking', [Admin\ReportController::class, 'banking'])->name('reports.banking');
    Route::get('reports/banking/export', [Admin\ReportController::class, 'export'])->name('reports.banking.export');
    Route::resource('quotations', Admin\QuotationController::class);
    Route::patch('quotations/{quotation}/status', [Admin\QuotationController::class, 'status'])->name('quotations.status');
    Route::post('quotations/{quotation}/invoice', [Admin\QuotationController::class, 'toInvoice'])->name('quotations.invoice');
    Route::post('quotations/{quotation}/contract', [Admin\QuotationController::class, 'toContract'])->name('quotations.contract');
    Route::post('quotations/{quotation}/remind', [Admin\QuotationController::class, 'remind'])->middleware('throttle:20,1')->name('quotations.remind');

    Route::resource('invoices', Admin\InvoiceController::class);
    Route::patch('invoices/{invoice}/status', [Admin\InvoiceController::class, 'status'])->name('invoices.status');
    Route::post('invoices/{invoice}/remind', [Admin\InvoiceController::class, 'remind'])->middleware('throttle:20,1')->name('invoices.remind');
    Route::post('invoices/{invoice}/credit-note', [Admin\AdjustmentController::class, 'creditNote'])->name('invoices.credit');
    Route::post('invoices/{invoice}/late-fee', [Admin\AdjustmentController::class, 'lateFee'])->name('invoices.late-fee');
    Route::post('invoices/{invoice}/refund', [Admin\AdjustmentController::class, 'refundInvoice'])->name('invoices.refund');
    Route::post('contracts/{contract}/refund', [Admin\AdjustmentController::class, 'refundContract'])->name('contracts.refund');

    Route::get('payments', [Admin\PaymentController::class, 'index'])->name('payments.index');
    Route::get('payments/create', [Admin\PaymentController::class, 'create'])->name('payments.create');
    Route::post('payments', [Admin\PaymentController::class, 'store'])->name('payments.store');
    Route::get('payments/{payment}', [Admin\PaymentController::class, 'show'])->name('payments.show');
    Route::patch('payments/{payment}/verify', [Admin\PaymentController::class, 'verify'])->name('payments.verify');
    Route::patch('payments/{payment}/reject', [Admin\PaymentController::class, 'reject'])->name('payments.reject');
    Route::delete('payments/{payment}', [Admin\PaymentController::class, 'destroy'])->name('payments.destroy');
});

/*
| Client portal.
*/
Route::middleware(['auth', 'role:client'])->prefix('portal')->name('portal.')->group(function () {
    Route::get('/', Portal\DashboardController::class)->name('dashboard');
    Route::get('welcome', [Portal\WelcomeController::class, 'show'])->name('welcome');
    Route::post('welcome', [Portal\WelcomeController::class, 'complete'])->name('welcome.complete');
    Route::get('contracts', [Portal\ContractController::class, 'index'])->name('contracts.index');
    Route::get('contracts/{contract}', [Portal\ContractController::class, 'show'])->name('contracts.show');
    Route::get('contracts/{contract}/sign', [Portal\ContractController::class, 'sign'])->name('contracts.sign');
    Route::post('contracts/{contract}/sign', [Portal\ContractController::class, 'storeSignature'])->middleware('throttle:10,1')->name('contracts.sign.store');
    Route::get('quotations', [Portal\QuotationController::class, 'index'])->name('quotations.index');
    Route::get('quotations/{quotation}', [Portal\QuotationController::class, 'show'])->name('quotations.show');
    Route::patch('quotations/{quotation}/answer', [Portal\QuotationController::class, 'answer'])->name('quotations.answer');
    Route::get('invoices', [Portal\InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('invoices/{invoice}', [Portal\InvoiceController::class, 'show'])->name('invoices.show');
    Route::get('payments', [Portal\PaymentController::class, 'index'])->name('payments.index');
    Route::get('payments/new', [Portal\PaymentController::class, 'create'])->name('payments.create');
    Route::post('payments', [Portal\PaymentController::class, 'store'])->middleware('throttle:6,1')->name('payments.store');
    Route::get('payments/{payment}', [Portal\PaymentController::class, 'show'])->name('payments.show');
});
