import { Link } from 'react-router-dom';
import { Badge, courseLevelLabel, formatDuration, Icon, languageLabel, ProgressBar } from './ui';

const levelTone = {
    beginner: 'green',
    intermediate: 'amber',
    advanced: 'red',
    all: 'slate',
};

export default function CourseCard({ course }) {
    const level = course.level && course.level !== 'all' ? course.level : null;
    const instructorName = course.instructor?.name ?? 'Instruktur';
    const duration = formatDuration(course.duration_minutes);
    const lessons = course.lessons_count ?? 0;
    const enrollments = course.enrollments_count ?? 0;

    return (
        <Link
            to={`/courses/${course.slug}`}
            className="group flex flex-col overflow-hidden border border-slate-200 bg-white transition hover:border-brand-300"
        >
            <div className="relative aspect-[16/9] w-full overflow-hidden bg-slate-100">
                {course.thumbnail_url ? (
                    <img src={course.thumbnail_url} alt={course.title} className="h-full w-full object-cover" loading="lazy" />
                ) : (
                    <div className="flex h-full w-full items-center justify-center bg-brand-50/60 text-brand-700/40">
                        <Icon name="book" className="h-12 w-12" />
                    </div>
                )}
                {level ? (
                    <span className="absolute left-3 top-3">
                        <Badge color={levelTone[level] ?? 'slate'} className="bg-white/90 shadow-sm ring-1 ring-inset ring-slate-200/60 backdrop-blur">
                            {courseLevelLabel(level)}
                        </Badge>
                    </span>
                ) : null}
            </div>

            <div className="flex flex-1 flex-col gap-2.5 p-4">
                <div className="flex items-center justify-between gap-2 border-b border-slate-100 pb-2.5 text-xs">
                    <span className="truncate font-semibold uppercase tracking-wide text-brand-700">
                        {course.category?.name ?? 'Umum'}
                    </span>
                    <span className="flex shrink-0 items-center gap-1 text-slate-400">
                        <Icon name="play" className="h-3.5 w-3.5" />
                        {lessons} pelajaran
                    </span>
                </div>

                <h3 className="line-clamp-2 font-semibold leading-snug text-slate-900 group-hover:text-brand-700">
                    {course.title}
                </h3>

                {course.short_description ? (
                    <p className="line-clamp-2 text-xs leading-relaxed text-slate-500">{course.short_description}</p>
                ) : null}

                <div className="flex items-center gap-2 text-xs text-slate-500">
                    <span className="flex h-5 w-5 items-center justify-center rounded-full bg-slate-100 text-[9px] font-bold text-slate-600">
                        {instructorName
                            .split(' ')
                            .map((p) => p[0])
                            .slice(0, 2)
                            .join('')
                            .toUpperCase()}
                    </span>
                    <span className="truncate">oleh {instructorName}</span>
                </div>

                <div className="mt-auto flex items-center justify-between gap-3 border-t border-slate-100 pt-2.5 text-xs text-slate-500">
                    {duration ? (
                        <span className="flex items-center gap-1.5">
                            <Icon name="clock" className="h-3.5 w-3.5 text-slate-400" />
                            {duration}
                        </span>
                    ) : (
                        <span className="flex items-center gap-1.5">
                            <Icon name="file" className="h-3.5 w-3.5 text-slate-400" />
                            Materi digital
                        </span>
                    )}
                    {course.language ? (
                        <span className="flex items-center gap-1.5">
                            <Icon name="globe" className="h-3.5 w-3.5 text-slate-400" />
                            {languageLabel(course.language)}
                        </span>
                    ) : null}
                    {enrollments > 0 ? (
                        <span className="flex items-center gap-1.5">
                            <Icon name="users" className="h-3.5 w-3.5 text-slate-400" />
                            {enrollments} terdaftar
                        </span>
                    ) : null}
                </div>

                {course.progress_percent !== undefined && course.progress_percent !== null ? (
                    <div className="space-y-1.5">
                        <ProgressBar value={course.progress_percent} />
                        <p className="text-right text-xs font-semibold text-emerald-600">{course.progress_percent}% selesai</p>
                    </div>
                ) : null}
            </div>
        </Link>
    );
}