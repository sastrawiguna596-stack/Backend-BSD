<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('program_code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('program_levels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('program_id')->constrained('programs')->onDelete('cascade');
            $table->string('level_code')->unique();
            $table->string('name');
            $table->integer('level_order')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('classrooms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('program_level_id')->constrained('program_levels')->onDelete('cascade');
            $table->string('class_code')->unique();
            $table->string('name');
            $table->integer('capacity')->default(20);
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('class_teachers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('classroom_id')->constrained('classrooms')->onDelete('cascade');
            $table->foreignUuid('teacher_id')->constrained('teachers')->onDelete('cascade');
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->string('role')->default('primary');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_teachers');
        Schema::dropIfExists('classrooms');
        Schema::dropIfExists('program_levels');
        Schema::dropIfExists('programs');
    }
};
