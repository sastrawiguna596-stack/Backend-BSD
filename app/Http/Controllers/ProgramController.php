<?php

namespace App\Http\Controllers;

use App\Models\Program;
use App\Models\ProgramLevel;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProgramController extends Controller
{
    /**
     * Daftar semua program beserta levels.
     */
    public function index(Request $request)
    {
        $query = Program::with('levels')
            ->when($request->has('is_active'), function ($q) use ($request) {
                $q->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
            })
            ->when($request->search, function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%');
            });

        $programs = $query->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data'    => $programs,
        ]);
    }

    /**
     * Buat program baru.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $program = Program::create([
            'program_code' => 'PRG-' . strtoupper(Str::random(5)),
            'name'         => $validated['name'],
            'description'  => $validated['description'] ?? null,
            'is_active'    => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Program berhasil dibuat.',
            'data'    => $program,
        ], 201);
    }

    /**
     * Detail program beserta levels dan classrooms.
     */
    public function show(string $id)
    {
        $program = Program::with('levels.classrooms')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => $program,
        ]);
    }

    /**
     * Update program.
     */
    public function update(Request $request, string $id)
    {
        $program = Program::findOrFail($id);

        $validated = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'is_active'   => 'sometimes|boolean',
        ]);

        $program->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Program berhasil diperbarui.',
            'data'    => $program->fresh('levels'),
        ]);
    }

    /**
     * Nonaktifkan program.
     */
    public function destroy(string $id)
    {
        $program = Program::findOrFail($id);
        $program->update(['is_active' => false]);

        return response()->json([
            'success' => true,
            'message' => 'Program berhasil dinonaktifkan.',
        ]);
    }

    // =========================================================
    // LEVEL (nested under program)
    // =========================================================

    /**
     * Tambah level ke program.
     * POST /v1/programs/{program}/levels
     */
    public function storeLevel(Request $request, string $programId)
    {
        $program = Program::findOrFail($programId);

        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'level_order' => 'sometimes|integer|min:1',
        ]);

        $level = ProgramLevel::create([
            'program_id'  => $program->id,
            'level_code'  => 'LVL-' . strtoupper(Str::random(5)),
            'name'        => $validated['name'],
            'level_order' => $validated['level_order'] ?? 1,
            'is_active'   => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Level berhasil ditambahkan ke program.',
            'data'    => $level,
        ], 201);
    }

    /**
     * Update level di program.
     * PUT /v1/programs/{program}/levels/{level}
     */
    public function updateLevel(Request $request, string $programId, string $levelId)
    {
        Program::findOrFail($programId);
        $level = ProgramLevel::where('program_id', $programId)->findOrFail($levelId);

        $validated = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'level_order' => 'sometimes|integer|min:1',
            'is_active'   => 'sometimes|boolean',
        ]);

        $level->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Level berhasil diperbarui.',
            'data'    => $level->fresh(),
        ]);
    }

    /**
     * Nonaktifkan level dari program.
     * DELETE /v1/programs/{program}/levels/{level}
     */
    public function destroyLevel(string $programId, string $levelId)
    {
        Program::findOrFail($programId);
        $level = ProgramLevel::where('program_id', $programId)->findOrFail($levelId);
        $level->update(['is_active' => false]);

        return response()->json([
            'success' => true,
            'message' => 'Level berhasil dinonaktifkan.',
        ]);
    }
}
