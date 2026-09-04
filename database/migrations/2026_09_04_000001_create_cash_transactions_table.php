<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Membuat tabel cash_transactions sesuai spesifikasi Hari 6 CLAUDE.md:
     * - cash_code format CASH-2026-xxxx
     * - status: menunggu_konfirmasi_admin, paid, rejected, cancelled
     * - audit trail: submitted_by (ortu/user), verified_by (admin/owner)
     */
    public function up(): void
    {
        Schema::create('cash_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('cash_code')->unique(); // e.g: CASH-2026-0001
            $table->foreignUuid('payment_plan_id')->constrained('payment_plans')->onDelete('cascade');
            $table->foreignUuid('payment_id')->nullable()->constrained('payments')->onDelete('set null');
            $table->foreignUuid('enrollment_id')->constrained('enrollments')->onDelete('cascade');
            $table->foreignUuid('student_id')->constrained('students')->onDelete('cascade');
            $table->decimal('amount', 12, 2);
            $table->string('status')->default('menunggu_konfirmasi_admin'); // menunggu_konfirmasi_admin, paid, rejected, cancelled
            $table->foreignUuid('submitted_by')->constrained('users')->onDelete('cascade');
            $table->dateTime('submitted_at');
            $table->string('receipt_number')->nullable(); // No kuitansi fisik opsional
            $table->string('proof_image')->nullable(); // Foto bukti serah terima cash opsional
            $table->text('notes')->nullable(); // Catatan dari orang tua
            $table->foreignUuid('verified_by')->nullable()->constrained('users')->onDelete('set null');
            $table->dateTime('verified_at')->nullable();
            $table->text('rejection_reason')->nullable(); // Alasan jika admin menolak
            $table->text('admin_notes')->nullable(); // Catatan dari admin saat konfirmasi
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_transactions');
    }
};
