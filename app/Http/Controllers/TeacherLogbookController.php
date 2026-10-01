<?php

namespace App\Http\Controllers;

use App\Models\Teacher;
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
        $user = $request->user();
        $isTeacher = $user && strtolower($user->role) === 'teacher';
        $teacherId = null;

        if ($isTeacher) {
            $teacherId = Teacher::where('user_id', $user->id)->value('id') ?: '00000000-0000-0000-0000-000000000000';
        }

        $query = TeacherLogbook::with(['teacher.user', 'classSession.classroom'])
            ->when($isTeacher, function ($q) use ($teacherId) {
                $q->where('teacher_id', $teacherId);
            })
            ->when($request->filled('teacher_id') && !$isTeacher, fn($q) => $q->where('teacher_id', $request->teacher_id))
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
            'teacher_id'       => 'nullable|uuid|exists:teachers,id',
            'check_in'         => 'required|date',
            'check_out'        => 'required|date|after_or_equal:check_in',
            'teaching_minutes' => 'required|integer|min:1',
            'material'         => 'required|string',
            'notes'            => 'nullable|string',
            'status'           => 'sometimes|string|in:draft,submitted,verified',
        ]);

        if ($request->user() && $request->user()->role === 'teacher') {
            $validated['teacher_id'] = Teacher::where('user_id', $request->user()->id)->value('id');
        } elseif (empty($validated['teacher_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'teacher_id wajib diisi.'
            ], 422);
        }

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
    public function show(Request $request, string $id)
    {
        $logbook = TeacherLogbook::with(['teacher.user', 'classSession.classroom'])
            ->findOrFail($id);

        if ($request->user() && $request->user()->role === 'teacher') {
            $myTeacherId = Teacher::where('user_id', $request->user()->id)->value('id');
            if ($logbook->teacher_id !== $myTeacherId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses ke logbook ini.',
                ], 403);
            }
        }

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

        if ($request->user() && $request->user()->role === 'teacher') {
            $myTeacherId = Teacher::where('user_id', $request->user()->id)->value('id');
            if ($logbook->teacher_id !== $myTeacherId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses untuk mengubah logbook ini.',
                ], 403);
            }
        }

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
    public function destroy(Request $request, string $id)
    {
        $logbook = TeacherLogbook::findOrFail($id);

        if ($request->user() && $request->user()->role === 'teacher') {
            $myTeacherId = Teacher::where('user_id', $request->user()->id)->value('id');
            if ($logbook->teacher_id !== $myTeacherId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses untuk menghapus logbook ini.',
                ], 403);
            }
        }

        $logbook->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logbook berhasil dihapus.',
        ]);
    }
}
