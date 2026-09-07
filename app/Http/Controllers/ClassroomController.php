<?php

namespace App\Http\Controllers;

use App\Models\Classroom;
use App\Models\Teacher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClassroomController extends Controller
{
    /**
     * Daftar semua kelas beserta program, level, jumlah siswa, dan guru.
     */
    public function index(Request $request)
    {
        $query = Classroom::with(['programLevel.program', 'teachers.user'])
            ->withCount(['enrollments as active_students_count' => function ($q) {
                $q->where('status', 'active');
            }])
            ->when($request->status, function ($q) use ($request) {
                $q->where('status', $request->status);
            })
            ->when($request->program_level_id, function ($q) use ($request) {
                $q->where('program_level_id', $request->program_level_id);
            })
            ->when($request->search, function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%');
            });

        $classrooms = $query->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data'    => $classrooms,
        ]);
    }

    /**
     * Buat kelas baru.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'program_level_id' => 'required|uuid|exists:program_levels,id',
            'name'             => 'required|string|max:255',
            'capacity'         => 'sometimes|integer|min:1|max:100',
        ]);

        $classroom = Classroom::create([
            'program_level_id' => $validated['program_level_id'],
            'class_code'       => 'CLS-' . strtoupper(Str::random(5)),
            'name'             => $validated['name'],
            'capacity'         => $validated['capacity'] ?? 20,
            'status'           => 'active',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Kelas berhasil dibuat.',
            'data'    => $classroom->load('programLevel.program'),
        ], 201);
    }

    /**
     * Detail kelas + daftar siswa + daftar guru.
     */
    public function show(string $id)
    {
        $classroom = Classroom::with([
                'programLevel.program',
                'teachers.user',
                'enrollments' => function ($q) {
                    $q->where('status', 'active')->with('student');
                },
            ])
            ->withCount(['enrollments as active_students_count' => function ($q) {
                $q->where('status', 'active');
            }])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => $classroom,
        ]);
    }

    /**
     * Update kelas.
     */
    public function update(Request $request, string $id)
    {
        $classroom = Classroom::findOrFail($id);

        $validated = $request->validate([
            'name'             => 'sometimes|string|max:255',
            'capacity'         => 'sometimes|integer|min:1|max:100',
            'program_level_id' => 'sometimes|uuid|exists:program_levels,id',
            'status'           => 'sometimes|string|in:active,inactive',
        ]);

        $classroom->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Kelas berhasil diperbarui.',
            'data'    => $classroom->fresh('programLevel.program'),
        ]);
    }

    /**
     * Nonaktifkan kelas.
     */
    public function destroy(string $id)
    {
        $classroom = Classroom::findOrFail($id);
        $classroom->update(['status' => 'inactive']);

        return response()->json([
            'success' => true,
            'message' => 'Kelas berhasil dinonaktifkan.',
        ]);
    }

    // =========================================================
    // ASSIGN / REMOVE GURU
    // =========================================================

    /**
     * Assign guru ke kelas (multi-guru).
     * POST /v1/classrooms/{classroom}/teachers
     */
    public function assignTeacher(Request $request, string $classroomId)
    {
        $classroom = Classroom::findOrFail($classroomId);

        $validated = $request->validate([
            'teacher_id'      => 'required|uuid|exists:teachers,id',
            'effective_from'  => 'required|date',
            'effective_until' => 'nullable|date|after_or_equal:effective_from',
            'role'            => 'sometimes|string|in:primary,assistant',
        ]);

        // Cegah duplikasi guru aktif
        $exists = DB::table('class_teachers')
            ->where('classroom_id', $classroomId)
            ->where('teacher_id', $validated['teacher_id'])
            ->whereNull('effective_until')
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Guru ini sudah aktif di kelas ini.',
            ], 422);
        }

        DB::table('class_teachers')->insert([
            'id'              => Str::uuid()->toString(),
            'classroom_id'    => $classroomId,
            'teacher_id'      => $validated['teacher_id'],
            'effective_from'  => $validated['effective_from'],
            'effective_until' => $validated['effective_until'] ?? null,
            'role'            => $validated['role'] ?? 'primary',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Guru berhasil ditambahkan ke kelas.',
            'data'    => $classroom->fresh('teachers.user'),
        ]);
    }

    /**
     * Lepas guru dari kelas (set effective_until = hari ini).
     * DELETE /v1/classrooms/{classroom}/teachers/{teacher}
     */
    public function removeTeacher(string $classroomId, string $teacherId)
    {
        $classroom = Classroom::findOrFail($classroomId);
        Teacher::findOrFail($teacherId);

        $updated = DB::table('class_teachers')
            ->where('classroom_id', $classroomId)
            ->where('teacher_id', $teacherId)
            ->whereNull('effective_until')
            ->update(['effective_until' => now()->toDateString(), 'updated_at' => now()]);

        if (! $updated) {
            return response()->json([
                'success' => false,
                'message' => 'Guru tidak ditemukan aktif di kelas ini.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Guru berhasil dilepas dari kelas.',
        ]);
    }
}
