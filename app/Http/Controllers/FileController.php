<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\Payment;
use App\Support\Audit;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Private uploads (payment proofs, signed contracts) live on the local disk, never in public/. */
class FileController extends Controller
{
    public function proof(Payment $payment): StreamedResponse
    {
        $this->authorizeView($payment);
        abort_unless($payment->proof_path && Storage::disk('local')->exists($payment->proof_path), 404);

        return Storage::disk('local')->response($payment->proof_path);
    }

    public function contract(Contract $contract): StreamedResponse
    {
        $this->authorizeView($contract);
        Audit::log($contract, 'file_downloaded');
        abort_unless($contract->file_path && Storage::disk('local')->exists($contract->file_path), 404);

        return Storage::disk('local')->download($contract->file_path, $contract->reference.'-contract.'.pathinfo($contract->file_path, PATHINFO_EXTENSION));
    }
}
