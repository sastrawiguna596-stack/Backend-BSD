<?php

namespace App\Http\Controllers;

use App\Models\StudentAttendance;
use App\Models\Teacher;
use Illuminate\Http\Request;

class StudentAttendanceController extends Controller
{
    /**
     * Tampilkan semua data absensi siswa.
     * Mendukung filter berdasarkan student_id, class_session_id, dan attendance_status.
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

        $query = StudentAttendance::with(['student', 'classSession.classroom', 'classSession.teacher.user'])
            ->when($isTeacher, function ($q) use ($teacherId) {
                $q->whereHas('classSession', function ($cs) use ($teacherId) {
                    $cs->where('teacher_id', $teacherId);
                });
            })
            ->when($isParent, function ($q) use ($user) {
                $q->whereHas('student.parents', function ($p) use ($user) {
                    $p->where('user_id', $user->id);
                });
            })
            ->when($request->student_id, fn($q) => $q->where('student_id', $request->student_id))
            ->when($request->class_session_id, fn($q) => $q->where('class_session_id', $request->class_session_id))
            ->when($request->attendance_status, fn($q) => $q->where('attendance_status', $request->attendance_status))
            ->latest();

        $attendances = $query->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data'    => $attendances,
        ]);
    }

    /**
     * Buat data absensi secara manual (tanpa melalui submit-attendance).
     */
    public function store(Request $request)
    {
        $user = $request->user();
        $role = $user ? strtolower($user->role) : '';

        if ($role === 'parent') {
            return response()->json([
                'success' => false,
                'message' => 'Orang tua tidak memiliki hak akses untuk mencatat absensi.'
            ], 403);
        }

        $isTeacher = $role === 'teacher';

        $validated = $request->validate([
            'class_session_id'  => 'required|uuid|exists:class_sessions,id',
            'student_id'        => 'required|uuid|exists:students,id',
            'attendance_status' => 'required|string|in:present,absent,late,excused',
            'notes'             => 'nullable|string',
        ]);

        if ($isTeacher) {
            $teacherId = Teacher::where('user_id', $user->id)->value('id');
            $session = \App\Models\ClassSession::find($validated['class_session_id']);
            if (!$session || $session->teacher_id !== $teacherId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses untuk mencatat absensi pada sesi kelas ini.'
                ], 403);
            }
        }

        $validated['recorded_at'] = now();
        $validated['recorded_by'] = $request->user()?->id;

        $attendance = StudentAttendance::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Absensi berhasil dicatat.',
            'data'    => $attendance->load(['student', 'classSession']),
        ], 201);
    }

    /**
     * Tampilkan detail satu data absensi.
     */
    public function show(Request $request, string $id)
    {
        $attendance = StudentAttendance::with(['student', 'classSession.classroom', 'classSession.teacher.user'])
            ->findOrFail($id);

        $user = $request->user();
        if ($user && strtolower($user->role) === 'teacher') {
            $teacherId = Teacher::where('user_id', $user->id)->value('id');
            if ($attendance->classSession?->teacher_id !== $teacherId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses ke data absensi ini.'
                ], 403);
            }
        }

        if ($user && strtolower($user->role) === 'parent') {
            $isParentOfStudent = $attendance->student?->parents()
                ->where('user_id', $user->id)
                ->exists();

            if (!$isParentOfStudent) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses ke data absensi ini.'
                ], 403);
            }
        }

        return response()->json([
            'success' => true,
            'data'    => $attendance,
        ]);
    }

    /**
     * Perbarui data absensi (misal: koreksi status dari absent ke excused).
     */
    public function update(Request $request, string $id)
    {
        $attendance = StudentAttendance::with('classSession')->findOrFail($id);
        $user = $request->user();
        $role = $user ? strtolower($user->role) : '';

        if ($role === 'parent') {
            return response()->json([
                'success' => false,
                'message' => 'Orang tua tidak memiliki hak akses untuk mengubah absensi.'
            ], 403);
        }

        if ($role === 'teacher') {
            $teacherId = Teacher::where('user_id', $user->id)->value('id');
            if ($attendance->classSession?->teacher_id !== $teacherId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses untuk mengubah absensi ini.'
                ], 403);
            }
        }

        $validated = $request->validate([
            'attendance_status' => 'sometimes|string|in:present,absent,late,excused',
            'notes'             => 'nullable|string',
        ]);

        $attendance->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Absensi berhasil diperbarui.',
            'data'    => $attendance->fresh()->load(['student', 'classSession']),
        ]);
    }

    /**
     * Hapus data absensi.
     */
    public function destroy(Request $request, string $id)
    {
        $user = $request->user();
        $role = $user ? strtolower($user->role) : '';

        if (!in_array($role, ['admin', 'owner'])) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya admin atau owner yang dapat menghapus data absensi.'
            ], 403);
        }

        $attendance = StudentAttendance::findOrFail($id);
        $attendance->delete();

        return response()->json([
            'success' => true,
            'message' => 'Data absensi berhasil dihapus.',
        ]);
    }
}
