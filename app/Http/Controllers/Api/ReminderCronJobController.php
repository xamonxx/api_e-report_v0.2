<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ReminderCronJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class ReminderCronJobController extends Controller
{
    public function index(): JsonResponse
    {
        $this->ensureSuperAdmin();

        return response()->json([
            'data' => ReminderCronJob::query()
                ->orderBy('time_of_day')
                ->orderBy('id')
                ->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureSuperAdmin();
        $validated = $this->validatePayload($request);

        try {
            $job = DB::transaction(function () use ($validated) {
                $job = ReminderCronJob::query()->create([
                    'name' => trim($validated['name']),
                    'type' => ReminderCronJob::TYPE_ATTENDANCE_REMINDER,
                    'time_of_day' => $validated['time_of_day'],
                    'message' => $this->normalizeMessage($validated['message'] ?? null),
                    'is_active' => $validated['is_active'] ?? true,
                ]);

                AuditLog::logCreated($job, "Membuat cron job pengingat: {$job->name}.");

                return $job;
            });

            return response()->json([
                'message' => 'Cron job pengingat berhasil dibuat.',
                'data' => $job,
            ], 201);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Cron job pengingat gagal dibuat. Silakan coba lagi.',
            ], 500);
        }
    }

    public function update(Request $request, ReminderCronJob $reminderCronJob): JsonResponse
    {
        $this->ensureSuperAdmin();
        $validated = $this->validatePayload($request);

        try {
            DB::transaction(function () use ($reminderCronJob, $validated) {
                $oldValues = $reminderCronJob->only([
                    'name',
                    'type',
                    'time_of_day',
                    'message',
                    'is_active',
                    'last_sent_date',
                ]);

                $reminderCronJob->update([
                    'name' => trim($validated['name']),
                    'time_of_day' => $validated['time_of_day'],
                    'message' => $this->normalizeMessage($validated['message'] ?? null),
                    'is_active' => $validated['is_active'] ?? $reminderCronJob->is_active,
                ]);

                AuditLog::logUpdated(
                    $reminderCronJob->fresh(),
                    $oldValues,
                    "Memperbarui cron job pengingat: {$reminderCronJob->name}."
                );
            });

            return response()->json([
                'message' => 'Cron job pengingat berhasil diperbarui.',
                'data' => $reminderCronJob->fresh(),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Cron job pengingat gagal diperbarui. Silakan coba lagi.',
            ], 500);
        }
    }

    public function toggle(ReminderCronJob $reminderCronJob): JsonResponse
    {
        $this->ensureSuperAdmin();

        try {
            DB::transaction(function () use ($reminderCronJob) {
                $oldValues = ['is_active' => $reminderCronJob->is_active];
                $reminderCronJob->update(['is_active' => ! $reminderCronJob->is_active]);

                $action = $reminderCronJob->is_active ? 'Mengaktifkan' : 'Menonaktifkan';
                AuditLog::logUpdated(
                    $reminderCronJob->fresh(),
                    $oldValues,
                    "{$action} cron job pengingat: {$reminderCronJob->name}."
                );
            });

            return response()->json([
                'message' => $reminderCronJob->is_active
                    ? 'Cron job pengingat berhasil diaktifkan.'
                    : 'Cron job pengingat berhasil dinonaktifkan.',
                'data' => $reminderCronJob->fresh(),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Status cron job pengingat gagal diubah. Silakan coba lagi.',
            ], 500);
        }
    }

    public function destroy(ReminderCronJob $reminderCronJob): JsonResponse
    {
        $this->ensureSuperAdmin();

        try {
            DB::transaction(function () use ($reminderCronJob) {
                AuditLog::logDeleted(
                    $reminderCronJob,
                    "Menghapus cron job pengingat: {$reminderCronJob->name}."
                );
                $reminderCronJob->delete();
            });

            return response()->json([
                'message' => 'Cron job pengingat berhasil dihapus.',
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Cron job pengingat gagal dihapus. Silakan coba lagi.',
            ], 500);
        }
    }

    /** @return array{name:string, time_of_day:string, message?:string|null, is_active?:bool} */
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'time_of_day' => ['required', 'date_format:H:i'],
            'message' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'name.required' => 'Nama cron job wajib diisi.',
            'name.max' => 'Nama cron job maksimal 160 karakter.',
            'time_of_day.required' => 'Jam pengiriman wajib dipilih.',
            'time_of_day.date_format' => 'Format jam pengiriman harus HH:mm.',
            'message.max' => 'Pesan maksimal 2000 karakter.',
        ]);
    }

    private function normalizeMessage(?string $message): ?string
    {
        $message = is_string($message) ? trim($message) : null;

        return $message === '' ? null : $message;
    }

    private function ensureSuperAdmin(): void
    {
        abort_if(! auth()->user()?->isSuperAdmin(), 403, 'Unauthorized. Super Admin role required.');
    }
}
