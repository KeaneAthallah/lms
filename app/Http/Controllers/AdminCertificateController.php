<?php

namespace App\Http\Controllers;

use App\Models\Certificate;
use Illuminate\Http\Request;

class AdminCertificateController extends Controller
{
    public function index(Request $request)
    {
        $query = Certificate::with(['course:id,title,slug', 'student:id,name,email'])
            ->latest('issued_at');

        if ($search = $request->string('search')->trim()->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('certificate_number', 'like', "%{$search}%")
                    ->orWhere('identifier', 'like', "%{$search}%")
                    ->orWhereHas('student', fn ($s) => $s->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
            });
        }

        $certificates = $query->paginate(15)->withQueryString();

        return response()->json([
            'certificates' => $certificates->map(fn (Certificate $c): array => [
                'id' => $c->id,
                'certificate_number' => $c->certificate_number,
                'identifier' => $c->identifier,
                'issued_at' => $c->issued_at?->toISOString(),
                'course' => ['id' => $c->course->id, 'title' => $c->course->title, 'slug' => $c->course->slug],
                'student' => ['id' => $c->student->id, 'name' => $c->student->name, 'email' => $c->student->email],
            ]),
            'meta' => [
                'current_page' => $certificates->currentPage(),
                'last_page' => $certificates->lastPage(),
                'total' => $certificates->total(),
            ],
        ]);
    }
}
