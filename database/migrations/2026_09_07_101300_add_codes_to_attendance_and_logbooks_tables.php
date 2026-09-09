<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('student_attendances', function (Blueprint $table) {
            $table->string('attendance_code')->unique()->nullable()->after('student_id');
        });

        Schema::table('teacher_logbooks', function (Blueprint $table) {
            $table->string('log_code')->unique()->nullable()->after('teacher_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('student_attendances', function (Blueprint $table) {
            $table->dropColumn('attendance_code');
        });

        Schema::table('teacher_logbooks', function (Blueprint $table) {
            $table->dropColumn('log_code');
        });
    }
};
