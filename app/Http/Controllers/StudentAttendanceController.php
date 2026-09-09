<?php

namespace App\Http\Controllers;

use App\Models\StudentAttendance;
use Illuminate\Http\Request;

class StudentAttendanceController extends Controller
{
    /**
     * Tampilkan semua data absensi siswa.
     * Mendukung filter berdasarkan student_id, class_session_id, dan attendance_status.
     */
    public function index(Request $request)
    {
        $query = StudentAttendance::with(['student', 'classSession.classroom', 'classSession.teacher.user'])
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
        $validated = $request->validate([
            'class_session_id'  => 'required|uuid|exists:class_sessions,id',
            'student_id'        => 'required|uuid|exists:students,id',
            'attendance_status' => 'required|string|in:present,absent,late,excused',
            'notes'             => 'nullable|string',
        ]);

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
    public function show(string $id)
    {
        $attendance = StudentAttendance::with(['student', 'classSession.classroom', 'classSession.teacher.user'])
            ->findOrFail($id);

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
        $attendance = StudentAttendance::findOrFail($id);

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
    public function destroy(string $id)
    {
        $attendance = StudentAttendance::findOrFail($id);
        $attendance->delete();

        return response()->json([
            'success' => true,
            'message' => 'Data absensi berhasil dihapus.',
        ]);
    }
}
