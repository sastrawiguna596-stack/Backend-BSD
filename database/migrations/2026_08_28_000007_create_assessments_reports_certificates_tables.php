<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('student_id')->constrained('students')->onDelete('cascade');
            $table->foreignUuid('enrollment_id')->constrained('enrollments')->onDelete('cascade');
            $table->foreignUuid('program_level_id')->constrained('program_levels')->onDelete('cascade');
            $table->string('assessment_name');
            $table->decimal('score', 5, 2);
            $table->decimal('weight', 5, 2)->default(1.0);
            $table->date('assessment_date');
            $table->foreignUuid('recorded_by')->nullable()->constrained('users')->onDelete('set null');
            $table->string('status')->default('final');
            $table->timestamps();
        });

        Schema::create('final_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('report_code')->unique();
            $table->foreignUuid('student_id')->constrained('students')->onDelete('cascade');
            $table->foreignUuid('enrollment_id')->constrained('enrollments')->onDelete('cascade');
            $table->decimal('final_score', 5, 2);
            $table->string('graduation_status');
            $table->date('generated_at');
            $table->foreignUuid('verified_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
        });

        Schema::create('certificate_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('program_id')->constrained('programs')->onDelete('cascade');
            $table->foreignUuid('program_level_id')->nullable()->constrained('program_levels')->onDelete('set null');
            $table->string('template_name');
            $table->string('template_file');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('certificates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('certificate_code')->unique();
            $table->foreignUuid('student_id')->constrained('students')->onDelete('cascade');
            $table->foreignUuid('enrollment_id')->constrained('enrollments')->onDelete('cascade');
            $table->foreignUuid('final_report_id')->constrained('final_reports')->onDelete('cascade');
            $table->foreignUuid('certificate_template_id')->constrained('certificate_templates')->onDelete('cascade');
            $table->date('issued_date');
            $table->string('file_url')->nullable();
            $table->foreignUuid('generated_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
        Schema::dropIfExists('certificate_templates');
        Schema::dropIfExists('final_reports');
        Schema::dropIfExists('assessments');
    }
};
