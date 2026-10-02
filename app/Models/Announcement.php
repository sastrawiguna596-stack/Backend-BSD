<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = [];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    public function getPosterUrlAttribute($value)
    {
        if (!$value) {
            return null;
        }

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }

        return str_starts_with($value, 'storage/') || str_starts_with($value, '/storage/')
            ? url($value)
            : url('storage/' . $value);
    }

    public function targets()
    {
        return $this->hasMany(AnnouncementTarget::class, 'announcement_id');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
