<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\AnnouncementTarget;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AnnouncementController extends Controller
{
    /**
     * Menampilkan daftar pengumuman.
     * - Publik / Siswa / Ortu: otomatis hanya menampilkan status 'published' yang sesuai role.
     * - Admin / Owner: melihat semua pengumuman (draft, published, archived) + filter status.
     */
    public function index(Request $request)
    {
        $user = $request->user('sanctum');

        $query = Announcement::with([
            'targets',
            'author:id,name,email,role',
        ]);

        if ($user && in_array($user->role, ['admin', 'owner'])) {
            // Admin & Owner: bisa filter status (draft, published, archived)
            $query->when($request->filled('status'), function ($q) use ($request) {
                $q->where('status', $request->status);
            })
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->where(function ($sub) use ($request) {
                    $sub->where('title', 'like', '%' . $request->search . '%')
                        ->orWhere('description', 'like', '%' . $request->search . '%');
                });
            });
        } else {
            // Publik / Siswa / Ortu / Guru: hanya yang sudah published
            $query->where('status', 'published');

            if ($user) {
                $role = $user->role;
                $query->whereHas('targets', function ($q) use ($role) {
                    $q->whereIn('target_role', [$role, 'all']);
                });
            } else {
                // Pengunjung umum (belum login): target publik/all
                $query->whereHas('targets', function ($q) {
                    $q->whereIn('target_role', ['all', 'public']);
                });
            }
        }

        $announcements = $query->orderBy('published_at', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 10));

        return response()->json([
            'success' => true,
            'data'    => $announcements,
        ]);
    }

    /**
     * Membuat pengumuman baru (Admin / Owner).
     * Mendukung status: draft / published / archived, target roles, serta upload file poster.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title'        => 'required|string|max:255',
            'description'  => 'required|string',
            'status'       => 'nullable|string|in:draft,published,archived',
            'poster'       => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120', // Maks 5MB
            'target_roles' => 'nullable|array',
            'target_roles.*' => 'string|in:student,parent,teacher,admin,owner,all',
        ]);

        $user = $request->user();
        $status = $validated['status'] ?? 'published';
        $publishedAt = ($status === 'published') ? Carbon::now() : null;

        $posterUrl = null;
        if ($request->hasFile('poster')) {
            $path = $request->file('poster')->store('announcements', 'public');
            $posterUrl = Storage::url($path);
        }

        return DB::transaction(function () use ($validated, $user, $status, $publishedAt, $posterUrl) {
            $announcement = Announcement::create([
                'title'        => $validated['title'],
                'description'  => $validated['description'],
                'poster_url'   => $posterUrl,
                'status'       => $status,
                'published_at' => $publishedAt,
                'created_by'   => $user?->id,
                'updated_by'   => $user?->id,
            ]);

            // Simpan target peran (default 'all' jika kosong)
            $targetRoles = !empty($validated['target_roles']) ? $validated['target_roles'] : ['all'];
            foreach ($targetRoles as $role) {
                AnnouncementTarget::create([
                    'announcement_id' => $announcement->id,
                    'target_role'     => $role,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Pengumuman berhasil dibuat.',
                'data'    => $announcement->load(['targets', 'author:id,name,email,role']),
            ], 201);
        });
    }

    /**
     * Detail satu pengumuman berdasarkan ID.
     */
    public function show(string $id)
    {
        $announcement = Announcement::with([
            'targets',
            'author:id,name,email,role',
            'updater:id,name,email,role',
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => $announcement,
        ]);
    }

    /**
     * Mengubah data pengumuman (Admin / Owner).
     */
    public function update(Request $request, string $id)
    {
        $announcement = Announcement::findOrFail($id);

        $validated = $request->validate([
            'title'        => 'sometimes|required|string|max:255',
            'description'  => 'sometimes|required|string',
            'status'       => 'nullable|string|in:draft,published,archived',
            'poster'       => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120',
            'target_roles' => 'nullable|array',
            'target_roles.*' => 'string|in:student,parent,teacher,admin,owner,all',
        ]);

        $user = $request->user();

        return DB::transaction(function () use ($announcement, $validated, $user, $request) {
            $dataToUpdate = [];

            if ($request->has('title')) {
                $dataToUpdate['title'] = $validated['title'];
            }

            if ($request->has('description')) {
                $dataToUpdate['description'] = $validated['description'];
            }

            if ($request->has('status')) {
                $newStatus = $validated['status'];
                $dataToUpdate['status'] = $newStatus;

                // Jika status diubah jadi published dan sebelumnya belum pernah di-publish
                if ($newStatus === 'published' && !$announcement->published_at) {
                    $dataToUpdate['published_at'] = Carbon::now();
                }
            }

            if ($request->hasFile('poster')) {
                // Hapus file lama jika ada
                if ($announcement->poster_url) {
                    $oldPath = str_replace('/storage/', '', $announcement->poster_url);
                    Storage::disk('public')->delete($oldPath);
                }

                $path = $request->file('poster')->store('announcements', 'public');
                $dataToUpdate['poster_url'] = Storage::url($path);
            }

            $dataToUpdate['updated_by'] = $user?->id;
            $announcement->update($dataToUpdate);

            // Update target peran jika diberikan
            if ($request->has('target_roles') && is_array($validated['target_roles'])) {
                $announcement->targets()->delete();
                foreach ($validated['target_roles'] as $role) {
                    AnnouncementTarget::create([
                        'announcement_id' => $announcement->id,
                        'target_role'     => $role,
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Pengumuman berhasil diperbarui.',
                'data'    => $announcement->fresh(['targets', 'author:id,name,email,role', 'updater:id,name,email,role']),
            ]);
        });
    }

    /**
     * Menghapus pengumuman (Admin / Owner).
     */
    public function destroy(string $id)
    {
        $announcement = Announcement::findOrFail($id);

        // Hapus poster fisik di storage jika ada
        if ($announcement->poster_url) {
            $oldPath = str_replace('/storage/', '', $announcement->poster_url);
            Storage::disk('public')->delete($oldPath);
        }

        $announcement->delete();

        return response()->json([
            'success' => true,
            'message' => 'Pengumuman berhasil dihapus.',
        ]);
    }
}
