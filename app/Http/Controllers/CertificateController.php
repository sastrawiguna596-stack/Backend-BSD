<?php

namespace App\Http\Controllers;

use App\Models\Certificate;
use App\Models\CertificateTemplate;
use App\Models\FinalReport;
use App\Models\Enrollment;
use App\Models\Teacher;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CertificateController extends Controller
{
    /**
     * GET /certificates
     * Filter: student_id, enrollment_id, search
     * Role-aware: parent hanya lihat anaknya, teacher hanya lihat kelasnya.
     */
    public function index(Request $request)
    {
        $user      = $request->user();
        $isTeacher = $user && strtolower($user->role) === 'teacher';
        $isParent  = $user && strtolower($user->role) === 'parent';
        $teacherId = null;

        if ($isTeacher) {
            $teacherId = Teacher::where('user_id', $user->id)->value('id') ?: '00000000-0000-0000-0000-000000000000';
        }

        $query = Certificate::with([
            'student',
            'enrollment.classroom',
            'enrollment.program',
            'enrollment.programLevel',
            'finalReport',
            'generatedBy:id,name,email,role',
        ])
        ->when($isParent, function ($q) use ($user) {
            $q->whereHas('student.parents', function ($p) use ($user) {
                $p->where('user_id', $user->id);
            });
        })
        ->when($isTeacher, function ($q) use ($teacherId) {
            $q->whereHas('enrollment.classroom.teachers', function ($t) use ($teacherId) {
                $t->where('teachers.id', $teacherId);
            });
        });

        if ($request->filled('student_id')) {
            $query->where('student_id', $request->student_id);
        }

        if ($request->filled('enrollment_id')) {
            $query->where('enrollment_id', $request->enrollment_id);
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
     * POST /certificates
     * Issue a new certificate for a student enrollment.
     * Auto-creates FinalReport & CertificateTemplate jika belum ada.
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

        // Generate unique certificate code
        $codeNum = Certificate::count() + 1;
        $code    = sprintf('BSD/CERT/%s/%03d', date('Y'), $codeNum);

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
            'data'    => $certificate->load([
                'student',
                'enrollment.classroom',
                'enrollment.program',
                'enrollment.programLevel',
                'finalReport',
                'generatedBy',
            ]),
        ], 201);
    }

    /**
     * GET /certificates/{id}
     */
    public function show(string $id)
    {
        $certificate = Certificate::with([
            'student',
            'enrollment.classroom',
            'enrollment.program',
            'enrollment.programLevel',
            'finalReport',
            'certificateTemplate',
            'generatedBy:id,name,email,role',
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => $certificate,
        ]);
    }

    /**
     * PUT /certificates/{id}
     */
    public function update(Request $request, string $id)
    {
        $cert      = Certificate::findOrFail($id);
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
        $certificate = Certificate::findOrFail($id);
        $certificate->delete();

        return response()->json([
            'success' => true,
            'message' => 'Sertifikat berhasil dihapus.',
        ]);
    }
}