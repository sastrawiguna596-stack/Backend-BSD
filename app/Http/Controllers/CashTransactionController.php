<?php

namespace App\Http\Controllers;

use App\Models\CashTransaction;
use App\Models\Payment;
use App\Models\PaymentPlan;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CashTransactionController extends Controller
{
    /**
     * Menampilkan daftar transaksi cash (Admin/Owner melihat semua, Ortu hanya miliknya)
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = CashTransaction::with([
            'paymentPlan',
            'payment',
            'enrollment.student',
            'enrollment.program',
            'submitter:id,name,email',
            'verifier:id,name,email',
        ])
        ->when($user->role === 'parent', function ($q) use ($user) {
            $q->where('submitted_by', $user->id);
        })
        ->when($request->filled('status'), function ($q) use ($request) {
            $q->where('status', $request->status);
        })
        ->when($request->filled('student_id'), function ($q) use ($request) {
            $q->where('student_id', $request->student_id);
        })
        ->when($request->filled('enrollment_id'), function ($q) use ($request) {
            $q->where('enrollment_id', $request->enrollment_id);
        })
        ->when($request->filled('payment_plan_id'), function ($q) use ($request) {
            $q->where('payment_plan_id', $request->payment_plan_id);
        })
        ->orderBy('submitted_at', 'desc');

        $transactions = $query->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data'    => $transactions,
        ]);
    }

    /**
     * Detail satu transaksi cash
     */
    public function show(Request $request, string $id)
    {
        $user = $request->user();

        $cashTransaction = CashTransaction::with([
            'paymentPlan',
            'payment',
            'enrollment.student',
            'enrollment.program',
            'submitter:id,name,email',
            'verifier:id,name,email',
        ])->findOrFail($id);

        if ($user->role === 'parent' && $cashTransaction->submitted_by !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Anda hanya dapat melihat pengajuan pembayaran milik Anda.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data'    => $cashTransaction,
        ]);
    }

    /**
     * Orang Tua mengajukan pembayaran Tunai / Cash
     * Status awal: menunggu_konfirmasi_admin (TIDAK otomatis lunas)
     */
    public function submit(Request $request)
    {
        $validated = $request->validate([
            'payment_plan_id' => 'required|uuid|exists:payment_plans,id',
            'receipt_number'  => 'nullable|string|max:100',
            'notes'           => 'nullable|string|max:500',
            'proof_image'     => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048', // 2MB max
        ]);

        $paymentPlan = PaymentPlan::with(['enrollment.student', 'enrollment.program'])->findOrFail($validated['payment_plan_id']);

        if ($paymentPlan->is_paid || $paymentPlan->status === 'paid') {
            return response()->json([
                'success' => false,
                'message' => 'Tagihan ini sudah berstatus lunas.',
            ], 422);
        }

        // Cek apakah sudah ada pengajuan cash yang masih menunggu konfirmasi admin
        $existingSubmission = CashTransaction::where('payment_plan_id', $paymentPlan->id)
            ->where('status', 'menunggu_konfirmasi_admin')
            ->first();

        if ($existingSubmission) {
            return response()->json([
                'success' => false,
                'message' => 'Pengajuan pembayaran tunai untuk tagihan ini sudah dibuat dan sedang menunggu konfirmasi admin.',
                'data'    => $existingSubmission,
            ], 422);
        }

        $user = $request->user();
        $enrollment = $paymentPlan->enrollment;
        $student = $enrollment->student;

        // Upload bukti fisik opsional jika ada
        $proofPath = null;
        if ($request->hasFile('proof_image')) {
            $proofPath = $request->file('proof_image')->store('cash_receipts', 'public');
        }

        return DB::transaction(function () use ($paymentPlan, $enrollment, $student, $user, $validated, $proofPath) {
            $amount = (float) $paymentPlan->amount;
            $year = date('Y');

            // Generate kode unik CASH-2026-xxxx
            $lastCash = CashTransaction::where('cash_code', 'like', "CASH-{$year}-%")
                ->orderBy('created_at', 'desc')
                ->lockForUpdate()
                ->first();

            $sequence = 1;
            if ($lastCash && preg_match("/CASH-{$year}-(\d+)/", $lastCash->cash_code, $matches)) {
                $sequence = (int)$matches[1] + 1;
            }
            $cashCode = sprintf("CASH-%s-%04d", $year, $sequence);
            $paymentCode = 'INV-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));

            // 1. Buat record transaksi payments induk dengan status pending
            $payment = Payment::create([
                'payment_code'     => $paymentCode,
                'payment_plan_id'  => $paymentPlan->id,
                'enrollment_id'    => $enrollment->id,
                'student_id'       => $student->id,
                'amount'           => $amount,
                'fee'              => 0,
                'total_amount'     => $amount,
                'payment_method'   => 'cash',
                'payment_channel'  => 'cash',
                'provider'         => 'direct_cash',
                'reference_number' => $cashCode,
                'payment_status'   => 'pending',
                'created_by'       => $user->id,
                'notes'            => $validated['notes'] ?? null,
            ]);

            // 2. Buat detail audit trail cash_transactions
            $cashTransaction = CashTransaction::create([
                'cash_code'        => $cashCode,
                'payment_plan_id'  => $paymentPlan->id,
                'payment_id'       => $payment->id,
                'enrollment_id'    => $enrollment->id,
                'student_id'       => $student->id,
                'amount'           => $amount,
                'status'           => 'menunggu_konfirmasi_admin',
                'submitted_by'     => $user->id,
                'submitted_at'     => Carbon::now(),
                'receipt_number'   => $validated['receipt_number'] ?? null,
                'proof_image'      => $proofPath ? Storage::url($proofPath) : null,
                'notes'            => $validated['notes'] ?? null,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Pengajuan pembayaran tunai berhasil dikirim. Silakan serahkan uang ke Admin untuk diverifikasi.',
                'data'    => $cashTransaction->load(['paymentPlan', 'payment', 'enrollment.student', 'submitter:id,name,email']),
            ], 201);
        });
    }

    /**
     * Admin / Owner mengonfirmasi penerimaan fisik uang tunai
     * Alur: status cash paid, payment paid, payment_plan lunas
     */
    public function confirm(Request $request, string $id)
    {
        $validated = $request->validate([
            'admin_notes'    => 'nullable|string|max:500',
            'receipt_number' => 'nullable|string|max:100',
        ]);

        $cashTransaction = CashTransaction::with(['paymentPlan', 'payment'])->findOrFail($id);

        if ($cashTransaction->status === 'paid') {
            return response()->json([
                'success' => false,
                'message' => 'Pembayaran tunai ini sudah pernah dikonfirmasi sebelumnya.',
            ], 422);
        }

        if ($cashTransaction->status === 'rejected') {
            return response()->json([
                'success' => false,
                'message' => 'Pembayaran tunai ini sudah berstatus ditolak.',
            ], 422);
        }

        $user = $request->user();

        DB::transaction(function () use ($cashTransaction, $user, $validated) {
            $now = Carbon::now();

            // 1. Update status cash_transactions menjadi paid
            $cashTransaction->update([
                'status'         => 'paid',
                'verified_by'    => $user->id,
                'verified_at'    => $now,
                'admin_notes'    => $validated['admin_notes'] ?? $cashTransaction->admin_notes,
                'receipt_number' => $validated['receipt_number'] ?? $cashTransaction->receipt_number,
            ]);

            // 2. Update status payments induk menjadi paid
            if ($cashTransaction->payment) {
                $cashTransaction->payment->update([
                    'payment_status' => 'paid',
                    'paid_at'        => $now,
                    'verified_by'    => $user->id,
                ]);
            }

            // 3. Update status tagihan payment_plans menjadi lunas
            if ($cashTransaction->paymentPlan) {
                $cashTransaction->paymentPlan->update([
                    'is_paid' => true,
                    'status'  => 'paid',
                ]);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Pembayaran tunai berhasil dikonfirmasi. Tagihan telah berstatus Lunas.',
            'data'    => $cashTransaction->fresh([
                'paymentPlan',
                'payment',
                'enrollment.student',
                'submitter:id,name,email',
                'verifier:id,name,email',
            ]),
        ]);
    }

    /**
     * Admin / Owner menolak pengajuan pembayaran tunai (misal: uang belum diterima / nominal kurang)
     */
    public function reject(Request $request, string $id)
    {
        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:500',
        ]);

        $cashTransaction = CashTransaction::with(['paymentPlan', 'payment'])->findOrFail($id);

        if ($cashTransaction->status === 'paid') {
            return response()->json([
                'success' => false,
                'message' => 'Pembayaran tunai yang sudah lunas tidak dapat ditolak.',
            ], 422);
        }

        $user = $request->user();

        DB::transaction(function () use ($cashTransaction, $user, $validated) {
            $cashTransaction->update([
                'status'           => 'rejected',
                'verified_by'      => $user->id,
                'verified_at'      => Carbon::now(),
                'rejection_reason' => $validated['rejection_reason'],
            ]);

            if ($cashTransaction->payment) {
                $cashTransaction->payment->update([
                    'payment_status' => 'failed',
                    'verified_by'    => $user->id,
                    'notes'          => 'Ditolak admin: ' . $validated['rejection_reason'],
                ]);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Pengajuan pembayaran tunai berhasil ditolak.',
            'data'    => $cashTransaction->fresh([
                'paymentPlan',
                'payment',
                'submitter:id,name,email',
                'verifier:id,name,email',
            ]),
        ]);
    }
}
