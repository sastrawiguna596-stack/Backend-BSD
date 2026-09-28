<?php

namespace Tests\Feature;

use App\Models\ParentModel;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParentAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_can_access_their_profile_and_children(): void
    {
        $user = User::factory()->create(['role' => 'parent']);
        $parent = ParentModel::create([
            'user_id' => $user->id,
            'parent_code' => 'PAR-TEST-001',
            'relationship' => 'Ibu',
        ]);
        $student = Student::create([
            'student_code' => 'STU-TEST-001',
            'full_name' => 'Anak Test',
            'status' => 'active',
        ]);
        $parent->students()->attach($student->id, [
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'relationship' => 'Anak Kandung',
            'is_primary' => true,
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/parents/my-profile');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $parent->id,
                    'parent_code' => 'PAR-TEST-001',
                ],
            ]);

        $this->assertCount(1, $response->json('data.students'));
    }

    public function test_parent_can_read_students_and_payment_plans(): void
    {
        $user = User::factory()->create(['role' => 'parent']);
        $parent = ParentModel::create([
            'user_id' => $user->id,
            'parent_code' => 'PAR-TEST-002',
            'relationship' => 'Ayah',
        ]);
        $student = Student::create([
            'student_code' => 'STU-TEST-002',
            'full_name' => 'Anak Test 2',
            'status' => 'active',
        ]);
        $parent->students()->attach($student->id, [
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'relationship' => 'Anak Kandung',
            'is_primary' => true,
        ]);

        $responseStudents = $this->actingAs($user)->getJson('/api/v1/students');
        $responseStudents->assertStatus(200);

        $responsePaymentPlans = $this->actingAs($user)->getJson('/api/v1/payment-plans');
        $responsePaymentPlans->assertStatus(200);
    }
}
