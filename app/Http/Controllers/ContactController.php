<?php
// app/Http/Controllers/ContactController.php

namespace App\Http\Controllers;

use App\Models\ContactUs;           // ← correct model
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth; // ← ADD THIS
use App\Mail\ContactUsNotification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class ContactController extends Controller
{
    // public function index(): JsonResponse
    // {
    //     return response()->json(['success' => true]);
    // }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'    => 'required|string|max:100',
            'email'   => 'required|email|max:150',
            'subject' => 'required|string|max:200',
            'message' => 'required|string|max:5000',
        ]);

        $payload = [
            ...$validated,
            'user_id' => auth()->id(),
        ];

        ContactUs::create($payload);

        try {
            $adminEmail = config('mail.admin_address');
            Log::info('Attempting to send contact email to: ' . $adminEmail);

            Mail::to($adminEmail)->send(new ContactUsNotification($payload));

            Log::info('Contact email sent successfully.');
        } catch (\Exception $e) {
            Log::error('Contact email failed: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => "Your message has been sent. We'll respond within 24 hours.",
        ]);
    }

    /**
     * User: list own messages
     */
    public function index(): JsonResponse
    {
        $contacts = ContactUs::where('user_id', Auth::id())
            ->latest()
            ->get();
 
        return response()->json($contacts);
    }
 
    /**
     * Admin: list all messages with search, status filter, pagination
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $query = ContactUs::query()->latest();
 
        // Filter by status (unread / read)
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
 
        // Search across name, email, subject, message
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name',    'like', "%{$search}%")
                  ->orWhere('email',   'like', "%{$search}%")
                  ->orWhere('subject', 'like', "%{$search}%")
                  ->orWhere('message', 'like', "%{$search}%");
            });
        }
 
        $perPage  = min((int) $request->get('per_page', 15), 100); // cap at 100
        $contacts = $query->paginate($perPage);
 
        return response()->json($contacts);
    }
 
    /**
     * Admin: mark a single message as read
     */
    public function markRead(int $id): JsonResponse
    {
        $contact = ContactUs::findOrFail($id);
        $contact->update(['status' => 'read']);
 
        return response()->json([
            'success' => true,
            'message' => 'Marked as read.',
            'data'    => $contact,
        ]);
    }
 
    /**
     * Admin: delete a single message
     */
    public function destroy(int $id): JsonResponse
    {
        ContactUs::findOrFail($id)->delete();
 
        return response()->json([
            'success' => true,
            'message' => 'Deleted successfully.',
        ]);
    }
 
    /**
     * Admin: bulk delete multiple messages
     */
    public function bulkDestroy(Request $request): JsonResponse
    {
        $request->validate([
            'ids'   => 'required|array',
            'ids.*' => 'integer|exists:contact_us,id',
        ]);
 
        ContactUs::whereIn('id', $request->ids)->delete();
 
        return response()->json([
            'success' => true,
            'message' => count($request->ids) . ' message(s) deleted.',
        ]);
    }
 
    /**
     * Admin: bulk mark as read
     */
    public function bulkMarkRead(Request $request): JsonResponse
    {
        $request->validate([
            'ids'   => 'required|array',
            'ids.*' => 'integer|exists:contact_us,id',
        ]);
 
        ContactUs::whereIn('id', $request->ids)->update(['status' => 'read']);
 
        return response()->json([
            'success' => true,
            'message' => count($request->ids) . ' message(s) marked as read.',
        ]);
    }
}