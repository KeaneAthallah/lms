<?php

namespace App\Models;

use App\CategoryStatus;
use Database\Factories\CourseCategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseCategory extends Model
{
    /** @use HasFactory<CourseCategoryFactory> */
    use HasFactory;

    protected $fillable = ['name', 'slug', 'description', 'icon', 'status'];

    protected function casts(): array
    {
        return [
            'status' => CategoryStatus::class,
        ];
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class, 'category_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', CategoryStatus::Active->value);
    }
}
