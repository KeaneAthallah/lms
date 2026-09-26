<?php

namespace App;

enum QuizQuestionType: string
{
    case MultipleChoice = 'multiple_choice';
    case TrueFalse = 'true_false';
    case ShortAnswer = 'short_answer';

    /**
     * Several options may be chosen. Controlled by `settings.partial_credit`:
     * off means all-or-nothing, on means proportional credit.
     */
    case MultiSelect = 'multi_select';

    /**
     * A single number. `settings.tolerance` sets the absolute margin of error
     * that still counts as correct, so "about 9.8" is markable without
     * accepting any number.
     */
    case Numeric = 'numeric';

    /**
     * Several blanks embedded in the question text, each with its own list of
     * accepted answers. Accepted answers live in `settings.blanks`, keyed by
     * blank index, which is why this type needs no `is_correct` option.
     */
    case FillInBlank = 'fill_in_blank';
}
