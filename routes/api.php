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
use App\Http\Controllers\CashTransactionController;
use App\Http\Controllers\FinanceReportController;
use App\Http\Controllers\TeacherPayrollController;
use App\Http\Controllers\AcademicDashboardController;

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

    // Webhook Payment Gateway 
    Route::post('/payments/webhook', [PaymentController::class, 'webhook']);

    // ==========================================
    // 2. PROTECTED ROUTES (Harus Login / Token)
    // ==========================================
    Route::middleware('auth:sanctum')->group(function () {

        // --- Auth ---
        Route::get('/auth/me',         [AuthController::class, 'me']);
        Route::put('/auth/profile',    [AuthController::class, 'updateProfile']);
        Route::put('/auth/password',   [AuthController::class, 'updatePassword']);
        Route::post('/auth/logout',    [AuthController::class, 'logout']);

        // --- Profile Endpoints (Untuk User yang Sedang Login) ---
        Route::get('parents/my-profile',  [ParentController::class, 'myProfile']);
        Route::get('teachers/my-profile', [TeacherController::class, 'myProfile']);

        // --- Data yang Bisa Dibaca Semua Role yang Sudah Login ---
        Route::get('students',           [StudentController::class, 'index']);
        Route::get('students/{student}', [StudentController::class, 'show']);

        Route::get('teachers',           [TeacherController::class, 'index']);
        Route::get('teachers/{teacher}', [TeacherController::class, 'show']);

        Route::get('classrooms',               [ClassroomController::class, 'index']);
        Route::get('classrooms/{classroom}',   [ClassroomController::class, 'show']);

        Route::get('payment-plans',                  [PaymentPlanController::class, 'index']);
        Route::get('payment-plans/{payment_plan}',   [PaymentPlanController::class, 'show']);

        Route::get('holidays',           [HolidayController::class, 'index']);
        Route::get('holidays/{holiday}', [HolidayController::class, 'show']);

        // ==========================================
        // 3. OWNER & ADMIN ONLY
        //    Manajemen Guru, Siswa, Orang Tua (Write)
        // ==========================================
        Route::middleware('role:owner,admin')->group(function () {

            // --- Manajemen Guru (Write) ---
            Route::post('teachers',                      [TeacherController::class, 'store']);
            Route::put('teachers/{teacher}',             [TeacherController::class, 'update']);
            Route::patch('teachers/{teacher}',           [TeacherController::class, 'update']);
            Route::delete('teachers/{teacher}',          [TeacherController::class, 'destroy']);

            // --- Manajemen Siswa (Write) ---
            Route::post('students',                      [StudentController::class, 'store']);
            Route::put('students/{student}',             [StudentController::class, 'update']);
            Route::patch('students/{student}',           [StudentController::class, 'update']);
            Route::delete('students/{student}',          [StudentController::class, 'destroy']);

            // --- Manajemen Orang Tua ---
            Route::apiResource('parents', ParentController::class);
            // Kaitkan / lepas relasi orang tua - siswa
            Route::post('parents/{parent}/students/{student}',   [ParentController::class, 'attachStudent']);
            Route::delete('parents/{parent}/students/{student}', [ParentController::class, 'detachStudent']);

            // --- Manajemen Pengguna ---
            Route::apiResource('users', UserController::class);

            // --- Master Data Lainnya (Write) ---
            Route::post('classrooms',                                  [ClassroomController::class, 'store']);
            Route::put('classrooms/{classroom}',                       [ClassroomController::class, 'update']);
            Route::patch('classrooms/{classroom}',                     [ClassroomController::class, 'update']);
            Route::delete('classrooms/{classroom}',                    [ClassroomController::class, 'destroy']);
            // Assign / lepas guru dari kelas
            Route::post('classrooms/{classroom}/teachers',             [ClassroomController::class, 'assignTeacher']);
            Route::delete('classrooms/{classroom}/teachers/{teacher}',  [ClassroomController::class, 'removeTeacher']);

            Route::post('holidays',                      [HolidayController::class, 'store']);
            Route::put('holidays/{holiday}',             [HolidayController::class, 'update']);
            Route::patch('holidays/{holiday}',           [HolidayController::class, 'update']);
            Route::delete('holidays/{holiday}',          [HolidayController::class, 'destroy']);

            Route::apiResource('inventories', InventoryController::class);
            Route::apiResource('inventory', InventoryController::class);

            // --- Program & Level (Write: Admin / Owner Only) ---
            Route::post('programs',                           [ProgramController::class, 'store']);
            Route::put('programs/{program}',                  [ProgramController::class, 'update']);
            Route::patch('programs/{program}',                [ProgramController::class, 'update']);
            Route::delete('programs/{program}',               [ProgramController::class, 'destroy']);
            Route::post('programs/{program}/levels',          [ProgramController::class, 'storeLevel']);
            Route::put('programs/{program}/levels/{level}',   [ProgramController::class, 'updateLevel']);
            Route::delete('programs/{program}/levels/{level}', [ProgramController::class, 'destroyLevel']);

            // --- Enrollment / Pendaftaran Siswa (Write: Admin / Owner Only) ---
            Route::post('enrollments',                        [EnrollmentController::class, 'store']);
            Route::put('enrollments/{enrollment}',            [EnrollmentController::class, 'update']);
            Route::patch('enrollments/{enrollment}',          [EnrollmentController::class, 'update']);
            Route::delete('enrollments/{enrollment}',         [EnrollmentController::class, 'destroy']);

            // --- Jadwal Kelas & Generate Sesi (Write: Admin / Owner Only) ---
            Route::post('class-schedules/{class_schedule}/generate-sessions', [ClassScheduleController::class, 'generateSessions']);
            Route::post('class-schedules',                    [ClassScheduleController::class, 'store']);
            Route::put('class-schedules/{class_schedule}',    [ClassScheduleController::class, 'update']);
            Route::patch('class-schedules/{class_schedule}',  [ClassScheduleController::class, 'update']);
            Route::delete('class-schedules/{class_schedule}', [ClassScheduleController::class, 'destroy']);

            Route::post('payment-plans/generate',        [PaymentPlanController::class, 'generateForEnrollment']);
            Route::post('payment-plans',                 [PaymentPlanController::class, 'store']);
            Route::put('payment-plans/{payment_plan}',   [PaymentPlanController::class, 'update']);
            Route::patch('payment-plans/{payment_plan}', [PaymentPlanController::class, 'update']);
            Route::delete('payment-plans/{payment_plan}',[PaymentPlanController::class, 'destroy']);

            // --- Verifikasi Pembayaran Tunai (Admin / Owner Only) ---
            Route::post('cash-transactions/{id}/confirm', [CashTransactionController::class, 'confirm']);
            Route::post('cash-transactions/{id}/reject',  [CashTransactionController::class, 'reject']);

            // --- Dashboard Akademik Terpadu (Hari 7 - Admin / Owner) ---
            Route::get('academic/dashboard',             [AcademicDashboardController::class, 'dashboard']);

            // --- Dashboard & Laporan Keuangan (Hari 7 - Admin / Owner Only) ---
            Route::get('finance/dashboard',             [FinanceReportController::class, 'dashboard']);
            Route::get('finance/reports/income',        [FinanceReportController::class, 'incomeReport']);
            Route::get('finance/reports/outstanding',   [FinanceReportController::class, 'outstandingReport']);
            Route::get('finance/reports/reconciliation',[FinanceReportController::class, 'cashReconciliation']);

            // --- Manajemen Laporan Akhir & Sertifikat (Admin / Owner Override & Sertifikat) ---
            Route::put('final-reports/{final_report}',     [FinalReportController::class, 'update']);
            Route::patch('final-reports/{final_report}',   [FinalReportController::class, 'update']);
            Route::delete('final-reports/{final_report}',  [FinalReportController::class, 'destroy']);

            Route::post('certificates',                    [CertificateController::class, 'store']);
            Route::put('certificates/{certificate}',      [CertificateController::class, 'update']);
            Route::patch('certificates/{certificate}',    [CertificateController::class, 'update']);
            Route::delete('certificates/{certificate}',   [CertificateController::class, 'destroy']);

            // --- Pengumuman (Write) ---
            Route::post('/announcements', [AnnouncementController::class, 'store']);
            Route::apiResource('announcements', AnnouncementController::class)->except(['index', 'store']);
        });

        // ==========================================
        // 3.4 OWNER, ADMIN & GURU
        //     Penerbitan Laporan Akhir Siswa
        // ==========================================
        Route::middleware('role:owner,admin,teacher')->group(function () {
            Route::post('final-reports', [FinalReportController::class, 'store']);
        });

        // ==========================================
        // 3.5 OWNER ONLY
        //     Penggajian Guru (Payroll Write)
        // ==========================================
        Route::middleware('role:owner')->group(function () {
            Route::post('teacher-payrolls/generate', [TeacherPayrollController::class, 'generatePayroll']);
            Route::post('teacher-payrolls',          [TeacherPayrollController::class, 'store']);
            Route::put('teacher-payrolls/{teacher_payroll}',    [TeacherPayrollController::class, 'update']);
            Route::patch('teacher-payrolls/{teacher_payroll}',  [TeacherPayrollController::class, 'update']);
            Route::delete('teacher-payrolls/{teacher_payroll}', [TeacherPayrollController::class, 'destroy']);
        });

        // ==========================================
        // 4. SEMUA ROLE YANG SUDAH LOGIN
        // ==========================================

        // --- Payroll (Read untuk Owner & Guru yang bersangkutan) ---
        Route::get('teacher-payrolls',                        [TeacherPayrollController::class, 'index']);
        Route::get('teacher-payrolls/{teacher_payroll}',      [TeacherPayrollController::class, 'show']);

        // --- Akademik & Kelas ---
        // Classrooms, Programs, Enrollments, Schedules: Read = all authenticated roles
        Route::apiResource('classrooms', ClassroomController::class)->only(['index', 'show']);
        Route::apiResource('programs', ProgramController::class)->only(['index', 'show']);
        Route::apiResource('enrollments', EnrollmentController::class)->only(['index', 'show']);

        Route::post('class-sessions/{class_session}/reschedule', [ClassSessionController::class, 'reschedule']);
        Route::post('class-sessions/{class_session}/submit-attendance', [ClassSessionController::class, 'submitAttendanceAndLogbook']);
        Route::apiResource('class-sessions', ClassSessionController::class);

        Route::apiResource('class-schedules', ClassScheduleController::class)->only(['index', 'show']);

        // --- Monitoring & Evaluasi ---
        Route::apiResource('student-attendances', StudentAttendanceController::class);
        Route::apiResource('teacher-logbooks', TeacherLogbookController::class);
        Route::apiResource('assessments', AssessmentController::class);
        Route::get('final-reports',                   [FinalReportController::class, 'index']);
        Route::get('final-reports/{final_report}',    [FinalReportController::class, 'show']);
        Route::get('certificates',                    [CertificateController::class, 'index']);
        Route::get('certificates/{certificate}',     [CertificateController::class, 'show']);

        // --- Keuangan & Pembayaran Digital ---
        Route::get('payments/methods', [PaymentController::class, 'methods']);
        Route::post('payments/charge', [PaymentController::class, 'charge']);
        Route::get('payments/{payment}/status', [PaymentController::class, 'checkStatus']);
        Route::apiResource('payments', PaymentController::class)->only(['index', 'show']);

        // --- Transaksi Tunai (Cash Payment) ---
        Route::get('cash-transactions',          [CashTransactionController::class, 'index']);
        Route::get('cash-transactions/{id}',     [CashTransactionController::class, 'show']);
        Route::post('cash-transactions/submit',  [CashTransactionController::class, 'submit']);

    }); // End auth:sanctum
});
