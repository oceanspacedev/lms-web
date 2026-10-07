<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Services\WaghubService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PublicDocumentRequestController extends Controller
{
    public function create(): Response
    {
        return $this->form();
    }

    public function status(string $token): Response
    {
        $submission = DocumentRequest::where('public_token', $token)->firstOrFail();
        if ($submission->status === 'revision') {
            return $this->form($submission);
        }

        return response()->view('requests.status', ['submission' => $submission])->withHeaders($this->privateHeaders());
    }

    public function store(Request $request, WaghubService $waghub): RedirectResponse
    {
        return $this->handleSubmission($request, $waghub);
    }

    public function revise(Request $request, WaghubService $waghub, string $token): RedirectResponse
    {
        return $this->handleSubmission($request, $waghub, DocumentRequest::where('public_token', $token)->firstOrFail());
    }

    private function handleSubmission(Request $request, WaghubService $waghub, ?DocumentRequest $submission = null): RedirectResponse
    {
        try {
            return $this->submit($request, $waghub, $submission);
        } catch (ValidationException $exception) {
            $exception->redirectTo($submission ? route('requests.public.status', ['token' => $submission->public_token]) : route('requests.public.create'));
            throw $exception;
        }
    }

    private function form(?DocumentRequest $submission = null): Response
    {
        $types = $submission ? collect([$submission->documentType]) : DocumentType::where('is_active', true)->where('accept_public_requests', true)->whereNotNull('request_pic_id')->orderBy('name')->get();
        $schemas = $types->mapWithKeys(fn (DocumentType $type): array => [$type->id => $submission?->requirements ?? ['fields' => [], 'attachments' => $type->request_attachments, 'has_expiry' => $type->has_expiry]]);

        return response()->view('requests.create', [
            'submission' => $submission, 'types' => $types, 'schemas' => $schemas,
            'companies' => $submission ? collect([$submission->company]) : Company::where('is_active', true)->orderBy('name')->get(),
        ])->withHeaders($this->privateHeaders());
    }

    private function submit(Request $request, WaghubService $waghub, ?DocumentRequest $submission = null): RedirectResponse
    {
        abort_unless(blank($request->input('website')), 422);
        $data = $request->validate([
            'requester_name' => ['required', 'string', 'max:255'], 'requester_phone' => ['required', 'string', 'max:30'],
            'requester_division' => ['required', 'string', 'max:100'], 'request_reason' => ['required', 'string', 'max:4000'],
            'company_id' => [$submission ? 'nullable' : 'required', 'integer'], 'document_type_id' => [$submission ? 'nullable' : 'required', 'integer'],
            'details' => ['sometimes', 'array', 'max:20'], 'attachments' => ['sometimes', 'array', 'max:20'],
        ], ['required' => ':attribute wajib diisi.', 'after_or_equal' => ':attribute tidak boleh sebelum tanggal mulai.'], [
            'requester_name' => 'Nama pengaju', 'requester_phone' => 'Nomor telepon', 'company_id' => 'Badan usaha', 'document_type_id' => 'Jenis dokumen', 'requester_division' => 'Divisi', 'request_reason' => 'Keperluan',
        ]);
        $phone = $waghub->normalizePhone($data['requester_phone']);
        if (! $phone) {
            throw ValidationException::withMessages(['requester_phone' => 'Nomor WhatsApp tidak valid. Gunakan 08 atau +62.']);
        }
        $data['requester_phone'] = $phone;
        $data['title'] = Str::limit($data['request_reason'], 120, '');
        $data['partner_name'] = $submission?->partner_name ?? Company::findOrFail($data['company_id'])->name;
        $data['purpose'] = 'new';
        $data['has_cost'] = false;
        $data['details'] ??= [];
        $data['attachments'] ??= [];
        $result = DocumentRequest::submitPublic($data, $submission);

        return redirect()->route('requests.public.status', ['token' => $result->public_token])->with('submitted', true);
    }

    /** @return array<string, string> */
    private function privateHeaders(): array
    {
        return ['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow'];
    }
}
