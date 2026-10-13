<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\ClassSchedule;
use App\Models\Program;
use App\Models\ProgramLevel;
use App\Models\Teacher;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClassScheduleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function createProgramAndClassroom(): Classroom
    {
        $program = Program::create([
            'program_code' => 'PRG-' . Str::random(4),
            'name'         => 'Coding Kids',
            'is_active'    => true,
        ]);

        $level = ProgramLevel::create([
            'program_id'  => $program->id,
            'level_code'  => 'LVL-' . Str::random(4),
            'name'        => 'Beginner',
            'level_order' => 1,
        ]);

        return Classroom::create([
            'program_level_id' => $level->id,
            'class_code'       => 'CLS-' . Str::random(4),
            'name'             => 'Classroom A',
            'capacity'         => 10,
            'status'           => 'active',
        ]);
    }

    protected function createTeacherUser(string $code = 'TCH-001', bool $isActive = true): array
    {
        $user = User::factory()->create(['role' => 'teacher']);
        $teacher = Teacher::create([
            'user_id'      => $user->id,
            'teacher_code' => $code,
            'is_active'    => $isActive,
        ]);

        return [$user, $teacher];
    }

    public function test_active_teacher_assigned_to_classroom_can_access_schedule_in_index_and_show(): void
    {
        $classroom = $this->createProgramAndClassroom();
        [$primaryUser, $primaryTeacher] = $this->createTeacherUser('TCH-PRIM');
        [$assignedUser, $assignedTeacher] = $this->createTeacherUser('TCH-ASGN');

        $classroom->teachers()->attach($assignedTeacher->id, [
            'id'              => (string) Str::uuid(),
            'effective_from'  => Carbon::today()->subDays(5)->toDateString(),
            'effective_until' => null,
            'role'            => 'assistant',
        ]);

        $schedule = ClassSchedule::create([
            'classroom_id'    => $classroom->id,
            'teacher_id'      => $primaryTeacher->id,
            'day_of_week'     => 'Monday',
            'start_time'      => '10:00:00',
            'end_time'        => '11:30:00',
            'effective_from'  => Carbon::today()->subDays(10)->toDateString(),
            'effective_until' => null,
            'is_active'       => true,
        ]);

        // Index
        $responseIndex = $this->actingAs($assignedUser, 'sanctum')->getJson('/api/v1/class-schedules');
        $responseIndex->assertStatus(200);
        $scheduleIds = collect($responseIndex->json('data'))->pluck('id')->toArray();
        $this->assertContains($schedule->id, $scheduleIds);

        // Show
        $responseShow = $this->actingAs($assignedUser, 'sanctum')->getJson('/api/v1/class-schedules/' . $schedule->id);
        $responseShow->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data'    => [
                    'id' => $schedule->id,
                ],
            ]);
    }

    public function test_teacher_with_expired_classroom_assignment_cannot_access_schedule(): void
    {
        $classroom = $this->createProgramAndClassroom();
        [$primaryUser, $primaryTeacher] = $this->createTeacherUser('TCH-PRIM-2');
        [$expiredUser, $expiredTeacher] = $this->createTeacherUser('TCH-EXP');

        // Assignment expired yesterday
        $classroom->teachers()->attach($expiredTeacher->id, [
            'id'              => (string) Str::uuid(),
            'effective_from'  => Carbon::today()->subDays(30)->toDateString(),
            'effective_until' => Carbon::today()->subDay()->toDateString(),
            'role'            => 'assistant',
        ]);

        $schedule = ClassSchedule::create([
            'classroom_id'    => $classroom->id,
            'teacher_id'      => $primaryTeacher->id,
            'day_of_week'     => 'Tuesday',
            'start_time'      => '10:00:00',
            'end_time'        => '11:30:00',
            'effective_from'  => Carbon::today()->subDays(10)->toDateString(),
            'effective_until' => null,
            'is_active'       => true,
        ]);

        // Index should NOT include the schedule
        $responseIndex = $this->actingAs($expiredUser, 'sanctum')->getJson('/api/v1/class-schedules');
        $responseIndex->assertStatus(200);
        $scheduleIds = collect($responseIndex->json('data'))->pluck('id')->toArray();
        $this->assertNotContains($schedule->id, $scheduleIds);

        // Show should return 403 Forbidden
        $responseShow = $this->actingAs($expiredUser, 'sanctum')->getJson('/api/v1/class-schedules/' . $schedule->id);
        $responseShow->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Anda tidak memiliki akses ke data jadwal ini.',
            ]);
    }

    public function test_inactive_teacher_cannot_access_schedules(): void
    {
        $classroom = $this->createProgramAndClassroom();
        [$inactiveUser, $inactiveTeacher] = $this->createTeacherUser('TCH-INACT', false);

        $classroom->teachers()->attach($inactiveTeacher->id, [
            'id'              => (string) Str::uuid(),
            'effective_from'  => Carbon::today()->subDays(10)->toDateString(),
            'effective_until' => null,
            'role'            => 'assistant',
        ]);

        $schedule = ClassSchedule::create([
            'classroom_id'    => $classroom->id,
            'teacher_id'      => $inactiveTeacher->id,
            'day_of_week'     => 'Wednesday',
            'start_time'      => '10:00:00',
            'end_time'        => '11:30:00',
            'effective_from'  => Carbon::today()->subDays(10)->toDateString(),
            'effective_until' => null,
            'is_active'       => true,
        ]);

        // Index returns empty
        $responseIndex = $this->actingAs($inactiveUser, 'sanctum')->getJson('/api/v1/class-schedules');
        $responseIndex->assertStatus(200);
        $this->assertEmpty($responseIndex->json('data'));

        // Show returns 403
        $responseShow = $this->actingAs($inactiveUser, 'sanctum')->getJson('/api/v1/class-schedules/' . $schedule->id);
        $responseShow->assertStatus(403);
    }

    public function test_unrelated_teacher_cannot_access_other_schedules(): void
    {
        $classroom = $this->createProgramAndClassroom();
        [$primaryUser, $primaryTeacher] = $this->createTeacherUser('TCH-PRIM-3');
        [$unrelatedUser, $unrelatedTeacher] = $this->createTeacherUser('TCH-UNRELATED');

        $schedule = ClassSchedule::create([
            'classroom_id'    => $classroom->id,
            'teacher_id'      => $primaryTeacher->id,
            'day_of_week'     => 'Thursday',
            'start_time'      => '10:00:00',
            'end_time'        => '11:30:00',
            'effective_from'  => Carbon::today()->subDays(10)->toDateString(),
            'effective_until' => null,
            'is_active'       => true,
        ]);

        $responseIndex = $this->actingAs($unrelatedUser, 'sanctum')->getJson('/api/v1/class-schedules');
        $responseIndex->assertStatus(200);
        $scheduleIds = collect($responseIndex->json('data'))->pluck('id')->toArray();
        $this->assertNotContains($schedule->id, $scheduleIds);

        $responseShow = $this->actingAs($unrelatedUser, 'sanctum')->getJson('/api/v1/class-schedules/' . $schedule->id);
        $responseShow->assertStatus(403);
    }

    public function test_admin_can_access_all_schedules_in_index_and_show(): void
    {
        $classroom = $this->createProgramAndClassroom();
        [$primaryUser, $primaryTeacher] = $this->createTeacherUser('TCH-PRIM-4');
        $admin = User::factory()->create(['role' => 'admin']);

        $schedule = ClassSchedule::create([
            'classroom_id'    => $classroom->id,
            'teacher_id'      => $primaryTeacher->id,
            'day_of_week'     => 'Friday',
            'start_time'      => '14:00:00',
            'end_time'        => '15:30:00',
            'effective_from'  => Carbon::today()->subDays(10)->toDateString(),
            'effective_until' => null,
            'is_active'       => true,
        ]);

        $responseIndex = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/class-schedules');
        $responseIndex->assertStatus(200);
        $scheduleIds = collect($responseIndex->json('data'))->pluck('id')->toArray();
        $this->assertContains($schedule->id, $scheduleIds);

        $responseShow = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/class-schedules/' . $schedule->id);
        $responseShow->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data'    => ['id' => $schedule->id],
            ]);
    }
}
