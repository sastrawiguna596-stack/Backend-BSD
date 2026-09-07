<?php

namespace Database\Seeders;

use App\Models\Classroom;
use App\Models\Program;
use App\Models\ProgramLevel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AcademicSeeder extends Seeder
{
    public function run(): void
    {
        // ── Program 1: Kumon Matematika ──
        $math = Program::create([
            'program_code' => 'PRG-MATH',
            'name'         => 'Kumon Matematika',
            'description'  => 'Program bimbingan belajar matematika bertahap dari dasar hingga mahir.',
            'is_active'    => true,
        ]);

        $mathLevels = [
            ['name' => 'Level A (Dasar)',     'level_order' => 1, 'level_code' => 'LVL-MA'],
            ['name' => 'Level B (Menengah)',   'level_order' => 2, 'level_code' => 'LVL-MB'],
            ['name' => 'Level C (Lanjutan)',   'level_order' => 3, 'level_code' => 'LVL-MC'],
        ];

        foreach ($mathLevels as $lv) {
            $level = ProgramLevel::create([
                'program_id'  => $math->id,
                'level_code'  => $lv['level_code'],
                'name'        => $lv['name'],
                'level_order' => $lv['level_order'],
                'is_active'   => true,
            ]);

            // Buat 1 kelas per level
            Classroom::create([
                'program_level_id' => $level->id,
                'class_code'       => 'CLS-M' . $lv['level_order'],
                'name'             => 'Kelas Matematika ' . $lv['name'],
                'capacity'         => 15,
                'status'           => 'active',
            ]);
        }

        // ── Program 2: English Club ──
        $english = Program::create([
            'program_code' => 'PRG-ENG',
            'name'         => 'English Club',
            'description'  => 'Program belajar bahasa Inggris dengan metode interaktif.',
            'is_active'    => true,
        ]);

        $engLevels = [
            ['name' => 'Beginner',      'level_order' => 1, 'level_code' => 'LVL-EB'],
            ['name' => 'Intermediate',   'level_order' => 2, 'level_code' => 'LVL-EI'],
            ['name' => 'Advanced',       'level_order' => 3, 'level_code' => 'LVL-EA'],
        ];

        foreach ($engLevels as $lv) {
            $level = ProgramLevel::create([
                'program_id'  => $english->id,
                'level_code'  => $lv['level_code'],
                'name'        => $lv['name'],
                'level_order' => $lv['level_order'],
                'is_active'   => true,
            ]);

            Classroom::create([
                'program_level_id' => $level->id,
                'class_code'       => 'CLS-E' . $lv['level_order'],
                'name'             => 'Kelas English ' . $lv['name'],
                'capacity'         => 12,
                'status'           => 'active',
            ]);
        }

        // ── Program 3: Coding for Kids ──
        $coding = Program::create([
            'program_code' => 'PRG-CODE',
            'name'         => 'Coding for Kids',
            'description'  => 'Program pemrograman dasar untuk anak-anak menggunakan Scratch dan Python.',
            'is_active'    => true,
        ]);

        $codingLevel = ProgramLevel::create([
            'program_id'  => $coding->id,
            'level_code'  => 'LVL-C1',
            'name'        => 'Scratch Beginner',
            'level_order' => 1,
            'is_active'   => true,
        ]);

        Classroom::create([
            'program_level_id' => $codingLevel->id,
            'class_code'       => 'CLS-C1',
            'name'             => 'Kelas Coding Scratch',
            'capacity'         => 10,
            'status'           => 'active',
        ]);

        $this->command->info('✅ 3 Program + 7 Level + 7 Kelas berhasil di-seed.');
    }
}
