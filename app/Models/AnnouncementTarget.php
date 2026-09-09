<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AnnouncementTarget extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'announcement_targets';
    protected $guarded = [];

    public function announcement()
    {
        return $this->belongsTo(Announcement::class, 'announcement_id');
    }
}
