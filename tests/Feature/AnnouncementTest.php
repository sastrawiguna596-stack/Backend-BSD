<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_and_publish_announcement()
    {
        Storage::fake('public');

        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $file = UploadedFile::fake()->image('poster.jpg', 600, 400);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/announcements', [
            'title'        => 'Pengumuman Libur Nasional',
            'description'  => 'Kegiatan belajar diliburkan pada hari libur nasional.',
            'status'       => 'published',
            'target_roles' => ['parent', 'teacher'],
            'poster'       => $file,
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Pengumuman berhasil dibuat.',
            ]);

        $this->assertDatabaseHas('announcements', [
            'title'  => 'Pengumuman Libur Nasional',
            'status' => 'published',
        ]);

        $this->assertDatabaseHas('announcement_targets', [
            'target_role' => 'parent',
        ]);
        $this->assertDatabaseHas('announcement_targets', [
            'target_role' => 'teacher',
        ]);
    }

    public function test_parents_only_see_published_announcements_targeted_to_them()
    {
        $parent = User::factory()->create(['role' => 'parent']);
        $admin = User::factory()->create(['role' => 'admin']);

        // 1. Published targeted to parent
        $ann1 = Announcement::create([
            'title'        => 'Info Untuk Ortu',
            'description'  => 'Deskripsi ortu',
            'status'       => 'published',
            'published_at' => now(),
            'created_by'   => $admin->id,
        ]);
        $ann1->targets()->create(['target_role' => 'parent']);

        // 2. Draft targeted to parent
        $ann2 = Announcement::create([
            'title'        => 'Draft Ortu',
            'description'  => 'Belum rilis',
            'status'       => 'draft',
            'created_by'   => $admin->id,
        ]);
        $ann2->targets()->create(['target_role' => 'parent']);

        // 3. Published targeted only to teacher
        $ann3 = Announcement::create([
            'title'        => 'Info Khusus Guru',
            'description'  => 'Deskripsi guru',
            'status'       => 'published',
            'published_at' => now(),
            'created_by'   => $admin->id,
        ]);
        $ann3->targets()->create(['target_role' => 'teacher']);

        $response = $this->actingAs($parent, 'sanctum')->getJson('/api/v1/announcements');

        $response->assertStatus(200);
        $titles = collect($response->json('data.data'))->pluck('title')->toArray();

        $this->assertContains('Info Untuk Ortu', $titles);
        $this->assertNotContains('Draft Ortu', $titles);
        $this->assertNotContains('Info Khusus Guru', $titles);
    }
}
