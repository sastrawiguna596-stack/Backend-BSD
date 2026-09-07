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

    public function teacher()
    {
        return $this->belongsTo(Teacher::class, 'teacher_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

