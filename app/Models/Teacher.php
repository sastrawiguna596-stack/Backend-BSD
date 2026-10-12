<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Teacher extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = [];

    protected $appends = ['name'];

    public function getNameAttribute()
    {
        return $this->user?->name;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function classrooms()
    {
        return $this->belongsToMany(Classroom::class, 'class_teachers', 'teacher_id', 'classroom_id')
                    ->withPivot('effective_from', 'effective_until', 'role')
                    ->withTimestamps();
    }

    public function hourlyRates()
    {
        return $this->hasMany(TeacherHourlyRate::class);
    }

    public function bonuses()
    {
        return $this->hasMany(TeacherBonus::class);
    }

    public function payrolls()
    {
        return $this->hasMany(TeacherPayroll::class);
    }
}
