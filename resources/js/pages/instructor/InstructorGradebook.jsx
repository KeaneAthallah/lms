import { Fragment, useCallback, useEffect, useMemo, useState } from 'react';
import api from '../../api';
import { Button, Card, cx, EmptyState, PageHeader, PageLoader, useToast } from '../../components/ui';
import GradebookCategoriesModal from './GradebookCategoriesModal';

function cellColor(percentage) {
    if (percentage === null || percentage === undefined) return 'text-slate-300';
    if (percentage >= 80) return 'text-emerald-700';
    if (percentage >= 60) return 'text-amber-700';
    return 'text-red-600';
}

export default function InstructorGradebook() {
    const slug = location.pathname.split('/')[3];
    const toast = useToast();
    const [data, setData] = useState(null);
    const [settingsOpen, setSettingsOpen] = useState(false);

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
                    <Button variant="secondary" icon="settings" onClick={() => setSettingsOpen(true)}>
                        Edit categories
                    </Button>
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
                                                        <td key={assessment.key} className="px-4 py-3 text-center">
                                                            {cell ? (
                                                                <span
                                                                    className={cx('font-semibold', cellColor(cell.percentage))}
                                                                    title={`${cell.score} of ${cell.max_score}`}
                                                                >
                                                                    {cell.percentage}%
                                                                </span>
                                                            ) : (
                                                                <span className="text-slate-300">—</span>
                                                            )}
                                                        </td>
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