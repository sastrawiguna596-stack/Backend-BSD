<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClassSession extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'class_sessions';
    protected $guarded = [];

    public function classroom()
    {
        return $this->belongsTo(Classroom::class);
    }

    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }

    public function classSchedule()
    {
        return $this->belongsTo(ClassSchedule::class);
    }

    /**
     * Check if there's an overlapping session for the same room or teacher.
     */
    public static function hasConflict($classroomId, $teacherId, $sessionDate, $startTime, $endTime, $excludeSessionId = null)
    {
        return self::where('session_date', $sessionDate)
            ->where(function ($query) use ($classroomId, $teacherId) {
                $query->where('classroom_id', $classroomId)
                      ->orWhere('teacher_id', $teacherId);
            })
            ->where(function ($query) use ($startTime, $endTime) {
                $query->where('start_time', '<', $endTime)
                      ->where('end_time', '>', $startTime);
            })
            ->where('status', '!=', 'cancelled')
            ->when($excludeSessionId, function ($query) use ($excludeSessionId) {
                $query->where('id', '!=', $excludeSessionId);
            })
            ->exists();
    }
}
