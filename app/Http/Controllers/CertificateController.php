<?php

namespace App\Http\Controllers;

use App\Models\Certificate;
use App\Models\Student;
use App\Models\Enrollment;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CertificateController extends Controller
{
    /**
     * GET /certificates
     * Filter: student_id, enrollment_id
     */
    public function index(Request $request)
    {
        $query = Certificate::with([
            'student',
            'enrollment.classroom',
            'enrollment.program',
            'enrollment.programLevel',
            'finalReport',
            'generatedBy',
        ]);

        if ($request->filled('student_id')) {
            $query->where('student_id', $request->student_id);
        }

        if ($request->filled('enrollment_id')) {
            $query->where('enrollment_id', $request->enrollment_id);
        }

        $certificates = $query->latest('issued_date')->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data'    => $certificates,
        ]);
    }

    /**
     * POST /certificates
     * Issue a new certificate for a student enrollment.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'student_id'    => 'required|uuid|exists:students,id',
            'enrollment_id' => 'required|uuid|exists:enrollments,id',
            'final_report_id' => 'nullable|uuid|exists:final_reports,id',
            'issued_date'   => 'required|date',
            'file_url'      => 'nullable|string',
        ]);

        // Generate unique certificate code
        $code = 'BSD/CERT/' . date('Y') . '/' . strtoupper(Str::random(6));

        $cert = Certificate::create([
            'certificate_code'     => $code,
            'student_id'           => $validated['student_id'],
            'enrollment_id'        => $validated['enrollment_id'],
            'final_report_id'      => $validated['final_report_id'] ?? null,
            'certificate_template_id' => null,
            'issued_date'          => $validated['issued_date'],
            'file_url'             => $validated['file_url'] ?? null,
            'generated_by'         => $request->user()?->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Sertifikat berhasil diterbitkan.',
            'data'    => $cert->load(['student', 'enrollment.classroom', 'enrollment.program', 'generatedBy']),
        ], 201);
    }

    /**
     * GET /certificates/{id}
     */
    public function show(string $id)
    {
        $cert = Certificate::with([
            'student',
            'enrollment.classroom',
            'enrollment.program',
            'enrollment.programLevel',
            'finalReport',
            'generatedBy',
        ])->findOrFail($id);

        return response()->json(['success' => true, 'data' => $cert]);
    }

    /**
     * PUT /certificates/{id}
     */
    public function update(Request $request, string $id)
    {
        $cert = Certificate::findOrFail($id);
        $validated = $request->validate([
            'issued_date' => 'sometimes|date',
            'file_url'    => 'nullable|string',
        ]);
        $cert->update($validated);
        return response()->json(['success' => true, 'data' => $cert->fresh()]);
    }

    /**
     * DELETE /certificates/{id}
     */
    public function destroy(string $id)
    {
        Certificate::findOrFail($id)->delete();
        return response()->json(['success' => true, 'message' => 'Sertifikat dihapus.']);
    }
}