<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TeacherLogbook extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'teacher_logbooks';
    protected $guarded = [];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->log_code)) {
                $year = date('Y');
                $lastRecord = self::where('log_code', 'like', "LOG-{$year}-%")
                    ->orderBy('log_code', 'desc')
                    ->first();

                if ($lastRecord) {
                    $lastNumber = (int) substr($lastRecord->log_code, -4);
                    $newNumber = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
                } else {
                    $newNumber = '0001';
                }

                $model->log_code = "LOG-{$year}-{$newNumber}";
            }
        });
    }

    public function classSession()
    {
        return $this->belongsTo(ClassSession::class);
    }

    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }
}
