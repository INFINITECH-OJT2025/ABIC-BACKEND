<?php

namespace App\Http\Controllers;

use App\Models\BankContact;
use App\Models\BankContactChannel;
use App\Models\Bank;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BankContactController extends Controller
{
    /**
     * List all bank contacts (optionally filtered by bank_id)
     */
    public function index(Request $request)
    {
        try {
            $query = BankContact::with(['bank', 'channels']);

            // Filter by bank_id if provided
            if ($request->has('bank_id')) {
                $query->where('bank_id', $request->bank_id);
            }

            $contacts = $query->orderBy('branch_name')->get();

            return response()->json([
                'success' => true,
                'message' => 'Bank contacts retrieved successfully',
                'data' => $contacts
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve bank contacts: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve bank contacts due to server error',
                'errors' => null
            ], 500);
        }
    }

    /**
     * Show a specific bank contact
     */
    public function show($id)
    {
        try {
            $contact = BankContact::with(['bank', 'channels'])->find($id);

            if (!$contact) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bank contact not found',
                    'errors' => null
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Bank contact retrieved successfully',
                'data' => $contact
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve bank contact: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve bank contact due to server error',
                'errors' => null
            ], 500);
        }
    }

    /**
     * Create a new bank contact
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'bank_id' => 'required|integer|exists:banks,id',
                'branch_name' => 'required|string|max:150',
                'contact_person' => 'nullable|string|max:255',
                'position' => 'nullable|string|max:150',
                'notes' => 'nullable|string',
                'channels' => 'nullable|array',
                'channels.*.channel_type' => 'required|string|in:PHONE,MOBILE,EMAIL,VIBER',
                'channels.*.value' => 'required|string|max:255',
                'channels.*.label' => 'nullable|string|max:100',
            ], [
                'bank_id.required' => 'Bank ID is required',
                'bank_id.exists' => 'Selected bank does not exist',
                'branch_name.required' => 'Branch name is required',
                'branch_name.max' => 'Branch name must not exceed 150 characters',
                'contact_person.max' => 'Contact person name must not exceed 255 characters',
                'position.max' => 'Position must not exceed 150 characters',
                'channels.*.channel_type.required' => 'Channel type is required',
                'channels.*.channel_type.in' => 'Channel type must be PHONE, MOBILE, EMAIL, or VIBER',
                'channels.*.value.required' => 'Channel value is required',
                'channels.*.value.max' => 'Channel value must not exceed 255 characters',
                'channels.*.label.max' => 'Channel label must not exceed 100 characters',
            ]);

            DB::beginTransaction();

            // Create the contact
            $contact = BankContact::create([
                'bank_id' => $validated['bank_id'],
                'branch_name' => $validated['branch_name'],
                'contact_person' => $validated['contact_person'] ?? null,
                'position' => $validated['position'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

            // Create channels if provided
            if (isset($validated['channels']) && is_array($validated['channels'])) {
                foreach ($validated['channels'] as $channelData) {
                    BankContactChannel::create([
                        'contact_id' => $contact->id,
                        'channel_type' => $channelData['channel_type'],
                        'value' => $channelData['value'],
                        'label' => $channelData['label'] ?? null,
                    ]);
                }
            }

            DB::commit();

            // Load relationships
            $contact->load(['bank', 'channels']);

            // Log the activity
            Log::info('Bank contact created', [
                'contact_id' => $contact->id,
                'bank_id' => $contact->bank_id,
                'branch_name' => $contact->branch_name,
                'created_by' => auth()->user()->id,
                'created_at' => now()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Bank contact created successfully',
                'data' => $contact
            ], 201);

        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create bank contact: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create bank contact due to server error',
                'errors' => null
            ], 500);
        }
    }

    /**
     * Update a bank contact
     */
    public function update(Request $request, $id)
    {
        try {
            $contact = BankContact::find($id);

            if (!$contact) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bank contact not found',
                    'errors' => null
                ], 404);
            }

            $validated = $request->validate([
                'bank_id' => 'sometimes|required|integer|exists:banks,id',
                'branch_name' => 'required|string|max:150',
                'contact_person' => 'nullable|string|max:255',
                'position' => 'nullable|string|max:150',
                'notes' => 'nullable|string',
                'channels' => 'nullable|array',
                'channels.*.id' => 'nullable|integer|exists:bank_contact_channels,id',
                'channels.*.channel_type' => 'required|string|in:PHONE,MOBILE,EMAIL,VIBER',
                'channels.*.value' => 'required|string|max:255',
                'channels.*.label' => 'nullable|string|max:100',
            ], [
                'bank_id.exists' => 'Selected bank does not exist',
                'branch_name.required' => 'Branch name is required',
                'branch_name.max' => 'Branch name must not exceed 150 characters',
                'contact_person.max' => 'Contact person name must not exceed 255 characters',
                'position.max' => 'Position must not exceed 150 characters',
                'channels.*.channel_type.required' => 'Channel type is required',
                'channels.*.channel_type.in' => 'Channel type must be PHONE, MOBILE, EMAIL, or VIBER',
                'channels.*.value.required' => 'Channel value is required',
                'channels.*.value.max' => 'Channel value must not exceed 255 characters',
                'channels.*.label.max' => 'Channel label must not exceed 100 characters',
            ]);

            DB::beginTransaction();

            // Update the contact
            $contact->update([
                'bank_id' => $validated['bank_id'] ?? $contact->bank_id,
                'branch_name' => $validated['branch_name'],
                'contact_person' => $validated['contact_person'] ?? null,
                'position' => $validated['position'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

            // Handle channels
            if (isset($validated['channels'])) {
                // Get existing channel IDs
                $existingChannelIds = $contact->channels->pluck('id')->toArray();
                $providedChannelIds = array_filter(array_column($validated['channels'], 'id'));

                // Delete channels that are not in the provided list
                $channelsToDelete = array_diff($existingChannelIds, $providedChannelIds);
                if (!empty($channelsToDelete)) {
                    BankContactChannel::whereIn('id', $channelsToDelete)->delete();
                }

                // Update or create channels
                foreach ($validated['channels'] as $channelData) {
                    if (isset($channelData['id']) && $channelData['id']) {
                        // Update existing channel
                        $channel = BankContactChannel::find($channelData['id']);
                        if ($channel && $channel->contact_id === $contact->id) {
                            $channel->update([
                                'channel_type' => $channelData['channel_type'],
                                'value' => $channelData['value'],
                                'label' => $channelData['label'] ?? null,
                            ]);
                        }
                    } else {
                        // Create new channel
                        BankContactChannel::create([
                            'contact_id' => $contact->id,
                            'channel_type' => $channelData['channel_type'],
                            'value' => $channelData['value'],
                            'label' => $channelData['label'] ?? null,
                        ]);
                    }
                }
            }

            DB::commit();

            // Load relationships
            $contact->load(['bank', 'channels']);

            // Log the activity
            Log::info('Bank contact updated', [
                'contact_id' => $contact->id,
                'bank_id' => $contact->bank_id,
                'branch_name' => $contact->branch_name,
                'updated_by' => auth()->user()->id,
                'updated_at' => now()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Bank contact updated successfully',
                'data' => $contact->fresh(['bank', 'channels'])
            ]);

        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update bank contact: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update bank contact due to server error',
                'errors' => null
            ], 500);
        }
    }

    /**
     * Delete a bank contact
     */
    public function destroy($id)
    {
        try {
            $contact = BankContact::find($id);

            if (!$contact) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bank contact not found',
                    'errors' => null
                ], 404);
            }

            // Log the deletion
            Log::info('Bank contact deleted', [
                'contact_id' => $contact->id,
                'bank_id' => $contact->bank_id,
                'branch_name' => $contact->branch_name,
                'deleted_by' => auth()->user()->id,
                'deleted_at' => now()
            ]);

            // Channels will be deleted automatically due to cascade
            $contact->delete();

            return response()->json([
                'success' => true,
                'message' => 'Bank contact deleted successfully',
                'data' => null
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to delete bank contact: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete bank contact due to server error',
                'errors' => null
            ], 500);
        }
    }
}
