import { useEffect, useState } from 'react';
import api from '../api';
import CourseCard from '../components/CourseCard';
import { ButtonLink, EmptyState, PageHeader, PageLoader } from '../components/ui';

export default function MyCourses() {
    const [courses, setCourses] = useState(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        api.get('/api/dashboard')
            .then(({ data: dash }) => {
                const ids = new Set();
                const list = [
                    ...(dash.continue_learning ?? []).map((e) => e.course),
                    ...(dash.courses_in_progress ?? []).map((e) => e.course),
                    ...(dash.completed_courses ?? []).map((e) => e.course),
                ].filter((c) => {
                    if (ids.has(c.id)) return false;
                    ids.add(c.id);
                    return true;
                });
                setCourses(list);
            })
            .finally(() => setLoading(false));
    }, []);

    if (loading) return <PageLoader label="Loading your courses…" />;

    if (!courses?.length) {
        return (
            <EmptyState
                icon="book"
                title="No courses yet"
                message="Enroll in your first course and it will appear here."
                action={<ButtonLink to="/browse" icon="search">Browse courses</ButtonLink>}
            />
        );
    }

    return (
        <div>
            <PageHeader icon="grid" title="My Courses" subtitle="Courses you are enrolled in." />
            <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                {courses.map((c) => (
                    <CourseCard key={c.id} course={c} />
                ))}
            </div>
        </div>
    );
}