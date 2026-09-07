<?php

namespace App\Http\Controllers;

use App\Models\ClassSession;
use Illuminate\Http\Request;
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
}
