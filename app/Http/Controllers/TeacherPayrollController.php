<?php

namespace App\Http\Controllers;

use App\Models\Teacher;
use App\Models\TeacherPayroll;
use App\Models\TeacherLogbook;
use App\Models\TeacherHourlyRate;
use App\Models\TeacherBonus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
     * Generate payroll for a teacher based on teaching logs and active rates.
     */
    public function generatePayroll(Request $request)
    {
        $validated = $request->validate([
            'teacher_id' => 'required|uuid|exists:teachers,id',
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start'
        ]);

        $teacherId = $validated['teacher_id'];
        $periodStart = $validated['period_start'];
        $periodEnd = $validated['period_end'];

        try {
            DB::beginTransaction();

            // 1. Get total teaching minutes from verified/submitted logbooks
            $totalMinutes = TeacherLogbook::where('teacher_id', $teacherId)
                ->whereIn('status', ['submitted', 'verified'])
                ->whereDate('check_in', '>=', $periodStart)
                ->whereDate('check_in', '<=', $periodEnd)
                ->sum('teaching_minutes');

            $teachingHours = $totalMinutes / 60;

            // 2. Get the active hourly rate for the period
            // Assuming the rate is effective during the period end
            $activeRate = TeacherHourlyRate::where('teacher_id', $teacherId)
                ->where('effective_from', '<=', $periodEnd)
                ->where(function ($query) use ($periodEnd) {
                    $query->whereNull('effective_until')
                          ->orWhere('effective_until', '>=', $periodEnd);
                })
                ->orderBy('effective_from', 'desc')
                ->first();

            $hourlyRate = $activeRate ? $activeRate->hourly_rate : 0;
            $baseAmount = $teachingHours * $hourlyRate;

            // 3. Get total bonuses in the period
            $bonusAmount = TeacherBonus::where('teacher_id', $teacherId)
                ->whereDate('bonus_date', '>=', $periodStart)
                ->whereDate('bonus_date', '<=', $periodEnd)
                ->sum('amount');

            $totalAmount = $baseAmount + $bonusAmount;

            // 4. Create Payroll
            $payroll = TeacherPayroll::create([
                'teacher_id' => $teacherId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'teaching_hours' => $teachingHours,
                'base_amount' => $baseAmount,
                'bonus_amount' => $bonusAmount,
                'total_amount' => $totalAmount,
                'status' => 'draft',
                'created_by' => $request->user() ? $request->user()->id : null
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Payroll berhasil di-generate.',
                'data' => $payroll
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal meng-generate payroll.',
                'error' => $e->getMessage()
            ], 500);
        }
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

        $validated = $request->validate([
            'status'         => 'sometimes|string|in:draft,pending,approved,paid,cancelled,partial',
            'teaching_hours' => 'sometimes|numeric|min:0',
            'base_amount'    => 'sometimes|numeric|min:0',
            'bonus_amount'   => 'sometimes|numeric|min:0',
            'total_amount'   => 'sometimes|numeric|min:0',
            'notes'          => 'nullable|string',
        ]);

        if (isset($validated['bonus_amount']) || isset($validated['base_amount'])) {
            $base = $validated['base_amount'] ?? $payroll->base_amount;
            $bonus = $validated['bonus_amount'] ?? $payroll->bonus_amount;
            if (!isset($validated['total_amount'])) {
                $validated['total_amount'] = $base + $bonus;
            }
        }

        if (array_key_exists('notes', $validated) && !Schema::hasColumn('teacher_payrolls', 'notes')) {
            unset($validated['notes']);
        }

        $payroll->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Payroll berhasil diperbarui.',
            'data'    => $payroll->fresh('teacher.user'),
        ]);
    }

    public function destroy(string $id)
    {
        $payroll = TeacherPayroll::findOrFail($id);
        $payroll->delete();
        return response()->json(['success' => true, 'message' => 'Payroll berhasil dihapus.']);
    }
}
