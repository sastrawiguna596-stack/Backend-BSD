<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use Illuminate\Http\Request;

class AssessmentController extends Controller
{
    /**
     * List semua penilaian.
     * Support filter: student_id, enrollment_id, program_level_id
     */
    public function index(Request $request)
    {
        $assessments = Assessment::with(['student', 'enrollment', 'programLevel', 'recordedBy'])
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
    public function show(string $id)
    {
        $assessment = Assessment::with(['student', 'enrollment', 'programLevel', 'recordedBy'])
            ->findOrFail($id);

        return response()->json(['success' => true, 'data' => $assessment]);
    }

    /**
     * Koreksi nilai atau bobot.
     * Bisa dilakukan oleh guru, admin, atau owner.
     */
    public function update(Request $request, string $id)
    {
        $assessment = Assessment::findOrFail($id);

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
    public function destroy(string $id)
    {
        Assessment::findOrFail($id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Data nilai berhasil dihapus.',
        ]);
    }
}
