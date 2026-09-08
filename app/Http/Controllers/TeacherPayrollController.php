<?php

namespace App\Http\Controllers;

use App\Models\TeacherPayroll;
use App\Models\TeacherLogbook;
use App\Models\TeacherHourlyRate;
use App\Models\TeacherBonus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TeacherPayrollController extends Controller
{
    public function index()
    {
        $payrolls = TeacherPayroll::with('teacher')->get();
        return response()->json(['success' => true, 'data' => $payrolls]);
    }

    public function show(string $id)
    {
        $payroll = TeacherPayroll::with('teacher')->findOrFail($id);
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

    public function destroy(string $id)
    {
        $payroll = TeacherPayroll::findOrFail($id);
        $payroll->delete();
        return response()->json(['success' => true, 'message' => 'Payroll berhasil dihapus.']);
    }
}
