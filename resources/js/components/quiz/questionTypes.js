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
