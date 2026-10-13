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
            })
            ->when($request->boolean('active_only'), function ($q) {
                $q->active();
            });
        } else {
            // Publik / Siswa / Ortu / Guru: hanya yang sudah published dan belum kedaluwarsa
            $query->where('status', 'published')->active();

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
            'expires_at'   => [
                'nullable',
                'date',
                function ($attribute, $value, $fail) use ($request) {
                    $status = $request->input('status', 'published');
                    if ($status === 'published' && !empty($value)) {
                        $expiry = $this->parseExpiresAt($value);
                        if ($expiry && $expiry->isPast()) {
                            $fail('Tanggal kedaluwarsa untuk pengumuman yang dipublikasikan tidak boleh sudah lewat.');
                        }
                    }
                },
            ],
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
            $payload = [
                'title'        => $validated['title'],
                'description'  => $validated['description'],
                'poster_url'   => $posterUrl,
                'status'       => $status,
                'published_at' => $publishedAt,
                'expires_at'   => $this->parseExpiresAt($validated['expires_at'] ?? null),
                'created_by'   => $user?->id,
                'updated_by'   => $user?->id,
            ];

            $announcement = Announcement::create($payload);

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
            'expires_at'   => [
                'nullable',
                'date',
                function ($attribute, $value, $fail) use ($request, $announcement) {
                    $newStatus = $request->input('status', $announcement->status);
                    if ($newStatus === 'published' && !empty($value)) {
                        $expiry = $this->parseExpiresAt($value);
                        if ($expiry && $expiry->isPast()) {
                            $fail('Tanggal kedaluwarsa untuk pengumuman yang dipublikasikan tidak boleh sudah lewat.');
                        }
                    }
                },
            ],
            'target_roles' => 'nullable|array',
            'target_roles.*' => 'string|in:student,parent,teacher,admin,owner,all',
        ]);

        if ($request->has('status') && $request->input('status') === 'published') {
            $effectiveExpiry = $request->has('expires_at')
                ? $this->parseExpiresAt($validated['expires_at'] ?? null)
                : $announcement->expires_at;

            if ($effectiveExpiry && $effectiveExpiry->isPast()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak dapat mempublikasikan pengumuman dengan tanggal kedaluwarsa yang sudah lewat.'
                ], 422);
            }
        }

        $user = $request->user();

        return DB::transaction(function () use ($announcement, $validated, $user, $request) {
            $dataToUpdate = [];

            if ($request->has('title')) {
                $dataToUpdate['title'] = $validated['title'];
            }

            if ($request->has('description')) {
                $dataToUpdate['description'] = $validated['description'];
            }

            if ($request->has('expires_at')) {
                $dataToUpdate['expires_at'] = $this->parseExpiresAt($validated['expires_at'] ?? null);
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
                $oldPoster = $announcement->getRawOriginal('poster_url');
                $path = $request->file('poster')->store('announcements', 'public');
                $dataToUpdate['poster_url'] = Storage::url($path);
                $this->deletePosterFile($oldPoster, $announcement->id);
            }

            $dataToUpdate['updated_by'] = $user?->id;
            $announcement->update($dataToUpdate);

            // Update target peran jika field target_roles dikirimkan
            if ($request->has('target_roles')) {
                $targetRoles = !empty($validated['target_roles']) ? $validated['target_roles'] : ['all'];
                $announcement->targets()->delete();
                foreach ($targetRoles as $role) {
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

        $oldPoster = $announcement->getRawOriginal('poster_url');
        $this->deletePosterFile($oldPoster, $announcement->id);

        $announcement->delete();

        return response()->json([
            'success' => true,
            'message' => 'Pengumuman berhasil dihapus.',
        ]);
    }

    /**
     * Parse input waktu kedaluwarsa:
     * - Null / kosong -> null
     * - Format tanggal saja (YYYY-MM-DD) -> berlaku sampai akhir hari (endOfDay)
     * - Format datetime dengan jam spesifik -> pertahankan waktu spesifik
     */
    protected function parseExpiresAt(?string $value): ?Carbon
    {
        if (empty($value)) {
            return null;
        }

        $raw = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return Carbon::parse($raw)->endOfDay();
        }

        return Carbon::parse($raw);
    }

    /**
     * Hapus file poster fisik dari disk public menggunakan path relatif yang benar.
     * Tidak menghapus jika file masih digunakan oleh pengumuman lain.
     */
    protected function deletePosterFile(?string $rawPosterUrl, string $currentAnnouncementId): void
    {
        if (empty($rawPosterUrl)) {
            return;
        }

        $path = parse_url($rawPosterUrl, PHP_URL_PATH) ?? $rawPosterUrl;
        $path = ltrim($path, '/');
        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        if (empty($path)) {
            return;
        }

        $isUsedByOther = Announcement::where('id', '!=', $currentAnnouncementId)
            ->where(function ($q) use ($rawPosterUrl, $path) {
                $q->where('poster_url', $rawPosterUrl)
                  ->orWhere('poster_url', $path)
                  ->orWhere('poster_url', '/storage/' . $path)
                  ->orWhere('poster_url', 'storage/' . $path);
            })
            ->exists();

        if (!$isUsedByOther && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
