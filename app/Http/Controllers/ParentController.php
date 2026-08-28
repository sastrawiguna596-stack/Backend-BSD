<?php

namespace App\Http\Controllers;

use App\Models\ParentModel;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ParentController extends Controller
{
    /**
     * Daftar semua orang tua / wali beserta data akun pengguna.
     */
    public function index(Request $request)
    {
        $query = ParentModel::with(['user', 'students'])
            ->when($request->search, function ($q) use ($request) {
                $q->whereHas('user', function ($u) use ($request) {
                    $u->where('name', 'like', '%' . $request->search . '%')
                      ->orWhere('email', 'like', '%' . $request->search . '%');
                });
            });

        $parents = $query->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data'    => $parents,
        ]);
    }

    /**
     * Buat akun orang tua / wali baru.
     * Membuat data di tabel users dan parents dalam satu transaksi.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'         => 'required|string|max:255',
            'email'        => 'required|email|unique:users,email',
            'phone'        => 'nullable|string|max:20',
            'password'     => 'required|string|min:8',
            'relationship' => 'nullable|string|max:50',
            'address'      => 'nullable|string',
        ]);

        $parent = DB::transaction(function () use ($validated) {
            // Buat akun user
            $user = User::create([
                'user_code'     => 'USR-ORTU-' . strtoupper(Str::random(5)),
                'role'          => 'parent',
                'name'          => $validated['name'],
                'email'         => $validated['email'],
                'phone'         => $validated['phone'] ?? null,
                'password_hash' => Hash::make($validated['password']),
                'is_active'     => true,
            ]);

            // Buat profil orang tua
            $parent = ParentModel::create([
                'user_id'      => $user->id,
                'parent_code'  => 'PAR-' . strtoupper(Str::random(6)),
                'relationship' => $validated['relationship'] ?? null,
                'address'      => $validated['address'] ?? null,
            ]);

            return $parent->load('user');
        });

        return response()->json([
            'success' => true,
            'message' => 'Orang tua / wali berhasil ditambahkan.',
            'data'    => $parent,
        ], 201);
    }

    /**
     * Detail satu orang tua beserta daftar siswa yang diasuh.
     */
    public function show(string $id)
    {
        $parent = ParentModel::with(['user', 'students'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => $parent,
        ]);
    }

    /**
     * Update data orang tua dan akun pengguna terkait.
     */
    public function update(Request $request, string $id)
    {
        $parent = ParentModel::with('user')->findOrFail($id);

        $validated = $request->validate([
            'name'         => 'sometimes|string|max:255',
            'email'        => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($parent->user_id)],
            'phone'        => 'nullable|string|max:20',
            'password'     => 'nullable|string|min:8',
            'relationship' => 'nullable|string|max:50',
            'address'      => 'nullable|string',
        ]);

        DB::transaction(function () use ($parent, $validated) {
            // Update data akun user
            $userFields = array_filter([
                'name'          => $validated['name'] ?? null,
                'email'         => $validated['email'] ?? null,
                'phone'         => $validated['phone'] ?? null,
                'password_hash' => isset($validated['password']) ? Hash::make($validated['password']) : null,
            ], fn($v) => ! is_null($v));

            if (! empty($userFields)) {
                $parent->user->update($userFields);
            }

            // Update profil orang tua
            $parent->update(array_filter([
                'relationship' => $validated['relationship'] ?? null,
                'address'      => $validated['address'] ?? null,
            ], fn($v) => ! is_null($v)));
        });

        return response()->json([
            'success' => true,
            'message' => 'Data orang tua berhasil diperbarui.',
            'data'    => $parent->fresh('user'),
        ]);
    }

    /**
     * Nonaktifkan akun orang tua (soft disable).
     * Data relasi ke siswa tetap tersimpan.
     */
    public function destroy(string $id)
    {
        $parent = ParentModel::with('user')->findOrFail($id);

        $parent->user->update(['is_active' => false]);

        return response()->json([
            'success' => true,
            'message' => 'Akun orang tua berhasil dinonaktifkan.',
        ]);
    }

    /**
     * Kaitkan orang tua ke siswa tertentu.
     * POST /v1/parents/{parent}/students/{student}
     */
    public function attachStudent(Request $request, string $parentId, string $studentId)
    {
        $parent  = ParentModel::findOrFail($parentId);
        $student = Student::findOrFail($studentId);

        $validated = $request->validate([
            'relationship' => 'nullable|string|max:50',
            'is_primary'   => 'boolean',
        ]);

        // Cegah duplikasi relasi
        if ($parent->students()->where('student_id', $studentId)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Orang tua ini sudah terhubung dengan siswa tersebut.',
            ], 422);
        }

        $parent->students()->attach($studentId, [
            'relationship' => $validated['relationship'] ?? null,
            'is_primary'   => $validated['is_primary'] ?? false,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Siswa berhasil dikaitkan ke orang tua.',
        ]);
    }

    /**
     * Lepas relasi orang tua dari siswa.
     * DELETE /v1/parents/{parent}/students/{student}
     */
    public function detachStudent(string $parentId, string $studentId)
    {
        $parent = ParentModel::findOrFail($parentId);
        $parent->students()->detach($studentId);

        return response()->json([
            'success' => true,
            'message' => 'Relasi siswa berhasil dilepas.',
        ]);
    }
}
