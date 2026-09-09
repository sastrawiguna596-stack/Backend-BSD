<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CashTransaction extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'cash_transactions';

    protected $guarded = [];

    protected $casts = [
        'amount'       => 'float',
        'submitted_at' => 'datetime',
        'verified_at'  => 'datetime',
    ];

    /**
     * Relasi ke tagihan (PaymentPlan)
     */
    public function paymentPlan()
    {
        return $this->belongsTo(PaymentPlan::class, 'payment_plan_id');
    }

    /**
     * Relasi ke record transaksi induk (Payment)
     */
    public function payment()
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    /**
     * Relasi ke pendaftaran (Enrollment)
     */
    public function enrollment()
    {
        return $this->belongsTo(Enrollment::class, 'enrollment_id');
    }

    /**
     * Relasi ke data Siswa (Student)
     */
    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    /**
     * User yang mengajukan pembayaran cash (Orang Tua / Wali)
     */
    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * Admin / Owner yang memverifikasi fisik uang
     */
    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
