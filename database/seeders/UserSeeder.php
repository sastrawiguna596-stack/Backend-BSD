<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserSeeder extends Seeder
{
    /**
     * Seed akun testing untuk semua role di BSD After School Club.
     *
     * Akun yang dibuat:
     * ---------------------------------------------------------------
     * Role    | Email                   | Password
     * --------|-------------------------|----------------------------
     * owner   | owner@bsd.test          | password
     * admin   | admin@bsd.test          | password
     * teacher | guru@bsd.test           | password
     * parent  | ortu@bsd.test           | password
     * ---------------------------------------------------------------
     */
    public function run(): void
    {
        // ---------------------------------------------------------------
        // 1. OWNER
        // ---------------------------------------------------------------
        $ownerId = Str::uuid();
        $ownerUserId = Str::uuid();

        DB::table('users')->insert([
            'id'            => $ownerUserId,
            'user_code'     => 'USR-OWNER-001',
            'role'          => 'owner',
            'name'          => 'Budi Pemilik',
            'email'         => 'owner@bsd.test',
            'phone'         => '081200000001',
            'password_hash' => Hash::make('password'),
            'is_active'     => true,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        DB::table('owners')->insert([
            'id'         => $ownerId,
            'user_id'    => $ownerUserId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // ---------------------------------------------------------------
        // 2. ADMIN
        // ---------------------------------------------------------------
        $adminId = Str::uuid();
        $adminUserId = Str::uuid();

        DB::table('users')->insert([
            'id'            => $adminUserId,
            'user_code'     => 'USR-ADMIN-001',
            'role'          => 'admin',
            'name'          => 'Siti Admin',
            'email'         => 'admin@bsd.test',
            'phone'         => '081200000002',
            'password_hash' => Hash::make('password'),
            'is_active'     => true,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        DB::table('admins')->insert([
            'id'         => $adminId,
            'user_id'    => $adminUserId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // ---------------------------------------------------------------
        // 3. TEACHER (Guru)
        // ---------------------------------------------------------------
        $teacherId = Str::uuid();
        $teacherUserId = Str::uuid();

        DB::table('users')->insert([
            'id'            => $teacherUserId,
            'user_code'     => 'USR-GURU-001',
            'role'          => 'teacher',
            'name'          => 'Ahmad Guru, S.Pd',
            'email'         => 'guru@bsd.test',
            'phone'         => '081200000003',
            'password_hash' => Hash::make('password'),
            'is_active'     => true,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        DB::table('teachers')->insert([
            'id'                  => $teacherId,
            'user_id'             => $teacherUserId,
            'teacher_code'        => 'TCH-001',
            'bank_name'           => 'BCA',
            'bank_account_name'   => 'Ahmad Guru',
            'bank_account_number' => '1234567890',
            'is_active'           => true,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        // ---------------------------------------------------------------
        // 4. PARENT (Orang Tua / Wali)
        // ---------------------------------------------------------------
        $parentId = Str::uuid();
        $parentUserId = Str::uuid();

        DB::table('users')->insert([
            'id'            => $parentUserId,
            'user_code'     => 'USR-ORTU-001',
            'role'          => 'parent',
            'name'          => 'Dewi Ortu',
            'email'         => 'ortu@bsd.test',
            'phone'         => '081200000004',
            'password_hash' => Hash::make('password'),
            'is_active'     => true,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        DB::table('parents')->insert([
            'id'           => $parentId,
            'user_id'      => $parentUserId,
            'parent_code'  => 'PAR-001',
            'relationship' => 'Ibu',
            'address'      => 'Jl. Mawar No. 1, Jakarta Selatan',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        // ---------------------------------------------------------------
        // 5. STUDENT (Siswa — tidak butuh akun login, tapi butuh data)
        // ---------------------------------------------------------------
        $studentId = Str::uuid();

        DB::table('students')->insert([
            'id'           => $studentId,
            'student_code' => 'STU-001',
            'full_name'    => 'Andi Siswa',
            'birth_date'   => '2010-05-15',
            'school_name'  => 'SMP Negeri 1 Jakarta',
            'school_grade' => 'Kelas 8',
            'address'      => 'Jl. Mawar No. 1, Jakarta Selatan',
            'status'       => 'trial',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        // Kaitkan siswa ke orang tua
        DB::table('parent_students')->insert([
            'id'           => Str::uuid(),
            'parent_id'    => $parentId,
            'student_id'   => $studentId,
            'relationship' => 'Anak Kandung',
            'is_primary'   => true,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }
}
