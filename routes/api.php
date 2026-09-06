<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CertificateController;
use App\Http\Controllers\Api\CourseContentController;
use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\EnrollmentController;
use App\Http\Controllers\Api\InstructorController;
use App\Http\Controllers\Api\LearnerContentController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PlatformController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\UserAdminController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/register', [AuthController::class, 'register']);
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:3,1');
    Route::post('auth/reset-password', [AuthController::class, 'resetPassword']);

    Route::get('courses', [CourseController::class, 'index']);
    Route::get('courses/{id}', [CourseController::class, 'show']);
    Route::get('courses/{id}/schedules', [ScheduleController::class, 'index']);

    // Public certificate registry (no auth) - future SPA verify page.
    Route::get('public/certificates/{reference}', [CertificateController::class, 'verifyPublicJson'])->name('certificates.public.json');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);

        // Own account: profile + security (all roles).
        Route::put('account/profile', [AccountController::class, 'updateProfile']);
        Route::put('account/password', [AccountController::class, 'updatePassword']);
        Route::post('account/avatar', [AccountController::class, 'uploadAvatar']);

        Route::post('enrollments', [EnrollmentController::class, 'apply']);
        Route::get('enrollments/mine', [EnrollmentController::class, 'mine']);
        Route::post('enrollments/{id}/cancel', [EnrollmentController::class, 'cancel']);
        Route::post('enrollments/{id}/complete', [EnrollmentController::class, 'complete']);

        Route::post('enrollments/{id}/pay/{feeType}', [PaymentController::class, 'initiate']);
        Route::get('payments', [PaymentController::class, 'index']);
        Route::get('payments/{paymentId}', [PaymentController::class, 'verify']);
        Route::get('payments/{paymentId}/receipt', [PaymentController::class, 'receipt']);

        Route::get('schedules/mine', [ScheduleController::class, 'mine']);

        Route::get('certificates/mine', [CertificateController::class, 'mine']);
        Route::post('enrollments/{enrollmentId}/certificate', [CertificateController::class, 'issue']);
        Route::get('certificates/{id}', [CertificateController::class, 'show']);
        Route::get('certificates/{id}/pdf', [CertificateController::class, 'pdf']);
        Route::get('certificates/{id}/download', [CertificateController::class, 'download']);

        // Watermarked SAMPLE of a course's certificate design (catalog). Not a
        // certificate: no record, no reference, no QR. Throttled against scraping.
        Route::get('courses/{courseId}/certificate-preview', [CertificateController::class, 'preview'])
            ->middleware('throttle:20,1')
            ->name('certificates.preview');

        // Learner course actions: submissions, attempts, progress
        Route::get('courses/{courseId}/content', [LearnerContentController::class, 'content']);
        Route::post('courses/{courseId}/submit/{type}/{typeId}', [LearnerContentController::class, 'submit']);
        Route::post('courses/{courseId}/attempt/{type}/{typeId}', [LearnerContentController::class, 'submitAttempt']);
        Route::post('courses/{courseId}/lessons/{lessonId}/progress', [LearnerContentController::class, 'markLesson']);
        Route::get('courses/{courseId}/progress', [LearnerContentController::class, 'progress']);

        Route::prefix('admin')->group(function () {
            Route::get('courses', [CourseController::class, 'manageIndex']);
            Route::get('enrollments', [EnrollmentController::class, 'adminIndex']);
            Route::post('enrollments/{id}/admit', [EnrollmentController::class, 'admit']);
            Route::post('enrollments/{id}/reject', [EnrollmentController::class, 'reject']);

            Route::post('courses', [CourseController::class, 'store']);
            Route::put('courses/{id}', [CourseController::class, 'update']);
            Route::delete('courses/{id}', [CourseController::class, 'destroy']);
            Route::post('courses/{courseId}/schedules', [ScheduleController::class, 'store']);
            Route::put('courses/{courseId}/schedules/{scheduleId}', [ScheduleController::class, 'update']);
            Route::delete('courses/{courseId}/schedules/{scheduleId}', [ScheduleController::class, 'destroy']);

            Route::get('instructors', [InstructorController::class, 'index']);
            Route::post('instructors', [InstructorController::class, 'store']);
            Route::put('instructors/{id}', [InstructorController::class, 'update']);
            Route::delete('instructors/{id}', [InstructorController::class, 'destroy']);

            Route::get('users', [UserAdminController::class, 'index']);
            Route::put('users/{id}', [UserAdminController::class, 'update']);

            Route::get('stats', [PlatformController::class, 'stats']);

            // Course content builder
            Route::get('courses/{courseId}/content', [CourseContentController::class, 'show']);
            Route::post('courses/{courseId}/sections', [CourseContentController::class, 'storeSection']);
            Route::put('courses/{courseId}/sections/{sectionId}', [CourseContentController::class, 'updateSection']);
            Route::delete('courses/{courseId}/sections/{sectionId}', [CourseContentController::class, 'destroySection']);
            Route::post('courses/{courseId}/lessons', [CourseContentController::class, 'storeLesson']);
            Route::put('courses/{courseId}/lessons/{lessonId}', [CourseContentController::class, 'updateLesson']);
            Route::delete('courses/{courseId}/lessons/{lessonId}', [CourseContentController::class, 'destroyLesson']);
            Route::post('courses/{courseId}/outcomes', [CourseContentController::class, 'storeOutcome']);
            Route::put('courses/{courseId}/outcomes/{outcomeId}', [CourseContentController::class, 'updateOutcome']);
            Route::delete('courses/{courseId}/outcomes/{outcomeId}', [CourseContentController::class, 'destroyOutcome']);
            Route::post('courses/{courseId}/resources', [CourseContentController::class, 'storeResource']);
            Route::put('courses/{courseId}/resources/{resourceId}', [CourseContentController::class, 'updateResource']);
            Route::delete('courses/{courseId}/resources/{resourceId}', [CourseContentController::class, 'destroyResource']);
            Route::post('courses/{courseId}/quizzes', [CourseContentController::class, 'storeQuiz']);
            Route::put('courses/{courseId}/quizzes/{quizId}', [CourseContentController::class, 'updateQuiz']);
            Route::delete('courses/{courseId}/quizzes/{quizId}', [CourseContentController::class, 'destroyQuiz']);
            Route::post('courses/{courseId}/exercises', [CourseContentController::class, 'storeExercise']);
            Route::put('courses/{courseId}/exercises/{exerciseId}', [CourseContentController::class, 'updateExercise']);
            Route::delete('courses/{courseId}/exercises/{exerciseId}', [CourseContentController::class, 'destroyExercise']);
            Route::post('courses/{courseId}/exams', [CourseContentController::class, 'storeExam']);
            Route::put('courses/{courseId}/exams/{examId}', [CourseContentController::class, 'updateExam']);
            Route::delete('courses/{courseId}/exams/{examId}', [CourseContentController::class, 'destroyExam']);
            Route::post('courses/{courseId}/assignments', [CourseContentController::class, 'storeAssignment']);
            Route::put('courses/{courseId}/assignments/{assignmentId}', [CourseContentController::class, 'updateAssignment']);
            Route::delete('courses/{courseId}/assignments/{assignmentId}', [CourseContentController::class, 'destroyAssignment']);

            // Bulk multiple-choice upload: Excel template + filled-file import.
            Route::get('courses/{courseId}/questions/template', [CourseContentController::class, 'templateQuestions']);
            Route::post('courses/{courseId}/{kind}/{parentId}/questions/import', [CourseContentController::class, 'importQuestions'])
                ->whereIn('kind', ['quiz', 'exercise', 'exam']);
            Route::put('courses/{courseId}/submissions/{submissionId}/grade', [CourseContentController::class, 'gradeSubmission']);
        });
    });

    Route::get('payments/pesapal/callback', [PaymentController::class, 'callback']);
    Route::post('payments/pesapal/callback', [PaymentController::class, 'callback']);
});