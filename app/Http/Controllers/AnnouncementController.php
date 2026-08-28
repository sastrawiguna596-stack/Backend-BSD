<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AnnouncementController extends Controller
{
    /**
     * Mengambil daftar pengumuman untuk siswa / wali murid
     */
    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => [
                [
                    'id' => '9a8b7c6d-1234-5678-90ab-cdef12345678',
                    'title' => 'Pengumuman Libur Hari Kemerdekaan & Tryout Nasional',
                    'description' => 'Dalam rangka memperingati Hari Kemerdekaan, kegiatan bimbingan belajar diliburkan dan akan diadakan Tryout Nasional.',
                    'poster_url' => asset('storage/announcements/poster-kemerdekaan.jpg'),
                    'targets' => ['student', 'parent'],
                    'published_at' => date('Y-m-d H:i:s')
                ]
            ]
        ]);
    }

    /**
     * Membuat pengumuman baru + Upload File Gambar Poster / Flyer
     * Content-Type: multipart/form-data
     */
    public function store(Request $request)
    {
        // Validasi input & file gambar poster/flyer
        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'poster' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120', // Maksimal 5MB
            'target_roles' => 'nullable|array'
        ]);

        $posterUrl = null;

        // Proses simpan file gambar poster jika di-upload
        if ($request->hasFile('poster')) {
            $file = $request->file('poster');
            // Simpan ke folder: storage/app/public/announcements
            $path = $file->store('announcements', 'public');
            // Hasilkan URL publik yang bisa diakses Frontend: http://localhost:8000/storage/announcements/...
            $posterUrl = asset('storage/' . $path);
        }

        return response()->json([
            'success' => true,
            'message' => 'Pengumuman berhasil dibuat dan gambar poster berhasil di-upload!',
            'data' => [
                'id' => 'uuid-generated-id',
                'title' => $request->input('title'),
                'description' => $request->input('description'),
                'poster_url' => $posterUrl,
                'target_roles' => $request->input('target_roles', ['student', 'parent']),
                'published_at' => date('Y-m-d H:i:s')
            ]
        ], 201);
    }
}
