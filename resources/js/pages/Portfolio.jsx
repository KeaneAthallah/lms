import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';
import { Badge, ButtonLink, EmptyState, Icon, PageHeader, PageLoader, Section, StatCard, formatDate } from '../components/ui';

const skillStatusMeta = {
    mastered: { color: 'green', label: 'Mastered' },
    building: { color: 'blue', label: 'Building' },
    review: { color: 'amber', label: 'Review' },
    unassessed: { color: 'slate', label: 'Unassessed' },
};

function CertificatesSection({ certificates }) {
    if (!certificates.length) return null;

    return (
        <Section title="Certificates" icon="certificate">
            <ul className="divide-y divide-slate-100">
                {certificates.map((certificate) => (
                    <li key={certificate.identifier} className="flex flex-col gap-2 px-5 py-4 sm:flex-row sm:items-center">
                        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-amber-50 text-amber-600">
                            <Icon name="award" className="h-5 w-5" />
                        </div>
                        <div className="min-w-0 flex-1">
                            <h3 className="font-semibold text-slate-900">
                                <Link to={`/certificates/${certificate.identifier}`} className="hover:underline">
                                    {certificate.course?.title}
                                </Link>
                            </h3>
                            <p className="text-xs font-medium text-slate-500">
                                Issued {certificate.issued_at ? formatDate(certificate.issued_at) : '—'}
                                {certificate.instructor ? ` · ${certificate.instructor}` : ''}
                            </p>
                        </div>
                        <Badge color="green" dot>Verified</Badge>
                    </li>
                ))}
            </ul>
        </Section>
    );
}

function SkillsSection({ skills }) {
    if (!skills.length) return null;

    return (
        <Section title="Skills portfolio" icon="cpu" actions={<Badge color="violet" dot>{skills.length} concepts</Badge>}>
            <ul className="divide-y divide-slate-100">
                {skills.map((skill) => {
                    const meta = skillStatusMeta[skill.status] ?? skillStatusMeta.unassessed;

                    return (
                        <li key={`${skill.course.id}-${skill.concept}`} className="flex items-center gap-3 px-5 py-3.5">
                            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600">
                                <Icon name="checkCircle" className="h-4.5 w-4.5" />
                            </span>
                            <div className="min-w-0 flex-1">
                                <h3 className="font-semibold text-slate-900">{skill.concept}</h3>
                                <p className="truncate text-xs font-medium text-slate-500">{skill.course.title}</p>
                            </div>
                            <div className="shrink-0 text-right">
                                <Badge color={meta.color} dot>{meta.label}</Badge>
                                <p className="mt-0.5 text-xs font-bold text-slate-600">{skill.mastery_percent}%</p>
                            </div>
                        </li>
                    );
                })}
            </ul>
        </Section>
    );
}

function GradesSection({ grades }) {
    if (!grades.length) return null;

    return (
        <Section title="Recent grades" icon="barChart">
            <ul className="divide-y divide-slate-100">
                {grades.map((grade) => (
                    <li key={grade.id} className="flex items-center gap-3 px-5 py-3.5">
                        <div className="min-w-0 flex-1">
                            <h3 className="font-semibold text-slate-900">
                                {grade.course ? (
                                    <Link to={`/courses/${grade.course.slug}`} className="hover:underline">
                                        {grade.course.title}
                                    </Link>
                                ) : (
                                    'Course'
                                )}
                            </h3>
                            <p className="text-xs font-medium text-slate-500">
                                {grade.type} · {formatDate(grade.graded_at)}
                            </p>
                        </div>
                        <div className="shrink-0 text-right">
                            <span className="text-sm font-bold text-slate-800">
                                {grade.percentage !== null ? `${grade.percentage}%` : `${grade.score}/${grade.max_score}`}
                            </span>
                            {grade.percentage !== null ? <p className="text-xs text-slate-400">{grade.score}/{grade.max_score}</p> : null}
                        </div>
                    </li>
                ))}
            </ul>
        </Section>
    );
}

function CoursesSection({ courses }) {
    if (!courses.length) return null;

    return (
        <Section title="Course history" icon="book" actions={<Badge color="blue" dot>{courses.length} courses</Badge>}>
            <ul className="divide-y divide-slate-100">
                {courses.map((entry) => (
                    <li key={entry.id} className="flex flex-col gap-2 px-5 py-4 sm:flex-row sm:items-center">
                        <div className="min-w-0 flex-1">
                            <h3 className="font-semibold text-slate-900">
                                {entry.course ? (
                                    <Link to={`/courses/${entry.course.slug}`} className="hover:underline">
                                        {entry.course.title}
                                    </Link>
                                ) : (
                                    'Course'
                                )}
                            </h3>
                            <p className="text-xs font-medium text-slate-500">
                                {entry.status === 'completed' ? 'Completed' : 'In progress'} · {entry.progress_percent}%
                            </p>
                        </div>
                        <div className="shrink-0">
                            <Badge color={entry.status === 'completed' ? 'green' : 'blue'} dot>
                                {entry.status === 'completed' ? 'Completed' : 'In progress'}
                            </Badge>
                        </div>
                    </li>
                ))}
            </ul>
        </Section>
    );
}

export default function Portfolio() {
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        api.get('/api/portfolio')
            .then(({ data }) => setData(data.data))
            .catch(() => setFailed(true))
            .finally(() => setLoading(false));
    }, []);

    if (loading) return <PageLoader label="Assembling your portfolio…" />;

    if (failed || !data) {
        return (
            <EmptyState
                icon="award"
                title="Portfolio unavailable"
                message="We couldn't assemble your portfolio right now. Please try again in a moment."
            />
        );
    }

    const summary = data.summary ?? {};
    const { certificates = [], recent_grades = [], skills = [], courses = [] } = data;
    const hasContent = summary.certificates_count > 0 || summary.concepts_mastered > 0 || courses.length > 0;

    return (
        <div className="space-y-8">
            <PageHeader
                icon="award"
                title="My portfolio"
                subtitle="A verifiable record of everything you've completed — certificates, grades, mastered concepts, and real learning time."
            />

            <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <StatCard label="Certificates" value={summary.certificates_count ?? 0} icon="award" tone="green" />
                <StatCard label="Concepts mastered" value={summary.concepts_mastered ?? 0} icon="cpu" tone="violet" />
                <StatCard label="Courses in progress" value={summary.courses_in_progress ?? 0} icon="book" tone="brand" />
                <StatCard label="Learning minutes" value={summary.learning_minutes_total ?? 0} icon="clock" tone="amber" />
            </div>

            <div className="flex flex-wrap gap-2">
                <ButtonLink to="/certificates" variant="secondary" size="sm" icon="certificate">View certificates</ButtonLink>
                <ButtonLink to="/learning-map" variant="secondary" size="sm" icon="compass">Open learning map</ButtonLink>
                <ButtonLink to="/grades" variant="secondary" size="sm" icon="barChart">View all grades</ButtonLink>
            </div>

            <SkillsSection skills={skills} />
            <CertificatesSection certificates={certificates} />
            <GradesSection grades={recent_grades} />
            <CoursesSection courses={courses} />

            {!hasContent ? (
                <EmptyState
                    icon="award"
                    title="Your portfolio is empty"
                    message="Enroll in a course, complete its lessons, and pass its quizzes to start building verifiable evidence here."
                    action={<ButtonLink to="/browse" icon="search">Browse courses</ButtonLink>}
                />
            ) : null}
        </div>
    );
}