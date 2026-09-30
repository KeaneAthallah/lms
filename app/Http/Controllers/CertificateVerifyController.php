<?php

namespace App\Http\Controllers;

use App\Models\Certificate;
use App\Services\CertificateService;
use Illuminate\Http\Request;

class CertificateVerifyController extends Controller
{
    public function verify(Request $request, string $reference)
    {
        // One lookup on a public, unauthenticated route. A miss is a 404 rather
        // than a rendered failure state, so the view only ever has one outcome.
        $certificate = (new CertificateService)->verify($reference);

        if (! $certificate) {
            abort(404, 'Certificate not found.');
        }

        return view('certificates.verify', [
            'certificate' => $certificate,
            'identifier' => $certificate->identifier,
        ]);
    }

    public function show(Request $request, string $identifier)
    {
        // Addressed by identifier only. The document is the artifact of record, so
        // it keeps the one key that is never reused or reissued.
        $certificate = Certificate::with(['course.instructor', 'student'])
            ->where('identifier', $identifier)
            ->firstOrFail();

        return view('certificates.show', [
            'certificate' => $certificate,
        ]);
    }
}
