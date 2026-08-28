<?php

namespace App\Http\Controllers;

use App\Enums\StudentStatus;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StudentController extends Controller
{
    /**
     * Daftar semua siswa.
     * Mendukung filter status dan pencarian nama.
     */
    public function index(Request $request)
    {
        $query = Student::with('parents.user')
            ->when($request->status, function ($q) use ($request) {
                $q->where('status', $request->status);
            })
            ->when($request->search, function ($q) use ($request) {
                $q->where('full_name', 'like', '%' . $request->search . '%')
                  ->orWhere('student_code', 'like', '%' . $request->search . '%');
            })
            ->when($request->school_grade, function ($q) use ($request) {
                $q->where('school_grade', $request->school_grade);
            });

        $students = $query->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data'    => $students,
        ]);
    }

    /**
     * Tambah siswa baru.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'full_name'    => 'required|string|max:255',
            'birth_date'   => 'nullable|date',
            'school_name'  => 'nullable|string|max:255',
            'school_grade' => 'nullable|string|max:50',
            'address'      => 'nullable|string',
            'status'       => ['sometimes', Rule::enum(StudentStatus::class)],
        ]);

        $validated['student_code'] = 'STU-' . strtoupper(\Illuminate\Support\Str::random(6));
        $validated['status']       = $validated['status'] ?? StudentStatus::Trial->value;

        $student = Student::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Siswa berhasil ditambahkan.',
            'data'    => $student,
        ], 201);
    }

    /**
     * Detail satu siswa beserta data orang tua.
     */
    public function show(string $id)
    {
        $student = Student::with('parents.user')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => $student,
        ]);
    }

    /**
     * Update data siswa.
     */
    public function update(Request $request, string $id)
    {
        $student = Student::findOrFail($id);

        $validated = $request->validate([
            'full_name'    => 'sometimes|string|max:255',
            'birth_date'   => 'nullable|date',
            'school_name'  => 'nullable|string|max:255',
            'school_grade' => 'nullable|string|max:50',
            'address'      => 'nullable|string',
            'status'       => ['sometimes', Rule::enum(StudentStatus::class)],
        ]);

        $student->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Data siswa berhasil diperbarui.',
            'data'    => $student->fresh(),
        ]);
    }

    /**
     * Nonaktifkan siswa (set status = inactive).
     * Data historis belajar & pembayaran tetap aman.
     */
    public function destroy(string $id)
    {
        $student = Student::findOrFail($id);
        $student->update(['status' => StudentStatus::Inactive->value]);

        return response()->json([
            'success' => true,
            'message' => 'Siswa berhasil dinonaktifkan.',
        ]);
    }
}
