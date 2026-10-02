<?php

namespace App\Http\Controllers;

use App\Enums\StudentStatus;
use App\Models\Assessment;
use App\Models\CashTransaction;
use App\Models\Certificate;
use App\Models\Enrollment;
use App\Models\FinalReport;
use App\Models\Payment;
use App\Models\PaymentPlan;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\Teacher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StudentController extends Controller
{
    /**
     * Daftar semua siswa.
     * Mendukung filter status dan pencarian nama.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $isTeacher = $user && strtolower($user->role) === 'teacher';
        $isParent = $user && strtolower($user->role) === 'parent';
        $teacherId = null;

        if ($isTeacher) {
            $teacherId = Teacher::where('user_id', $user->id)->value('id') ?: '00000000-0000-0000-0000-000000000000';
        }

        $query = Student::with('parents.user')
            ->when($isParent, function ($q) use ($user) {
                $q->whereHas('parents', function ($p) use ($user) {
                    $p->where('user_id', $user->id);
                });
            })
            ->when($isTeacher, function ($q) use ($teacherId) {
                $q->whereHas('enrollments.classroom.teachers', function ($t) use ($teacherId) {
                    $t->where('teachers.id', $teacherId);
                });
            })
            ->when($request->filled('classroom_id'), function ($q) use ($request) {
                $q->whereHas('enrollments', function ($enr) use ($request) {
                    $enr->where('classroom_id', $request->classroom_id)->where('status', 'active');
                });
            })
            ->when($request->filled('status') && !in_array(strtolower($request->status), ['semua', 'all']), function ($q) use ($request) {
                $statusMap = [
                    'aktif'       => 'active',
                    'active'      => 'active',
                    'percobaan'   => 'trial',
                    'trial'       => 'trial',
                    'cuti'        => 'on_leave',
                    'on_leave'    => 'on_leave',
                    'tidak aktif' => 'inactive',
                    'inactive'    => 'inactive',
                    'lulus'       => 'graduated',
                    'graduated'   => 'graduated',
                ];
                $cleanStatus = $statusMap[strtolower(trim($request->status))] ?? $request->status;
                $q->where('status', $cleanStatus);
            })
            ->when($request->search, function ($q) use ($request) {
                $q->where(function ($sub) use ($request) {
                    $sub->where('full_name', 'like', '%' . $request->search . '%')
                        ->orWhere('student_code', 'like', '%' . $request->search . '%');
                });
            })
            ->when($request->school_grade, function ($q) use ($request) {
                $q->where('school_grade', $request->school_grade);
            });

        $students = $query->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data'    => $students,
        ]);
    }

    /**
     * Tambah siswa baru.
     */
    public function store(Request $request)
    {
        $statusMap = [
            'aktif'       => 'active',
            'active'      => 'active',
            'percobaan'   => 'trial',
            'trial'       => 'trial',
            'cuti'        => 'on_leave',
            'on_leave'    => 'on_leave',
            'tidak aktif' => 'inactive',
            'inactive'    => 'inactive',
            'lulus'       => 'graduated',
            'graduated'   => 'graduated',
        ];

        if ($request->filled('status')) {
            $rawStatus = strtolower(trim((string)$request->status));
            if (isset($statusMap[$rawStatus])) {
                $request->merge(['status' => $statusMap[$rawStatus]]);
            }
        }

        $validated = $request->validate([
            'full_name'    => 'required|string|max:255',
            'birth_date'   => 'nullable|date',
            'school_name'  => 'nullable|string|max:255',
            'school_grade' => 'nullable|string|max:50',
            'address'      => 'nullable|string',
            'status'       => ['sometimes', Rule::enum(StudentStatus::class)],
        ]);

        $validated['student_code'] = 'STU-' . strtoupper(\Illuminate\Support\Str::random(6));
        $validated['status']       = $validated['status'] ?? StudentStatus::Trial->value;

        $student = Student::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Siswa berhasil ditambahkan.',
            'data'    => $student,
        ], 201);
    }

    /**
     * Detail satu siswa beserta data orang tua.
     */
    public function show(string $id)
    {
        $student = Student::with('parents.user')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => $student,
        ]);
    }

    /**
     * Update data siswa.
     */
    public function update(Request $request, string $id)
    {
        $student = Student::findOrFail($id);

        $statusMap = [
            'aktif'       => 'active',
            'active'      => 'active',
            'percobaan'   => 'trial',
            'trial'       => 'trial',
            'cuti'        => 'on_leave',
            'on_leave'    => 'on_leave',
            'tidak aktif' => 'inactive',
            'inactive'    => 'inactive',
            'lulus'       => 'graduated',
            'graduated'   => 'graduated',
        ];

        if ($request->filled('status')) {
            $rawStatus = strtolower(trim((string)$request->status));
            if (isset($statusMap[$rawStatus])) {
                $request->merge(['status' => $statusMap[$rawStatus]]);
            }
        }

        $validated = $request->validate([
            'full_name'    => 'sometimes|string|max:255',
            'birth_date'   => 'nullable|date',
            'school_name'  => 'nullable|string|max:255',
            'school_grade' => 'nullable|string|max:50',
            'address'      => 'nullable|string',
            'status'       => ['sometimes', Rule::enum(StudentStatus::class)],
        ]);

        $student->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Data siswa berhasil diperbarui.',
            'data'    => $student->fresh(),
        ]);
    }

    /**
     * Hapus siswa beserta seluruh relasi terkait secara permanen.
     */
    public function destroy(string $id)
    {
        $student = Student::findOrFail($id);

        $hasPaidTransactions = Payment::where('student_id', $student->id)->where('payment_status', 'paid')->exists()
            || CashTransaction::where('student_id', $student->id)->where('status', 'paid')->exists();

        if ($hasPaidTransactions) {
            // Demi menjaga integritas laporan keuangan dan audit trail,
            // siswa dengan transaksi sah dinonaktifkan alih-alih dihapus permanen.
            $student->update(['status' => 'inactive']);
            return response()->json([
                'success' => true,
                'message' => 'Siswa memiliki riwayat transaksi keuangan yang telah dibayar. Status siswa dinonaktifkan demi menjaga integritas buku kas.',
            ]);
        }

        DB::transaction(function () use ($student) {
            // 1. Lepas relasi orang tua (pivot parent_students)
            $student->parents()->detach();

            // 2. Hapus data sertifikat & laporan akhir
            Certificate::where('student_id', $student->id)->delete();
            FinalReport::where('student_id', $student->id)->delete();

            // 3. Hapus data penilaian & absensi siswa
            Assessment::where('student_id', $student->id)->delete();
            StudentAttendance::where('student_id', $student->id)->delete();

            // 4. Hapus data transaksi keuangan & tagihan belum lunas
            CashTransaction::where('student_id', $student->id)->delete();
            Payment::where('student_id', $student->id)->delete();

            // 5. Hapus payment plans di bawah enrollments siswa
            $enrollmentIds = Enrollment::where('student_id', $student->id)->pluck('id');
            if ($enrollmentIds->isNotEmpty()) {
                PaymentPlan::whereIn('enrollment_id', $enrollmentIds)->delete();
                Enrollment::whereIn('id', $enrollmentIds)->delete();
            }

            // 6. Hapus record siswa
            $student->delete();
        });

        return response()->json([
            'success' => true,
            'message' => 'Data siswa berhasil dihapus secara permanen.',
        ]);
    }
}
