import { BLANK_PATTERN } from './questionTypes';

/**
 * Renders question text, resolving any fill-in-the-blank placeholders against
 * the answers the student gave.
 *
 * Without this the review header would show the author-facing `{{1}}` markers
 * while the per-blank breakdown underneath showed the real answers.
 */
export default function QuestionText({ text, answers = {}, fallback = null }) {
    const segments = String(text ?? '').split(BLANK_PATTERN);

    return (
        <>
            {segments.map((segment, index) => {
                // A capturing split alternates text, blank index, text, blank index…
                if (index % 2 === 0) return <span key={`t-${index}`}>{segment}</span>;

                const blank = Number(segment);
                const value = answers[blank];

                if (value !== undefined && value !== null && String(value).trim() !== '') {
                    return (
                        <span key={`b-${index}`} className="mx-0.5 rounded bg-slate-100 px-1 font-semibold text-slate-900">
                            {String(value)}
                        </span>
                    );
                }

                if (fallback) {
                    return (
                        <span key={`b-${index}`} className="mx-0.5 rounded bg-amber-50 px-1 italic text-slate-500">
                            {fallback}
                        </span>
                    );
                }

                // Unanswered: show a ruled space so the sentence still reads as a
                // sentence, with no number leaking the author-facing notation.
                return <span key={`b-${index}`} className="mx-1 inline-block h-4 w-24 border-b-2 border-slate-300 align-middle" />;
            })}
        </>
    );
}
