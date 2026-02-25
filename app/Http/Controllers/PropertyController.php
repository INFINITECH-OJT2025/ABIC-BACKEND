<?php

namespace App\Http\Controllers;

use App\Models\Property;
use Illuminate\Http\Request;
use \Exception;

class PropertyController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        try {
            // Start a Query Builder
            $query = Property::query();

            // Filter by status if provided, otherwise default to ACTIVE
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            } else {
                $query->where('status', 'ACTIVE');
            }

            // Filter by property_type if provided
            if ($request->filled('property_type') && $request->property_type !== 'ALL') {
                $query->where('property_type', $request->property_type);
            }

            // APPLIES SEARCH FILTERING
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('property_type', 'like', "%{$search}%")
                        ->orWhere('address', 'like', "%{$search}%");
                });
            }

            // Sorting
            $sortBy = $request->input('sort_by', 'date'); // 'date' or 'name'
            $sortOrder = $request->input('sort_order', 'desc'); // 'asc' or 'desc'
            
            if ($sortBy === 'date') {
                // Sort by created_at (date created) - desc by default
                $query->orderBy('created_at', $sortOrder === 'asc' ? 'ASC' : 'DESC');
            } elseif ($sortBy === 'name') {
                // Sort alphabetically by name - asc by default
                $query->orderBy('name', $sortOrder === 'asc' ? 'ASC' : 'DESC');
            } else {
                // Default: newest first
                $query->orderBy('created_at', 'DESC');
            }

            // Orders & Paginates Results
            $perPage = $request->input('per_page', 10);
            // If per_page is 'all' or a very large number, get all results without pagination
            if ($perPage === 'all' || (is_numeric($perPage) && $perPage > 1000)) {
                $properties = $query->get();
                return response()->json([
                    'success' => true,
                    'message' => 'Properties retrieved successfully',
                    'data' => $properties
                ]);
            }
            $properties = $query->paginate((int)$perPage);

            return response()->json([
                'success' => true,
                'message' => 'Properties retrieved successfully',
                'data' => $properties
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Something went wrong',
                'data' => null
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function createProperty(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => ['required', 'string', 'min:2', 'unique:properties,name'],
                'property_type' => ['required', 'string', 'in:CONDOMINIUM,HOUSE,LOT,COMMERCIAL'],
                'address' => ['nullable', 'string'],
                'status' => ['required', 'string', 'in:ACTIVE,INACTIVE'],
            ]);

            $property = Property::create($validated);

            return response()->json([
                'success' => true,
                'message' => 'Property created successfully',
                'data' => $property
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        try {
            $property = Property::find($id);
            if (!$property) {
                return response()->json([
                    'success' => false,
                    'message' => 'Property not found',
                    'data' => null
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Property retrieved successfully',
                'data' => $property
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Something went wrong',
                'data' => null,
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        try {
            // Find the Property id first
            $property = Property::find($id);

            // If Property doesn't exist display error
            if (!$property) {
                return response()->json([
                    'success' => false,
                    'message' => 'Property not found',
                    'data' => null,
                ], 404);
            }

            // Validate data
            $validated = $request->validate([
                'name' => ['required', 'string', 'min:2', 'unique:properties,name,' . $property->id],
                'property_type' => ['required', 'string', 'in:CONDOMINIUM,HOUSE,LOT,COMMERCIAL'],
                'address' => ['nullable', 'string'],
                'status' => ['required', 'string', 'in:ACTIVE,INACTIVE'],
            ]);

            // Update data
            $property->update($validated);

            // Return success response
            return response()->json([
                'success' => true,
                'message' => 'Property Details Successfully updated',
                'data' => $property
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ], 500);
        }
    }
}
