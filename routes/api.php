<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\TeacherController;
use App\Http\Controllers\ParentController;
use App\Http\Controllers\ClassroomController;
use App\Http\Controllers\HolidayController;
use App\Http\Controllers\InventoryController;

use App\Http\Controllers\ProgramController;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\ClassSessionController;
use App\Http\Controllers\ClassScheduleController;

use App\Http\Controllers\StudentAttendanceController;
use App\Http\Controllers\TeacherLogbookController;
use App\Http\Controllers\AssessmentController;
use App\Http\Controllers\FinalReportController;
use App\Http\Controllers\CertificateController;

use App\Http\Controllers\PaymentPlanController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\TeacherPayrollController;

use App\Http\Controllers\AnnouncementController;

/*
|--------------------------------------------------------------------------
| BSD After School Club - API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    // ==========================================
    // 1. PUBLIC ROUTES (Tanpa Autentikasi)
    // ==========================================
    Route::post('/auth/login',    [AuthController::class, 'login']);
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::get('/announcements',  [AnnouncementController::class, 'index']);
    Route::post('/payments/xendit/callback', [PaymentController::class, 'xenditCallback']);

    // ==========================================
    // 2. PROTECTED ROUTES (Harus Login / Token)
    // ==========================================
    Route::middleware('auth:sanctum')->group(function () {

        // --- Auth ---
        Route::get('/auth/me',      [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        // ==========================================
        // 3. OWNER & ADMIN ONLY
        //    Manajemen Guru, Siswa, Orang Tua
        // ==========================================
        Route::middleware('role:owner,admin')->group(function () {

            // --- Manajemen Guru ---
            Route::apiResource('teachers', TeacherController::class);

            // --- Manajemen Siswa ---
            Route::apiResource('students', StudentController::class);

            // --- Manajemen Orang Tua ---
            Route::apiResource('parents', ParentController::class);
            // Kaitkan / lepas relasi orang tua - siswa
            Route::post('parents/{parent}/students/{student}',   [ParentController::class, 'attachStudent']);
            Route::delete('parents/{parent}/students/{student}', [ParentController::class, 'detachStudent']);

            // --- Manajemen Pengguna ---
            Route::apiResource('users', UserController::class);

            // --- Master Data Lainnya ---
            Route::apiResource('classrooms', ClassroomController::class);
            // Assign / lepas guru dari kelas
            Route::post('classrooms/{classroom}/teachers',            [ClassroomController::class, 'assignTeacher']);
            Route::delete('classrooms/{classroom}/teachers/{teacher}', [ClassroomController::class, 'removeTeacher']);

            Route::apiResource('holidays', HolidayController::class);
            Route::apiResource('inventories', InventoryController::class);
            Route::apiResource('payment-plans', PaymentPlanController::class);
            Route::apiResource('teacher-payrolls', TeacherPayrollController::class);

            // --- Pengumuman (Write) ---
            Route::post('/announcements', [AnnouncementController::class, 'store']);
            Route::apiResource('announcements', AnnouncementController::class)->except(['index', 'store']);
        });

        // ==========================================
        // 4. SEMUA ROLE YANG SUDAH LOGIN
        // ==========================================

        // --- Akademik & Kelas ---
        Route::apiResource('programs', ProgramController::class);
        // Level management (nested di bawah program)
        Route::post('programs/{program}/levels',          [ProgramController::class, 'storeLevel']);
        Route::put('programs/{program}/levels/{level}',   [ProgramController::class, 'updateLevel']);
        Route::delete('programs/{program}/levels/{level}', [ProgramController::class, 'destroyLevel']);
        Route::apiResource('enrollments', EnrollmentController::class);
        
        Route::post('class-sessions/{class_session}/reschedule', [ClassSessionController::class, 'reschedule']);
        Route::apiResource('class-sessions', ClassSessionController::class);
        
        Route::post('class-schedules/{class_schedule}/generate-sessions', [ClassScheduleController::class, 'generateSessions']);
        Route::apiResource('class-schedules', ClassScheduleController::class);

        // --- Monitoring & Evaluasi ---
        Route::apiResource('student-attendances', StudentAttendanceController::class);
        Route::apiResource('teacher-logbooks', TeacherLogbookController::class);
        Route::apiResource('assessments', AssessmentController::class);
        Route::apiResource('final-reports', FinalReportController::class);
        Route::apiResource('certificates', CertificateController::class);

        // --- Keuangan ---
        Route::apiResource('payments', PaymentController::class);

    }); // End auth:sanctum
});
