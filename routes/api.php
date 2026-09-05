<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CertificateController;
use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\EnrollmentController;
use App\Http\Controllers\Api\InstructorController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ScheduleController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/register', [AuthController::class, 'register']);
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:3,1');
    Route::post('auth/reset-password', [AuthController::class, 'resetPassword']);

    Route::get('courses', [CourseController::class, 'index']);
    Route::get('courses/{id}', [CourseController::class, 'show']);
    Route::get('courses/{id}/schedules', [ScheduleController::class, 'index']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);

        Route::post('enrollments', [EnrollmentController::class, 'apply']);
        Route::get('enrollments/mine', [EnrollmentController::class, 'mine']);
        Route::post('enrollments/{id}/cancel', [EnrollmentController::class, 'cancel']);
        Route::post('enrollments/{id}/complete', [EnrollmentController::class, 'complete']);

        Route::post('enrollments/{id}/pay/{feeType}', [PaymentController::class, 'initiate']);
        Route::get('payments/{paymentId}', [PaymentController::class, 'verify']);

        Route::get('certificates/mine', [CertificateController::class, 'mine']);
        Route::post('enrollments/{enrollmentId}/certificate', [CertificateController::class, 'issue']);
        Route::get('certificates/{id}', [CertificateController::class, 'show']);

        Route::prefix('admin')->group(function () {
            Route::get('courses', [CourseController::class, 'manageIndex']);
            Route::get('enrollments', [EnrollmentController::class, 'adminIndex']);
            Route::post('enrollments/{id}/admit', [EnrollmentController::class, 'admit']);
            Route::post('enrollments/{id}/reject', [EnrollmentController::class, 'reject']);

            Route::post('courses', [CourseController::class, 'store']);
            Route::put('courses/{id}', [CourseController::class, 'update']);
            Route::delete('courses/{id}', [CourseController::class, 'destroy']);
            Route::post('courses/{courseId}/schedules', [ScheduleController::class, 'store']);

            Route::get('instructors', [InstructorController::class, 'index']);
            Route::post('instructors', [InstructorController::class, 'store']);
            Route::put('instructors/{id}', [InstructorController::class, 'update']);
            Route::delete('instructors/{id}', [InstructorController::class, 'destroy']);
        });
    });

    Route::get('payments/pesapal/callback', [PaymentController::class, 'callback']);
    Route::post('payments/pesapal/callback', [PaymentController::class, 'callback']);
});