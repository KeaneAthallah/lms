<?php

namespace App\Http\Controllers;

use App\Models\Certificate;
use App\Services\CertificateService;
use Illuminate\Http\Request;

class CertificateVerifyController extends Controller
{
    public function verify(Request $request, string $identifier)
    {
        $certificate = Certificate::with('course.instructor', 'student')
            ->where('identifier', $identifier)
            ->first();

        if (! $certificate) {
            abort(404, 'Certificate not found.');
        }

        $isValid = (new CertificateService)->verify($identifier);

        return view('certificates.verify', [
            'verified' => $isValid,
            'certificate' => $certificate,
            'identifier' => $identifier,
        ]);
    }

    public function show(Request $request, string $identifier)
    {
        $certificate = Certificate::with(['course.instructor', 'student'])
            ->where('identifier', $identifier)
            ->firstOrFail();

        return view('certificates.show', [
            'certificate' => $certificate,
        ]);
    }
}
