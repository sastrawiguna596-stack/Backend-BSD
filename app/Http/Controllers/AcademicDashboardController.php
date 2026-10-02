<?php

namespace App\Http\Controllers;

use App\Models\Classroom;
use App\Models\ClassSchedule;
use App\Models\ClassSession;
use App\Models\Program;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\Teacher;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AcademicDashboardController extends Controller
{
    /**
     * GET /v1/academic/dashboard
     * Menghasilkan agregasi metrik akademik terpadu (Hari 7)
     * Untuk dashboard Admin dan Owner tanpa multiple waterfall HTTP request.
     */
    public function dashboard(Request $request)
    {
        $today = Carbon::today()->toDateString();
        $englishDay = Carbon::today()->englishDayOfWeek;

        // 1. Siswa
        $totalStudents = Student::count();
        $activeStudents = Student::where('status', 'active')->count();

        // 2. Guru
        $totalTeachers = Teacher::count();
        $activeTeachers = Teacher::where('is_active', true)->count();

        // 3. Kelas & Program
        $totalClassrooms = Classroom::count();
        $activeClassrooms = Classroom::where('status', 'active')->count();
        $totalPrograms = Program::count();

        // 4. Sesi & Jadwal Hari Ini
        $todaySessionsCount = ClassSession::whereDate('session_date', $today)->count();
        $todaySchedulesCount = ClassSchedule::where('day_of_week', $englishDay)
            ->where('is_active', true)
            ->where('effective_from', '<=', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('effective_until')
                  ->orWhere('effective_until', '>=', $today);
            })
            ->count();

        // 5. Rekap Absensi Hari Ini (Aggregated directly in database)
        $attendanceCounts = StudentAttendance::whereHas('classSession', function ($q) use ($today) {
                $q->whereDate('session_date', $today);
            })
            ->selectRaw('attendance_status, count(*) as count')
            ->groupBy('attendance_status')
            ->pluck('count', 'attendance_status');

        $presentCount = (int) ($attendanceCounts['present'] ?? 0);
        $lateCount    = (int) ($attendanceCounts['late'] ?? 0);
        $excusedCount = (int) ($attendanceCounts['excused'] ?? 0);
        $absentCount  = (int) ($attendanceCounts['absent'] ?? 0);
        $totalAttendanceRecorded = (int) $attendanceCounts->sum();

        // 6. Daftar Sesi Hari Ini (dengan detail kelas, guru, jam)
        $todaySessions = ClassSession::with(['classroom.programLevel.program', 'teacher.user'])
            ->whereDate('session_date', $today)
            ->orderBy('start_time', 'asc')
            ->take(10)
            ->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'summary' => [
                    'total_students'    => $totalStudents,
                    'active_students'   => $activeStudents,
                    'total_teachers'    => $totalTeachers,
                    'active_teachers'   => $activeTeachers,
                    'total_classrooms'  => $totalClassrooms,
                    'active_classrooms' => $activeClassrooms,
                    'total_programs'    => $totalPrograms,
                    'today_sessions'    => $todaySessionsCount,
                    'today_schedules'   => $todaySchedulesCount,
                ],
                'attendance_today' => [
                    'present'        => $presentCount,
                    'late'           => $lateCount,
                    'excused'        => $excusedCount,
                    'absent'         => $absentCount,
                    'total_recorded' => $totalAttendanceRecorded,
                ],
                'today_sessions' => $todaySessions,
            ],
        ]);
    }
}
