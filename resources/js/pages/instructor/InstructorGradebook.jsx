import { Fragment, useCallback, useEffect, useMemo, useState } from 'react';
import api from '../../api';
import { Button, Card, cx, EmptyState, PageHeader, PageLoader, useToast } from '../../components/ui';
import GradeAdjustmentHistoryModal from './GradeAdjustmentHistoryModal';
import GradeAdjustmentModal from './GradeAdjustmentModal';
import GradebookCategoriesModal from './GradebookCategoriesModal';

function cellColor(percentage) {
    if (percentage === null || percentage === undefined) return 'text-slate-300';
    if (percentage >= 80) return 'text-emerald-700';
    if (percentage >= 60) return 'text-amber-700';
    return 'text-red-600';
}

/**
 * One grade cell.
 *
 * A grade an instructor has touched is marked rather than silently different: a
 * dropped grade is struck through and greyed, and an overridden one is labelled,
 * because a number in a table that nobody can account for is worse than a wrong
 * one. The percentage shown is always the one that counts towards the course
 * grade.
 */
function GradeCell({ cell, onClick }) {
    if (!cell) {
        return (
            <td className="px-4 py-3 text-center">
                <span className="text-slate-300">—</span>
            </td>
        );
    }

    const title = [
        `${cell.score} of ${cell.max_score}`,
        cell.dropped ? 'Excluded from the course grade' : null,
        cell.overridden ? `Replaces the recorded ${cell.recorded_score} of ${cell.recorded_max_score}` : null,
        cell.note,
    ]
        .filter(Boolean)
        .join(' · ');

    return (
        <td className="px-4 py-3 text-center">
            <button
                type="button"
                onClick={onClick}
                title={title}
                className={cx(
                    'group relative w-full rounded px-1 py-0.5 text-center transition hover:bg-slate-100',
                    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-400',
                    cell.dropped ? 'opacity-50' : null
                )}
            >
                <span className={cx('font-semibold', cell.dropped ? 'text-slate-400 line-through' : cellColor(cell.percentage))}>
                    {cell.percentage}%
                </span>
                {cell.overridden ? (
                    <span className="absolute -right-0.5 -top-1 h-1.5 w-1.5 rounded-full bg-amber-500" aria-hidden="true" />
                ) : null}
                {cell.dropped ? (
                    <span className="mt-0.5 block text-[10px] font-semibold uppercase tracking-wide text-slate-400">Dropped</span>
                ) : null}
                {cell.overridden && !cell.dropped ? (
                    <span className="mt-0.5 block text-[10px] font-semibold uppercase tracking-wide text-amber-600">Manual</span>
                ) : null}
            </button>
        </td>
    );
}

export default function InstructorGradebook() {
    const slug = location.pathname.split('/')[3];
    const toast = useToast();
    const [data, setData] = useState(null);
    const [settingsOpen, setSettingsOpen] = useState(false);
    const [historyOpen, setHistoryOpen] = useState(false);
    const [adjusting, setAdjusting] = useState(null);

    const load = useCallback(async () => {
        await api
            .get(`/api/instructor/courses/${slug}/gradebook`)
            .then(({ data }) => setData(data))
            .catch(() => toast('Could not load the gradebook.', 'error'));
    }, [slug, toast]);

    useEffect(() => {
        load();
    }, [load]);

    const categories = data?.categories ?? [];

    const hasCategories = useMemo(() => categories.some((c) => c.id !== null), [categories]);

    const categoryByKey = useMemo(() => Object.fromEntries(categories.map((c) => [c.key, c])), [categories]);

    const headerBands = useMemo(() => {
        const bands = [];
        for (const assessment of data?.assessments ?? []) {
            const last = bands[bands.length - 1];
            if (last && last.key === assessment.category_key) {
                last.count += 1;
            } else {
                bands.push({ key: assessment.category_key, count: 1 });
            }
        }
        return bands;
    }, [data]);

    if (!data) {
        return <PageLoader label="Loading gradebook…" />;
    }

    const { course, assessments, students } = data;

    const studentSticky = hasCategories
        ? 'bg-slate-50'
        : 'bg-white';

    return (
        <div className="space-y-6">
            <PageHeader
                title="Gradebook"
                subtitle={`${course.title} · ${students.length} students`}
                actions={
                    <div className="flex items-center gap-2">
                        <Button variant="secondary" icon="clock" onClick={() => setHistoryOpen(true)}>
                            Adjustments
                        </Button>
                        <Button variant="secondary" icon="settings" onClick={() => setSettingsOpen(true)}>
                            Edit categories
                        </Button>
                    </div>
                }
            />

            {!students.length ? (
                <EmptyState icon="users" title="No students yet" message="Students appear here once they enroll in the course." />
            ) : !assessments.length ? (
                <EmptyState icon="clipboard" title="No assessments yet" message="Add a quiz or an assignment to the course and the gradebook builds itself around it." />
            ) : (
                <Card className="p-0">
                    <div className="overflow-x-auto scrollbar-slim">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-slate-200 text-left">
                                    <th className={`sticky left-0 z-20 px-5 py-2 font-semibold text-slate-500 ${studentSticky}`}>Student</th>
                                    {hasCategories
                                        ? headerBands.map((band) => {
                                            const cat = categoryByKey[band.key];
                                            return (
                                                <th key={band.key ?? 'empty'} colSpan={band.count} className="bg-slate-50 px-4 py-2 text-center font-semibold text-slate-500">
                                                    {cat ? `${cat.name} · ${cat.weight}%` : 'Uncategorized'}
                                                    {cat?.average !== null && cat?.average !== undefined ? (
                                                        <div className="mt-0.5 text-xs font-normal text-slate-400">avg {cat.average}%</div>
                                                    ) : null}
                                                </th>
                                            );
                                        })
                                        : null}
                                    <th className={`sticky right-0 z-20 px-5 py-2 text-center font-semibold text-slate-500 ${studentSticky}`}>Course grade</th>
                                </tr>
                                <tr className="border-b border-slate-200 text-left">
                                    <th className="sticky left-0 z-10 bg-white px-5 py-3 font-semibold text-slate-500">
                                        {hasCategories ? '' : 'Student'}
                                    </th>
                                    {assessments.map((assessment) => (
                                        <th key={assessment.key} className="min-w-32 px-4 py-3 text-center font-semibold text-slate-500">
                                            {assessment.title}
                                            {assessment.average !== null ? (
                                                <div className="mt-0.5 text-xs font-normal text-slate-400">avg {assessment.average}%</div>
                                            ) : null}
                                        </th>
                                    ))}
                                    <th className="sticky right-0 z-10 bg-white px-5 py-3 text-center font-bold text-slate-700">
                                        {hasCategories ? '' : 'Course grade'}
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {students.map((student) => {
                                    const rowSpan = hasCategories ? 2 : 1;
                                    return (
                                        <Fragment key={student.id}>
                                            <tr className="hover:bg-slate-50">
                                                <td rowSpan={rowSpan} className="sticky left-0 z-10 bg-white px-5 py-3">
                                                    <div className="min-w-40">
                                                        <p className="truncate font-medium text-slate-900">{student.name}</p>
                                                        <p className="truncate text-xs text-slate-500">{student.email}</p>
                                                    </div>
                                                </td>
                                                {assessments.map((assessment) => {
                                                    const cell = student.cells?.[assessment.key];

                                                    return (
                                                        <GradeCell
                                                            key={assessment.key}
                                                            cell={cell}
                                                            onClick={
                                                                cell
                                                                    ? () => setAdjusting({ student, assessment, cell })
                                                                    : undefined
                                                            }
                                                        />
                                                    );
                                                })}
                                                <td rowSpan={rowSpan} className="sticky right-0 z-10 bg-white px-5 py-3 text-center">
                                                    {student.course_percentage !== null ? (
                                                        <span className={cx('font-bold', cellColor(student.course_percentage))}>{student.course_percentage}%</span>
                                                    ) : (
                                                        <span className="text-slate-300">—</span>
                                                    )}
                                                </td>
                                            </tr>
                                            {hasCategories ? (
                                                <tr className="bg-slate-50/50 hover:bg-slate-50">
                                                    {assessments.map((assessment, index) => {
                                                        const isBandStart = index === 0 || assessment.category_key !== assessments[index - 1].category_key;
                                                        const pct = student.category_percentages?.[assessment.category_key];
                                                        return (
                                                            <td key={assessment.key} className="px-4 py-1.5 text-center text-xs">
                                                                {isBandStart && pct !== null && pct !== undefined ? (
                                                                    <span className={cx('font-semibold', cellColor(pct))}>{pct}%</span>
                                                                ) : (
                                                                    <span className="text-slate-300">&nbsp;</span>
                                                                )}
                                                            </td>
                                                        );
                                                    })}
                                                </tr>
                                            ) : null}
                                        </Fragment>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </Card>
            )}

            <GradeAdjustmentModal
                open={Boolean(adjusting)}
                onClose={() => setAdjusting(null)}
                onSaved={load}
                courseSlug={slug}
                student={adjusting?.student}
                assessment={adjusting?.assessment}
                cell={adjusting?.cell}
            />

            <GradeAdjustmentHistoryModal open={historyOpen} onClose={() => setHistoryOpen(false)} courseSlug={slug} />

            <GradebookCategoriesModal
                open={settingsOpen}
                onClose={() => setSettingsOpen(false)}
                onSaved={load}
                courseSlug={slug}
                categories={categories}
                assessments={assessments}
            />
        </div>
    );
}