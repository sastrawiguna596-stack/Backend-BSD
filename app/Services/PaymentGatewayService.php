<?php

namespace App\Services;

use App\Models\Payment;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaymentGatewayService
{
    protected string $baseUrl;
    protected ?string $apiKey;
    protected ?string $secretKey;
    protected ?string $merchantId;
    protected ?string $callbackUrl;
    protected ?string $returnUrl;

    public function __construct()
    {
        $this->baseUrl     = config('services.payment_gateway.base_url', 'https://pay.zannstore.com/v1');
        $this->apiKey      = config('services.payment_gateway.api_key');
        $this->secretKey   = config('services.payment_gateway.secret_key');
        $this->merchantId  = config('services.payment_gateway.merchant_id');
        $this->callbackUrl = config('services.payment_gateway.callback_url');
        $this->returnUrl   = config('services.payment_gateway.return_url', config('app.url') . '/pembayaran/sukses');
    }

    /**
     * Membuat request charge transaksi langsung ke Payment Gateway (pay.zannstore.com)
     *
     * @param array $payload [
     *   'payment_code'   => string,
     *   'amount'         => float,
     *   'payment_method' => 'va'|'qris'|'ewallet',
     *   'payment_channel'=> string, // e.g: QRISNB, BRIVA, BCVA, SHOPEEPAY, GOPAY, DANA
     *   'customer_name'  => string,
     *   'note'           => string,
     *   'expired_time'   => string, // e.g: "30m", "24h"
     *   'type_fee'       => string, // "user" | "merchant"
     * ]
     * @return array Standardized response untuk controller & frontend
     * @throws Exception
     */
    public function createTransaction(array $payload): array
    {
        $expiredTime = $payload['expired_time'] ?? '30m';
        $typeFee = $payload['type_fee'] ?? 'user';
        $channelCode = $this->mapPaymentChannel($payload['payment_method'], $payload['payment_channel'] ?? '');

        // Generate signature hash SHA-256 (merchant + secret_key + trx_id)
        $signature = $this->generateSignature([
            'merchant'   => $this->merchantId,
            'secret_key' => $this->secretKey,
            'trx_id'     => $payload['payment_code'],
        ]);

        $requestBody = [
            'request'       => 'new',
            'merchant'      => $this->merchantId,
            'trx_id'        => $payload['payment_code'],
            'payment'       => $channelCode,
            'amount'        => (int) $payload['amount'],
            'customer_name' => $payload['customer_name'] ?? 'Orang Tua Murid',
            'note'          => $payload['note'] ?? 'Pembayaran Tagihan ' . $payload['payment_code'],
            'expired_time'  => $expiredTime,
            'type_fee'      => $typeFee,
            'return_url'    => $this->returnUrl,
            'callback_url'  => $this->callbackUrl,
            'signature'     => $signature,
        ];

        try {
            $response = Http::withHeaders([
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ])->timeout(20)->post($this->baseUrl, $requestBody);

            $body = $response->json();

            if ($response->successful() && (($body['status'] ?? false) === true) && isset($body['data'])) {
                return $this->normalizeGatewayData($payload['payment_method'], $channelCode, $body['data']);
            }

            $errorMessage = $body['message'] ?? $body['msg'] ?? 'Gagal membuat transaksi ke payment gateway.';

            Log::error('Payment Gateway Error Response', [
                'status_code' => $response->status(),
                'response'    => $body,
                'request'     => $requestBody,
            ]);

            throw new Exception($errorMessage);

        } catch (Exception $e) {
            Log::error('Payment Gateway Request Exception: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Memetakan kode channel frontend ke kode channel gateway
     */
    protected function mapPaymentChannel(string $method, string $channel): string
    {
        $channelUpper = strtoupper(trim($channel));

        if ($method === 'qris') {
            return !empty($channelUpper) && $channelUpper !== 'QRIS' ? $channelUpper : 'QRISNB';
        }

        if ($method === 'va') {
            return match (true) {
                str_contains($channelUpper, 'BRI')     => 'BRIVA',
                str_contains($channelUpper, 'BCA')     => 'BCVA',
                str_contains($channelUpper, 'BNI')     => 'BNIVA',
                str_contains($channelUpper, 'MANDIRI') => 'MANDIRIVA',
                default => !empty($channelUpper) ? $channelUpper : 'BCVA',
            };
        }

        if ($method === 'ewallet') {
            return match (true) {
                str_contains($channelUpper, 'SHOPEE') => 'SHOPEEPAY',
                str_contains($channelUpper, 'GOPAY')   => 'GOPAY',
                str_contains($channelUpper, 'OVO')     => 'OVO',
                str_contains($channelUpper, 'DANA')    => 'DANA',
                default => !empty($channelUpper) ? $channelUpper : 'SHOPEEPAY',
            };
        }

        return $channelUpper ?: 'QRISSPY';
    }

    /**
     * Menormalisasi response dari pay.zannstore.com (QRIS / VA / E-Wallet)
     */
    protected function normalizeGatewayData(string $method, string $channelCode, array $data): array
    {
        $referenceNumber = $data['trx_svr'] ?? $data['prov_txid'] ?? $data['trx_id'] ?? null;
        $fee = (float) ($data['fee'] ?? 0);
        $amount = (float) ($data['amount'] ?? 0);
        $totalAmount = (float) ($data['total_amount_bayar'] ?? $data['total_amount'] ?? ($amount + $fee));

        $expiredAtRaw = $data['expired_at'] ?? $data['expired_time'] ?? null;
        $expiredAt = $expiredAtRaw ? Carbon::parse($expiredAtRaw) : Carbon::now()->addMinutes(30);

        return [
            'success'          => true,
            'provider'         => 'payment_gateway',
            'reference_number' => $referenceNumber,
            'method_code'      => $data['method_code'] ?? $channelCode,
            'method_name'      => $data['method_name'] ?? $channelCode,
            'amount'           => $amount,
            'fee'              => $fee,
            'total_amount'     => $totalAmount,
            'virtual_account'  => $data['virtual_account'] ?? null,
            'qr_url'           => $data['qr_url'] ?? null,
            'qr_content'       => $data['qr_content'] ?? null,
            'checkout_url'     => $data['checkout_url'] ?? $data['payment_url'] ?? null,
            'expired_at'       => $expiredAt,
            'message'          => 'Berhasil membuat transaksi',
        ];
    }

    /**
     * Memverifikasi keabsahan signature callback/webhook
     * Mendukung format flat payload maupun payload di dalam objek 'data'
     */
    public function verifyWebhookSignature(array $payload, string $incomingSignature = ''): bool
    {
        if (empty($this->secretKey)) {
            return true;
        }

        $data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : $payload;
        $signature = $incomingSignature ?: ($data['signature'] ?? ($payload['signature'] ?? ''));

        if (empty($signature)) {
            return false;
        }

        $merchant = $this->merchantId ?? '';
        $trxId    = $data['trx_id'] ?? ($data['payment_code'] ?? '');
        $status   = $data['status'] ?? '';
        $amount   = $data['amount'] ?? '';

        // Opsi A: sha256(merchant + secret_key + trx_id)
        $expectedA = hash('sha256', $merchant . $this->secretKey . $trxId);
        if (hash_equals($expectedA, $signature)) {
            return true;
        }

        // Opsi B: sha256(merchant + secret_key + trx_id + status)
        $expectedB = hash('sha256', $merchant . $this->secretKey . $trxId . $status);
        if (hash_equals($expectedB, $signature)) {
            return true;
        }

        // Opsi C: HMAC sha256 (merchant:trx_id:amount:status)
        $expectedC = hash_hmac('sha256', "{$merchant}:{$trxId}:{$amount}:{$status}", $this->secretKey);
        if (hash_equals($expectedC, $signature)) {
            return true;
        }

        return false;
    }

    /**
     * Generate Signature SHA-256: sha256(merchant + secret_key + trx_id)
     */
    protected function generateSignature(array $data): string
    {
        $merchant  = $data['merchant'] ?? $this->merchantId ?? '';
        $secretKey = $data['secret_key'] ?? $this->secretKey ?? '';
        $trxId     = $data['trx_id'] ?? '';

        return hash('sha256', $merchant . $secretKey . $trxId);
    }
}
