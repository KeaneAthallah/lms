import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';
import { Badge, DataTable, EmptyState, formatDate, PageHeader, PageLoader, Paginator, ProgressBar } from '../components/ui';

export default function Grades() {
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [page, setPage] = useState(1);

    useEffect(() => {
        setLoading(true);
        api.get(`/api/grades?page=${page}`)
            .then(({ data }) => setData(data))
            .catch(() => {})
            .finally(() => setLoading(false));
    }, [page]);

    if (loading) return <PageLoader label="Loading your grades…" />;

    const grades = data?.data ?? [];

    const columns = [
        {
            key: 'course',
            header: 'Course',
            render: (grade) =>
                grade.course ? (
                    <Link to={`/courses/${grade.course.slug}`} className="font-medium text-slate-900 hover:text-brand-600">
                        {grade.course.title}
                    </Link>
                ) : (
                    <span className="text-slate-400">Course removed</span>
                ),
        },
        {
            key: 'type',
            header: 'Type',
            render: (grade) => <Badge color={grade.type === 'quiz' ? 'violet' : 'amber'} dot>{grade.type}</Badge>,
        },
        {
            key: 'score',
            header: 'Score',
            align: 'right',
            render: (grade) => (
                <div className="flex items-center justify-end gap-3">
                    <span className="font-semibold text-slate-900">
                        {grade.score} <span className="font-normal text-slate-400">/ {grade.max_score}</span>
                    </span>
                    <span className="w-20">
                        <ProgressBar value={grade.percentage} color={grade.percentage >= 70 ? 'bg-emerald-500' : 'bg-red-400'} />
                    </span>
                </div>
            ),
        },
        {
            key: 'graded',
            header: 'Graded',
            align: 'right',
            thClassName: 'hidden md:table-cell',
            tdClassName: 'hidden md:table-cell',
            render: (grade) => <span className="text-slate-500">{formatDate(grade.graded_at)}</span>,
        },
    ];

    return (
        <div className="space-y-6">
            <PageHeader icon="chart" title="Your grades" subtitle="Quiz and assignment results from the courses you are taking." />

            {grades.length === 0 ? (
                <EmptyState icon="chart" title="No grades yet" message="Complete quizzes and assignments to see your results here." />
            ) : (
                <>
                    <DataTable rows={grades} rowKey={(g) => g.id} columns={columns} />
                    <Paginator meta={data.meta} onPage={setPage} />
                </>
            )}
        </div>
    );
}