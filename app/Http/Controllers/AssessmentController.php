<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\Teacher;
use Illuminate\Http\Request;

class AssessmentController extends Controller
{
    /**
     * List semua penilaian.
     * Support filter: student_id, enrollment_id, program_level_id
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

        $assessments = Assessment::with(['student', 'enrollment.classroom', 'programLevel', 'recordedBy'])
            ->when($isParent, function ($q) use ($user) {
                $q->whereHas('student.parents', function ($p) use ($user) {
                    $p->where('user_id', $user->id);
                });
            })
            ->when($isTeacher, function ($q) use ($teacherId, $user) {
                $userId = $user->id;
                $q->where(function ($sub) use ($teacherId, $userId) {
                    $sub->where('recorded_by', $userId)
                        ->orWhereHas('enrollment.classroom.teachers', function ($t) use ($teacherId) {
                            $t->where('teachers.id', $teacherId);
                        });
                });
            })
            ->when($request->student_id, fn($q) => $q->where('student_id', $request->student_id))
            ->when($request->enrollment_id, fn($q) => $q->where('enrollment_id', $request->enrollment_id))
            ->when($request->program_level_id, fn($q) => $q->where('program_level_id', $request->program_level_id))
            ->latest('assessment_date')
            ->paginate($request->get('per_page', 15));

        return response()->json(['success' => true, 'data' => $assessments]);
    }

    /**
     * Input nilai baru untuk satu siswa pada satu enrollment.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        $role = $user ? strtolower($user->role) : '';

        if ($role === 'parent') {
            return response()->json([
                'success' => false,
                'message' => 'Orang tua tidak memiliki hak akses untuk mencatat penilaian.'
            ], 403);
        }

        $validated = $request->validate([
            'student_id'       => 'required|uuid|exists:students,id',
            'enrollment_id'    => 'required|uuid|exists:enrollments,id',
            'program_level_id' => 'required|uuid|exists:program_levels,id',
            'assessment_name'  => 'required|string|max:255',
            'score'            => 'required|numeric|min:0|max:100',
            'weight'           => 'nullable|numeric|min:0.1',
            'assessment_date'  => 'required|date',
            'status'           => 'sometimes|string|in:draft,final',
        ]);

        if ($role === 'teacher') {
            $teacherId = Teacher::where('user_id', $user->id)->value('id');
            $teachesClass = Enrollment::where('id', $validated['enrollment_id'])
                ->whereHas('classroom.teachers', function ($q) use ($teacherId) {
                    $q->where('teachers.id', $teacherId);
                })
                ->exists();

            if (!$teachesClass) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda hanya dapat menginput nilai untuk siswa di kelas yang Anda ajar.'
                ], 403);
            }
        }

        $validated['weight']      = $validated['weight'] ?? 1.0;
        $validated['status']      = $validated['status'] ?? 'final';
        $validated['recorded_by'] = $request->user()?->id;

        $assessment = Assessment::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Nilai berhasil dicatat.',
            'data'    => $assessment->load(['student', 'enrollment', 'programLevel']),
        ], 201);
    }

    /**
     * Detail satu penilaian.
     */
    public function show(Request $request, string $id)
    {
        $assessment = Assessment::with(['student.parents', 'enrollment.classroom.teachers', 'programLevel', 'recordedBy'])
            ->findOrFail($id);

        $user = $request->user();
        $role = $user ? strtolower($user->role) : '';

        if ($role === 'parent') {
            $isParentOfStudent = $assessment->student && $assessment->student->parents()
                ->where('user_id', $user->id)
                ->exists();

            if (!$isParentOfStudent) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses ke penilaian ini.'
                ], 403);
            }
        }

        if ($role === 'teacher') {
            $teacherId = Teacher::where('user_id', $user->id)->value('id');
            $isRecordedByMe = $assessment->recorded_by === $user->id;
            $teachesClass = $assessment->enrollment && $assessment->enrollment->classroom && $assessment->enrollment->classroom->teachers()
                ->where('teachers.id', $teacherId)
                ->exists();

            if (!$isRecordedByMe && !$teachesClass) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses ke penilaian siswa di luar kelas Anda.'
                ], 403);
            }
        }

        return response()->json(['success' => true, 'data' => $assessment]);
    }

    /**
     * Koreksi nilai atau bobot.
     * Bisa dilakukan oleh guru pengajar, admin, atau owner.
     */
    public function update(Request $request, string $id)
    {
        $assessment = Assessment::with('enrollment.classroom.teachers')->findOrFail($id);
        $user = $request->user();
        $role = $user ? strtolower($user->role) : '';

        if ($role === 'parent') {
            return response()->json([
                'success' => false,
                'message' => 'Orang tua tidak memiliki hak akses untuk mengubah nilai.'
            ], 403);
        }

        if ($role === 'teacher') {
            $teacherId = Teacher::where('user_id', $user->id)->value('id');
            $isRecordedByMe = $assessment->recorded_by === $user->id;
            $teachesClass = $assessment->enrollment && $assessment->enrollment->classroom && $assessment->enrollment->classroom->teachers()
                ->where('teachers.id', $teacherId)
                ->exists();

            if (!$isRecordedByMe && !$teachesClass) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses untuk mengubah nilai siswa di luar kelas Anda.'
                ], 403);
            }
        }

        $validated = $request->validate([
            'assessment_name' => 'sometimes|string|max:255',
            'score'           => 'sometimes|numeric|min:0|max:100',
            'weight'          => 'nullable|numeric|min:0.1',
            'assessment_date' => 'sometimes|date',
            'status'          => 'sometimes|string|in:draft,final',
        ]);

        $assessment->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Nilai berhasil diperbarui.',
            'data'    => $assessment->fresh()->load(['student', 'enrollment', 'programLevel']),
        ]);
    }

    /**
     * Hapus satu data penilaian.
     */
    public function destroy(Request $request, string $id)
    {
        $assessment = Assessment::with('enrollment.classroom.teachers')->findOrFail($id);
        $user = $request->user();
        $role = $user ? strtolower($user->role) : '';

        if ($role === 'parent') {
            return response()->json([
                'success' => false,
                'message' => 'Orang tua tidak memiliki hak akses untuk menghapus nilai.'
            ], 403);
        }

        if ($role === 'teacher') {
            $teacherId = Teacher::where('user_id', $user->id)->value('id');
            $isRecordedByMe = $assessment->recorded_by === $user->id;
            $teachesClass = $assessment->enrollment && $assessment->enrollment->classroom && $assessment->enrollment->classroom->teachers()
                ->where('teachers.id', $teacherId)
                ->exists();

            if (!$isRecordedByMe && !$teachesClass) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses untuk menghapus nilai ini.'
                ], 403);
            }
        }

        $assessment->delete();

        return response()->json([
            'success' => true,
            'message' => 'Data nilai berhasil dihapus.',
        ]);
    }
}
