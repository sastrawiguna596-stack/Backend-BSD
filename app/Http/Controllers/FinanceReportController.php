<?php

namespace App\Http\Controllers;

use App\Models\CashTransaction;
use App\Models\Payment;
use App\Models\PaymentPlan;
use App\Models\TeacherPayroll;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinanceReportController extends Controller
{
    /**
     * Dashboard Ringkasan Keuangan (Owner & Admin Only)
     * Menampilkan metrik pendapatan bulanan, piutang/tunggakan, pengeluaran honor guru, dan grafik tren.
     */
    public function dashboard(Request $request)
    {
        $now = Carbon::now();
        $selectedYear = (int) $request->get('year', $now->year);
        $selectedMonth = (int) $request->get('month', $now->month);

        $startOfMonth = Carbon::createFromDate($selectedYear, $selectedMonth, 1)->startOfMonth();
        $endOfMonth = Carbon::createFromDate($selectedYear, $selectedMonth, 1)->endOfMonth();

        // 1. Total Pendapatan Bulan Ini (Paid Payments)
        $monthlyPayments = Payment::where('payment_status', 'paid')
            ->whereBetween('paid_at', [$startOfMonth, $endOfMonth]);

        $totalMonthlyIncome = (float) $monthlyPayments->sum('total_amount');
        $digitalIncome = (float) Payment::where('payment_status', 'paid')
            ->whereBetween('paid_at', [$startOfMonth, $endOfMonth])
            ->whereIn('payment_method', ['va', 'qris', 'ewallet'])
            ->sum('total_amount');

        $cashIncome = (float) Payment::where('payment_status', 'paid')
            ->whereBetween('paid_at', [$startOfMonth, $endOfMonth])
            ->where('payment_method', 'cash')
            ->sum('total_amount');

        // 2. Total Tunggakan (Outstanding / Overdue)
        // A. Overdue (melewati tanggal jatuh tempo dan belum lunas)
        $totalOverdueAmount = (float) PaymentPlan::where('is_paid', false)
            ->where('status', '!=', 'cancelled')
            ->where('due_date', '<', $now->toDateString())
            ->sum('amount');

        $totalOverdueCount = PaymentPlan::where('is_paid', false)
            ->where('status', '!=', 'cancelled')
            ->where('due_date', '<', $now->toDateString())
            ->count();

        // B. Total Pending / Unpaid bulan berjalan
        $totalUpcomingAmount = (float) PaymentPlan::where('is_paid', false)
            ->where('status', '!=', 'cancelled')
            ->where('due_date', '>=', $now->toDateString())
            ->sum('amount');

        // 3. Pengeluaran Payroll / Honor Guru Bulan Ini
        $totalPayrollExpense = (float) TeacherPayroll::whereBetween('period_start', [$startOfMonth, $endOfMonth])
            ->whereIn('status', ['paid', 'approved'])
            ->sum('total_amount');

        $pendingPayrollExpense = (float) TeacherPayroll::whereBetween('period_start', [$startOfMonth, $endOfMonth])
            ->whereIn('status', ['draft', 'submitted', 'pending'])
            ->sum('total_amount');

        // 4. Net Profit / Laba Operasional Bulan Ini
        $netOperatingIncome = $totalMonthlyIncome - $totalPayrollExpense;

        // 5. Antrean Verifikasi Cash Masuk
        $pendingCashConfirmationCount = CashTransaction::where('status', 'menunggu_konfirmasi_admin')->count();
        $pendingCashConfirmationAmount = (float) CashTransaction::where('status', 'menunggu_konfirmasi_admin')->sum('amount');

        // 6. Tren Pendapatan & Pengeluaran 6 Bulan Terakhir (Untuk Grafik / Chart Frontend)
        $monthlyTrends = [];
        for ($i = 5; $i >= 0; $i--) {
            $monthCursor = Carbon::now()->subMonths($i);
            $startCursor = $monthCursor->copy()->startOfMonth();
            $endCursor = $monthCursor->copy()->endOfMonth();

            $incomeInMonth = (float) Payment::where('payment_status', 'paid')
                ->whereBetween('paid_at', [$startCursor, $endCursor])
                ->sum('total_amount');

            $payrollInMonth = (float) TeacherPayroll::whereBetween('period_start', [$startCursor, $endCursor])
                ->whereIn('status', ['paid', 'approved'])
                ->sum('total_amount');

            $monthlyTrends[] = [
                'month'       => $monthCursor->format('M Y'),
                'month_raw'   => $monthCursor->format('Y-m'),
                'income'      => $incomeInMonth,
                'expense'     => $payrollInMonth,
                'net_balance' => $incomeInMonth - $payrollInMonth,
            ];
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'period' => [
                    'year'  => $selectedYear,
                    'month' => $selectedMonth,
                    'label' => $startOfMonth->translatedFormat('F Y'),
                ],
                'summary' => [
                    'total_income'     => $totalMonthlyIncome,
                    'digital_income'   => $digitalIncome,
                    'cash_income'      => $cashIncome,
                    'total_payroll'    => $totalPayrollExpense,
                    'pending_payroll'  => $pendingPayrollExpense,
                    'net_income'       => $netOperatingIncome,
                ],
                'outstanding' => [
                    'overdue_amount'  => $totalOverdueAmount,
                    'overdue_count'   => $totalOverdueCount,
                    'upcoming_amount' => $totalUpcomingAmount,
                ],
                'cash_verification_queue' => [
                    'count'  => $pendingCashConfirmationCount,
                    'amount' => $pendingCashConfirmationAmount,
                ],
                'monthly_trends' => $monthlyTrends,
            ]
        ]);
    }

    /**
     * Laporan Detail Arus Kas Masuk (Income Report)
     * Filter: start_date, end_date, payment_method, student_id, program_id
     */
    public function incomeReport(Request $request)
    {
        $startDate = $request->get('start_date', Carbon::now()->startOfMonth()->toDateString());
        $endDate   = $request->get('end_date', Carbon::now()->endOfMonth()->toDateString());

        $query = Payment::with([
            'enrollment.student',
            'enrollment.program',
            'enrollment.classroom',
            'paymentPlan',
            'verifier:id,name',
        ])
        ->where('payment_status', 'paid')
        ->whereBetween('paid_at', [
            Carbon::parse($startDate)->startOfDay(),
            Carbon::parse($endDate)->endOfDay(),
        ])
        ->when($request->filled('payment_method'), function ($q) use ($request) {
            $q->where('payment_method', $request->payment_method);
        })
        ->when($request->filled('student_id'), function ($q) use ($request) {
            $q->where('student_id', $request->student_id);
        })
        ->when($request->filled('program_id'), function ($q) use ($request) {
            $q->whereHas('enrollment', function ($sq) use ($request) {
                $sq->where('program_id', $request->program_id);
            });
        })
        ->orderBy('paid_at', 'desc');

        $totalIncome = (float) (clone $query)->sum('total_amount');
        $totalFee = (float) (clone $query)->sum('fee');
        $totalBaseAmount = (float) (clone $query)->sum('amount');
        $transactionsCount = (clone $query)->count();

        $payments = $query->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data'    => [
                'period' => [
                    'start_date' => $startDate,
                    'end_date'   => $endDate,
                ],
                'statistics' => [
                    'total_transactions' => $transactionsCount,
                    'total_base_amount'  => $totalBaseAmount,
                    'total_fee'          => $totalFee,
                    'total_income'       => $totalIncome,
                ],
                'payments' => $payments,
            ]
        ]);
    }

    /**
     * Laporan Piutang / Tagihan Belum Lunas (Outstanding Report)
     * Filter: status (overdue, unpaid), student_id, program_id
     */
    public function outstandingReport(Request $request)
    {
        $today = Carbon::today()->toDateString();

        $query = PaymentPlan::with([
            'enrollment.student',
            'enrollment.program',
            'enrollment.classroom',
        ])
        ->where('is_paid', false)
        ->where('status', '!=', 'cancelled')
        ->when($request->filled('status'), function ($q) use ($request, $today) {
            $status = strtolower($request->status);
            if ($status === 'overdue') {
                $q->where('due_date', '<', $today);
            } elseif ($status === 'upcoming' || $status === 'unpaid') {
                $q->where('due_date', '>=', $today);
            }
        })
        ->when($request->filled('student_id'), function ($q) use ($request) {
            $q->whereHas('enrollment', function ($sq) use ($request) {
                $sq->where('student_id', $request->student_id);
            });
        })
        ->when($request->filled('program_id'), function ($q) use ($request) {
            $q->whereHas('enrollment', function ($sq) use ($request) {
                $sq->where('program_id', $request->program_id);
            });
        })
        ->orderBy('due_date', 'asc');

        $totalOutstanding = (float) (clone $query)->sum('amount');
        $totalOverdue = (float) (clone $query)->where('due_date', '<', $today)->sum('amount');
        $totalUpcoming = (float) (clone $query)->where('due_date', '>=', $today)->sum('amount');
        $unpaidCount = (clone $query)->count();

        $plans = $query->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data'    => [
                'statistics' => [
                    'total_unpaid_invoices' => $unpaidCount,
                    'total_outstanding'     => $totalOutstanding,
                    'total_overdue'         => $totalOverdue,
                    'total_upcoming'        => $totalUpcoming,
                ],
                'plans' => $plans,
            ]
        ]);
    }

    /**
     * Laporan Rekonsiliasi Kas Harian / Bulanan (Cash Reconciliation Report)
     * Membandingkan dan memvalidasi arus kas fisik meja kasir dengan mutasi digital gateway.
     */
    public function cashReconciliation(Request $request)
    {
        $date = $request->get('date', Carbon::today()->toDateString());
        $startOfDay = Carbon::parse($date)->startOfDay();
        $endOfDay   = Carbon::parse($date)->endOfDay();

        // 1. Penerimaan Kas Fisik Terverifikasi (Cash Transactions Paid)
        $verifiedCashQuery = CashTransaction::with([
            'enrollment.student',
            'submitter:id,name',
            'verifier:id,name',
        ])
        ->where('status', 'paid')
        ->whereBetween('verified_at', [$startOfDay, $endOfDay]);

        $verifiedCashTotal = (float) (clone $verifiedCashQuery)->sum('amount');
        $verifiedCashList = $verifiedCashQuery->get();

        // 2. Penerimaan Digital Terverifikasi (Digital Payments Paid)
        $digitalPaymentsQuery = Payment::with(['enrollment.student'])
            ->where('payment_status', 'paid')
            ->whereIn('payment_method', ['va', 'qris', 'ewallet'])
            ->whereBetween('paid_at', [$startOfDay, $endOfDay]);

        $digitalTotal = (float) (clone $digitalPaymentsQuery)->sum('total_amount');
        $digitalList = $digitalPaymentsQuery->get();

        // 3. Kas Fisik yang Masih Pending / Menunggu Konfirmasi di tanggal tersebut
        $pendingCashTotal = (float) CashTransaction::where('status', 'menunggu_konfirmasi_admin')
            ->whereBetween('submitted_at', [$startOfDay, $endOfDay])
            ->sum('amount');

        return response()->json([
            'success' => true,
            'data'    => [
                'date' => $date,
                'summary' => [
                    'verified_physical_cash' => $verifiedCashTotal,
                    'verified_digital_total' => $digitalTotal,
                    'total_reconciled_income'=> $verifiedCashTotal + $digitalTotal,
                    'pending_cash_to_verify' => $pendingCashTotal,
                ],
                'cash_transactions' => $verifiedCashList,
                'digital_transactions' => $digitalList,
            ]
        ]);
    }
}
