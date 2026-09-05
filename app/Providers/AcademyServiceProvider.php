<?php

declare(strict_types=1);

namespace App\Providers;

use App\Repositories\Contracts\CertificateRepositoryInterface;
use App\Repositories\Contracts\CourseFeeRepositoryInterface;
use App\Repositories\Contracts\CourseRepositoryInterface;
use App\Repositories\Contracts\EnrollmentRepositoryInterface;
use App\Repositories\Contracts\PaymentJournalRepositoryInterface;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Repositories\Contracts\ScheduleRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Eloquent\CertificateRepository;
use App\Repositories\Eloquent\CourseFeeRepository;
use App\Repositories\Eloquent\CourseRepository;
use App\Repositories\Eloquent\EnrollmentRepository;
use App\Repositories\Eloquent\PaymentJournalRepository;
use App\Repositories\Eloquent\PaymentRepository;
use App\Repositories\Eloquent\ScheduleRepository;
use App\Repositories\Eloquent\UserRepository;
use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Services\Payment\Gateways\PesaPalGateway;
use Illuminate\Support\ServiceProvider;

class AcademyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(UserRepositoryInterface::class, UserRepository::class);
        $this->app->bind(CourseRepositoryInterface::class, CourseRepository::class);
        $this->app->bind(CourseFeeRepositoryInterface::class, CourseFeeRepository::class);
        $this->app->bind(EnrollmentRepositoryInterface::class, EnrollmentRepository::class);
        $this->app->bind(PaymentRepositoryInterface::class, PaymentRepository::class);
        $this->app->bind(ScheduleRepositoryInterface::class, ScheduleRepository::class);
        $this->app->bind(CertificateRepositoryInterface::class, CertificateRepository::class);
        $this->app->bind(PaymentJournalRepositoryInterface::class, PaymentJournalRepository::class);

        $this->app->singleton(PaymentGatewayInterface::class, PesaPalGateway::class);
    }

    public function boot(): void
    {
        //
    }
}