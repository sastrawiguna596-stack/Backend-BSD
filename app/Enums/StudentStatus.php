<?php

namespace App\Enums;

enum StudentStatus: string
{
    case Active   = 'active';
    case Trial    = 'trial';
    case OnLeave  = 'on_leave';
    case Inactive = 'inactive';
    case Graduated = 'graduated';

    /**
     * Label bahasa Indonesia untuk ditampilkan ke UI.
     */
    public function label(): string
    {
        return match($this) {
            self::Active    => 'Aktif',
            self::Trial     => 'Percobaan',
            self::OnLeave   => 'Cuti',
            self::Inactive  => 'Tidak Aktif',
            self::Graduated => 'Lulus',
        };
    }

    /**
     * Nilai default saat pertama kali mendaftar.
     */
    public static function default(): self
    {
        return self::Trial;
    }
}
