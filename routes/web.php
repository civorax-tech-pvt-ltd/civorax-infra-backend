<?php

use App\Http\Controllers\CertificateController;
use App\Http\Controllers\Site\CostReportPrintController;
use App\Http\Controllers\Site\LedgerPrintController;
use App\Http\Controllers\Site\MusterRollPrintController;
use App\Http\Controllers\Site\SiteAppController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('verify/{code}', [CertificateController::class, 'verify'])
    ->middleware('throttle:30,1')
    ->name('certificates.verify');

Route::get('certificates/{certificate}', [CertificateController::class, 'show'])
    ->middleware('auth')
    ->name('certificates.show');

Route::middleware('auth')->prefix('site')->name('site.')->group(function () {
    Route::get('muster-rolls/{musterRoll}/print', MusterRollPrintController::class)->name('muster-rolls.print');
    Route::get('labourers/{labourer}/ledger', [LedgerPrintController::class, 'labourer'])->name('labourers.ledger');
    Route::get('naikes/{contractor}/ledger', [LedgerPrintController::class, 'contractor'])->name('naikes.ledger');
    Route::get('projects/{project}/costs', CostReportPrintController::class)->name('projects.costs');
    Route::get('vendors/{vendor}/statement', [LedgerPrintController::class, 'vendor'])->name('vendors.statement');

    // Offline-capable site app (see public/site-sw.js).
    Route::get('app', [SiteAppController::class, 'show'])->name('app');
    Route::get('app/data', [SiteAppController::class, 'data'])->name('app.data');
    Route::post('app/attendance', [SiteAppController::class, 'storeAttendance'])->name('app.attendance');
    Route::post('app/reports', [SiteAppController::class, 'storeReport'])->name('app.reports');
});
