<?php

use App\Http\Controllers\AnnouncementController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| BSD After School Club - API Routes
|--------------------------------------------------------------------------
*/

// --- 0. Pengumuman & Poster / Flyer ---
Route::prefix('announcements')->group(function () {
    Route::get('/', [AnnouncementController::class, 'index']);
    Route::post('/', [AnnouncementController::class, 'store']);
});


// --- 1. Autentikasi (Auth) ---
Route::prefix('auth')->group(function () {
    Route::post('/login', function (Request $request) {
        $email = $request->input('email', 'siswa@example.com');
        return response()->json([
            'success' => true,
            'message' => 'Login berhasil (BSD After School Club)',
            'data' => [
                'token' => 'mock_token_bsd_after_school_' . time(),
                'user' => [
                    'id' => 1,
                    'name' => 'Budi Santoso',
                    'email' => $email,
                    'role' => 'student',
                    'class_grade' => '12 SMA IPA'
                ]
            ]
        ]);
    });

    Route::post('/register', function (Request $request) {
        return response()->json([
            'success' => true,
            'message' => 'Registrasi siswa/wali berhasil',
            'data' => [
                'name' => $request->input('name', 'Budi Santoso'),
                'email' => $request->input('email', 'siswa@example.com')
            ]
        ]);
    });

    Route::get('/me', function (Request $request) {
        return response()->json([
            'success' => true,
            'data' => [
                'id' => 1,
                'name' => 'Budi Santoso',
                'email' => 'siswa@example.com',
                'role' => 'student',
                'class_grade' => '12 SMA IPA',
                'parent_name' => 'Bapak Santoso',
                'phone' => '081234567890'
            ]
        ]);
    });
});

// --- 2. Cek Jadwal Les (Schedules) ---
Route::prefix('schedules')->group(function () {
    Route::get('/my-schedule', function (Request $request) {
        return response()->json([
            'success' => true,
            'month' => $request->query('month', date('m')),
            'year' => $request->query('year', date('Y')),
            'data' => [
                [
                    'id' => 101,
                    'subject' => 'Matematika IPA',
                    'tutor_name' => 'Dr. Ahmad Hidayat',
                    'date' => date('Y-m-d', strtotime('+1 day')),
                    'time_start' => '15:30',
                    'time_end' => '17:00',
                    'room' => 'Ruang A2 / Zoom Online',
                    'status' => 'UPCOMING'
                ],
                [
                    'id' => 102,
                    'subject' => 'Fisika Dasar',
                    'tutor_name' => 'Siti Nurhaliza, M.Pd',
                    'date' => date('Y-m-d', strtotime('+3 days')),
                    'time_start' => '16:00',
                    'time_end' => '17:30',
                    'room' => 'Ruang B1',
                    'status' => 'UPCOMING'
                ]
            ]
        ]);
    });

    Route::get('/classes', function (Request $request) {
        return response()->json([
            'success' => true,
            'data' => [
                ['id' => 1, 'name' => 'Kelas SD Subjek Umum', 'quota' => 15],
                ['id' => 2, 'name' => 'Kelas SMP Intensif UN', 'quota' => 20],
                ['id' => 3, 'name' => 'Kelas SMA UTBK & SBMPTN', 'quota' => 25]
            ]
        ]);
    });
});

// --- 3. Memantau Progress (Monitoring) ---
Route::prefix('monitoring')->group(function () {
    Route::get('/attendance', function (Request $request) {
        return response()->json([
            'success' => true,
            'summary' => [
                'total_hadir' => 12,
                'total_izin' => 1,
                'total_alpha' => 0
            ],
            'data' => [
                [
                    'date' => date('Y-m-d', strtotime('-2 days')),
                    'subject' => 'Fisika',
                    'status' => 'HADIR',
                    'notes' => 'Hadir tepat waktu, aktif berdiskusi'
                ],
                [
                    'date' => date('Y-m-d', strtotime('-5 days')),
                    'subject' => 'Matematika',
                    'status' => 'HADIR',
                    'notes' => 'Tugas mandiri diselesaikan dengan baik'
                ]
            ]
        ]);
    });

    Route::get('/reports', function (Request $request) {
        return response()->json([
            'success' => true,
            'data' => [
                [
                    'subject' => 'Matematika',
                    'latest_score' => 92,
                    'evaluator' => 'Dr. Ahmad Hidayat',
                    'feedback' => 'Pemahaman aljabar dan kalkulus sangat tinggi.'
                ],
                [
                    'subject' => 'Fisika',
                    'latest_score' => 88,
                    'evaluator' => 'Siti Nurhaliza, M.Pd',
                    'feedback' => 'Bagus pada dinamika gerak, tingkatkan latihan rumus energi.'
                ]
            ]
        ]);
    });
});

// --- 4. Paket Bimbel ---
Route::get('/packages', function () {
    return response()->json([
        'success' => true,
        'data' => [
            [
                'id' => 1,
                'name' => 'Paket Reguler Intensive (Bulanan)',
                'price' => 750000,
                'duration' => '1 Bulan',
                'features' => ['3x Pertemuan / Minggu', 'Tryout Online', 'Modul Belajar']
            ],
            [
                'id' => 2,
                'name' => 'Paket Premium Privat (Bulanan)',
                'price' => 1500000,
                'duration' => '1 Bulan',
                'features' => ['1-on-1 Tutor', 'Jadwal Fleksibel', 'Laporan Mingguan Wali']
            ]
        ]
    ]);
});

// --- 5. Pembayaran Xendit Payment Gateway ---
Route::prefix('payments/xendit')->group(function () {
    Route::post('/create-invoice', function (Request $request) {
        $externalId = 'BSD-INV-' . date('Ymd') . '-' . rand(100, 999);
        return response()->json([
            'success' => true,
            'message' => 'Invoice Xendit berhasil dibuat (Sandbox/Dev)',
            'data' => [
                'external_id' => $externalId,
                'invoice_url' => 'https://checkout.xendit.co/web/mock_invoice_' . rand(1000, 9999),
                'amount' => $request->input('amount', 750000),
                'payer_email' => $request->input('payer_email', 'siswa@example.com'),
                'description' => $request->input('description', 'Pembayaran SPP BSD After School Club'),
                'status' => 'PENDING',
                'expiry_date' => date('c', strtotime('+1 day'))
            ]
        ]);
    });

    Route::get('/status/{external_id}', function ($external_id) {
        return response()->json([
            'success' => true,
            'data' => [
                'external_id' => $external_id,
                'status' => 'PAID',
                'payment_method' => 'VIRTUAL_ACCOUNT',
                'payment_channel' => 'BCA',
                'paid_at' => date('c')
            ]
        ]);
    });

    Route::post('/callback', function (Request $request) {
        return response()->json([
            'status' => 'success',
            'message' => 'Callback webhook received'
        ]);
    });
});
