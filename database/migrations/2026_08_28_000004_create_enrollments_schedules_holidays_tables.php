<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('enrollment_code')->unique();
            $table->foreignUuid('student_id')->constrained('students')->onDelete('cascade');
            $table->foreignUuid('classroom_id')->constrained('classrooms')->onDelete('cascade');
            $table->foreignUuid('program_id')->constrained('programs')->onDelete('cascade');
            $table->foreignUuid('program_level_id')->constrained('program_levels')->onDelete('cascade');
            $table->date('start_date');
            $table->integer('total_sessions');
            $table->integer('sessions_per_week')->default(2);
            $table->string('payment_scheme');
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('class_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('classroom_id')->constrained('classrooms')->onDelete('cascade');
            $table->foreignUuid('teacher_id')->constrained('teachers')->onDelete('cascade');
            $table->string('day_of_week');
            $table->time('start_time');
            $table->time('end_time');
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('schedule_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('class_schedule_id')->constrained('class_schedules')->onDelete('cascade');
            $table->date('original_date');
            $table->date('new_date');
            $table->time('new_start_time');
            $table->time('new_end_time');
            $table->string('change_type');
            $table->text('reason')->nullable();
            $table->string('status')->default('pending');
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->date('holiday_date');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('class_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('classroom_id')->constrained('classrooms')->onDelete('cascade');
            $table->foreignUuid('teacher_id')->constrained('teachers')->onDelete('cascade');
            $table->foreignUuid('class_schedule_id')->nullable()->constrained('class_schedules')->onDelete('set null');
            $table->integer('session_number');
            $table->date('session_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->string('status')->default('scheduled');
            $table->boolean('is_rescheduled')->default(false);
            $table->boolean('is_holiday')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_sessions');
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('schedule_changes');
        Schema::dropIfExists('class_schedules');
        Schema::dropIfExists('enrollments');
    }
};
