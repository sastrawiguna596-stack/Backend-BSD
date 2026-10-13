<?php

namespace Tests\Feature;

use App\Models\CashTransaction;
use App\Models\Classroom;
use App\Models\ClassSession;
use App\Models\Enrollment;
use App\Models\ParentModel;
use App\Models\Payment;
use App\Models\PaymentPlan;
use App\Models\Program;
use App\Models\ProgramLevel;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeacherLogbook;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SecurityAndIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setupClassroom(): array
    {
        $program = Program::create([
            'program_code' => 'PRG-' . Str::random(4),
            'name'         => 'Sains Cilik',
            'is_active'    => true,
        ]);

        $level = ProgramLevel::create([
            'program_id'  => $program->id,
            'level_code'  => 'LVL-' . Str::random(4),
            'name'        => 'Level 1',
            'level_order' => 1,
        ]);

        $classroom = Classroom::create([
            'program_level_id' => $level->id,
            'class_code'       => 'CLS-' . Str::random(4),
            'name'             => 'Kelas Sains A',
            'capacity'         => 12,
            'status'           => 'active',
        ]);

        return [$program, $level, $classroom];
    }

    public function test_parent_cannot_view_unrelated_student_idor_defense(): void
    {
        $parentUser = User::factory()->create(['role' => 'parent']);
        $parentModel = ParentModel::create([
            'user_id'      => $parentUser->id,
            'parent_code'  => 'PAR-001',
            'relationship' => 'Ibu',
        ]);

        $ownChild = Student::create([
            'student_code' => 'STU-001',
            'full_name'    => 'Anak Kandung',
            'status'       => 'active',
        ]);
        $parentModel->students()->attach($ownChild->id, [
            'id'           => (string) Str::uuid(),
            'relationship' => 'Anak Kandung',
            'is_primary'   => true,
        ]);

        $unrelatedStudent = Student::create([
            'student_code' => 'STU-999',
            'full_name'    => 'Anak Orang Lain',
            'status'       => 'active',
        ]);

        // Own child -> 200 OK
        $resOwn = $this->actingAs($parentUser, 'sanctum')->getJson('/api/v1/students/' . $ownChild->id);
        $resOwn->assertStatus(200)
            ->assertJson(['success' => true, 'data' => ['id' => $ownChild->id]]);

        // Unrelated student -> 403 Forbidden
        $resOther = $this->actingAs($parentUser, 'sanctum')->getJson('/api/v1/students/' . $unrelatedStudent->id);
        $resOther->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Anda tidak memiliki hak akses ke data siswa ini.',
            ]);
    }

    public function test_teacher_cannot_self_verify_or_edit_verified_logbook(): void
    {
        [, , $classroom] = $this->setupClassroom();

        $teacherUser1 = User::factory()->create(['role' => 'teacher']);
        $teacher1 = Teacher::create([
            'user_id'      => $teacherUser1->id,
            'teacher_code' => 'TCH-001',
            'is_active'    => true,
        ]);

        $teacherUser2 = User::factory()->create(['role' => 'teacher']);
        $teacher2 = Teacher::create([
            'user_id'      => $teacherUser2->id,
            'teacher_code' => 'TCH-002',
            'is_active'    => true,
        ]);

        $session = ClassSession::create([
            'classroom_id'   => $classroom->id,
            'teacher_id'     => $teacher1->id,
            'session_number' => 1,
            'session_date'   => Carbon::today()->toDateString(),
            'start_time'     => '09:00:00',
            'end_time'       => '10:30:00',
            'status'         => 'completed',
        ]);

        $logbook1 = TeacherLogbook::create([
            'class_session_id' => $session->id,
            'teacher_id'       => $teacher1->id,
            'material'         => 'Pengenalan Robotik',
            'status'           => 'submitted',
        ]);

        // 1. Teacher 2 cannot edit Teacher 1's logbook -> 403
        $resOtherTeacher = $this->actingAs($teacherUser2, 'sanctum')->patchJson('/api/v1/teacher-logbooks/' . $logbook1->id, [
            'material' => 'Diubah Orang Lain',
        ]);
        $resOtherTeacher->assertStatus(403);

        // 2. Teacher 1 cannot self-verify own logbook -> 403
        $resSelfVerify = $this->actingAs($teacherUser1, 'sanctum')->patchJson('/api/v1/teacher-logbooks/' . $logbook1->id, [
            'status' => 'verified',
        ]);
        $resSelfVerify->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Hanya Owner atau Admin yang dapat memverifikasi logbook.',
            ]);

        // 3. Admin can verify logbook -> 200
        $adminUser = User::factory()->create(['role' => 'admin']);
        $resAdminVerify = $this->actingAs($adminUser, 'sanctum')->patchJson('/api/v1/teacher-logbooks/' . $logbook1->id, [
            'status' => 'verified',
        ]);
        $resAdminVerify->assertStatus(200);

        // 4. Once verified, Teacher 1 cannot modify it -> 403
        $resEditVerified = $this->actingAs($teacherUser1, 'sanctum')->patchJson('/api/v1/teacher-logbooks/' . $logbook1->id, [
            'material' => 'Mencoba Mengubah Setelah Verifikasi',
        ]);
        $resEditVerified->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Logbook yang sudah diverifikasi tidak dapat diubah.',
            ]);
    }

    public function test_cash_confirmation_is_transactional_and_idempotent(): void
    {
        [$program, $level, $classroom] = $this->setupClassroom();
        $admin = User::factory()->create(['role' => 'admin']);
        $parent = User::factory()->create(['role' => 'parent']);

        $student = Student::create([
            'student_code' => 'STU-CASH-001',
            'full_name'    => 'Siswa Cash',
            'status'       => 'active',
        ]);

        $enrollment = Enrollment::create([
            'student_id'       => $student->id,
            'classroom_id'     => $classroom->id,
            'program_id'       => $program->id,
            'program_level_id' => $level->id,
            'enrollment_code'  => 'ENR-CASH-001',
            'start_date'       => Carbon::today()->toDateString(),
            'total_sessions'   => 8,
            'payment_scheme'   => 'per_pertemuan',
            'status'           => 'active',
        ]);

        $plan = PaymentPlan::create([
            'enrollment_id'  => $enrollment->id,
            'session_number' => 1,
            'amount'         => 150000,
            'due_date'       => Carbon::today()->addDays(7)->toDateString(),
            'is_paid'        => false,
            'status'         => 'unpaid',
        ]);

        $payment = Payment::create([
            'payment_code'    => 'PAY-2026-0001',
            'enrollment_id'   => $enrollment->id,
            'student_id'      => $student->id,
            'payment_plan_id' => $plan->id,
            'amount'          => 150000,
            'total_amount'    => 150000,
            'payment_method'  => 'cash',
            'payment_status'  => 'pending',
        ]);

        $cash = CashTransaction::create([
            'cash_code'       => 'CASH-2026-0001',
            'payment_plan_id' => $plan->id,
            'payment_id'      => $payment->id,
            'enrollment_id'   => $enrollment->id,
            'student_id'      => $student->id,
            'amount'          => 150000,
            'status'          => 'menunggu_konfirmasi_admin',
            'submitted_by'    => $parent->id,
            'submitted_at'    => Carbon::now(),
        ]);

        // First confirmation -> marks paid
        $resConfirm = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/cash-transactions/' . $cash->id . '/confirm', [
            'admin_notes'    => 'Uang tunai diterima kasir.',
            'receipt_number' => 'KW-001-OK',
        ]);
        $resConfirm->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertEquals('paid', $cash->fresh()->status);
        $this->assertEquals('paid', $payment->fresh()->payment_status);
        $this->assertTrue((bool)$plan->fresh()->is_paid);

        // Second confirmation (idempotent duplicate request) -> returns 200 without double mutating
        $resDuplicate = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/cash-transactions/' . $cash->id . '/confirm');
        $resDuplicate->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Pembayaran tunai ini sudah terkonfirmasi lunas.',
            ]);
    }
}
