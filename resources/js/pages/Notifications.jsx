import { useEffect, useState } from 'react';
import api from '../api';
import { Badge, Button, cx, EmptyState, formatDateTime, Icon, PageHeader, PageLoader, Section, useToast } from '../components/ui';

const typeIcon = {
    quiz: 'puzzle',
    grade: 'checkCircle',
    certificate: 'award',
    enrollment: 'book',
    assignment: 'clipboard',
    default: 'bell',
};

export default function Notifications() {
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const toast = useToast();

    const load = async () => {
        try {
            const { data } = await api.get('/api/notifications');
            setData(data);
        } catch {
            /* keep last data */
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        load();
    }, []);

    const markAllRead = async () => {
        await api.post('/api/notifications/read-all');
        window.dispatchEvent(new Event('lms:notifications'));
        toast('All notifications marked as read.', 'success');
        load();
    };

    const markRead = async (id) => {
        await api.post(`/api/notifications/${id}/read`);
        window.dispatchEvent(new Event('lms:notifications'));
        load();
    };

    if (loading) return <PageLoader label="Loading notifications…" />;

    const notifications = data?.notifications ?? [];

    return (
        <div className="mx-auto max-w-3xl space-y-6">
            <PageHeader
                icon="bell"
                title="Notifications"
                subtitle={data?.unread_count ? `${data.unread_count} unread notification${data.unread_count === 1 ? '' : 's'}` : 'You are all caught up'}
                actions={
                    data?.unread_count ? (
                        <Button variant="secondary" onClick={markAllRead} icon="check">
                            Mark all as read
                        </Button>
                    ) : null
                }
            />

            {notifications.length === 0 ? (
                <EmptyState icon="bell" title="No notifications" message="Updates about your courses will appear here." />
            ) : (
                <Section>
                    <ul className="divide-y divide-slate-100">
                        {notifications.map((n) => (
                            <li
                                key={n.id}
                                className={cx(
                                    'relative transition hover:bg-slate-50',
                                    n.read_at ? '' : 'bg-brand-50/40',
                                )}
                            >
                                {!n.read_at ? <span className="absolute inset-y-3 left-0 w-1 rounded-r-full bg-brand-500" /> : null}
                                <button
                                    type="button"
                                    onClick={() => !n.read_at && markRead(n.id)}
                                    className="flex w-full items-start gap-4 px-5 py-4 text-left"
                                >
                                    <span className="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-500">
                                        <Icon name={typeIcon[n.type] ?? typeIcon.default} className="h-4 w-4" />
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="flex items-center gap-2">
                                            <span className={cx('font-semibold text-slate-900', !n.read_at && 'text-brand-800')}>{n.title}</span>
                                            {!n.read_at ? <span className="h-2 w-2 rounded-full bg-brand-500" aria-label="Unread" /> : null}
                                        </span>
                                        <span className="mt-0.5 block line-clamp-2 text-sm text-slate-500">{n.message}</span>
                                        <span className="mt-1 block text-xs text-slate-400">{formatDateTime(n.created_at)}</span>
                                    </span>
                                    {n.type ? <Badge color="slate">{n.type}</Badge> : null}
                                </button>
                            </li>
                        ))}
                    </ul>
                </Section>
            )}
        </div>
    );
}