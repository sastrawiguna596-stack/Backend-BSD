<?php

namespace App\Http\Controllers;

use App\Models\Enrollment;
use App\Models\PaymentPlan;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentPlanController extends Controller
{
    /**
     * Menampilkan daftar tagihan (Payment Plans).
     * Mendukung filter status (paid, unpaid, overdue, pending), is_paid, enrollment_id, dan student_id.
     */
    public function index(Request $request)
    {
        $query = PaymentPlan::with([
            'enrollment.student',
            'enrollment.program',
            'enrollment.programLevel',
            'payments'
        ])
        ->when($request->filled('status'), function ($q) use ($request) {
            $status = strtolower($request->status);
            if ($status === 'paid') {
                $q->where(function ($sub) {
                    $sub->where('is_paid', true)
                        ->orWhere('status', 'paid');
                });
            } elseif ($status === 'overdue') {
                $q->where('is_paid', false)
                  ->where('status', '!=', 'cancelled')
                  ->where('due_date', '<', Carbon::today()->toDateString());
            } elseif ($status === 'unpaid' || $status === 'pending') {
                $q->where('is_paid', false)
                  ->where('status', '!=', 'cancelled')
                  ->where('due_date', '>=', Carbon::today()->toDateString());
            } else {
                $q->where('status', $request->status);
            }
        })
        ->when($request->has('is_paid'), function ($q) use ($request) {
            $q->where('is_paid', filter_var($request->is_paid, FILTER_VALIDATE_BOOLEAN));
        })
        ->when($request->filled('enrollment_id'), function ($q) use ($request) {
            $q->where('enrollment_id', $request->enrollment_id);
        })
        ->when($request->filled('student_id'), function ($q) use ($request) {
            $q->whereHas('enrollment', function ($sq) use ($request) {
                $sq->where('student_id', $request->student_id);
            });
        })
        ->orderBy('due_date', 'asc');

        $paymentPlans = $query->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data'    => $paymentPlans,
        ]);
    }

    /**
     * Membuat satu tagihan baru secara manual.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'enrollment_id'   => 'required|uuid|exists:enrollments,id',
            'session_number'  => 'required|integer|min:1',
            'amount'          => 'required|numeric|min:0',
            'due_date'        => 'required|date',
            'status'          => 'nullable|string|in:pending,unpaid,paid,cancelled',
        ]);

        $validated['status'] = $validated['status'] ?? 'pending';
        $validated['is_paid'] = ($validated['status'] === 'paid');

        $paymentPlan = PaymentPlan::create($validated);
        $paymentPlan->load(['enrollment.student', 'enrollment.program']);

        return response()->json([
            'success' => true,
            'message' => 'Tagihan berhasil dibuat.',
            'data'    => $paymentPlan,
        ], 201);
    }

    /**
     * Generate tagihan otomatis untuk sebuah enrollment berdasarkan paket sesi.
     * Mendukung 2 skema sesuai SRS Meeting:
     * 1. Skema cicilan merata (installments & interval_weeks)
     * 2. Skema bertahap kustom / milestone (array custom_schedules dengan session_number, percentage / amount, due_date)
     */
    public function generateForEnrollment(Request $request)
    {
        $validated = $request->validate([
            'enrollment_id'       => 'required|uuid|exists:enrollments,id',
            'total_amount'        => 'required|numeric|min:0',
            // Opsi 1: Otomatis bagi rata
            'installments'        => 'nullable|integer|min:1|max:12',
            'first_due_date'      => 'nullable|date',
            'interval_weeks'      => 'nullable|integer|min:1',
            // Opsi 2: Kustom persentase / milestone termin
            'custom_schedules'    => 'nullable|array|min:1',
            'custom_schedules.*.session_number' => 'required_with:custom_schedules|integer|min:1',
            'custom_schedules.*.due_date'       => 'required_with:custom_schedules|date',
            'custom_schedules.*.amount'         => 'nullable|numeric|min:0',
            'custom_schedules.*.percentage'     => 'nullable|numeric|min:0|max:100',
        ]);

        $enrollment = Enrollment::findOrFail($validated['enrollment_id']);
        $totalAmount = (float) $validated['total_amount'];
        $createdPlans = [];

        DB::transaction(function () use ($enrollment, $totalAmount, $validated, &$createdPlans) {
            // Hapus draft tagihan lama jika belum ada yang dibayar
            PaymentPlan::where('enrollment_id', $enrollment->id)
                ->where('is_paid', false)
                ->delete();

            if (!empty($validated['custom_schedules'])) {
                // Skema Bertahap / Milestone Kustom
                foreach ($validated['custom_schedules'] as $item) {
                    $amount = isset($item['amount'])
                        ? (float)$item['amount']
                        : round(($totalAmount * ((float)$item['percentage'] / 100)), 2);

                    $plan = PaymentPlan::create([
                        'enrollment_id'  => $enrollment->id,
                        'session_number' => $item['session_number'],
                        'amount'         => $amount,
                        'due_date'       => $item['due_date'],
                        'status'         => 'pending',
                        'is_paid'        => false,
                    ]);

                    $createdPlans[] = $plan;
                }
            } else {
                // Skema Cicilan Bagi Rata
                $installments = $validated['installments'] ?? 1;
                $firstDueDate = $validated['first_due_date'] ?? now()->toDateString();
                $intervalWeeks = $validated['interval_weeks'] ?? 4;
                $amountPerInstallment = round($totalAmount / $installments, 2);

                for ($i = 1; $i <= $installments; $i++) {
                    $dueDate = Carbon::parse($firstDueDate)
                        ->addWeeks(($i - 1) * $intervalWeeks)
                        ->toDateString();

                    $plan = PaymentPlan::create([
                        'enrollment_id'  => $enrollment->id,
                        'session_number' => $i,
                        'amount'         => $amountPerInstallment,
                        'due_date'       => $dueDate,
                        'status'         => 'pending',
                        'is_paid'        => false,
                    ]);

                    $createdPlans[] = $plan;
                }
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Berhasil generate skema tagihan untuk pendaftaran ' . $enrollment->enrollment_code,
            'data'    => $createdPlans,
        ], 201);
    }

    /**
     * Menampilkan detail satu tagihan.
     */
    public function show(string $id)
    {
        $paymentPlan = PaymentPlan::with([
            'enrollment.student',
            'enrollment.program',
            'enrollment.programLevel',
            'payments'
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => $paymentPlan,
        ]);
    }

    /**
     * Memperbarui data tagihan (nominal / tanggal jatuh tempo).
     */
    public function update(Request $request, string $id)
    {
        $paymentPlan = PaymentPlan::findOrFail($id);

        $validated = $request->validate([
            'session_number' => 'sometimes|integer|min:1',
            'amount'         => 'sometimes|numeric|min:0',
            'due_date'       => 'sometimes|date',
            'status'         => 'sometimes|string|in:pending,unpaid,paid,cancelled',
            'is_paid'        => 'sometimes|boolean',
        ]);

        if (isset($validated['status']) && !isset($validated['is_paid'])) {
            $validated['is_paid'] = ($validated['status'] === 'paid');
        }

        $paymentPlan->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Tagihan berhasil diperbarui.',
            'data'    => $paymentPlan->fresh(['enrollment.student']),
        ]);
    }

    /**
     * Menghapus tagihan jika belum lunas.
     */
    public function destroy(string $id)
    {
        $paymentPlan = PaymentPlan::findOrFail($id);

        if ($paymentPlan->is_paid) {
            return response()->json([
                'success' => false,
                'message' => 'Tagihan yang sudah berstatus lunas tidak dapat dihapus.',
            ], 422);
        }

        $paymentPlan->delete();

        return response()->json([
            'success' => true,
            'message' => 'Tagihan berhasil dihapus.',
        ]);
    }
}
