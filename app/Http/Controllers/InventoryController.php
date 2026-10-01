<?php

namespace App\Http\Controllers;

use App\Models\Inventory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class InventoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Inventory::query();

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->filled('condition')) {
            $query->where('condition', $request->condition);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('inventory_code', 'like', "%{$search}%")
                  ->orWhere('location', 'like', "%{$search}%")
                  ->orWhere('category', 'like', "%{$search}%");
            });
        }

        $items = $query->orderBy('created_at', 'desc')->paginate($request->input('per_page', 20));

        return response()->json([
            'success' => true,
            'data'    => $items,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'           => 'required|string|max:255',
            'category'       => 'nullable|string|max:100',
            'quantity'       => 'required|integer|min:0',
            'purchase_price' => 'nullable|numeric|min:0',
            'purchase_date'  => 'nullable|date',
            'condition'      => 'nullable|string|max:50',
            'location'       => 'nullable|string|max:100',
            'status'         => 'nullable|string|max:50',
        ]);

        $codeNum = Inventory::count() + 1;
        $validated['inventory_code'] = sprintf('INV-%03d', $codeNum);
        $validated['condition'] = $validated['condition'] ?? 'Baik';
        $validated['status'] = $validated['status'] ?? 'available';

        $item = Inventory::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Barang inventaris berhasil ditambahkan.',
            'data'    => $item,
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $item = Inventory::findOrFail($id);
        return response()->json([
            'success' => true,
            'data'    => $item,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $item = Inventory::findOrFail($id);

        $validated = $request->validate([
            'name'           => 'sometimes|required|string|max:255',
            'category'       => 'nullable|string|max:100',
            'quantity'       => 'sometimes|required|integer|min:0',
            'purchase_price' => 'nullable|numeric|min:0',
            'purchase_date'  => 'nullable|date',
            'condition'      => 'nullable|string|max:50',
            'location'       => 'nullable|string|max:100',
            'status'         => 'nullable|string|max:50',
        ]);

        $item->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Barang inventaris berhasil diperbarui.',
            'data'    => $item,
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $item = Inventory::findOrFail($id);
        $item->delete();

        return response()->json([
            'success' => true,
            'message' => 'Barang inventaris berhasil dihapus.',
        ]);
    }
}
