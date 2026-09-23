<?php

namespace App\Http\Controllers;

use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\PaymentPlan;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class EnrollmentController extends Controller
{
    /**
     * Daftar enrollment (bisa filter by student, classroom, status).
     */
    public function index(Request $request)
    {
        $query = Enrollment::with(['student', 'classroom', 'program', 'programLevel', 'paymentPlans'])
            ->when($request->student_id, function ($q) use ($request) {
                $q->where('student_id', $request->student_id);
            })
            ->when($request->classroom_id, function ($q) use ($request) {
                $q->where('classroom_id', $request->classroom_id);
            })
            ->when($request->status, function ($q) use ($request) {
                $q->where('status', $request->status);
            })
            ->orderBy('created_at', 'desc');

        $enrollments = $query->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data'    => $enrollments,
        ]);
    }

    /**
     * Daftarkan siswa ke kelas dan otomatis generate skema tagihan (Payment Plans).
     *
     * Validasi bisnis:
     * 1. Siswa maks ikut 2 program aktif
     * 2. Kelas tidak boleh melebihi kapasitas maks
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'student_id'        => 'required|uuid|exists:students,id',
            'classroom_id'      => 'required|uuid|exists:classrooms,id',
            'start_date'        => 'required|date',
            'total_sessions'    => 'required|integer|min:1',
            'sessions_per_week' => 'sometimes|integer|min:1|max:7',
            'payment_scheme'    => 'required|string|in:monthly,per_session,package',
            'total_amount'      => 'nullable|numeric|min:0',
            'amount_per_session'=> 'nullable|numeric|min:0',
            'installments'      => 'nullable|integer|min:1|max:12',
        ]);

        $student   = Student::findOrFail($validated['student_id']);
        $classroom = Classroom::with('programLevel')->findOrFail($validated['classroom_id']);

        // ── Validasi 1: Siswa sudah ikut 2 program aktif? ──
        $programId = $classroom->programLevel->program_id;

        // Cek apakah sudah enrolled di program yang sama
        $alreadyEnrolled = Enrollment::where('student_id', $student->id)
            ->where('program_id', $programId)
            ->where('classroom_id', $classroom->id)
            ->where('status', 'active')
            ->exists();

        if ($alreadyEnrolled) {
            return response()->json([
                'success' => false,
                'message' => 'Siswa sudah terdaftar di kelas ini.',
            ], 422);
        }

        // Cek jumlah program aktif yang berbeda
        $activeProgramCount = Enrollment::where('student_id', $student->id)
            ->where('status', 'active')
            ->distinct('program_id')
            ->count('program_id');

        // Kalau sudah 2 program, cek apakah program baru ini sudah termasuk
        $alreadyInThisProgram = Enrollment::where('student_id', $student->id)
            ->where('program_id', $programId)
            ->where('status', 'active')
            ->exists();

        if ($activeProgramCount >= 2 && ! $alreadyInThisProgram) {
            return response()->json([
                'success' => false,
                'message' => 'Siswa sudah terdaftar di 2 program aktif. Maksimal 2 program per siswa.',
            ], 422);
        }

        // ── Validasi 2: Kapasitas kelas penuh? ──
        if ($classroom->isFull()) {
            return response()->json([
                'success' => false,
                'message' => 'Kelas sudah penuh. Kapasitas maks: ' . $classroom->capacity . ' siswa.',
            ], 422);
        }

        // ── Buat enrollment & generate payment plan secara transaksional ──
        $enrollment = null;
        $createdPlans = [];

        DB::transaction(function () use ($validated, $student, $classroom, $programId, &$enrollment, &$createdPlans) {
            $enrollment = Enrollment::create([
                'enrollment_code'   => 'ENR-' . strtoupper(Str::random(6)),
                'student_id'        => $student->id,
                'classroom_id'      => $classroom->id,
                'program_id'        => $programId,
                'program_level_id'  => $classroom->program_level_id,
                'start_date'        => $validated['start_date'],
                'total_sessions'    => $validated['total_sessions'],
                'sessions_per_week' => $validated['sessions_per_week'] ?? 2,
                'payment_scheme'    => $validated['payment_scheme'],
                'status'            => 'active',
            ]);

            // Hitung nominal total tagihan
            $totalSessions = (int) $validated['total_sessions'];
            $sessionsPerWeek = (int) ($validated['sessions_per_week'] ?? 2);
            $amountPerSession = isset($validated['amount_per_session']) && $validated['amount_per_session'] > 0
                ? (float) $validated['amount_per_session']
                : 75000;

            $totalAmount = isset($validated['total_amount']) && $validated['total_amount'] > 0
                ? (float) $validated['total_amount']
                : ($amountPerSession * $totalSessions);

            $scheme = $validated['payment_scheme'];

            if ($scheme === 'package') {
                // Skema 1: Paket Lunas di Depan (1 Tagihan Total)
                $plan = PaymentPlan::create([
                    'enrollment_id'  => $enrollment->id,
                    'session_number' => 1,
                    'amount'         => $totalAmount,
                    'due_date'       => $validated['start_date'],
                    'status'         => 'pending',
                    'is_paid'        => false,
                ]);
                $createdPlans[] = $plan;
            } elseif ($scheme === 'monthly') {
                // Skema 2: Bulanan (Termin per 4 minggu)
                $installments = isset($validated['installments']) && $validated['installments'] > 0
                    ? (int) $validated['installments']
                    : max(1, (int) ceil($totalSessions / ($sessionsPerWeek * 4)));

                $amountPerInstallment = round($totalAmount / $installments, 2);

                for ($i = 1; $i <= $installments; $i++) {
                    $dueDate = Carbon::parse($validated['start_date'])
                        ->addWeeks(($i - 1) * 4)
                        ->toDateString();

                    $plan = PaymentPlan::create([
                        'enrollment_id'  => $enrollment->id,
                        'session_number' => $i,
                        'amount'         => $amountPerInstallment,
                        'due_date'       => $dueDate,
                        'status'         => 'pending',
                        'is_paid'        => false,
                    ]);
                    $createdPlans[] = $plan;
                }
            } else {
                // Skema 3: Per Sesi / Mingguan
                $installments = isset($validated['installments']) && $validated['installments'] > 0
                    ? (int) $validated['installments']
                    : $totalSessions;

                $amountPerPart = round($totalAmount / $installments, 2);
                $stepDays = $installments > 1
                    ? max(3, (int) round(($totalSessions / $sessionsPerWeek * 7) / $installments))
                    : 7;

                for ($i = 1; $i <= $installments; $i++) {
                    $dueDate = Carbon::parse($validated['start_date'])
                        ->addDays(($i - 1) * $stepDays)
                        ->toDateString();

                    $plan = PaymentPlan::create([
                        'enrollment_id'  => $enrollment->id,
                        'session_number' => $i,
                        'amount'         => $amountPerPart,
                        'due_date'       => $dueDate,
                        'status'         => 'pending',
                        'is_paid'        => false,
                    ]);
                    $createdPlans[] = $plan;
                }
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Siswa berhasil didaftarkan dan ' . count($createdPlans) . ' tagihan otomatis dibuat.',
            'data'    => $enrollment->load(['student', 'classroom', 'program', 'paymentPlans']),
        ], 201);
    }

    /**
     * Detail enrollment.
     */
    public function show(string $id)
    {
        $enrollment = Enrollment::with(['student', 'classroom.programLevel.program', 'program'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => $enrollment,
        ]);
    }

    /**
     * Update enrollment (misal ubah payment_scheme atau status).
     */
    public function update(Request $request, string $id)
    {
        $enrollment = Enrollment::findOrFail($id);

        $validated = $request->validate([
            'total_sessions'    => 'sometimes|integer|min:1',
            'sessions_per_week' => 'sometimes|integer|min:1|max:7',
            'payment_scheme'    => 'sometimes|string|in:monthly,per_session,package',
            'status'            => 'sometimes|string|in:active,inactive,completed',
        ]);

        $enrollment->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Enrollment berhasil diperbarui.',
            'data'    => $enrollment->fresh(),
        ]);
    }

    /**
     * Nonaktifkan enrollment (siswa keluar dari kelas).
     */
    public function destroy(string $id)
    {
        $enrollment = Enrollment::findOrFail($id);
        $enrollment->update(['status' => 'inactive']);

        return response()->json([
            'success' => true,
            'message' => 'Enrollment berhasil dinonaktifkan. Siswa telah dikeluarkan dari kelas.',
        ]);
    }
}
