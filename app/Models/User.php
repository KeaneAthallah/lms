<?php

namespace App\Models;

use App\Role;
use App\Traits\HasRoles;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'headline', 'bio', 'avatar_path', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function instructorCourses(): HasMany
    {
        return $this->hasMany(Course::class, 'instructor_id');
    }

    public function courses(): HasMany
    {
        return $this->instructorCourses();
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class, 'student_id');
    }

    public function lessonProgress(): HasMany
    {
        return $this->hasMany(LessonProgress::class, 'student_id');
    }

    public function quizAttempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class, 'student_id');
    }

    public function assignmentSubmissions(): HasMany
    {
        return $this->hasMany(AssignmentSubmission::class, 'student_id');
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class, 'student_id');
    }

    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class, 'student_id');
    }

    public function avatarUrl(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->avatar_path
                ? asset('storage/'.$this->avatar_path)
                : null,
        );
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(Role::Admin);
    }

    public function isInstructor(): bool
    {
        return $this->hasRole(Role::Instructor);
    }

    public function isStudent(): bool
    {
        return $this->hasRole(Role::Student);
    }

    public function isCustomerService(): bool
    {
        return $this->hasRole(Role::CustomerService);
    }
}
