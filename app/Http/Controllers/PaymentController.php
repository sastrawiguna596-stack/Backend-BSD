<?php

namespace App\Http\Controllers;

use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\PaymentPlan;
use App\Services\PaymentGatewayService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    protected PaymentGatewayService $gatewayService;

    public function __construct(PaymentGatewayService $gatewayService)
    {
        $this->gatewayService = $gatewayService;
    }

    /**
     * Menampilkan daftar riwayat pembayaran (Orang Tua & Admin)
     */
    public function index(Request $request)
    {
        $query = Payment::with([
            'paymentPlan',
            'enrollment.student',
            'enrollment.program',
            'enrollment.programLevel',
            'creator',
            'verifier',
        ])
        ->when($request->filled('status'), function ($q) use ($request) {
            $q->where('payment_status', $request->status);
        })
        ->when($request->filled('method'), function ($q) use ($request) {
            $q->where('payment_method', $request->method);
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
        ->orderBy('created_at', 'desc');

        $payments = $query->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data'    => $payments,
        ]);
    }

    /**
     * Membuat inisiasi pembayaran Digital (VA / QRIS / E-Wallet)
     */
    public function charge(Request $request)
    {
        $validated = $request->validate([
            'payment_plan_id' => 'required|uuid|exists:payment_plans,id',
            'payment_method'  => 'required|string|in:va,qris,ewallet',
            'payment_channel' => 'nullable|string|max:50', // e.g: QRISNB, BRIVA, BCVA, SHOPEEPAY, GOPAY, DANA
            'expired_time'    => 'nullable|string|max:10', // e.g: 30m, 24h
            'type_fee'        => 'nullable|string|in:user,merchant',
            'notes'           => 'nullable|string|max:255',
        ]);

        $paymentPlan = PaymentPlan::with(['enrollment.student', 'enrollment.program'])->findOrFail($validated['payment_plan_id']);

        if ($paymentPlan->is_paid || $paymentPlan->status === 'paid') {
            return response()->json([
                'success' => false,
                'message' => 'Tagihan ini sudah berstatus lunas.',
            ], 422);
        }

        $enrollment = $paymentPlan->enrollment;
        $student = $enrollment->student;
        $user = $request->user();

        $amount = (float) $paymentPlan->amount;
        $paymentMethod = strtolower($validated['payment_method']);
        $paymentChannel = $validated['payment_channel'] ?? ($paymentMethod === 'qris' ? 'QRISSP' : ($paymentMethod === 'va' ? 'BCVA' : 'SHOPEEPAY'));

        // Cek apakah sudah ada transaksi pending yang masih aktif dan belum kedaluwarsa untuk metode yang sama
        $existingPayment = Payment::where('payment_plan_id', $paymentPlan->id)
            ->where('payment_status', 'pending')
            ->where('payment_method', $paymentMethod)
            ->where('payment_channel', $paymentChannel)
            ->where(function ($query) {
                $query->whereNull('expired_at')
                      ->orWhere('expired_at', '>', Carbon::now());
            })
            ->latest()
            ->first();

        if ($existingPayment) {
            return response()->json([
                'success' => true,
                'message' => 'Menggunakan transaksi aktif yang belum kedaluwarsa.',
                'data'    => [
                    'payment' => $existingPayment->load(['paymentPlan', 'enrollment.student', 'enrollment.program']),
                    'instructions' => [
                        'method'          => $existingPayment->payment_method,
                        'method_name'     => $existingPayment->payment_channel,
                        'channel'         => $existingPayment->payment_channel,
                        'virtual_account' => $existingPayment->virtual_account,
                        'qr_url'          => $existingPayment->qr_url,
                        'qr_content'      => $existingPayment->qr_content,
                        'checkout_url'    => $existingPayment->checkout_url,
                        'amount'          => $existingPayment->amount,
                        'fee'             => $existingPayment->fee,
                        'total_amount'    => $existingPayment->total_amount,
                        'expired_at'      => $existingPayment->expired_at ? $existingPayment->expired_at->toIso8601String() : null,
                    ]
                ]
            ], 200);
        }

        // Generate nomor pembayaran unik (contoh: INV-1001 / PAY-2026-XXXX)
        $paymentCode = 'INV-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));

        try {
            // Request ke Payment Gateway (pay.zannstore.com)
            $gatewayResult = $this->gatewayService->createTransaction([
                'payment_code'    => $paymentCode,
                'amount'          => $amount,
                'payment_method'  => $paymentMethod,
                'payment_channel' => $paymentChannel,
                'customer_name'   => $student->full_name ?? ($user->name ?? 'Orang Tua Siswa'),
                'note'            => "Pembayaran Tagihan Sesi #{$paymentPlan->session_number} ({$enrollment->enrollment_code})",
                'expired_time'    => $validated['expired_time'] ?? '30m',
                'type_fee'        => $validated['type_fee'] ?? 'user',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }

        $fee = (float) ($gatewayResult['fee'] ?? 0);
        $totalAmount = (float) ($gatewayResult['total_amount'] ?? ($amount + $fee));

        // Simpan data transaksi ke database
        $payment = Payment::create([
            'payment_code'     => $paymentCode,
            'payment_plan_id'  => $paymentPlan->id,
            'enrollment_id'    => $enrollment->id,
            'student_id'       => $student->id,
            'amount'           => $amount,
            'fee'              => $fee,
            'total_amount'     => $totalAmount,
            'payment_method'   => $paymentMethod,
            'payment_channel'  => $gatewayResult['method_code'] ?? $paymentChannel,
            'provider'         => $gatewayResult['provider'] ?? 'payment_gateway',
            'reference_number' => $gatewayResult['reference_number'] ?? null,
            'virtual_account'  => $gatewayResult['virtual_account'] ?? null,
            'qr_content'       => $gatewayResult['qr_content'] ?? null,
            'qr_url'           => $gatewayResult['qr_url'] ?? null,
            'checkout_url'     => $gatewayResult['checkout_url'] ?? null,
            'payment_status'   => 'pending',
            'expired_at'       => $gatewayResult['expired_at'] ?? now()->addMinutes(30),
            'created_by'       => $user ? $user->id : null,
            'notes'            => $validated['notes'] ?? null,
        ]);

        // Response terstruktur untuk Frontend (React)
        return response()->json([
            'success' => true,
            'message' => $gatewayResult['message'] ?? 'Berhasil membuat transaksi',
            'data'    => [
                'payment' => $payment->load(['paymentPlan', 'enrollment.student', 'enrollment.program']),
                'instructions' => [
                    'method'          => $payment->payment_method,
                    'method_name'     => $gatewayResult['method_name'] ?? $payment->payment_channel,
                    'channel'         => $payment->payment_channel,
                    'virtual_account' => $payment->virtual_account,
                    'qr_url'          => $payment->qr_url,
                    'qr_content'      => $payment->qr_content,
                    'checkout_url'    => $payment->checkout_url,
                    'amount'          => $payment->amount,
                    'fee'             => $payment->fee,
                    'total_amount'    => $payment->total_amount,
                    'expired_at'      => $payment->expired_at ? $payment->expired_at->toIso8601String() : null,
                ]
            ]
        ], 201);
    }

    /**
     * Webhook / Callback Handler dari Payment Gateway (pay.zannstore.com)
     * Mengubah status payment menjadi paid dan otomatis melunasi payment_plans
     */
    public function webhook(Request $request)
    {
        $payload = $request->all();
        $data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : $payload;
        $signature = $request->header('X-Signature', $data['signature'] ?? ($payload['signature'] ?? ''));

        Log::info('Payment Gateway Webhook Received', ['payload' => $payload]);

        if (!$this->gatewayService->verifyWebhookSignature($payload, $signature)) {
            Log::warning('Payment Gateway Webhook Invalid Signature', ['signature' => $signature, 'payload' => $payload]);
            return response()->json(['success' => false, 'message' => 'Invalid signature.'], 403);
        }

        $trxId = $data['trx_id'] ?? ($data['payment_code'] ?? ($data['trx_svr'] ?? ($payload['trx_id'] ?? null)));
        $status = strtolower($data['status'] ?? ($data['transaction_status'] ?? ($payload['status'] ?? '')));

        if (!$trxId) {
            return response()->json(['success' => false, 'message' => 'Transaction ID is required.'], 400);
        }

        $payment = Payment::with('paymentPlan')
            ->where('payment_code', $trxId)
            ->orWhere('reference_number', $trxId)
            ->first();

        if (!$payment) {
            return response()->json(['success' => false, 'message' => 'Payment record not found.'], 404);
        }

        DB::transaction(function () use ($payment, $status, $payload) {
            if (in_array($status, ['success', 'settled', 'paid', 'berhasil', 'capture'])) {
                $payment->update([
                    'payment_status' => 'paid',
                    'paid_at'        => Carbon::now(),
                ]);

                // Update tagihan terkait di payment_plans menjadi lunas
                if ($payment->paymentPlan) {
                    $payment->paymentPlan->update([
                        'is_paid' => true,
                        'status'  => 'paid',
                    ]);
                }
            } elseif (in_array($status, ['expired', 'expire', 'kadaluarsa'])) {
                $payment->update([
                    'payment_status' => 'expired',
                ]);
            } elseif (in_array($status, ['failed', 'gagal', 'deny', 'cancel', 'batal'])) {
                $payment->update([
                    'payment_status' => 'failed',
                ]);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Webhook notification processed successfully.',
        ]);
    }

    /**
     * Detail riwayat pembayaran
     */
    public function show(string $id)
    {
        $payment = Payment::with([
            'paymentPlan',
            'enrollment.student',
            'enrollment.program',
            'enrollment.programLevel',
            'creator',
            'verifier',
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => $payment,
        ]);
    }

    /**
     * Mengecek status transaksi saat ini (polling dari tombol Cek Status di Frontend)
     */
    public function checkStatus(string $id)
    {
        $payment = Payment::with(['paymentPlan'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => [
                'payment_id'     => $payment->id,
                'payment_code'   => $payment->payment_code,
                'payment_status' => $payment->payment_status,
                'is_paid'        => $payment->payment_status === 'paid',
                'paid_at'        => $payment->paid_at ? $payment->paid_at->toIso8601String() : null,
                'total_amount'   => $payment->total_amount,
            ]
        ]);
    }
}
