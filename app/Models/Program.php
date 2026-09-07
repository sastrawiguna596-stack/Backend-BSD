<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Program extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = [];

    public function levels()
    {
        return $this->hasMany(ProgramLevel::class)->orderBy('level_order');
    }

    public function enrollments()
    {
        return $this->hasMany(Enrollment::class);
    }
}
