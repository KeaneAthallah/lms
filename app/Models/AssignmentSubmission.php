<?php

namespace App\Models;

use App\SubmissionStatus;
use Database\Factories\AssignmentSubmissionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssignmentSubmission extends Model
{
    /** @use HasFactory<AssignmentSubmissionFactory> */
    use HasFactory;

    protected $fillable = [
        'assignment_id',
        'student_id',
        'content',
        'files',
        'version',
        'status',
        'submitted_at',
        'grade',
        'feedback',
        'graded_by',
        'graded_at',
    ];

    protected function casts(): array
    {
        return [
            'files' => 'array',
            'version' => 'integer',
            'status' => SubmissionStatus::class,
            'grade' => 'decimal:2',
            'submitted_at' => 'datetime',
            'graded_at' => 'datetime',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function grader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'graded_by');
    }

    public function isGraded(): bool
    {
        return $this->status === SubmissionStatus::Graded;
    }
}
