<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LearningInsightsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'generated_at' => now()->toIso8601String(),
            'method' => 'deterministic_rules',
            'summary' => $this['summary'],
            'focus' => $this['focus'],
            'reviews' => $this['reviews'],
            'recommendations' => $this['recommendations'],
            'quiz_readiness' => $this['quiz_readiness'],
            'study_plan' => $this['study_plan'],
        ];
    }
}
