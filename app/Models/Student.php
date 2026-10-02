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
        'birth_date' => 'date',
    ];

    /**
     * Accessor aman untuk status siswa.
     * Mencegah crash jika di database tersimpan format bahasa Indonesia atau nilai legacy.
     */
    public function getStatusAttribute($value): string
    {
        if ($value instanceof StudentStatus) {
            return $value->value;
        }

        if (is_string($value)) {
            $mapped = StudentStatus::tryFrom(strtolower(trim($value)));
            if ($mapped) {
                return $mapped->value;
            }

            $indonesianMap = [
                'aktif'       => 'active',
                'percobaan'   => 'trial',
                'cuti'        => 'on_leave',
                'tidak aktif' => 'inactive',
                'nonaktif'    => 'inactive',
                'lulus'       => 'graduated',
            ];
            $clean = strtolower(trim($value));
            if (isset($indonesianMap[$clean])) {
                return $indonesianMap[$clean];
            }
        }

        return 'trial';
    }

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
