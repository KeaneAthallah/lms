/**
 * Renders a question's rubric disclosure for a student.
 *
 * The backend only ever sends criteria labels and points here, never the
 * keywords that trigger a match -- those are the scoring rule. Showing the
 * guide is the fair part of keyword scoring: the student learns that their
 * answer is assessed against named criteria and how much each one is worth,
 * without the answer key leaking.
 */
export default function RubricGuide({ rubric }) {
    if (!Array.isArray(rubric) || rubric.length === 0) return null;

    return (
        <div className="mt-2 space-y-1.5">
            <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">Scored by rubric</p>
            <ul className="space-y-1">
                {rubric.map((criterion, index) => (
                    <li key={index} className="flex items-center justify-between gap-3 text-xs text-slate-600">
                        <span>{criterion.label || `Criterion ${index + 1}`}</span>
                        <span className="shrink-0 font-semibold text-slate-400">{criterion.points} pts</span>
                    </li>
                ))}
            </ul>
        </div>
    );
}