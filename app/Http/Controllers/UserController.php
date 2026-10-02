<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Owner;
use App\Models\ParentModel;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * Menampilkan daftar pengguna (bisa difilter berdasarkan role: admin, owner, dll)
     */
    public function index(Request $request)
    {
        $role = $request->query('role');
        $search = $request->query('search');

        $query = User::query()
            ->when($role, function ($q) use ($role) {
                $q->where('role', $role);
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('user_code', 'like', "%{$search}%");
                });
            })
            ->orderBy('created_at', 'desc');

        $users = $query->paginate((int) $request->input('per_page', 50));

        return response()->json([
            'success' => true,
            'data'    => $users,
        ]);
    }

    /**
     * Membuat akun user baru (khusus admin/staff)
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'      => 'required|string|max:255',
            'email'     => 'required|string|email|max:255|unique:users,email',
            'password'  => 'required|string|min:6',
            'phone'     => 'nullable|string|max:30',
            'role'      => ['required', Rule::in(['admin', 'owner', 'teacher', 'parent'])],
            'is_active' => 'nullable|boolean',
        ]);

        $role = $validated['role'];
        $prefix = strtoupper(substr($role, 0, 3));

        $seq = 1;
        do {
            $userCode = sprintf('%s-%s-%04d', $prefix, date('Y'), $seq);
            $seq++;
        } while (User::where('user_code', $userCode)->exists());

        $user = DB::transaction(function () use ($validated, $role, $userCode) {
            $user = User::create([
                'user_code'     => $userCode,
                'name'          => $validated['name'],
                'email'         => $validated['email'],
                'phone'         => $validated['phone'] ?? null,
                'role'          => $role,
                'password_hash' => Hash::make($validated['password']),
                'is_active'     => $validated['is_active'] ?? true,
            ]);

            if ($role === 'admin') {
                Admin::firstOrCreate(['user_id' => $user->id]);
            } elseif ($role === 'owner') {
                Owner::firstOrCreate(['user_id' => $user->id]);
            } elseif ($role === 'teacher') {
                Teacher::firstOrCreate(
                    ['user_id' => $user->id],
                    [
                        'teacher_code' => 'TCH-' . strtoupper(Str::random(6)),
                        'is_active'    => $user->is_active,
                    ]
                );
            } elseif ($role === 'parent') {
                ParentModel::firstOrCreate(
                    ['user_id' => $user->id],
                    [
                        'parent_code' => 'PRT-' . strtoupper(Str::random(6)),
                    ]
                );
            }

            return $user;
        });

        return response()->json([
            'success' => true,
            'message' => 'Akun berhasil dibuat.',
            'data'    => $user,
        ], 201);
    }

    /**
     * Menampilkan detail satu user
     */
    public function show(string $id)
    {
        $user = User::findOrFail($id);
        return response()->json([
            'success' => true,
            'data'    => $user,
        ]);
    }

    /**
     * Memperbarui akun user
     */
    public function update(Request $request, string $id)
    {
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'name'      => 'sometimes|required|string|max:255',
            'email'     => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'phone'     => 'nullable|string|max:30',
            'password'  => 'nullable|string|min:6',
            'is_active' => 'nullable|boolean',
        ]);

        if (!empty($validated['password'])) {
            $validated['password_hash'] = Hash::make($validated['password']);
        }
        unset($validated['password']);

        $user->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Akun berhasil diperbarui.',
            'data'    => $user,
        ]);
    }

    /**
     * Menghapus user
     */
    public function destroy(string $id)
    {
        $user = User::findOrFail($id);

        if (auth()->id() === $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak dapat menghapus akun Anda sendiri.',
            ], 422);
        }

        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'Akun berhasil dihapus.',
        ]);
    }
}
