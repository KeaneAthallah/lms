<?php

namespace App\Support\Grading;

use App\QuizQuestionType;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves the {@see Grader} responsible for a question type.
 *
 * Keeping the type-to-grader mapping in one place means adding a question type
 * is a two-part change: a new `QuizQuestionType` case and one line here. The
 * fallback exists so that a quiz authored before a type was retired still grades
 * rather than fataling mid-submit — a student should never 500 because of an
 * instructor's content.
 */
class GraderRegistry
{
    /**
     * @var array<string, Grader>
     */
    private array $graders;

    public function __construct(private readonly Container $container)
    {
        $this->graders = [
            QuizQuestionType::MultipleChoice->value => $container->make(ChoiceGrader::class),
            QuizQuestionType::TrueFalse->value => $container->make(ChoiceGrader::class),
            QuizQuestionType::ShortAnswer->value => $container->make(ShortAnswerGrader::class),
            QuizQuestionType::MultiSelect->value => $container->make(MultiSelectGrader::class),
            QuizQuestionType::Numeric->value => $container->make(NumericGrader::class),
            QuizQuestionType::FillInBlank->value => $container->make(FillInBlankGrader::class),
        ];
    }

    public function for(QuizQuestionType $type): Grader
    {
        return $this->graders[$type->value] ?? $this->container->make(ChoiceGrader::class);
    }
}
