<?php

use App\Http\Controllers\Api\CertificateController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Public certificate verification - no authentication. Anyone holding the
// reference can confirm a certificate against the academy registry.
Route::get('verify/{reference}', [CertificateController::class, 'verifyPublic'])->name('certificates.verify');
Route::get('verify/{reference}/pdf', [CertificateController::class, 'publicPdf'])->name('certificates.verify.pdf');