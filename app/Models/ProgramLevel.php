<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProgramLevel extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'program_levels';
    protected $guarded = [];

    public function program()
    {
        return $this->belongsTo(Program::class);
    }

    public function classrooms()
    {
        return $this->hasMany(Classroom::class, 'program_level_id');
    }
}
