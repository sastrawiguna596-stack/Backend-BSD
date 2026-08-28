<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasUuids;

    protected $guarded = [];

    protected $hidden = [
        'password_hash',
        'remember_token',
    ];

    /**
     * Override lokasi kolom password agar auth Laravel
     * membaca dari kolom 'password_hash', bukan 'password'.
     */
    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    // -------------------------------------------------------
    // Relasi ke tabel profil per role
    // -------------------------------------------------------

    public function owner()
    {
        return $this->hasOne(Owner::class);
    }

    public function admin()
    {
        return $this->hasOne(Admin::class);
    }

    public function teacher()
    {
        return $this->hasOne(Teacher::class);
    }

    public function parent()
    {
        return $this->hasOne(ParentModel::class);
    }
}
