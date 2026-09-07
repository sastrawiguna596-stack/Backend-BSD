<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Classroom extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = [];

    public function programLevel()
    {
        return $this->belongsTo(ProgramLevel::class, 'program_level_id');
    }

    public function teachers()
    {
        return $this->belongsToMany(Teacher::class, 'class_teachers', 'classroom_id', 'teacher_id')
                    ->withPivot('effective_from', 'effective_until', 'role')
                    ->withTimestamps();
    }

    public function enrollments()
    {
        return $this->hasMany(Enrollment::class);
    }

    /**
     * Hitung jumlah enrollment aktif di kelas ini.
     */
    public function activeEnrollmentsCount(): int
    {
        return $this->enrollments()->where('status', 'active')->count();
    }

    /**
     * Cek apakah kelas sudah penuh.
     */
    public function isFull(): bool
    {
        return $this->activeEnrollmentsCount() >= $this->capacity;
    }
}
