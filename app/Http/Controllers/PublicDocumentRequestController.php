<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Services\WaghubService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PublicDocumentRequestController extends Controller
{
    /** @var array<string, string> Header untuk halaman yang memuat data pengajuan: tidak disimpan cache, tidak dirujuk, tidak diindeks. */
    public const PRIVATE_HEADERS = ['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow'];

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

    public function lookup(): Response
    {
        return response()->view('requests.lookup')->withHeaders($this->privateHeaders());
    }

    /**
     * Cek status tanpa tautan: nomor pengajuan dan nomor WhatsApp pengaju harus cocok.
     * Semua kegagalan (nomor tidak ada, nomor WhatsApp salah, format salah) melewati jalur yang sama dan menghasilkan
     * respons yang identik, supaya nomor pengajuan tidak bisa ditebak lewat perbedaan respons.
     */
    public function lookupSubmit(Request $request, WaghubService $waghub): Response|RedirectResponse
    {
        $submissionId = $this->lookupSubmissionId($request->input('submission_id'));
        $phone = $waghub->normalizePhone($this->lookupText($request->input('requester_phone')));
        $submission = DocumentRequest::whereNotNull('public_token')->whereKey($submissionId ?? 0)->first();
        $storedPhone = $waghub->normalizePhone($submission?->requester_phone);
        $samePhone = hash_equals(hash('sha256', (string) $storedPhone), hash('sha256', (string) $phone));

        if ($submission === null || $submissionId === null || $phone === null || $storedPhone === null || ! $samePhone) {
            return redirect()->route('requests.public.lookup')->with('lookup_failed', true)->withHeaders($this->privateHeaders());
        }

        return response()->view('requests.status', ['submission' => $submission, 'lookup' => true])->withHeaders($this->privateHeaders());
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
        $types = $submission ? collect([$submission->documentType]) : DocumentType::where('is_active', true)->orderBy('name')->get();
        $schemas = $types->mapWithKeys(fn (DocumentType $type): array => [$type->id => $submission?->requirements ?? $type->publicRequirements()]);

        return response()->view('requests.create', [
            'submission' => $submission, 'types' => $types, 'schemas' => $schemas,
            'companies' => $submission ? collect([$submission->company])->filter() : Company::where('is_active', true)->orderBy('name')->get(),
        ])->withHeaders($this->privateHeaders());
    }

    private function submit(Request $request, WaghubService $waghub, ?DocumentRequest $submission = null): RedirectResponse
    {
        abort_unless(blank($request->input('website')), 422);
        $this->stripPhoneSeparators($request);
        $isOther = $submission ? $submission->company_id === null : $request->input('company_id') === 'other';
        $data = $request->validate([
            'requester_name' => ['required', 'string', 'max:255'], 'requester_phone' => ['required', 'string', 'max:15', 'regex:/\A(?:08|\+?628)[0-9]{8,11}\z/'],
            'requester_division' => ['required', 'string', 'max:100'], 'request_reason' => ['required', 'string', 'max:4000'],
            'title' => ['required', 'string', 'max:255'], 'partner_name' => ['nullable', 'string', 'max:255'],
            'company_id' => [$submission ? 'nullable' : 'required', Rule::in([...Company::where('is_active', true)->pluck('id')->all(), 'other'])], 'document_type_id' => [$submission ? 'nullable' : 'required', 'integer'],
            'other_business_name' => [Rule::requiredIf($isOther), 'nullable', 'string', 'max:255'],
            'details' => ['sometimes', 'array', 'max:20'], 'attachments' => ['sometimes', 'array', 'max:20'],
        ], ['required' => ':attribute wajib diisi.', 'after_or_equal' => ':attribute tidak boleh sebelum tanggal mulai.', 'requester_phone.regex' => 'Nomor WhatsApp hanya boleh berisi angka, diawali 08, 62, atau +62.'], [
            'requester_name' => 'Nama pengaju', 'requester_phone' => 'Nomor telepon', 'company_id' => 'Badan usaha', 'document_type_id' => 'Jenis dokumen', 'requester_division' => 'Divisi', 'request_reason' => 'Keperluan', 'title' => 'Judul pengajuan', 'partner_name' => 'Pihak lawan / mitra',
        ]);
        $phone = $waghub->normalizePhone($data['requester_phone']);
        if (! $phone) {
            throw ValidationException::withMessages(['requester_phone' => 'Nomor WhatsApp tidak valid. Gunakan 08 atau +62.']);
        }
        $data['requester_phone'] = $phone;
        $data['other_business_name'] = $isOther ? $data['other_business_name'] : null;
        $data['company_id'] = $submission?->company_id ?? ($isOther ? null : $data['company_id']);
        $data['purpose'] = 'new';
        $data['has_cost'] = false;
        $data['details'] ??= [];
        $data['attachments'] ??= [];
        $result = DocumentRequest::submitPublic($data, $submission);

        return redirect()->route('requests.public.status', ['token' => $result->public_token])->with('submitted', true);
    }

    /**
     * Nomor yang ditempel dari WhatsApp ("+62 812-3456-7890") dibersihkan dari spasi, tanda hubung, titik, dan kurung
     * sebelum divalidasi, supaya batas panjang dan format berlaku untuk angkanya saja.
     */
    private function stripPhoneSeparators(Request $request): void
    {
        $phone = $request->input('requester_phone');
        if (is_string($phone)) {
            $request->merge(['requester_phone' => preg_replace('/[\s().-]/', '', $phone)]);
        }
    }

    private function lookupText(mixed $value): ?string
    {
        return is_string($value) || is_int($value) ? trim((string) $value) : null;
    }

    /** Nomor pengajuan seperti tertulis di status ("12", "#12", atau "Pengajuan #12"); null bila bukan angka wajar. */
    private function lookupSubmissionId(mixed $value): ?int
    {
        $text = $this->lookupText($value);
        if ($text === null || ! preg_match('/\A(?:pengajuan\s*)?#?\s*([0-9]{1,9})\z/i', $text, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    /** @return array<string, string> */
    private function privateHeaders(): array
    {
        return self::PRIVATE_HEADERS;
    }
}
