<?php

namespace App\Http\Controllers;

use App\Models\Teacher;
use App\Models\TeacherPayroll;
use App\Models\ClassSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class TeacherPayrollController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $role = $user ? strtolower($user->role) : '';

        if ($role === 'parent') {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki hak akses ke data payroll.'
            ], 403);
        }

        $isTeacher = $role === 'teacher';
        $teacherId = null;

        if ($isTeacher) {
            $teacherId = Teacher::where('user_id', $user->id)->value('id') ?: '00000000-0000-0000-0000-000000000000';
        }

        $query = TeacherPayroll::with('teacher.user')
            ->when($isTeacher, function ($q) use ($teacherId) {
                $q->where('teacher_id', $teacherId);
            })
            ->when($request->filled('teacher_id') && !$isTeacher, function ($q) use ($request) {
                $q->where('teacher_id', $request->teacher_id);
            })
            ->latest('period_start');

        $payrolls = $query->get();
        return response()->json(['success' => true, 'data' => $payrolls]);
    }

    public function show(Request $request, string $id)
    {
        $user = $request->user();
        $role = $user ? strtolower($user->role) : '';

        if ($role === 'parent') {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki hak akses ke data payroll.'
            ], 403);
        }

        $payroll = TeacherPayroll::with('teacher.user')->findOrFail($id);

        if ($role === 'teacher') {
            $myTeacherId = Teacher::where('user_id', $user->id)->value('id');
            if ($payroll->teacher_id !== $myTeacherId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses ke data payroll ini.'
                ], 403);
            }
        }

        return response()->json(['success' => true, 'data' => $payroll]);
    }

    /**
     * Generate payroll otomatis untuk seorang guru.
     * Honor dihitung berdasarkan satuan 30 menit mengajar.
     *
     * POST /api/v1/teacher-payrolls/generate
     * Body: { teacher_id, period_start, period_end }
     */
    public function generatePayroll(Request $request)
    {
        $request->validate([
            'teacher_id'   => 'required|exists:teachers,id',
            'period_start' => 'required|date',
            'period_end'   => 'required|date|after_or_equal:period_start',
        ]);

        $teacherId   = $request->teacher_id;
        $periodStart = $request->period_start;
        $periodEnd   = $request->period_end;

        // ── 1. Ambil semua sesi mengajar guru yang selesai dalam periode ────
        // Sesi dihitung jika: status = 'completed' ATAU ada logbook
        // Dan bukan hari libur
        $sessions = ClassSession::where('teacher_id', $teacherId)
            ->where('is_holiday', false)
            ->whereBetween('session_date', [$periodStart, $periodEnd])
            ->where(function ($query) {
                $query->where('status', 'completed')
                      ->orWhereHas('teacherLogbook');
            })
            ->get();

        // ── 2. Hitung total unit 30 menit dari semua sesi ───────────────────
        $totalUnits = 0;
        foreach ($sessions as $session) {
            $totalUnits += self::hitungUnit30Menit(
                $session->start_time,
                $session->end_time
            );
        }

        // ── 3. Konversi ke jam desimal dan hitung honor ─────────────────────
        // teaching_hours disimpan dalam JAM (kelipatan 0.5)
        // Contoh: 3 unit = 1.5 jam
        $teachingHours   = $totalUnits * 0.5;
        $tarifPer30Menit = 37500; // Rp 37.500 per 30 menit
        $baseAmount      = $totalUnits * $tarifPer30Menit;
        $bonusAmount     = 0;
        $totalAmount     = $baseAmount + $bonusAmount;

        // ── 4. Buat atau update payroll ─────────────────────────────────────
        $existing = TeacherPayroll::where('teacher_id', $teacherId)
            ->where('period_start', $periodStart)
            ->where('period_end', $periodEnd)
            ->first();

        if ($existing) {
            $existing->update([
                'teaching_hours' => $teachingHours,
                'base_amount'    => $baseAmount,
                'total_amount'   => $totalAmount,
            ]);
            $payroll = $existing->fresh(['teacher.user']);
        } else {
            $payroll = TeacherPayroll::create([
                'teacher_id'     => $teacherId,
                'period_start'   => $periodStart,
                'period_end'     => $periodEnd,
                'teaching_hours' => $teachingHours,
                'base_amount'    => $baseAmount,
                'bonus_amount'   => $bonusAmount,
                'total_amount'   => $totalAmount,
                'status'         => 'pending',
                'created_by'     => $request->user()?->id,
            ]);
            $payroll->load('teacher.user');
        }

        return response()->json([
            'success' => true,
            'message' => 'Payroll berhasil dibuat.',
            'data'    => $payroll,
        ], 201);
    }

    /**
     * Helper: hitung jumlah unit 30 menit dari jam mulai dan jam selesai.
     *
     * Contoh:
     *   08:00 – 08:30  → 1 unit  (= 0.5 jam)
     *   08:00 – 09:00  → 2 unit  (= 1.0 jam)
     *   08:00 – 09:30  → 3 unit  (= 1.5 jam)
     *   null  / null   → 1 unit  (default)
     *
     * @param  string|null $startTime  Format "HH:MM" atau "HH:MM:SS"
     * @param  string|null $endTime    Format "HH:MM" atau "HH:MM:SS"
     * @return int  Jumlah unit 30 menit (minimal 1)
     */
    protected static function hitungUnit30Menit(?string $startTime, ?string $endTime): int
    {
        if (!$startTime || !$endTime) {
            return 1; // default 1 unit jika tidak ada data waktu
        }

        [$sh, $sm] = array_map('intval', explode(':', $startTime));
        [$eh, $em] = array_map('intval', explode(':', $endTime));

        $diffMinutes = ($eh * 60 + $em) - ($sh * 60 + $sm);

        if ($diffMinutes <= 0) {
            return 1; // waktu tidak valid → fallback 1 unit
        }

        // Bulatkan ke unit 30 menit terdekat (minimal 1 unit)
        return max(1, (int) round($diffMinutes / 30));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'teacher_id'     => 'required|uuid|exists:teachers,id',
            'period_start'   => 'required|date',
            'period_end'     => 'required|date|after_or_equal:period_start',
            'teaching_hours' => 'nullable|numeric|min:0',
            'base_amount'    => 'required|numeric|min:0',
            'bonus_amount'   => 'nullable|numeric|min:0',
            'total_amount'   => 'nullable|numeric|min:0',
            'status'         => 'nullable|string|in:draft,pending,approved,paid,cancelled',
            'notes'          => 'nullable|string',
        ]);

        $teachingHours = $validated['teaching_hours'] ?? 0;
        $baseAmount = $validated['base_amount'];
        $bonusAmount = $validated['bonus_amount'] ?? 0;
        $totalAmount = $validated['total_amount'] ?? ($baseAmount + $bonusAmount);

        $payload = [
            'teacher_id'     => $validated['teacher_id'],
            'period_start'   => $validated['period_start'],
            'period_end'     => $validated['period_end'],
            'teaching_hours' => $teachingHours,
            'base_amount'    => $baseAmount,
            'bonus_amount'   => $bonusAmount,
            'total_amount'   => $totalAmount,
            'status'         => $validated['status'] ?? 'draft',
            'created_by'     => $request->user()?->id,
        ];

        if (Schema::hasColumn('teacher_payrolls', 'notes')) {
            $payload['notes'] = $validated['notes'] ?? null;
        }

        $payroll = TeacherPayroll::create($payload);

        return response()->json([
            'success' => true,
            'message' => 'Payroll berhasil dibuat.',
            'data'    => $payroll->load('teacher.user'),
        ], 201);
    }

    public function update(Request $request, string $id)
    {
        $payroll = TeacherPayroll::findOrFail($id);

        $request->validate([
            'status'         => 'sometimes|in:pending,paid,partial,draft,approved,cancelled',
            'bonus_amount'   => 'sometimes|numeric|min:0',
            'teaching_hours' => 'sometimes|numeric|min:0', // numeric, bukan integer
            'notes'          => 'sometimes|string|nullable',
        ]);

        $updateData = $request->only(['status', 'bonus_amount', 'teaching_hours', 'notes']);

        // Jika notes dikirim tapi kolom belum ada di DB, abaikan
        if (array_key_exists('notes', $updateData) && !Schema::hasColumn('teacher_payrolls', 'notes')) {
            unset($updateData['notes']);
        }

        // Jika bonus diubah, recalculate total_amount otomatis
        if ($request->has('bonus_amount')) {
            $updateData['total_amount'] = $payroll->base_amount + (float) $request->bonus_amount;
        }

        $payroll->update($updateData);

        return response()->json([
            'success' => true,
            'message' => 'Payroll berhasil diperbarui.',
            'data'    => $payroll->fresh(['teacher.user']),
        ]);
    }

    public function destroy(string $id)
    {
        $payroll = TeacherPayroll::findOrFail($id);
        $payroll->delete();
        return response()->json(['success' => true, 'message' => 'Payroll berhasil dihapus.']);
    }
}
