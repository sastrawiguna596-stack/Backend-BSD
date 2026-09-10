<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\FinalReport;
use Illuminate\Http\Request;

class FinalReportController extends Controller
{
    /**
     * List semua laporan akhir.
     * Support filter: student_id, graduation_status
     */
    public function index(Request $request)
    {
        $reports = FinalReport::with(['student', 'enrollment.programLevel', 'verifiedBy'])
            ->when($request->student_id, fn($q) => $q->where('student_id', $request->student_id))
            ->when($request->graduation_status, fn($q) => $q->where('graduation_status', $request->graduation_status))
            ->latest()
            ->paginate($request->get('per_page', 15));

        return response()->json(['success' => true, 'data' => $reports]);
    }

    /**
     * Generate (atau overwrite) laporan akhir untuk satu enrollment.
     * Menghitung final_score dari rata-rata tertimbang semua assessment pada enrollment.
     * Jika laporan sudah ada, data lama di-overwrite.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'enrollment_id' => 'required|uuid|exists:enrollments,id',
            'passing_grade' => 'nullable|numeric|min:0|max:100',
            'notes'         => 'nullable|string',
        ]);

        $enrollment   = Enrollment::with('student', 'programLevel')->findOrFail($validated['enrollment_id']);
        $passingGrade = $validated['passing_grade'] ?? 70;

        // Ambil semua assessment berstatus final untuk enrollment ini
        $assessments = Assessment::where('enrollment_id', $enrollment->id)
            ->where('status', 'final')
            ->get();

        // Hitung rata-rata tertimbang
        $totalWeightedScore = $assessments->sum(fn($a) => $a->score * $a->weight);
        $totalWeight        = $assessments->sum('weight');
        $finalScore         = $totalWeight > 0 ? round($totalWeightedScore / $totalWeight, 2) : 0;

        $graduationStatus = $finalScore >= $passingGrade ? 'passed' : 'failed';

        // Overwrite jika laporan untuk enrollment ini sudah ada
        $report = FinalReport::updateOrCreate(
            ['enrollment_id' => $enrollment->id],
            [
                'student_id'        => $enrollment->student_id,
                'final_score'       => $finalScore,
                'graduation_status' => $graduationStatus,
                'generated_at'      => now()->toDateString(),
                'verified_by'       => $request->user()?->id,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Laporan akhir berhasil di-generate.',
            'data'    => [
                'report'           => $report->load(['student', 'enrollment.programLevel']),
                'assessments_used' => $assessments->count(),
                'passing_grade'    => $passingGrade,
                'final_score'      => $finalScore,
                'graduation_status'=> $graduationStatus,
            ],
        ], 201);
    }

    /**
     * Detail satu laporan akhir beserta daftar assessment yang dipakai.
     */
    public function show(string $id)
    {
        $report = FinalReport::with(['student', 'enrollment.programLevel', 'verifiedBy'])
            ->findOrFail($id);

        // Sertakan daftar assessment yang digunakan dalam laporan ini
        $assessments = Assessment::where('enrollment_id', $report->enrollment_id)
            ->where('status', 'final')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'report'      => $report,
                'assessments' => $assessments,
            ],
        ]);
    }

    /**
     * Edit manual laporan akhir.
     * Admin/Owner bisa override nilai dan status kelulusan tanpa hitung ulang.
     */
    public function update(Request $request, string $id)
    {
        $report = FinalReport::findOrFail($id);

        $validated = $request->validate([
            'final_score'       => 'sometimes|numeric|min:0|max:100',
            'graduation_status' => 'sometimes|string|in:passed,failed,pending',
            'notes'             => 'nullable|string',
        ]);

        // Jika final_score diubah tanpa graduation_status, hitung ulang otomatis
        if (isset($validated['final_score']) && !isset($validated['graduation_status'])) {
            $passingGrade                 = $request->get('passing_grade', 70);
            $validated['graduation_status'] = $validated['final_score'] >= $passingGrade ? 'passed' : 'failed';
        }

        $report->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Laporan akhir berhasil diperbarui.',
            'data'    => $report->fresh()->load(['student', 'enrollment.programLevel']),
        ]);
    }

    /**
     * Hapus laporan akhir.
     */
    public function destroy(string $id)
    {
        FinalReport::findOrFail($id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Laporan akhir berhasil dihapus.',
        ]);
    }
}
