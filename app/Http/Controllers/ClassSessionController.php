<?php

namespace App\Http\Controllers;

use App\Models\ClassSession;
use App\Models\StudentAttendance;
use App\Models\TeacherLogbook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ClassSessionController extends Controller
{
    public function index()
    {
        $sessions = ClassSession::with(['classroom', 'teacher', 'classSchedule'])->get();
        return response()->json(['success' => true, 'data' => $sessions]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'classroom_id' => 'required|uuid|exists:classrooms,id',
            'teacher_id' => 'required|uuid|exists:teachers,id',
            'class_schedule_id' => 'nullable|uuid|exists:class_schedules,id',
            'session_number' => 'required|integer|min:1',
            'session_date' => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'status' => 'sometimes|string',
            'is_rescheduled' => 'sometimes|boolean',
            'is_holiday' => 'sometimes|boolean'
        ]);

        $startTime = Carbon::parse($validated['start_time'])->format('H:i:s');
        $endTime = Carbon::parse($validated['end_time'])->format('H:i:s');

        if (ClassSession::hasConflict(
            $validated['classroom_id'],
            $validated['teacher_id'],
            $validated['session_date'],
            $startTime,
            $endTime
        )) {
            return response()->json([
                'success' => false,
                'message' => 'Terdapat bentrok sesi untuk ruangan atau guru pada waktu yang dipilih.'
            ], 422);
        }

        $session = ClassSession::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Sesi berhasil dibuat.',
            'data' => $session
        ], 201);
    }

    public function show(string $id)
    {
        $session = ClassSession::with(['classroom', 'teacher', 'classSchedule'])->findOrFail($id);
        return response()->json(['success' => true, 'data' => $session]);
    }

    public function update(Request $request, string $id)
    {
        $session = ClassSession::findOrFail($id);

        $validated = $request->validate([
            'status' => 'sometimes|string',
            'is_holiday' => 'sometimes|boolean'
        ]);

        $session->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Sesi berhasil diperbarui.',
            'data' => $session->fresh()
        ]);
    }

    public function destroy(string $id)
    {
        $session = ClassSession::findOrFail($id);
        $session->delete();
        return response()->json(['success' => true, 'message' => 'Sesi berhasil dihapus.']);
    }

    /**
     * Reschedule 1 sesi spesifik ke tanggal dan jam baru.
     */
    public function reschedule(Request $request, string $id)
    {
        $session = ClassSession::findOrFail($id);

        $validated = $request->validate([
            'session_date' => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time'
        ]);

        $startTime = Carbon::parse($validated['start_time'])->format('H:i:s');
        $endTime = Carbon::parse($validated['end_time'])->format('H:i:s');

        if (ClassSession::hasConflict(
            $session->classroom_id,
            $session->teacher_id,
            $validated['session_date'],
            $startTime,
            $endTime,
            $session->id
        )) {
            return response()->json([
                'success' => false,
                'message' => 'Terdapat bentrok sesi untuk ruangan atau guru pada waktu reschedule.'
            ], 422);
        }

        $session->update([
            'session_date' => $validated['session_date'],
            'start_time' => $startTime,
            'end_time' => $endTime,
            'is_rescheduled' => true
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Sesi berhasil direschedule.',
            'data' => $session->fresh()
        ]);
    }

    /**
     * Submit attendance for students and logbook for teacher transactionally.
     */
    public function submitAttendanceAndLogbook(Request $request, string $id)
    {
        $session = ClassSession::findOrFail($id);

        $validated = $request->validate([
            'students' => 'required|array',
            'students.*.student_id' => 'required|uuid|exists:students,id',
            'students.*.attendance_status' => 'required|string|in:present,absent,late,excused',
            'students.*.notes' => 'nullable|string',
            'logbook' => 'required|array',
            'logbook.check_in' => 'required|date',
            'logbook.check_out' => 'required|date|after_or_equal:logbook.check_in',
            'logbook.teaching_minutes' => 'required|integer|min:1',
            'logbook.material' => 'required|string',
            'logbook.notes' => 'nullable|string'
        ]);

        try {
            DB::beginTransaction();

            $attendances = [];
            foreach ($validated['students'] as $studentData) {
                // Remove existing if any (optional, depends on logic if they can resubmit)
                StudentAttendance::where('class_session_id', $session->id)
                    ->where('student_id', $studentData['student_id'])
                    ->delete();

                $attendances[] = StudentAttendance::create([
                    'class_session_id' => $session->id,
                    'student_id' => $studentData['student_id'],
                    'attendance_status' => $studentData['attendance_status'],
                    'notes' => $studentData['notes'] ?? null,
                    'recorded_at' => now(),
                    'recorded_by' => $request->user() ? $request->user()->id : null
                ]);
            }

            // Remove existing logbook if any
            TeacherLogbook::where('class_session_id', $session->id)
                ->where('teacher_id', $session->teacher_id)
                ->delete();

            $logbook = TeacherLogbook::create([
                'class_session_id' => $session->id,
                'teacher_id' => $session->teacher_id,
                'check_in' => $validated['logbook']['check_in'],
                'check_out' => $validated['logbook']['check_out'],
                'teaching_minutes' => $validated['logbook']['teaching_minutes'],
                'material' => $validated['logbook']['material'],
                'notes' => $validated['logbook']['notes'] ?? null,
                'status' => 'submitted',
                'submitted_at' => now()
            ]);

            $session->update(['status' => 'completed']);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Absensi dan logbook berhasil disubmit.',
                'data' => [
                    'session' => $session->fresh(),
                    'attendances' => $attendances,
                    'logbook' => $logbook
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal submit absensi dan logbook.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
