<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentPlan extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'payment_plans';
    protected $guarded = [];

    protected $casts = [
        'due_date' => 'date',
        'is_paid'  => 'boolean',
        'amount'   => 'float',
    ];

    protected $appends = ['computed_status'];

    /**
     * Menghitung status dinamis tagihan sesuai aturan bisnis SRS / Meeting:
     * - Paid: jika sudah dibayar lunas
     * - Overdue: jika belum lunas dan tanggal jatuh tempo sudah terlewati
     * - Unpaid: jika belum lunas dan belum melewati tanggal jatuh tempo
     */
    public function getComputedStatusAttribute(): string
    {
        if ($this->is_paid || $this->status === 'paid') {
            return 'paid';
        }

        if ($this->status === 'cancelled') {
            return 'cancelled';
        }

        if ($this->due_date && $this->due_date->isPast() && !$this->due_date->isToday()) {
            return 'overdue';
        }

        return 'unpaid';
    }

    public function enrollment()
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function cashTransactions()
    {
        return $this->hasMany(CashTransaction::class, 'payment_plan_id');
    }
}

