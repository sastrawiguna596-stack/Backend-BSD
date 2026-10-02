<?php

namespace App\Http\Controllers;

use App\Models\ClassSchedule;
use App\Models\ClassSession;
use App\Models\Holiday;
use App\Models\Teacher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ClassScheduleController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $isTeacher = $user && strtolower($user->role) === 'teacher';
        $teacherId = null;

        if ($isTeacher) {
            $teacherId = Teacher::where('user_id', $user->id)->value('id') ?: '00000000-0000-0000-0000-000000000000';
        }

        $query = ClassSchedule::with(['classroom.programLevel.program', 'teacher.user'])
            ->when($isTeacher, function ($q) use ($teacherId) {
                $q->where('teacher_id', $teacherId);
            })
            ->when($request->filled('teacher_id') && !$isTeacher, function ($q) use ($request) {
                $q->where('teacher_id', $request->teacher_id);
            })
            ->when($request->filled('classroom_id'), function ($q) use ($request) {
                $q->where('classroom_id', $request->classroom_id);
            })
            ->when($request->filled('day_of_week'), function ($q) use ($request) {
                $q->where('day_of_week', $request->day_of_week);
            })
            ->when($request->has('is_active'), function ($q) use ($request) {
                $q->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
            });

        $schedules = $query->get();
        return response()->json(['success' => true, 'data' => $schedules]);
    }

    public function store(Request $request)
    {
        // Normalisasi nama hari jika dikirim dalam bahasa Indonesia atau otomatis tentukan dari effective_from
        $indonesianDays = [
            'senin' => 'Monday',
            'selasa' => 'Tuesday',
            'rabu' => 'Wednesday',
            'kamis' => 'Thursday',
            'jumat' => 'Friday',
            'sabtu' => 'Saturday',
            'minggu' => 'Sunday',
        ];

        if ($request->filled('day_of_week')) {
            $normalizedDay = strtolower(trim($request->day_of_week));
            if (isset($indonesianDays[$normalizedDay])) {
                $request->merge(['day_of_week' => $indonesianDays[$normalizedDay]]);
            }
        }

        $validated = $request->validate([
            'classroom_id' => 'required|uuid|exists:classrooms,id',
            'teacher_id' => 'required|uuid|exists:teachers,id',
            'day_of_week' => 'nullable|string|in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'effective_from' => 'required|date',
            'effective_until' => 'nullable|date|after_or_equal:effective_from',
        ]);

        if (empty($validated['day_of_week']) && !empty($validated['effective_from'])) {
            try {
                $validated['day_of_week'] = Carbon::parse($validated['effective_from'])->englishDayOfWeek;
            } catch (\Exception $e) {
                // Biarkan validasi atau default
            }
        }

        // Fix date_format for conflict check by appending seconds if necessary or rely on DB conversion
        $startTime = Carbon::parse($validated['start_time'])->format('H:i:s');
        $endTime = Carbon::parse($validated['end_time'])->format('H:i:s');

        if (ClassSchedule::hasConflict(
            $validated['classroom_id'],
            $validated['teacher_id'],
            $validated['day_of_week'],
            $startTime,
            $endTime,
            $validated['effective_from'],
            $validated['effective_until'] ?? null
        )) {
            return response()->json([
                'success' => false,
                'message' => 'Terdapat bentrok jadwal untuk ruangan atau guru pada waktu yang dipilih.'
            ], 422);
        }

        $schedule = ClassSchedule::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Jadwal berhasil dibuat.',
            'data' => $schedule
        ], 201);
    }

    public function show(Request $request, string $id)
    {
        $user = $request->user();
        $isTeacher = $user && strtolower($user->role) === 'teacher';

        $schedule = ClassSchedule::with(['classroom.programLevel.program', 'teacher.user'])->findOrFail($id);

        if ($isTeacher) {
            $teacherId = Teacher::where('user_id', $user->id)->value('id');
            if ($schedule->teacher_id !== $teacherId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses ke data jadwal ini.'
                ], 403);
            }
        }

        return response()->json(['success' => true, 'data' => $schedule]);
    }

    public function update(Request $request, string $id)
    {
        $schedule = ClassSchedule::findOrFail($id);

        $indonesianDays = [
            'senin' => 'Monday',
            'selasa' => 'Tuesday',
            'rabu' => 'Wednesday',
            'kamis' => 'Thursday',
            'jumat' => 'Friday',
            'sabtu' => 'Saturday',
            'minggu' => 'Sunday',
        ];

        if ($request->filled('day_of_week')) {
            $normalizedDay = strtolower(trim($request->day_of_week));
            if (isset($indonesianDays[$normalizedDay])) {
                $request->merge(['day_of_week' => $indonesianDays[$normalizedDay]]);
            }
        }

        $validated = $request->validate([
            'classroom_id' => 'sometimes|uuid|exists:classrooms,id',
            'teacher_id' => 'sometimes|uuid|exists:teachers,id',
            'day_of_week' => 'sometimes|string|in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday',
            'start_time' => 'sometimes|date_format:H:i',
            'end_time' => 'sometimes|date_format:H:i|after:start_time',
            'effective_from' => 'sometimes|date',
            'effective_until' => 'nullable|date|after_or_equal:effective_from',
            'is_active' => 'sometimes|boolean'
        ]);

        $checkData = array_merge($schedule->toArray(), $validated);
        $startTime = Carbon::parse($checkData['start_time'])->format('H:i:s');
        $endTime = Carbon::parse($checkData['end_time'])->format('H:i:s');

        if (ClassSchedule::hasConflict(
            $checkData['classroom_id'],
            $checkData['teacher_id'],
            $checkData['day_of_week'],
            $startTime,
            $endTime,
            $checkData['effective_from'],
            $checkData['effective_until'] ?? null,
            $schedule->id
        )) {
            return response()->json([
                'success' => false,
                'message' => 'Terdapat bentrok jadwal untuk ruangan atau guru pada waktu yang dipilih.'
            ], 422);
        }

        $schedule->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Jadwal reguler berhasil diperbarui.',
            'data' => $schedule->fresh()
        ]);
    }

    public function destroy(string $id)
    {
        $schedule = ClassSchedule::findOrFail($id);
        $schedule->delete();
        return response()->json(['success' => true, 'message' => 'Jadwal berhasil dihapus.']);
    }

    /**
     * Generate sesi otomatis dari jadwal reguler, skip hari libur.
     */
    public function generateSessions(Request $request, string $id)
    {
        $schedule = ClassSchedule::findOrFail($id);

        $validated = $request->validate([
            'total_sessions' => 'required|integer|min:1',
            'start_date' => 'required|date'
        ]);

        $totalSessions = $validated['total_sessions'];
        $currentDate = Carbon::parse($validated['start_date']);
        
        $dayOfWeekMap = [
            'Sunday' => Carbon::SUNDAY,
            'Monday' => Carbon::MONDAY,
            'Tuesday' => Carbon::TUESDAY,
            'Wednesday' => Carbon::WEDNESDAY,
            'Thursday' => Carbon::THURSDAY,
            'Friday' => Carbon::FRIDAY,
            'Saturday' => Carbon::SATURDAY,
        ];
        $targetDay = $dayOfWeekMap[$schedule->day_of_week];
        
        if ($currentDate->dayOfWeek != $targetDay) {
            $currentDate->next($targetDay);
        }

        $sessionsCreated = [];
        $skippedHolidays = [];
        $conflicts = [];
        $sessionNumber = 1;

        $maxExistingSession = ClassSession::where('class_schedule_id', $schedule->id)
                                ->max('session_number');
        if ($maxExistingSession) {
            $sessionNumber = $maxExistingSession + 1;
        }

        DB::transaction(function () use (
            $totalSessions,
            $schedule,
            &$currentDate,
            &$sessionsCreated,
            &$skippedHolidays,
            &$conflicts,
            &$sessionNumber
        ) {
            while (count($sessionsCreated) < $totalSessions) {
                $dateString = $currentDate->format('Y-m-d');

                $isHoliday = Holiday::where('holiday_date', $dateString)
                                ->where('is_active', true)
                                ->exists();

                if ($isHoliday) {
                    $skippedHolidays[] = $dateString;
                    $currentDate->addWeek();
                    continue;
                }

                if (ClassSession::hasConflict(
                    $schedule->classroom_id,
                    $schedule->teacher_id,
                    $dateString,
                    $schedule->start_time,
                    $schedule->end_time
                )) {
                    $conflicts[] = $dateString;
                    $currentDate->addWeek();
                    continue;
                }

                $session = ClassSession::create([
                    'classroom_id' => $schedule->classroom_id,
                    'teacher_id' => $schedule->teacher_id,
                    'class_schedule_id' => $schedule->id,
                    'session_number' => $sessionNumber,
                    'session_date' => $dateString,
                    'start_time' => $schedule->start_time,
                    'end_time' => $schedule->end_time,
                    'status' => 'scheduled'
                ]);

                $sessionsCreated[] = $session;
                $sessionNumber++;
                $currentDate->addWeek();
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Berhasil generate ' . count($sessionsCreated) . ' sesi.',
            'data' => [
                'sessions_count' => count($sessionsCreated),
                'sessions' => $sessionsCreated,
                'skipped_holidays' => $skippedHolidays,
                'skipped_conflicts' => $conflicts
            ]
        ], 201);
    }
}
