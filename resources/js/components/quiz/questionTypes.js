/**
 * Helpers shared by the student answer controls and the instructor authoring
 * form.
 *
 * These live apart from the components so that the builder can ask "which
 * blanks does this text have?" without importing a component that renders
 * inputs, and so that the placeholder pattern has exactly one definition that
 * both sides of the feature agree on.
 */

export const BLANK_PATTERN = /\{\{\s*(\d+)\s*\}\}/g;

/**
 * The question types a quiz can hold, in the order the authoring form offers
 * them.
 *
 * Kept in one place because a blueprint row and a question row are two views of
 * the same enum: a list here that drifts from the one in the authoring select
 * would let an author set a quota for a type they cannot write.
 */
export const QUESTION_TYPES = [
    { value: 'multiple_choice', label: 'Multiple choice' },
    { value: 'multi_select', label: 'Multiple select' },
    { value: 'true_false', label: 'True / False' },
    { value: 'short_answer', label: 'Short answer' },
    { value: 'numeric', label: 'Numeric' },
    { value: 'fill_in_blank', label: 'Fill in the blank' },
];

/** Blank indexes present in a fill-in-the-blank question, in written order. */
export function blankIndexes(questionText = '') {
    const found = String(questionText).matchAll(BLANK_PATTERN);
    return [...new Set([...found].map((match) => Number(match[1])))];
}

/**
 * Whether an answer counts as "attempted" for the "x of y answered" counter.
 *
 * Counted per question rather than by object key count, because an empty
 * multi-select array and a fill-in-the-blank with one blank left empty are both
 * stored but not answered, and both would otherwise let the student submit an
 * incomplete quiz.
 */
export function isAnswered(question, value) {
    if (value === undefined || value === null) return false;

    if (question.type === 'multi_select') {
        return Array.isArray(value) && value.length > 0;
    }

    if (question.type === 'fill_in_blank') {
        const blanks = blankIndexes(question.question_text);
        return blanks.length > 0 && blanks.every((index) => String(value[index] ?? '').trim() !== '');
    }

    return String(value).trim() !== '';
}
