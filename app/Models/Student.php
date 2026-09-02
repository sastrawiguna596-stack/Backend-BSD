<?php

namespace App\Models;

use App\Enums\StudentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Student extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = [];

    protected $casts = [
        'status'     => StudentStatus::class,
        'birth_date' => 'date',
    ];

    public function parents()
    {
        return $this->belongsToMany(ParentModel::class, 'parent_students', 'student_id', 'parent_id')
                    ->withPivot('relationship', 'is_primary')
                    ->withTimestamps();
    }

    public function enrollments()
    {
        return $this->hasMany(Enrollment::class);
    }

    /**
     * Hitung jumlah program aktif yang diikuti siswa ini.
     */
    public function activeProgramsCount(): int
    {
        return $this->enrollments()
            ->where('status', 'active')
            ->distinct('program_id')
            ->count('program_id');
    }
}
