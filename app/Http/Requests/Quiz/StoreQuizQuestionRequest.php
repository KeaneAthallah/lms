<?php

namespace App\Http\Requests\Quiz;

use App\Http\Requests\Concerns\AuthorizesContentAuthors;
use App\QuizQuestionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQuizQuestionRequest extends FormRequest
{
    use AuthorizesContentAuthors;

    public function rules(): array
    {
        $types = array_column(QuizQuestionType::cases(), 'value');
        $type = $this->input('type');

        return array_merge([
            'type' => ['required', Rule::in($types)],
            'question_text' => ['required', 'string', 'max:2000'],
            'explanation' => ['nullable', 'string', 'max:2000'],
            'points' => ['required', 'numeric', 'min:0.1'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'settings' => ['nullable', 'array'],
            'options' => ['array'],
            'options.*.option_text' => ['required', 'string', 'max:1000'],
            'options.*.is_correct' => ['nullable', 'boolean'],
            'options.*.explanation' => ['nullable', 'string', 'max:2000'],
        ], $this->rulesForCorrectOptions($type));
    }

    /**
     * Types whose answer key is the option flagged `is_correct`, and so need at
     * least two options with exactly one of them correct.
     *
     * `numeric` and `fill_in_blank` are excluded on purpose: they keep the key in
     * `settings`, because every option row is rendered to the student and would
     * otherwise display the answer.
     */
    private function rulesForCorrectOptions(?string $type): array
    {
        $needCorrectOption = in_array($type, [
            QuizQuestionType::MultipleChoice->value,
            QuizQuestionType::TrueFalse->value,
            QuizQuestionType::MultiSelect->value,
        ], true);

        if ($needCorrectOption) {
            return [
                'options' => ['array', 'min:2'],
                'options.*.is_correct' => ['required', 'boolean'],
            ];
        }

        return [];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $this->validateSettingsForType($validator);
            $this->validateBlanksMatchQuestionText($validator);
        });
    }

    private function validateSettingsForType($validator): void
    {
        $type = $this->input('type');
        $settings = $this->input('settings') ?? [];

        if (! is_array($settings)) {
            return;
        }

        if ($type === QuizQuestionType::Numeric->value) {
            if (! array_key_exists('answer', $settings) || ! is_numeric($settings['answer'])) {
                $validator->errors()->add(
                    'settings.answer',
                    'A numeric question needs the accepted number.',
                );
            }

            if (isset($settings['tolerance']) && ! is_numeric($settings['tolerance'])) {
                $validator->errors()->add('settings.tolerance', 'The tolerance must be a number.');
            }

            if (isset($settings['tolerance']) && (float) $settings['tolerance'] < 0) {
                $validator->errors()->add('settings.tolerance', 'The tolerance cannot be negative.');
            }
        }

        if ($type === QuizQuestionType::FillInBlank->value) {
            $blanks = $settings['blanks'] ?? null;

            if (! is_array($blanks) || $blanks === []) {
                $validator->errors()->add(
                    'settings.blanks',
                    'A fill-in-the-blank question needs accepted answers for at least one blank.',
                );

                return;
            }

            foreach ($blanks as $index => $alternatives) {
                if (! is_array($alternatives) || $alternatives === []) {
                    $validator->errors()->add(
                        "settings.blanks.{$index}",
                        'Each blank needs at least one accepted answer.',
                    );
                }
            }
        }

        // Settings that belong to another type would silently be ignored by the
        // grader, so an author would not know they had no effect. Negative
        // marking applies to every scored type, so it joins each list rather
        // than living in one.
        $allowed = [
            ...match ($type) {
                QuizQuestionType::MultiSelect->value => ['partial_credit'],
                QuizQuestionType::Numeric->value => ['answer', 'tolerance'],
                QuizQuestionType::FillInBlank->value => ['blanks', 'partial_credit'],
                default => [],
            },
            'negative_marking',
        ];

        if (isset($settings['negative_marking'])) {
            if (! is_numeric($settings['negative_marking'])) {
                $validator->errors()->add('settings.negative_marking', 'Negative marking must be a number.');
            } elseif ($settings['negative_marking'] < 0 || $settings['negative_marking'] > 1) {
                $validator->errors()->add('settings.negative_marking', 'Negative marking must be between 0 and 1.');
            }
        }

        foreach (array_keys($settings) as $key) {
            if (! in_array($key, $allowed, true)) {
                $validator->errors()->add(
                    "settings.{$key}",
                    "The {$key} setting does not apply to a {$type} question.",
                );
            }
        }
    }

    /**
     * Reject a question whose accepted answers do not line up with the blanks
     * actually written in the text. Catching this at save time beats shipping a
     * question that silently awards zero to everyone.
     */
    private function validateBlanksMatchQuestionText($validator): void
    {
        if ($this->input('type') !== QuizQuestionType::FillInBlank->value) {
            return;
        }

        $text = (string) $this->input('question_text');

        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $text, $matches);

        $inText = array_map('intval', $matches[1] ?? []);

        if ($inText === []) {
            $validator->errors()->add(
                'question_text',
                'Use {{1}}, {{2}} and so on to mark where the blanks go.',
            );

            return;
        }

        $inText = array_unique($inText);
        $configured = array_map('intval', array_keys((array) ($this->input('settings')['blanks'] ?? [])));

        foreach (array_diff($inText, $configured) as $missing) {
            $validator->errors()->add(
                'settings.blanks',
                "Blank {{{$missing}}} has no accepted answers.",
            );
        }
    }
}
