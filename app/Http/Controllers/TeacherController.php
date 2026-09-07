<?php

namespace App\Http\Controllers;

use App\Models\Teacher;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TeacherController extends Controller
{
    /**
     * Daftar semua guru beserta data akun pengguna.
     * Mendukung filter is_active.
     */
    public function index(Request $request)
    {
        $query = Teacher::with('user')
            ->when($request->has('is_active'), function ($q) use ($request) {
                $q->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
            })
            ->when($request->search, function ($q) use ($request) {
                $q->whereHas('user', function ($u) use ($request) {
                    $u->where('name', 'like', '%' . $request->search . '%')
                      ->orWhere('email', 'like', '%' . $request->search . '%');
                });
            });

        $teachers = $query->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data'    => $teachers,
        ]);
    }

    /**
     * Buat akun guru baru.
     * Membuat data di tabel users dan teachers dalam satu transaksi.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'                => 'required|string|max:255',
            'email'               => 'required|email|unique:users,email',
            'phone'               => 'nullable|string|max:20',
            'password'            => 'required|string|min:8',
            'bank_name'           => 'nullable|string|max:100',
            'bank_account_name'   => 'nullable|string|max:255',
            'bank_account_number' => 'nullable|string|max:50',
        ]);

        $teacher = DB::transaction(function () use ($validated) {
            // Buat akun user
            $user = User::create([
                'user_code'     => 'USR-GURU-' . strtoupper(Str::random(5)),
                'role'          => 'teacher',
                'name'          => $validated['name'],
                'email'         => $validated['email'],
                'phone'         => $validated['phone'] ?? null,
                'password_hash' => Hash::make($validated['password']),
                'is_active'     => true,
            ]);

            // Buat profil guru
            $teacher = Teacher::create([
                'user_id'             => $user->id,
                'teacher_code'        => 'TCH-' . strtoupper(Str::random(6)),
                'bank_name'           => $validated['bank_name'] ?? null,
                'bank_account_name'   => $validated['bank_account_name'] ?? null,
                'bank_account_number' => $validated['bank_account_number'] ?? null,
                'is_active'           => true,
            ]);

            return $teacher->load('user');
        });

        return response()->json([
            'success' => true,
            'message' => 'Guru berhasil ditambahkan.',
            'data'    => $teacher,
        ], 201);
    }

    /**
     * Detail satu guru.
     */
    public function show(string $id)
    {
        $teacher = Teacher::with('user')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => $teacher,
        ]);
    }

    /**
     * Update data guru dan akun pengguna terkait.
     */
    public function update(Request $request, string $id)
    {
        $teacher = Teacher::with('user')->findOrFail($id);

        $validated = $request->validate([
            'name'                => 'sometimes|string|max:255',
            'email'               => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($teacher->user_id)],
            'phone'               => 'nullable|string|max:20',
            'password'            => 'nullable|string|min:8',
            'bank_name'           => 'nullable|string|max:100',
            'bank_account_name'   => 'nullable|string|max:255',
            'bank_account_number' => 'nullable|string|max:50',
            'is_active'           => 'sometimes|boolean',
        ]);

        DB::transaction(function () use ($teacher, $validated) {
            // Update data akun user
            $userFields = array_filter([
                'name'          => $validated['name'] ?? null,
                'email'         => $validated['email'] ?? null,
                'phone'         => $validated['phone'] ?? null,
                'password_hash' => isset($validated['password']) ? Hash::make($validated['password']) : null,
                'is_active'     => $validated['is_active'] ?? null,
            ], fn($v) => ! is_null($v));

            if (! empty($userFields)) {
                $teacher->user->update($userFields);
            }

            // Update profil guru
            $teacher->update(array_filter([
                'bank_name'           => $validated['bank_name'] ?? null,
                'bank_account_name'   => $validated['bank_account_name'] ?? null,
                'bank_account_number' => $validated['bank_account_number'] ?? null,
                'is_active'           => $validated['is_active'] ?? null,
            ], fn($v) => ! is_null($v)));
        });

        return response()->json([
            'success' => true,
            'message' => 'Data guru berhasil diperbarui.',
            'data'    => $teacher->fresh('user'),
        ]);
    }

    /**
     * Nonaktifkan guru (soft disable — bukan hapus permanen).
     * Data historis absensi, logbook, dan gaji tetap aman.
     */
    public function destroy(string $id)
    {
        $teacher = Teacher::with('user')->findOrFail($id);

        DB::transaction(function () use ($teacher) {
            $teacher->update(['is_active' => false]);
            $teacher->user->update(['is_active' => false]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Guru berhasil dinonaktifkan.',
        ]);
    }
}
