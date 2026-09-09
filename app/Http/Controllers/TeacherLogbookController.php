<?php

namespace App\Http\Controllers;

use App\Models\TeacherLogbook;
use Illuminate\Http\Request;

class TeacherLogbookController extends Controller
{
    /**
     * Tampilkan semua logbook guru.
     * Mendukung filter berdasarkan teacher_id, class_session_id, dan status.
     */
    public function index(Request $request)
    {
        $query = TeacherLogbook::with(['teacher.user', 'classSession'])
            ->when($request->teacher_id, fn($q) => $q->where('teacher_id', $request->teacher_id))
            ->when($request->class_session_id, fn($q) => $q->where('class_session_id', $request->class_session_id))
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->latest();

        $logbooks = $query->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data'    => $logbooks,
        ]);
    }

    /**
     * Buat logbook guru secara manual (tanpa melalui submit-attendance).
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'class_session_id' => 'required|uuid|exists:class_sessions,id',
            'teacher_id'       => 'required|uuid|exists:teachers,id',
            'check_in'         => 'required|date',
            'check_out'        => 'required|date|after_or_equal:check_in',
            'teaching_minutes' => 'required|integer|min:1',
            'material'         => 'required|string',
            'notes'            => 'nullable|string',
            'status'           => 'sometimes|string|in:draft,submitted,verified',
        ]);

        $validated['status']       = $validated['status'] ?? 'submitted';
        $validated['submitted_at'] = now();

        $logbook = TeacherLogbook::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Logbook berhasil dibuat.',
            'data'    => $logbook->load(['teacher.user', 'classSession']),
        ], 201);
    }

    /**
     * Tampilkan detail satu logbook.
     */
    public function show(string $id)
    {
        $logbook = TeacherLogbook::with(['teacher.user', 'classSession.classroom'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => $logbook,
        ]);
    }

    /**
     * Perbarui data logbook (misal: koreksi materi atau status verifikasi).
     */
    public function update(Request $request, string $id)
    {
        $logbook = TeacherLogbook::findOrFail($id);

        $validated = $request->validate([
            'check_in'         => 'sometimes|date',
            'check_out'        => 'sometimes|date|after_or_equal:check_in',
            'teaching_minutes' => 'sometimes|integer|min:1',
            'material'         => 'sometimes|string',
            'notes'            => 'nullable|string',
            'status'           => 'sometimes|string|in:draft,submitted,verified',
        ]);

        $logbook->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Logbook berhasil diperbarui.',
            'data'    => $logbook->fresh()->load(['teacher.user', 'classSession']),
        ]);
    }

    /**
     * Hapus logbook.
     */
    public function destroy(string $id)
    {
        $logbook = TeacherLogbook::findOrFail($id);
        $logbook->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logbook berhasil dihapus.',
        ]);
    }
}
