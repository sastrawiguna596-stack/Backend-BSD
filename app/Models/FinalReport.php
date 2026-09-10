<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FinalReport extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'final_reports';
    protected $guarded = [];

    protected $casts = [
        'final_score'  => 'decimal:2',
        'generated_at' => 'date',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->report_code)) {
                $year      = date('Y');
                $lastRecord = self::where('report_code', 'like', "RPT-{$year}-%")
                    ->orderBy('report_code', 'desc')
                    ->first();

                $newNumber       = $lastRecord
                    ? str_pad((int) substr($lastRecord->report_code, -4) + 1, 4, '0', STR_PAD_LEFT)
                    : '0001';

                $model->report_code = "RPT-{$year}-{$newNumber}";
            }
        });
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function enrollment()
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function verifiedBy()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
