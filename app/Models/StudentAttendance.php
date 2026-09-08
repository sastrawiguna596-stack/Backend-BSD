<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentAttendance extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'student_attendances';
    protected $guarded = [];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->attendance_code)) {
                $year = date('Y');
                $lastRecord = self::where('attendance_code', 'like', "ATT-{$year}-%")
                    ->orderBy('attendance_code', 'desc')
                    ->first();

                if ($lastRecord) {
                    $lastNumber = (int) substr($lastRecord->attendance_code, -4);
                    $newNumber = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
                } else {
                    $newNumber = '0001';
                }

                $model->attendance_code = "ATT-{$year}-{$newNumber}";
            }
        });
    }

    public function classSession()
    {
        return $this->belongsTo(ClassSession::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}
