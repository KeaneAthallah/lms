<?php

use App\Http\Controllers\AdminCategoryController;
use App\Http\Controllers\AdminCertificateController;
use App\Http\Controllers\AdminCourseController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AdminEnrollmentController;
use App\Http\Controllers\AdminRoleController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AppController;
use App\Http\Controllers\AssignmentStudentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CertificateController;
use App\Http\Controllers\CertificateVerifyController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\GradeController;
use App\Http\Controllers\InstructorAnalyticsController;
use App\Http\Controllers\InstructorAssignmentController;
use App\Http\Controllers\InstructorCourseController;
use App\Http\Controllers\InstructorDashboardController;
use App\Http\Controllers\InstructorLessonController;
use App\Http\Controllers\InstructorMaterialController;
use App\Http\Controllers\InstructorQuizController;
use App\Http\Controllers\InstructorSectionController;
use App\Http\Controllers\InstructorStudentController;
use App\Http\Controllers\InstructorSubmissionController;
use App\Http\Controllers\LearningController;
use App\Http\Controllers\LessonProgressController;
use App\Http\Controllers\MaterialController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QuizStudentController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SPA shell + certificate verification pages
|--------------------------------------------------------------------------
|
| All JSON API routes live under the web middleware group below so that
| the session guard and CSRF protection apply. The SPA is served from a
| single Blade shell; certificate verification is a public Blade page.
|
*/

Route::get('/certificates/verify/{identifier}', [CertificateVerifyController::class, 'verify'])
    ->name('certificates.verify');
Route::get('/certificates/{identifier}', [CertificateVerifyController::class, 'show'])
    ->name('certificates.show');

Route::middleware('guest')->group(function (): void {
    Route::post('/api/register', [AuthController::class, 'register']);
    Route::post('/api/login', [AuthController::class, 'login']);
});

Route::post('/api/logout', [AuthController::class, 'logout'])->middleware('auth');
Route::get('/api/me', [AuthController::class, 'me'])->middleware('auth');

Route::prefix('api')->group(function (): void {
    Route::get('/courses', [CourseController::class, 'index']);
    Route::get('/courses/categories', [CourseController::class, 'categories']);
    Route::get('/courses/{course:slug}', [CourseController::class, 'show'])
        ->middleware(['throttle:60,1']);

    Route::middleware('auth')->group(function (): void {
        Route::match(['put', 'post'], '/profile', [ProfileController::class, 'update']);

        Route::get('/dashboard', [DashboardController::class, 'student']);

        Route::post('/courses/{course:slug}/enroll', [EnrollmentController::class, 'store']);
        Route::get('/courses/{course:slug}/learn', [LearningController::class, 'show']);
        Route::get('/courses/{course:slug}/learn/{lesson}', [LearningController::class, 'showLesson']);
        Route::patch('/lessons/{lesson}/progress', [LessonProgressController::class, 'update']);
        Route::get('/lessons/{lesson}/materials/{material}/download', [MaterialController::class, 'download']);

        Route::get('/courses/{course:slug}/assignments', [AssignmentStudentController::class, 'index']);
        Route::get('/assignments/{assignment}', [AssignmentStudentController::class, 'show']);
        Route::get('/assignments/{assignment}/my-submission', [AssignmentStudentController::class, 'mySubmission']);
        Route::post('/assignments/{assignment}/submit', [AssignmentStudentController::class, 'submit']);

        Route::get('/quizzes/{quiz}', [QuizStudentController::class, 'show']);
        Route::post('/quizzes/{quiz}/start', [QuizStudentController::class, 'start']);
        Route::get('/quiz-attempts/{attempt}', [QuizStudentController::class, 'showAttempt']);
        Route::post('/quiz-attempts/{attempt}/submit', [QuizStudentController::class, 'submit']);

        Route::get('/grades', [GradeController::class, 'index']);

        Route::get('/certificates', [CertificateController::class, 'index']);
        Route::get('/certificates/{certificate}', [CertificateController::class, 'show']);

        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
    });
});

Route::prefix('api')->middleware(['auth', 'role:instructor'])->group(function (): void {
    Route::get('/instructor/dashboard', [InstructorDashboardController::class, 'show']);

    Route::get('/instructor/courses', [InstructorCourseController::class, 'index']);
    Route::post('/instructor/courses', [InstructorCourseController::class, 'store']);
    Route::get('/instructor/courses/{course:slug}', [InstructorCourseController::class, 'show']);
    Route::put('/instructor/courses/{course:slug}', [InstructorCourseController::class, 'update']);
    Route::patch('/instructor/courses/{course:slug}/status/{status}', [InstructorCourseController::class, 'status']);
    Route::delete('/instructor/courses/{course:slug}', [InstructorCourseController::class, 'destroy']);

    Route::post('/instructor/courses/{course:slug}/sections', [InstructorSectionController::class, 'store']);
    Route::put('/instructor/courses/{course:slug}/sections/reorder', [InstructorSectionController::class, 'reorder']);
    Route::put('/instructor/courses/{course:slug}/sections/{section}', [InstructorSectionController::class, 'update']);
    Route::delete('/instructor/courses/{course:slug}/sections/{section}', [InstructorSectionController::class, 'destroy']);

    Route::post('/instructor/courses/{course:slug}/sections/{section}/lessons', [InstructorLessonController::class, 'store']);
    Route::put('/instructor/courses/{course:slug}/lessons/{lesson}', [InstructorLessonController::class, 'update']);
    Route::delete('/instructor/courses/{course:slug}/lessons/{lesson}', [InstructorLessonController::class, 'destroy']);

    Route::post('/instructor/courses/{course:slug}/lessons/{lesson}/materials', [InstructorMaterialController::class, 'store']);
    Route::delete('/instructor/courses/{course:slug}/lessons/{lesson}/materials/{material}', [InstructorMaterialController::class, 'destroy']);

    Route::post('/instructor/courses/{course:slug}/lessons/{lesson}/quiz', [InstructorQuizController::class, 'store']);
    Route::get('/instructor/courses/{course:slug}/quizzes/{quiz}', [InstructorQuizController::class, 'show']);
    Route::put('/instructor/courses/{course:slug}/quizzes/{quiz}', [InstructorQuizController::class, 'update']);
    Route::delete('/instructor/courses/{course:slug}/quizzes/{quiz}', [InstructorQuizController::class, 'destroy']);
    Route::post('/instructor/courses/{course:slug}/quizzes/{quiz}/questions', [InstructorQuizController::class, 'storeQuestion']);
    Route::put('/instructor/courses/{course:slug}/quizzes/{quiz}/questions/{question}', [InstructorQuizController::class, 'updateQuestion']);
    Route::delete('/instructor/courses/{course:slug}/quizzes/{quiz}/questions/{question}', [InstructorQuizController::class, 'destroyQuestion']);

    Route::post('/instructor/courses/{course:slug}/assignments', [InstructorAssignmentController::class, 'store']);
    Route::put('/instructor/courses/{course:slug}/assignments/{assignment}', [InstructorAssignmentController::class, 'update']);
    Route::delete('/instructor/courses/{course:slug}/assignments/{assignment}', [InstructorAssignmentController::class, 'destroy']);

    Route::get('/instructor/courses/{course:slug}/students', [InstructorStudentController::class, 'index']);
    Route::get('/instructor/courses/{course:slug}/students/{student}/submissions', [InstructorSubmissionController::class, 'studentSubmissions']);

    Route::get('/instructor/courses/{course:slug}/submissions', [InstructorSubmissionController::class, 'index']);
    Route::get('/instructor/courses/{course:slug}/submissions/{submission}', [InstructorSubmissionController::class, 'show']);
    Route::post('/instructor/courses/{course:slug}/submissions/{submission}/grade', [InstructorSubmissionController::class, 'grade']);
    Route::get('/instructor/courses/{course:slug}/submissions/{submission}/files/{fileIndex}', [InstructorSubmissionController::class, 'downloadFile']);

    Route::get('/instructor/courses/{course:slug}/analytics', [InstructorAnalyticsController::class, 'show']);
});

Route::prefix('api')->middleware(['auth', 'role:admin'])->group(function (): void {
    Route::get('/admin/dashboard', [AdminDashboardController::class, 'show']);

    Route::get('/admin/users', [AdminUserController::class, 'index']);
    Route::post('/admin/users', [AdminUserController::class, 'store']);
    Route::put('/admin/users/{user}', [AdminUserController::class, 'update']);
    Route::delete('/admin/users/{user}', [AdminUserController::class, 'destroy']);

    Route::get('/admin/roles', [AdminRoleController::class, 'index']);
    Route::post('/admin/roles', [AdminRoleController::class, 'store']);
    Route::put('/admin/roles/{role}', [AdminRoleController::class, 'update']);
    Route::delete('/admin/roles/{role}', [AdminRoleController::class, 'destroy']);

    Route::get('/admin/categories', [AdminCategoryController::class, 'index']);
    Route::post('/admin/categories', [AdminCategoryController::class, 'store']);
    Route::put('/admin/categories/{category}', [AdminCategoryController::class, 'update']);
    Route::delete('/admin/categories/{category}', [AdminCategoryController::class, 'destroy']);

    Route::get('/admin/courses', [AdminCourseController::class, 'index']);
    Route::get('/admin/courses/{course:slug}', [AdminCourseController::class, 'show']);
    Route::delete('/admin/courses/{course:slug}', [AdminCourseController::class, 'destroy']);

    Route::get('/admin/enrollments', [AdminEnrollmentController::class, 'index']);
    Route::delete('/admin/enrollments/{enrollment}', [AdminEnrollmentController::class, 'destroy']);

    Route::get('/admin/certificates', [AdminCertificateController::class, 'index']);
});

Route::get('/', [AppController::class, 'index'])->name('app');

Route::fallback(function (Request $request) {
    if ($request->is('api/*') || $request->expectsJson()) {
        return response()->json(['message' => 'Not found.'], 404);
    }

    return app(AppController::class)->index($request);
});
