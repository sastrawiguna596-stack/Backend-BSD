<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('enrollment_id')->constrained('enrollments')->onDelete('cascade');
            $table->integer('session_number');
            $table->decimal('amount', 12, 2);
            $table->date('due_date');
            $table->string('status')->default('pending');
            $table->boolean('is_paid')->default(false);
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('payment_code')->unique();
            $table->foreignUuid('payment_plan_id')->nullable()->constrained('payment_plans')->onDelete('set null');
            $table->foreignUuid('enrollment_id')->constrained('enrollments')->onDelete('cascade');
            $table->foreignUuid('student_id')->constrained('students')->onDelete('cascade');
            $table->decimal('amount', 12, 2);
            $table->string('payment_method');
            $table->string('payment_status')->default('pending');
            $table->dateTime('paid_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignUuid('verified_by')->nullable()->constrained('users')->onDelete('set null');
            $table->string('reference_number')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('cash_payment_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_id')->constrained('payments')->onDelete('cascade');
            $table->foreignUuid('submitted_by')->constrained('users')->onDelete('cascade');
            $table->dateTime('submitted_at');
            $table->string('proof')->nullable();
            $table->string('status')->default('pending');
            $table->foreignUuid('verified_by')->nullable()->constrained('users')->onDelete('set null');
            $table->dateTime('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('teacher_hourly_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('teacher_id')->constrained('teachers')->onDelete('cascade');
            $table->decimal('hourly_rate', 12, 2);
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
        });

        Schema::create('teacher_bonuses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('teacher_id')->constrained('teachers')->onDelete('cascade');
            $table->string('bonus_name');
            $table->decimal('amount', 12, 2);
            $table->date('bonus_date');
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
        });

        Schema::create('teacher_payrolls', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('payroll_code')->unique();
            $table->foreignUuid('teacher_id')->constrained('teachers')->onDelete('cascade');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('teaching_hours', 8, 2);
            $table->decimal('base_amount', 12, 2);
            $table->decimal('bonus_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2);
            $table->string('status')->default('draft');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_payrolls');
        Schema::dropIfExists('teacher_bonuses');
        Schema::dropIfExists('teacher_hourly_rates');
        Schema::dropIfExists('cash_payment_submissions');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('payment_plans');
    }
};
