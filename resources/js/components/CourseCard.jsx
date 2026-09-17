import { Link } from 'react-router-dom';
import { Badge, courseLevelLabel, formatDuration, Icon, languageLabel, ProgressBar } from './ui';

const levelTone = {
    beginner: 'green',
    intermediate: 'amber',
    advanced: 'red',
    all: 'blue',
};

export default function CourseCard({ course }) {
    const level = course.level && course.level !== 'all' ? course.level : null;
    const instructorName = course.instructor?.name ?? 'Instructor';
    const duration = formatDuration(course.duration_minutes);
    const initials = instructorName
        .split(' ')
        .map((p) => p[0])
        .slice(0, 2)
        .join('')
        .toUpperCase();

    return (
        <Link
            to={`/courses/${course.slug}`}
            className="group flex flex-col overflow-hidden rounded-lg border border-slate-200 bg-white shadow-card transition duration-300 hover:border-brand-300"
        >
            <div className="relative aspect-video w-full overflow-hidden bg-slate-100">
                {course.thumbnail_url ? (
                    <img
                        src={course.thumbnail_url}
                        alt={course.title}
                        className="h-full w-full object-cover"
                        loading="lazy"
                    />
                ) : (
                    <div className="flex h-full w-full items-center justify-center bg-slate-100 text-brand-700/50">
                        <Icon name="book" className="h-10 w-10" />
                    </div>
                )}
                <div className="absolute left-3 top-3 flex items-center gap-2">
                    {level ? (
                        <Badge color={levelTone[level] ?? 'blue'} className="bg-white/90 backdrop-blur">
                            {courseLevelLabel(level)}
                        </Badge>
                    ) : null}
                </div>
            </div>

            <div className="flex flex-1 flex-col gap-2 p-4">
                <div className="flex items-center justify-between gap-2 text-xs">
                    <span className="truncate font-semibold uppercase tracking-wide text-brand-600">
                        {course.category?.name ?? 'Umum'}
                    </span>
                    <span className="flex shrink-0 items-center gap-1 text-slate-400">
                        <Icon name="play" className="h-3 w-3" />
                        {course.lessons_count ?? 0} pelajaran
                    </span>
                </div>

                <h3 className="line-clamp-2 font-semibold leading-snug text-slate-900 group-hover:text-brand-700">
                    {course.title}
                </h3>

                {course.short_description ? (
                    <p className="line-clamp-2 text-xs leading-relaxed text-slate-500">{course.short_description}</p>
                ) : null}

                <div className="flex items-center gap-2 text-xs text-slate-500">
                    <span className="flex h-5 w-5 items-center justify-center rounded-full bg-brand-100 text-[9px] font-bold text-brand-700">
                        {initials}
                    </span>
                    <span className="truncate">oleh {instructorName}</span>
                </div>

                <div className="mt-auto flex items-center justify-between gap-3 border-t border-slate-100 pt-3 text-xs text-slate-500">
                    {duration ? (
                        <span className="flex items-center gap-1.5">
                            <Icon name="clock" className="h-3.5 w-3.5 text-slate-400" />
                            {duration}
                        </span>
                    ) : (
                        <span className="flex items-center gap-1.5">
                            <Icon name="play" className="h-3.5 w-3.5 text-slate-400" />
                            {course.lessons_count ?? 0} pelajaran
                        </span>
                    )}
                    {course.language ? (
                        <span className="flex items-center gap-1.5">
                            <Icon name="globe" className="h-3.5 w-3.5 text-slate-400" />
                            {languageLabel(course.language)}
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