<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CertificateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'certificate_number' => $this->certificate_number,
            'identifier' => $this->identifier,
            'issued_at' => $this->issued_at?->toISOString(),
            'verify_url' => url('/certificates/verify/'.$this->identifier),
            'student' => $this->whenLoaded('student', fn () => [
                'id' => $this->student->id,
                'name' => $this->student->name,
            ]),
            'course' => $this->whenLoaded('course', fn () => [
                'id' => $this->course->id,
                'title' => $this->course->title,
                'slug' => $this->course->slug,
                'instructor' => $this->course->instructor ? [
                    'id' => $this->course->instructor->id,
                    'name' => $this->course->instructor->name,
                ] : null,
            ]),
        ];
    }
}
