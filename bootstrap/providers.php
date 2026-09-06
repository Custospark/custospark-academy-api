<?php

use App\Providers\AcademyServiceProvider;
use App\Providers\AppServiceProvider;
use Barryvdh\DomPDF\ServiceProvider as DomPDFServiceProvider;

return [
    AppServiceProvider::class,
    AcademyServiceProvider::class,
    DomPDFServiceProvider::class,
];