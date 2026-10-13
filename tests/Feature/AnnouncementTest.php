<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\User;
use Carbon\Carbon;
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

    public function test_admin_creates_announcement_with_specific_time_preserves_exact_time(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $futureDatetime = Carbon::now()->addDays(5)->setTime(14, 30, 0)->format('Y-m-d H:i:s');

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/announcements', [
            'title'        => 'Webinar Parenting',
            'description'  => 'Webinar parenting melalui Zoom.',
            'status'       => 'published',
            'expires_at'   => $futureDatetime,
        ]);

        $response->assertStatus(201);
        $announcement = Announcement::where('title', 'Webinar Parenting')->first();
        $this->assertNotNull($announcement);
        $this->assertEquals($futureDatetime, $announcement->expires_at->format('Y-m-d H:i:s'));
    }

    public function test_admin_creates_announcement_with_date_only_sets_end_of_day(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $futureDate = Carbon::now()->addDays(5)->format('Y-m-d');

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/announcements', [
            'title'        => 'Pentas Seni',
            'description'  => 'Acara pentas seni tahunan.',
            'status'       => 'published',
            'expires_at'   => $futureDate,
        ]);

        $response->assertStatus(201);
        $announcement = Announcement::where('title', 'Pentas Seni')->first();
        $this->assertNotNull($announcement);
        $this->assertEquals($futureDate . ' 23:59:59', $announcement->expires_at->format('Y-m-d H:i:s'));
    }

    public function test_cannot_publish_announcement_with_past_expiration_date(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $pastDate = Carbon::now()->subDays(2)->format('Y-m-d H:i:s');

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/announcements', [
            'title'        => 'Acara Lampau',
            'description'  => 'Acara sudah selesai kemarin.',
            'status'       => 'published',
            'expires_at'   => $pastDate,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['expires_at']);
    }

    public function test_published_announcements_past_expiration_are_hidden_from_parents(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $parent = User::factory()->create(['role' => 'parent']);

        // Announcement 1: Active (expires in future)
        $activeAnn = Announcement::create([
            'title'        => 'Pengumuman Aktif',
            'description'  => 'Masih berlaku',
            'status'       => 'published',
            'published_at' => now(),
            'expires_at'   => Carbon::now()->addDays(2),
            'created_by'   => $admin->id,
        ]);
        $activeAnn->targets()->create(['target_role' => 'parent']);

        // Announcement 2: Expired
        $expiredAnn = Announcement::create([
            'title'        => 'Pengumuman Kedaluwarsa',
            'description'  => 'Sudah lewat',
            'status'       => 'published',
            'published_at' => now()->subDays(5),
            'expires_at'   => Carbon::now()->subHour(),
            'created_by'   => $admin->id,
        ]);
        $expiredAnn->targets()->create(['target_role' => 'parent']);

        $response = $this->actingAs($parent, 'sanctum')->getJson('/api/v1/announcements');
        $response->assertStatus(200);

        $titles = collect($response->json('data.data'))->pluck('title')->toArray();
        $this->assertContains('Pengumuman Aktif', $titles);
        $this->assertNotContains('Pengumuman Kedaluwarsa', $titles);
    }

    public function test_admin_active_only_filter_excludes_expired_announcements(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $activeAnn = Announcement::create([
            'title'        => 'Pengumuman Masa Depan',
            'description'  => 'Aktif',
            'status'       => 'published',
            'published_at' => now(),
            'expires_at'   => Carbon::now()->addDays(3),
            'created_by'   => $admin->id,
        ]);
        $activeAnn->targets()->create(['target_role' => 'all']);

        $expiredAnn = Announcement::create([
            'title'        => 'Pengumuman Masa Lalu',
            'description'  => 'Kedaluwarsa',
            'status'       => 'published',
            'published_at' => now()->subDays(4),
            'expires_at'   => Carbon::now()->subDay(),
            'created_by'   => $admin->id,
        ]);
        $expiredAnn->targets()->create(['target_role' => 'all']);

        // Admin request without active_only -> sees both
        $responseAll = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/announcements');
        $titlesAll = collect($responseAll->json('data.data'))->pluck('title')->toArray();
        $this->assertContains('Pengumuman Masa Depan', $titlesAll);
        $this->assertContains('Pengumuman Masa Lalu', $titlesAll);

        // Admin request with active_only=true -> sees only active
        $responseActive = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/announcements?active_only=true');
        $titlesActive = collect($responseActive->json('data.data'))->pluck('title')->toArray();
        $this->assertContains('Pengumuman Masa Depan', $titlesActive);
        $this->assertNotContains('Pengumuman Masa Lalu', $titlesActive);
    }

    public function test_updating_announcement_without_target_roles_preserves_existing_targets(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $ann = Announcement::create([
            'title'        => 'Judul Awal',
            'description'  => 'Deskripsi awal',
            'status'       => 'published',
            'published_at' => now(),
            'created_by'   => $admin->id,
        ]);
        $ann->targets()->create(['target_role' => 'teacher']);
        $ann->targets()->create(['target_role' => 'parent']);

        $response = $this->actingAs($admin, 'sanctum')->putJson('/api/v1/announcements/' . $ann->id, [
            'title' => 'Judul Baru Diperbarui',
        ]);

        $response->assertStatus(200);
        $targetRoles = $ann->fresh()->targets->pluck('target_role')->toArray();
        $this->assertEqualsCanonicalizing(['teacher', 'parent'], $targetRoles);
    }

    public function test_updating_announcement_with_empty_target_roles_defaults_to_all(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $ann = Announcement::create([
            'title'        => 'Target Spesifik',
            'description'  => 'Akan diubah ke all',
            'status'       => 'published',
            'published_at' => now(),
            'created_by'   => $admin->id,
        ]);
        $ann->targets()->create(['target_role' => 'student']);

        // Kirim target_roles eksplisit kosong []
        $response = $this->actingAs($admin, 'sanctum')->putJson('/api/v1/announcements/' . $ann->id, [
            'target_roles' => [],
        ]);

        $response->assertStatus(200);
        $targetRoles = $ann->fresh()->targets->pluck('target_role')->toArray();
        $this->assertEquals(['all'], $targetRoles);
    }

    public function test_updating_announcement_poster_deletes_old_poster_from_storage(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin']);

        // Upload first poster
        $file1 = UploadedFile::fake()->image('first_poster.jpg', 600, 400);
        $resCreate = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/announcements', [
            'title'       => 'Pengumuman Berposter',
            'description' => 'Ada poster',
            'poster'      => $file1,
        ]);
        $resCreate->assertStatus(201);
        $ann = Announcement::where('title', 'Pengumuman Berposter')->first();
        $rawPath1 = str_replace('/storage/', '', $ann->getRawOriginal('poster_url'));
        Storage::disk('public')->assertExists($rawPath1);

        // Upload second poster via update
        $file2 = UploadedFile::fake()->image('second_poster.jpg', 600, 400);
        $resUpdate = $this->actingAs($admin, 'sanctum')->putJson('/api/v1/announcements/' . $ann->id, [
            'poster' => $file2,
        ]);
        $resUpdate->assertStatus(200);

        // Old poster must be deleted
        Storage::disk('public')->assertMissing($rawPath1);

        // New poster must exist
        $rawPath2 = str_replace('/storage/', '', $ann->fresh()->getRawOriginal('poster_url'));
        Storage::disk('public')->assertExists($rawPath2);
    }

    public function test_destroy_announcement_deletes_poster_from_storage(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin']);

        $file = UploadedFile::fake()->image('to_delete.jpg', 600, 400);
        $res = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/announcements', [
            'title'       => 'Pengumuman Untuk Dihapus',
            'description' => 'Hapus nanti',
            'poster'      => $file,
        ]);
        $res->assertStatus(201);
        $ann = Announcement::where('title', 'Pengumuman Untuk Dihapus')->first();
        $rawPath = str_replace('/storage/', '', $ann->getRawOriginal('poster_url'));
        Storage::disk('public')->assertExists($rawPath);

        // Destroy
        $resDelete = $this->actingAs($admin, 'sanctum')->deleteJson('/api/v1/announcements/' . $ann->id);
        $resDelete->assertStatus(200);

        Storage::disk('public')->assertMissing($rawPath);
    }

    public function test_updating_poster_does_not_delete_if_used_by_other_announcement(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin']);

        // Upload first poster
        $file1 = UploadedFile::fake()->image('shared_poster.jpg', 600, 400);
        $res = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/announcements', [
            'title'       => 'Pengumuman Utama',
            'description' => 'Memiliki poster',
            'poster'      => $file1,
        ]);
        $res->assertStatus(201);
        $ann1 = Announcement::where('title', 'Pengumuman Utama')->first();
        $sharedPath = $ann1->getRawOriginal('poster_url');
        $diskPath = str_replace('/storage/', '', $sharedPath);
        Storage::disk('public')->assertExists($diskPath);

        // Another announcement reuses the same poster path
        $ann2 = Announcement::create([
            'title'       => 'Pengumuman Kedua Berbagi Poster',
            'description' => 'Pakai poster yang sama',
            'poster_url'  => $sharedPath,
            'status'      => 'published',
            'created_by'  => $admin->id,
        ]);

        // Now update ann1 with a new poster
        $file2 = UploadedFile::fake()->image('new_poster.jpg', 600, 400);
        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/announcements/' . $ann1->id, [
            'poster' => $file2,
        ]);

        // Shared poster must STILL exist because ann2 is using it
        Storage::disk('public')->assertExists($diskPath);
    }
}
