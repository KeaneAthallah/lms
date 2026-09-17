<?php

namespace App\Http\Controllers;

use App\Http\Resources\CertificateResource;
use App\Models\Certificate;
use Illuminate\Http\Request;

class CertificateController extends Controller
{
    public function index(Request $request)
    {
        $certificates = Certificate::where('student_id', $request->user()->id)
            ->with(['course.instructor', 'student'])
            ->orderByDesc('issued_at')
            ->paginate(15);

        return CertificateResource::collection($certificates);
    }

    public function show(Request $request, Certificate $certificate)
    {
        $this->authorize('view', $certificate);

        $certificate->load(['course.instructor:id,name', 'student:id,name', 'enrollment']);

        return new CertificateResource($certificate);
    }
}
