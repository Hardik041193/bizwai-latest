<?php
// app/Http/Controllers/ContactController.php

namespace App\Http\Controllers;

use App\Models\ContactUs;           // ← correct model
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ContactController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['success' => true]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'    => 'required|string|max:100',
            'email'   => 'required|email|max:150',
            'subject' => 'required|string|max:200',
            'message' => 'required|string|max:5000',
        ]);

        ContactUs::create([           // ← was ContactMessage (undefined), now fixed
            ...$validated,
            'user_id' => auth()->id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => "Your message has been sent. We'll respond within 24 hours.",
        ]);
    }
}