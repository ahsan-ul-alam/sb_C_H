<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\ExitSettlement;
use App\Models\PolicyVersion;
use App\Services\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Supporting evidence (signed policy, exit paperwork) kept on the private disk. Uploading is separate from
 * review: a Board member who did not upload the file marks it approved.
 */
class DocumentController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'subject_type' => ['required', 'in:exit,policy'],
            'subject_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:150'],
            'file' => ['required', 'file', 'max:8192', 'mimes:pdf,jpg,jpeg,png'],
        ]);

        $exists = $data['subject_type'] === 'exit'
            ? ExitSettlement::whereKey($data['subject_id'])->exists()
            : PolicyVersion::whereKey($data['subject_id'])->exists();
        abort_unless($exists, 404);

        $document = Document::create([
            'subject_type' => $data['subject_type'], 'subject_id' => $data['subject_id'], 'name' => $data['name'],
            'path' => $request->file('file')->store('finance-documents'), 'uploaded_by' => $request->user()->id,
        ]);
        Audit::log('document.uploaded', $data['subject_type'].' '.$data['subject_id'], $document->name);

        return back()->with('status', 'Document attached; it still needs Board review.');
    }

    public function review(Request $request, Document $document): RedirectResponse
    {
        $approve = $request->input('decision') === 'approve';
        abort_unless($request->user()->can($document->subject_type === 'exit' ? 'exit.approve' : 'policy.approve'), 403);

        return $this->attempt(function () use ($request, $document, $approve) {
            if ($document->review_status !== 'pending') {
                throw new RuntimeException('Already reviewed.');
            }
            if ($document->uploaded_by === $request->user()->id) {
                throw new RuntimeException('You cannot review a document you uploaded.');
            }
            $document->update(['review_status' => $approve ? 'approved' : 'rejected', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
            Audit::log('document.'.($approve ? 'approved' : 'rejected'), 'document '.$document->id);
        }, $approve ? 'Document approved.' : 'Document rejected.');
    }

    public function download(Document $document): StreamedResponse
    {
        abort_unless(Storage::exists($document->path), 404);

        return Storage::download($document->path, $document->name.'.'.pathinfo($document->path, PATHINFO_EXTENSION));
    }
}
