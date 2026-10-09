<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\Payment;
use App\Support\Audit;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Private uploads (payment proofs, signed contracts) live on the local disk, never in public/. */
class FileController extends Controller
{
    public function proof(Payment $payment): StreamedResponse
    {
        $this->authorizeView($payment);
        abort_if(blank($payment->proof_path), 404);
        $this->abortIfMissing($payment->proof_path, 'payment', $payment->id);

        return Storage::disk('local')->response($payment->proof_path);
    }

    public function contract(Contract $contract): StreamedResponse
    {
        $this->authorizeView($contract);
        abort_if(blank($contract->file_path), 404);
        $this->abortIfMissing($contract->file_path, 'contract', $contract->id);
        Audit::log($contract, 'file_downloaded'); // only once we know the download will succeed

        return Storage::disk('local')->download($contract->file_path, $contract->reference.'-contract.'.pathinfo($contract->file_path, PATHINFO_EXTENSION));
    }

    /** A path recorded in the database but gone from disk is lost evidence, so it is logged, not just a quiet 404. */
    private function abortIfMissing(string $path, string $kind, int $id): void
    {
        if (! Storage::disk('local')->exists($path)) {
            Log::error("Stored {$kind} file is missing from disk.", ['id' => $id, 'path' => $path]);
            abort(404, 'That file is no longer available. Please contact us.');
        }
    }
}
