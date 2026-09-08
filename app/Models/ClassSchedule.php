<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClassSchedule extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'class_schedules';
    protected $guarded = [];

    public function classroom()
    {
        return $this->belongsTo(Classroom::class);
    }

    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }

    public function sessions()
    {
        return $this->hasMany(ClassSession::class);
    }

    /**
     * Check if there's an overlapping schedule for the same room or teacher.
     */
    public static function hasConflict($classroomId, $teacherId, $dayOfWeek, $startTime, $endTime, $effectiveFrom, $effectiveUntil = null, $excludeScheduleId = null)
    {
        return self::where('day_of_week', $dayOfWeek)
            ->where(function ($query) use ($classroomId, $teacherId) {
                $query->where('classroom_id', $classroomId)
                      ->orWhere('teacher_id', $teacherId);
            })
            ->where(function ($query) use ($startTime, $endTime) {
                $query->where('start_time', '<', $endTime)
                      ->where('end_time', '>', $startTime);
            })
            ->where(function ($query) use ($effectiveFrom, $effectiveUntil) {
                $query->where('effective_from', '<=', $effectiveUntil ?? '9999-12-31')
                      ->where(function ($q) use ($effectiveFrom) {
                          $q->whereNull('effective_until')
                            ->orWhere('effective_until', '>=', $effectiveFrom);
                      });
            })
            ->where('is_active', true)
            ->when($excludeScheduleId, function ($query) use ($excludeScheduleId) {
                $query->where('id', '!=', $excludeScheduleId);
            })
            ->exists();
    }
}
