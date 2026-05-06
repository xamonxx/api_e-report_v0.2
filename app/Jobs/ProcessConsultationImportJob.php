<?php

namespace App\Jobs;

use App\Models\ConsultationImport;
use App\Services\ConsultationImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessConsultationImportJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;
    public int $maxExceptions = 1;
    public int $uniqueFor = 3600;

    public function __construct(
        public int $importId
    ) {
        $this->onQueue('imports');
    }

    public function uniqueId(): string
    {
        return 'consultation-import:' . $this->importId;
    }

    public function handle(ConsultationImportService $service): void
    {
        $import = ConsultationImport::find($this->importId);

        if (!$import) {
            return;
        }

        $service->process($import);
    }
}
