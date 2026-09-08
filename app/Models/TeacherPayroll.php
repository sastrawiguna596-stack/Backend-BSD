<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TeacherPayroll extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'teacher_payrolls';
    protected $guarded = [];

    protected $casts = [
        'period_start'   => 'date',
        'period_end'     => 'date',
        'teaching_hours' => 'float',
        'base_amount'    => 'float',
        'bonus_amount'   => 'float',
        'total_amount'   => 'float',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->payroll_code)) {
                $year = date('Y');
                $lastRecord = self::where('payroll_code', 'like', "HNR-{$year}-%")
                    ->orderBy('payroll_code', 'desc')
                    ->first();

                if ($lastRecord) {
                    $lastNumber = (int) substr($lastRecord->payroll_code, -4);
                    $newNumber = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
                } else {
                    $newNumber = '0001';
                }

                $model->payroll_code = "HNR-{$year}-{$newNumber}";
            }
        });
    }

    public function teacher()
    {
        return $this->belongsTo(Teacher::class, 'teacher_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
