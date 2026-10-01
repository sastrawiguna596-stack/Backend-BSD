<?php

namespace App\Http\Controllers;

use App\Models\Certificate;
use App\Models\CertificateTemplate;
use App\Models\FinalReport;
use App\Models\Enrollment;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CertificateController extends Controller
{
    /**
     * List all certificates with relations.
     */
    public function index(Request $request)
    {
        $query = Certificate::with([
            'student',
            'enrollment.programLevel',
            'enrollment.classroom',
            'finalReport',
            'generatedBy:id,name,email,role'
        ]);

        if ($request->filled('student_id')) {
            $query->where('student_id', $request->student_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('certificate_code', 'like', "%{$search}%")
                  ->orWhereHas('student', function ($sq) use ($search) {
                      $sq->where('name', 'like', "%{$search}%");
                  });
            });
        }

        $certificates = $query->latest('issued_date')->paginate($request->input('per_page', 20));

        return response()->json([
            'success' => true,
            'data'    => $certificates,
        ]);
    }

    /**
     * Generate a new certificate.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'student_id'              => 'required|uuid|exists:students,id',
            'enrollment_id'           => 'required|uuid|exists:enrollments,id',
            'final_report_id'         => 'nullable|uuid|exists:final_reports,id',
            'certificate_template_id' => 'nullable|uuid|exists:certificate_templates,id',
            'issued_date'             => 'nullable|date',
            'file_url'                => 'nullable|string',
        ]);

        $enrollment = Enrollment::with('programLevel')->findOrFail($validated['enrollment_id']);

        // Find or create final report if not supplied
        $finalReportId = $validated['final_report_id'] ?? null;
        if (!$finalReportId) {
            $finalReport = FinalReport::where('enrollment_id', $enrollment->id)->first();
            if (!$finalReport) {
                $finalReport = FinalReport::create([
                    'report_code'       => 'REP-' . date('Ymd') . '-' . strtoupper(Str::random(4)),
                    'student_id'        => $validated['student_id'],
                    'enrollment_id'     => $enrollment->id,
                    'final_score'       => 85.0,
                    'graduation_status' => 'passed',
                    'generated_at'      => now(),
                    'verified_by'       => $request->user()?->id,
                ]);
            }
            $finalReportId = $finalReport->id;
        }

        // Find or create certificate template if not supplied
        $templateId = $validated['certificate_template_id'] ?? null;
        if (!$templateId) {
            $template = CertificateTemplate::first();
            if (!$template) {
                $template = CertificateTemplate::create([
                    'program_id'       => $enrollment->program_id,
                    'program_level_id' => $enrollment->program_level_id,
                    'template_name'    => 'Template Standar ' . date('Y'),
                    'template_file'    => 'templates/default-certificate.pdf',
                    'is_active'        => true,
                ]);
            }
            $templateId = $template->id;
        }

        $codeNum = Certificate::count() + 1;
        $code = sprintf('BSD/CERT/%s/%03d', date('Y'), $codeNum);

        $certificate = Certificate::create([
            'certificate_code'        => $code,
            'student_id'              => $validated['student_id'],
            'enrollment_id'           => $enrollment->id,
            'final_report_id'         => $finalReportId,
            'certificate_template_id' => $templateId,
            'issued_date'             => $validated['issued_date'] ?? now()->toDateString(),
            'file_url'                => $validated['file_url'] ?? null,
            'generated_by'            => $request->user()?->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Sertifikat berhasil diterbitkan.',
            'data'    => $certificate->load(['student', 'enrollment.programLevel', 'finalReport']),
        ], 201);
    }

    /**
     * Detail one certificate.
     */
    public function show(string $id)
    {
        $certificate = Certificate::with([
            'student',
            'enrollment.programLevel',
            'enrollment.classroom',
            'finalReport',
            'certificateTemplate',
            'generatedBy:id,name,email,role'
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => $certificate,
        ]);
    }

    /**
     * Delete certificate.
     */
    public function destroy(string $id)
    {
        $certificate = Certificate::findOrFail($id);
        $certificate->delete();

        return response()->json([
            'success' => true,
            'message' => 'Sertifikat berhasil dihapus.',
        ]);
    }
}
