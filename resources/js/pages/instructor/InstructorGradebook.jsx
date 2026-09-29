import { useCallback, useEffect, useState } from 'react';
import api from '../../api';
import { Card, cx, EmptyState, PageHeader, PageLoader, useToast } from '../../components/ui';

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

    const load = useCallback(() => {
        api.get(`/api/instructor/courses/${slug}/gradebook`)
            .then(({ data }) => setData(data))
            .catch(() => toast('Could not load the gradebook.', 'error'));
    }, [slug, toast]);

    useEffect(() => {
        load();
    }, [load]);

    if (!data) {
        return <PageLoader label="Loading gradebook…" />;
    }

    const { course, assessments, students } = data;

    return (
        <div className="space-y-6">
            <PageHeader title="Gradebook" subtitle={`${course.title} · ${students.length} students`} />

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
                                    <th className="sticky left-0 z-10 bg-white px-5 py-3 font-semibold text-slate-500">Student</th>
                                    {assessments.map((assessment) => (
                                        <th key={assessment.key} className="min-w-32 px-4 py-3 text-center font-semibold text-slate-500">
                                            {assessment.title}
                                            {assessment.average !== null ? (
                                                <div className="mt-0.5 text-xs font-normal text-slate-400">avg {assessment.average}%</div>
                                            ) : null}
                                        </th>
                                    ))}
                                    <th className="sticky right-0 z-10 bg-white px-5 py-3 text-center font-bold text-slate-700">Course grade</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {students.map((student) => (
                                    <tr key={student.id} className="hover:bg-slate-50">
                                        <td className="sticky left-0 z-10 bg-white px-5 py-3">
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
                                        <td className="sticky right-0 z-10 bg-white px-5 py-3 text-center">
                                            {student.course_percentage !== null ? (
                                                <span className={cx('font-bold', cellColor(student.course_percentage))}>{student.course_percentage}%</span>
                                            ) : (
                                                <span className="text-slate-300">—</span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Card>
            )}
        </div>
    );
}