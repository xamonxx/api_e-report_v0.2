<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BugReportRequest;
use App\Models\BugReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BugReportController extends Controller
{
    /**
     * POST /api/v1/bug-reports  (public, throttled)
     *
     * Accepts a bug description and up to 3 optional screenshots. Validation,
     * sanitisation and the honeypot live in BugReportRequest; file storage uses
     * Laravel's hashed filenames so the original (attacker-controlled) name is
     * never used on disk.
     */
    public function store(BugReportRequest $request): JsonResponse
    {
        $imagePaths = [];

        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $image) {
                if ($image->isValid()) {
                    // store() generates a random hashed filename → no path traversal,
                    // no overwriting, no executable extension reuse.
                    $imagePaths[] = $image->store('bug-reports', 'public');
                }
            }
        }

        $report = BugReport::create([
            'ticket_code'    => BugReport::generateTicketCode(),
            'description'    => $request->validated('description'),
            'page_url'       => $request->validated('page_url'),
            'reporter_email' => $request->validated('reporter_email'),
            'image_paths'    => $imagePaths ?: null,
            'status'         => 'open',
            'user_id'        => $request->user()?->id,
            'ip_address'     => $request->ip(),
            'user_agent'     => $request->userAgent() ? mb_substr($request->userAgent(), 0, 255) : null,
        ]);

        return response()->json([
            'message'     => 'Laporan bug berhasil dikirim. Terima kasih atas masukannya!',
            'ticket_code' => $report->ticket_code,
        ], 201);
    }

    /**
     * GET /api/v1/bug-reports  (super_admin only)
     */
    public function index(Request $request): JsonResponse
    {
        $reports = BugReport::query()
            ->with('user:id,name,email')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest()
            ->paginate(20)
            ->through(fn (BugReport $r) => [
                'id'             => $r->id,
                'ticket_code'    => $r->ticket_code,
                'description'    => $r->description,
                'page_url'       => $r->page_url,
                'reporter_email' => $r->reporter_email,
                'images'         => $r->imageUrls(),
                'status'         => $r->status,
                'reporter'       => $r->user ? ['id' => $r->user->id, 'name' => $r->user->name] : null,
                'created_at'     => $r->created_at?->toIso8601String(),
            ]);

        return response()->json($reports);
    }

    /**
     * GET /api/v1/bug-reports/{bugReport}  (super_admin only)
     */
    public function show(BugReport $bugReport): JsonResponse
    {
        $bugReport->load('user:id,name,email');

        return response()->json([
            'data' => [
                'id'             => $bugReport->id,
                'ticket_code'    => $bugReport->ticket_code,
                'description'    => $bugReport->description,
                'page_url'       => $bugReport->page_url,
                'reporter_email' => $bugReport->reporter_email,
                'images'         => $bugReport->imageUrls(),
                'status'         => $bugReport->status,
                'ip_address'     => $bugReport->ip_address,
                'user_agent'     => $bugReport->user_agent,
                'reporter'       => $bugReport->user ? ['id' => $bugReport->user->id, 'name' => $bugReport->user->name] : null,
                'created_at'     => $bugReport->created_at?->toIso8601String(),
            ],
        ]);
    }
}
