<?php

namespace App\Http\Controllers;

use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EnrollmentController extends Controller
{
    /**
     * Daftar enrollment (bisa filter by student, classroom, status).
     */
    public function index(Request $request)
    {
        $query = Enrollment::with(['student', 'classroom', 'program', 'programLevel'])
            ->when($request->student_id, function ($q) use ($request) {
                $q->where('student_id', $request->student_id);
            })
            ->when($request->classroom_id, function ($q) use ($request) {
                $q->where('classroom_id', $request->classroom_id);
            })
            ->when($request->status, function ($q) use ($request) {
                $q->where('status', $request->status);
            });

        $enrollments = $query->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data'    => $enrollments,
        ]);
    }

    /**
     * Daftarkan siswa ke kelas.
     *
     * Validasi bisnis:
     * 1. Siswa maks ikut 2 program aktif
     * 2. Kelas tidak boleh melebihi kapasitas maks
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'student_id'       => 'required|uuid|exists:students,id',
            'classroom_id'     => 'required|uuid|exists:classrooms,id',
            'start_date'       => 'required|date',
            'total_sessions'   => 'required|integer|min:1',
            'sessions_per_week'=> 'sometimes|integer|min:1|max:7',
            'payment_scheme'   => 'required|string|in:monthly,per_session,package',
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

        // ── Buat enrollment ──
        $enrollment = Enrollment::create([
            'enrollment_code'  => 'ENR-' . strtoupper(Str::random(6)),
            'student_id'       => $student->id,
            'classroom_id'     => $classroom->id,
            'program_id'       => $programId,
            'program_level_id' => $classroom->program_level_id,
            'start_date'       => $validated['start_date'],
            'total_sessions'   => $validated['total_sessions'],
            'sessions_per_week'=> $validated['sessions_per_week'] ?? 2,
            'payment_scheme'   => $validated['payment_scheme'],
            'status'           => 'active',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Siswa berhasil didaftarkan ke kelas.',
            'data'    => $enrollment->load(['student', 'classroom', 'program']),
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
