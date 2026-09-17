import { createContext, useCallback, useContext, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Link } from 'react-router-dom';
import { Icon } from './icons';

export { Icon };

export function cx(...parts) {
    return parts.filter(Boolean).join(' ');
}

/* ---------------------------------- Toasts --------------------------------- */

const ToastContext = createContext(() => {});

let toastId = 0;

export function ToastProvider({ children }) {
    const [toasts, setToasts] = useState([]);
    const timers = useRef({});

    const dismiss = useCallback((id) => {
        setToasts((prev) => prev.filter((t) => t.id !== id));
        clearTimeout(timers.current[id]);
    }, []);

    const push = useCallback((message, type = 'info') => {
        const id = ++toastId;
        setToasts((prev) => [...prev.slice(-4), { id, message, type }]);
        timers.current[id] = setTimeout(() => dismiss(id), 4200);
    }, [dismiss]);

    const palette = {
        success: 'border-emerald-200 bg-emerald-50 text-emerald-800',
        error: 'border-red-200 bg-red-50 text-red-800',
        info: 'border-brand-200 bg-brand-50 text-brand-800',
    };

    return (
        <ToastContext.Provider value={push}>
            {children}
            {createPortal(
                <div className="pointer-events-none fixed inset-x-0 top-4 z-[60] flex flex-col items-center gap-2 px-4">
                    {toasts.map((toast) => (
                        <div
                            key={toast.id}
                            className={cx(
                                'pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-lg border px-4 py-3 text-sm font-medium shadow-lg backdrop-blur',
                                palette[toast.type],
                                toast.type === 'success' && 'animate-[fadeIn_.3s_ease]',
                            )}
                            role="status"
                        >
                            <Icon
                                name={toast.type === 'error' ? 'alert' : toast.type === 'success' ? 'checkCircle' : 'info'}
                                className="mt-0.5 h-4 w-4 shrink-0"
                            />
                            <span className="flex-1">{toast.message}</span>
                            <button type="button" onClick={() => dismiss(toast.id)} aria-label="Dismiss" className="shrink-0">
                                <Icon name="x" className="h-4 w-4" />
                            </button>
                        </div>
                    ))}
                </div>,
                document.body,
            )}
        </ToastContext.Provider>
    );
}

export function useToast() {
    return useContext(ToastContext);
}

/* --------------------------------- Buttons --------------------------------- */

const buttonVariants = {
    primary: 'bg-brand-600 text-white hover:bg-brand-700 focus-visible:outline-brand-600 shadow-sm',
    secondary: 'bg-white text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50',
    danger: 'bg-red-600 text-white hover:bg-red-700 shadow-sm',
    ghost: 'text-slate-600 hover:bg-slate-100',
    success: 'bg-emerald-600 text-white hover:bg-emerald-700 shadow-sm',
    dark: 'bg-slate-900 text-white hover:bg-slate-800 shadow-sm',
};

const buttonSizes = {
    sm: 'px-3 py-1.5 text-sm',
    md: 'px-4 py-2 text-sm',
    lg: 'px-5 py-2.5 text-base',
};

export function Button({ variant = 'primary', size = 'md', loading, icon, children, className, ...props }) {
    return (
        <button
            type="button"
            className={cx(
                'inline-flex items-center justify-center gap-2 rounded-lg font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-60',
                buttonVariants[variant],
                buttonSizes[size],
                className,
            )}
            disabled={loading || props.disabled}
            {...props}
        >
            {loading ? <Spinner className="h-4 w-4" /> : icon ? <Icon name={icon} className="h-4 w-4" /> : null}
            {children}
        </button>
    );
}

export function ButtonLink({ variant = 'primary', size = 'md', icon, to, children, className, ...props }) {
    return (
        <Link
            to={to}
            className={cx(
                'inline-flex items-center justify-center gap-2 rounded-lg font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2',
                buttonVariants[variant],
                buttonSizes[size],
                className,
            )}
            {...props}
        >
            {icon ? <Icon name={icon} className="h-4 w-4" /> : null}
            {children}
        </Link>
    );
}

/* ---------------------------------- Forms ---------------------------------- */

const inputClass =
    'w-full rounded-lg border-0 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-brand-600 disabled:bg-slate-50';

export function Field({ label, hint, error, children, required }) {
    return (
        <label className="block text-left">
            {label ? (
                <span className="mb-1.5 block text-sm font-medium text-slate-700">
                    {label} {required ? <span className="text-red-500">*</span> : null}
                </span>
            ) : null}
            {children}
            {hint ? <span className="mt-1 block text-xs text-slate-500">{hint}</span> : null}
            {error ? <span className="mt-1 block text-xs font-medium text-red-600">{error}</span> : null}
        </label>
    );
}

export function Input({ className, ...props }) {
    return <input className={cx(inputClass, className)} {...props} />;
}

export function Textarea({ className, ...props }) {
    const { label, ...rest } = props;
    return <textarea className={cx(inputClass, 'min-h-28 resize-y', className)} {...rest} />;
}

export function Select({ className, children, ...props }) {
    return (
        <select className={cx(inputClass, 'appearance-none bg-no-repeat pr-8', className)} {...props}>
            {children}
        </select>
    );
}

/* ---------------------------------- Misc ----------------------------------- */

export function Spinner({ className = 'h-5 w-5' }) {
    return (
        <svg className={cx('animate-spin text-current', className)} viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4z" />
        </svg>
    );
}

const badgePalette = {
    slate: 'bg-slate-100 text-slate-700 ring-slate-200',
    green: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    blue: 'bg-brand-50 text-brand-700 ring-brand-200',
    amber: 'bg-amber-50 text-amber-800 ring-amber-200',
    red: 'bg-red-50 text-red-700 ring-red-200',
    violet: 'bg-violet-50 text-violet-700 ring-violet-200',
};

export function Badge({ color = 'slate', children, className, dot }) {
    return (
        <span
            className={cx(
                'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset',
                badgePalette[color],
                className,
            )}
        >
            {dot ? <span className="h-1.5 w-1.5 rounded-full bg-current" /> : null}
            {children}
        </span>
    );
}

const statusLabels = {
    draft: { label: 'Draft', tone: 'slate' },
    published: { label: 'Published', tone: 'green' },
    archived: { label: 'Archived', tone: 'slate' },
    active: { label: 'In progress', tone: 'blue' },
    completed: { label: 'Completed', tone: 'green' },
    dropped: { label: 'Dropped', tone: 'red' },
    pending: { label: 'Pending', tone: 'amber' },
    graded: { label: 'Graded', tone: 'green' },
    returned: { label: 'Returned', tone: 'amber' },
    passed: { label: 'Passed', tone: 'green' },
    failed: { label: 'Failed', tone: 'red' },
    submitted: { label: 'Submitted', tone: 'green' },
    inactive: { label: 'Inactive', tone: 'slate' },
    enrolled: { label: 'Enrolled', tone: 'blue' },
    cancelled: { label: 'Cancelled', tone: 'red' },
};

export function StatusBadge({ status, mapping = statusLabels, dot = true, className }) {
    const meta = mapping[status] ?? { tone: 'slate' };
    return (
        <Badge color={meta.tone} dot={dot} className={className}>
            {meta.label ?? String(status).replace(/_/g, ' ')}
        </Badge>
    );
}

export function ProgressBar({ value = 0, color = 'bg-brand-600', className }) {
    const safe = Math.max(0, Math.min(100, Number(value) || 0));
    return (
        <div className={cx('h-2 w-full overflow-hidden rounded-full bg-slate-200', className)}>
            <div
                className={cx('h-full rounded-full transition-all duration-500', safe >= 100 ? 'bg-emerald-500' : color)}
                style={{ width: `${safe}%` }}
            />
        </div>
    );
}

export function EmptyState({ icon = 'book', title, message, action, className }) {
    return (
        <div className={cx('flex flex-col items-center justify-center rounded-xl border border-dashed border-slate-300 bg-white px-6 py-14 text-center', className)}>
            <div className="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400">
                <Icon name={icon} className="h-6 w-6" />
            </div>
            <h3 className="text-sm font-semibold text-slate-900">{title}</h3>
            {message ? <p className="mt-1 max-w-md text-sm text-slate-500">{message}</p> : null}
            {action ? <div className="mt-4">{action}</div> : null}
        </div>
    );
}

export function Modal({ open, onClose, title, children, footer, size = 'md' }) {
    if (!open) return null;

    const widths = { sm: 'max-w-md', md: 'max-w-lg', lg: 'max-w-3xl' };

    return createPortal(
        <div className="fixed inset-0 z-50 flex items-end justify-center p-0 sm:items-center sm:p-4" role="dialog" aria-modal="true">
            <div className="absolute inset-0 bg-slate-900/50 backdrop-blur-sm" onClick={onClose} />
            <div className={cx('relative w-full rounded-t-2xl bg-white shadow-2xl sm:rounded-2xl', widths[size], 'max-h-[92vh] overflow-y-auto')}>
                <div className="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                    <h3 className="text-base font-semibold text-slate-900">{title}</h3>
                    <button type="button" onClick={onClose} aria-label="Close" className="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600">
                        <Icon name="x" className="h-5 w-5" />
                    </button>
                </div>
                <div className="px-5 py-4">{children}</div>
                {footer ? <div className="flex justify-end gap-2 border-t border-slate-200 px-5 py-4">{footer}</div> : null}
            </div>
        </div>,
        document.body,
    );
}

export function PageHeader({ title, subtitle, actions, icon, className }) {
    return (
        <div className={cx('mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between', className)}>
            <div className="min-w-0">
                <div className="flex items-center gap-3">
                    {icon ? (
                        <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600">
                            <Icon name={icon} className="h-5 w-5" />
                        </span>
                    ) : null}
                    <div className="min-w-0">
                        <h1 className="truncate text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">{title}</h1>
                        {subtitle ? <p className="mt-0.5 text-sm text-slate-500">{subtitle}</p> : null}
                    </div>
                </div>
            </div>
            {actions ? <div className="flex flex-wrap items-center gap-2">{actions}</div> : null}
        </div>
    );
}

export function Card({ children, className }) {
    return <div className={cx('rounded-xl border border-slate-200 bg-white shadow-card', className)}>{children}</div>;
}

export function StatCard({ label, value, icon, tone = 'brand', hint }) {
    const tones = {
        brand: 'bg-brand-50 text-brand-600',
        green: 'bg-emerald-50 text-emerald-600',
        amber: 'bg-amber-50 text-amber-600',
        violet: 'bg-violet-50 text-violet-600',
        red: 'bg-red-50 text-red-600',
        slate: 'bg-slate-100 text-slate-600',
    };
    return (
        <Card className="p-4">
            <div className="flex items-center gap-3">
                <div className={cx('flex h-10 w-10 shrink-0 items-center justify-center rounded-lg', tones[tone])}>
                    <Icon name={icon} className="h-5 w-5" />
                </div>
                <div className="min-w-0">
                    <p className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</p>
                    <p className="text-lg font-bold text-slate-900">{value}</p>
                    {hint ? <p className="text-xs text-slate-400">{hint}</p> : null}
                </div>
            </div>
        </Card>
    );
}

export function Paginator({ meta, onPage, className }) {
    if (!meta || meta.last_page <= 1) return null;

    const pages = [];
    for (let p = 1; p <= meta.last_page; p++) {
        if (p === 1 || p === meta.last_page || Math.abs(p - meta.current_page) <= 2) {
            pages.push(p);
        } else if (pages[pages.length - 1] !== '…') {
            pages.push('…');
        }
    }

    return (
        <div className={cx('mt-6 flex items-center justify-center gap-1', className)}>
            <button
                type="button"
                disabled={meta.current_page <= 1}
                onClick={() => onPage(meta.current_page - 1)}
                className="rounded-md p-2 text-slate-500 hover:bg-slate-100 disabled:opacity-40"
                aria-label="Previous page"
            >
                <Icon name="chevronLeft" className="h-4 w-4" />
            </button>
            {pages.map((p, i) =>
                p === '…' ? (
                    <span key={`gap-${i}`} className="px-1 text-slate-400">
                        …
                    </span>
                ) : (
                    <button
                        type="button"
                        key={p}
                        onClick={() => onPage(p)}
                        className={cx(
                            'min-w-8 rounded-md px-2 py-1.5 text-sm font-medium',
                            p === meta.current_page ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-100',
                        )}
                    >
                        {p}
                    </button>
                ),
            )}
            <button
                type="button"
                disabled={meta.current_page >= meta.last_page}
                onClick={() => onPage(meta.current_page + 1)}
                className="rounded-md p-2 text-slate-500 hover:bg-slate-100 disabled:opacity-40"
                aria-label="Next page"
            >
                <Icon name="chevronRight" className="h-4 w-4" />
            </button>
        </div>
    );
}

export function Avatar({ src, name, size = 'h-8 w-8', className }) {
    const initials = (name ?? '?')
        .split(' ')
        .map((p) => p[0])
        .filter(Boolean)
        .slice(0, 2)
        .join('')
        .toUpperCase();

    if (src) {
        return <img src={src} alt={name} className={cx('rounded-full object-cover', size, className)} />;
    }

    return (
        <span
            className={cx(
                'inline-flex shrink-0 select-none items-center justify-center rounded-full bg-brand-600 font-semibold text-white',
                size,
                className,
            )}
            aria-hidden="true"
        >
            <span className="text-[0.65em]">{initials}</span>
        </span>
    );
}

export function formatDate(value, options = {}) {
    if (!value) return '—';
    return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', ...options }).format(new Date(value));
}

export function formatDateTime(value) {
    if (!value) return '—';
    return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
}

export function formatBytes(bytes) {
    if (!bytes) return '—';
    const units = ['B', 'KB', 'MB', 'GB'];
    let i = 0;
    let n = bytes;
    while (n >= 1024 && i < units.length - 1) {
        n /= 1024;
        i++;
    }
    return `${n.toFixed(n >= 10 || i === 0 ? 0 : 1)} ${units[i]}`;
}

export function timeAgo(value) {
    if (!value) return 'recently';
    const seconds = Math.floor((Date.now() - new Date(value).getTime()) / 1000);
    if (seconds < 60) return 'just now';
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes}m ago`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours}h ago`;
    const days = Math.floor(hours / 24);
    if (days < 30) return `${days}d ago`;
    return formatDate(value);
}

export function courseLevelLabel(level) {
    return {
        beginner: 'Pemula',
        intermediate: 'Menengah',
        advanced: 'Lanjutan',
    }[level] ?? (level ?? '');
}

export function languageLabel(code) {
    return {
        en: 'English',
        id: 'Bahasa Indonesia',
        ms: 'Bahasa Melayu',
    }[code] ?? (code ?? '');
}

export function formatDuration(minutes) {
    const value = Number(minutes);
    if (!Number.isFinite(value) || value <= 0) return null;
    if (value < 60) return `${Math.round(value)} menit`;
    const hours = Math.floor(value / 60);
    const rest = value % 60;
    return rest ? `${hours} jam ${Math.round(rest)} menit` : `${hours} jam`;
}

/* ----------------------------- Status / Layout ---------------------------- */

export function Skeleton({ className }) {
    return <div className={cx('animate-shimmer rounded-lg bg-slate-200', className)} />;
}

export function SkeletonRows({ rows = 3, className }) {
    return (
        <div className={cx('space-y-4', className)}>
            {Array.from({ length: rows }).map((_, i) => (
                <div key={i} className="flex items-center gap-3">
                    <Skeleton className="h-9 w-9 shrink-0 rounded-full" />
                    <div className="flex-1 space-y-2">
                        <Skeleton className="h-3 w-1/3" />
                        <Skeleton className="h-3 w-2/3" />
                    </div>
                </div>
            ))}
        </div>
    );
}

export function PageLoader({ label = 'Loading…', className }) {
    return (
        <div className={cx('flex flex-col items-center justify-center gap-3 py-24 text-slate-400', className)}>
            <Spinner className="h-8 w-8 text-brand-600" />
            <p className="text-sm font-medium">{label}</p>
        </div>
    );
}

const alertTones = {
    info: { wrap: 'border-brand-200 bg-brand-50 text-brand-800', icon: 'info' },
    success: { wrap: 'border-emerald-200 bg-emerald-50 text-emerald-800', icon: 'checkCircle' },
    warning: { wrap: 'border-amber-200 bg-amber-50 text-amber-800', icon: 'alert' },
    danger: { wrap: 'border-red-200 bg-red-50 text-red-800', icon: 'alert' },
};

export function Alert({ tone = 'info', icon, title, children, className }) {
    const t = alertTones[tone];
    return (
        <div role="status" className={cx('flex items-start gap-3 rounded-lg border px-4 py-3 text-sm', t.wrap, className)}>
            <Icon name={icon ?? t.icon} className="mt-0.5 h-4 w-4 shrink-0" />
            <div className="min-w-0">
                {title ? <p className="font-semibold">{title}</p> : null}
                {children ? <div className="mt-0.5 leading-relaxed">{children}</div> : null}
            </div>
        </div>
    );
}

export function ErrorState({ title = 'Something went wrong', message, onRetry, className }) {
    return (
        <div className={cx('flex flex-col items-center justify-center rounded-xl border border-red-200 bg-red-50 px-6 py-14 text-center', className)}>
            <div className="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-red-100 text-red-600">
                <Icon name="alert" className="h-6 w-6" />
            </div>
            <h3 className="text-sm font-semibold text-red-900">{title}</h3>
            {message ? <p className="mt-1 max-w-md text-sm text-red-700">{message}</p> : null}
            {onRetry ? (
                <Button variant="secondary" size="sm" icon="refresh" className="mt-4" onClick={onRetry}>
                    Try again
                </Button>
            ) : null}
        </div>
    );
}

export function IconButton({ icon, label, tone = 'default', className, ...props }) {
    const tones = {
        default: 'text-slate-400 hover:bg-slate-100 hover:text-slate-600',
        danger: 'text-slate-400 hover:bg-red-50 hover:text-red-600',
        brand: 'text-slate-400 hover:bg-brand-50 hover:text-brand-600',
    };
    return (
        <button
            type="button"
            aria-label={label}
            title={label}
            className={cx('inline-flex h-8 w-8 items-center justify-center rounded-lg transition', tones[tone], className)}
            {...props}
        >
            <Icon name={icon} className="h-4 w-4" />
        </button>
    );
}

export function Section({ title, icon, actions, children, className, bodyClassName }) {
    return (
        <Card className={cx('overflow-hidden', className)}>
            {title ? (
                <div className="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
                    <h2 className="flex items-center gap-2 text-sm font-bold text-slate-900">
                        {icon ? <Icon name={icon} className="h-4 w-4 text-slate-400" /> : null}
                        {title}
                    </h2>
                    {actions ? <div className="flex shrink-0 items-center gap-2">{actions}</div> : null}
                </div>
            ) : null}
            <div className={bodyClassName}>{children}</div>
        </Card>
    );
}

export function DataTable({ columns, rows = [], rowKey, onRowClick, empty, className }) {
    if (!rows.length && empty) {
        return <div className={className}>{empty}</div>;
    }

    return (
        <Card className={cx('overflow-hidden', className)}>
            <div className="overflow-x-auto scrollbar-slim">
                <table className="w-full text-left text-sm">
                    <thead className="bg-slate-50">
                        <tr>
                            {columns.map((col) => (
                                <th
                                    key={col.key ?? col.header}
                                    className={cx(
                                        'px-4 py-3 text-xs font-semibold uppercase tracking-wider text-slate-500',
                                        col.align === 'right' && 'text-right',
                                        col.thClassName,
                                    )}
                                >
                                    {col.header}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {rows.map((row, i) => (
                            <tr
                                key={rowKey ? rowKey(row, i) : i}
                                className={cx('transition', onRowClick && 'cursor-pointer hover:bg-slate-50')}
                                onClick={onRowClick ? () => onRowClick(row) : undefined}
                            >
                                {columns.map((col) => (
                                    <td
                                        key={col.key ?? col.header}
                                        className={cx('px-4 py-3 align-middle', col.align === 'right' && 'text-right', col.tdClassName)}
                                    >
                                        {col.render ? col.render(row, i) : row[col.key]}
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </Card>
    );
}

export function Breadcrumbs({ items, className }) {
    return (
        <nav aria-label="Breadcrumb" className={cx('mb-5', className)}>
            <ol className="flex flex-wrap items-center gap-1.5 text-xs font-medium text-slate-500">
                {items.map((item, i) => {
                    const isLast = i === items.length - 1;
                    return (
                        <li key={`${item.label}-${i}`} className="flex items-center gap-1.5">
                            {i > 0 ? <Icon name="chevronRight" className="h-3 w-3 text-slate-300" /> : null}
                            {!isLast && item.to ? (
                                <Link to={item.to} className="rounded transition hover:text-brand-600">
                                    {item.label}
                                </Link>
                            ) : (
                                <span className={isLast ? 'text-slate-700' : undefined}>{item.label}</span>
                            )}
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}

export function ConfirmDialog({ open, onClose, title, message, confirmLabel = 'Delete', cancelLabel = 'Cancel', icon = 'trash', tone = 'danger', loading, onConfirm }) {
    return (
        <Modal
            open={open}
            onClose={onClose}
            title={title}
            footer={
                <>
                    <Button variant="secondary" onClick={onClose} disabled={loading}>
                        {cancelLabel}
                    </Button>
                    <Button variant={tone === 'danger' ? 'danger' : 'primary'} icon={icon} loading={loading} onClick={onConfirm}>
                        {confirmLabel}
                    </Button>
                </>
            }
        >
            <p className="text-sm leading-relaxed text-slate-600">{message}</p>
        </Modal>
    );
}

export function Dropdown({ trigger, children, align = 'right', label = 'Actions' }) {
    const [open, setOpen] = useState(false);
    const ref = useRef(null);

    useEffect(() => {
        if (!open) return;
        const onPointerDown = (e) => {
            if (ref.current && !ref.current.contains(e.target)) setOpen(false);
        };
        const onKeyDown = (e) => {
            if (e.key === 'Escape') setOpen(false);
        };
        document.addEventListener('mousedown', onPointerDown);
        document.addEventListener('keydown', onKeyDown);
        return () => {
            document.removeEventListener('mousedown', onPointerDown);
            document.removeEventListener('keydown', onKeyDown);
        };
    }, [open]);

    return (
        <div ref={ref} className="relative inline-block text-left">
            <button
                type="button"
                aria-haspopup="menu"
                aria-expanded={open}
                aria-label={label}
                onClick={() => setOpen((v) => !v)}
                className="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-600"
            >
                <Icon name="moreVertical" className="h-4 w-4" />
            </button>
            {open ? (
                <div
                    role="menu"
                    className={cx(
                        'absolute z-20 mt-1 min-w-44 rounded-lg border border-slate-200 bg-white py-1 shadow-popover',
                        align === 'right' ? 'right-0' : 'left-0',
                        'animate-fade-in',
                    )}
                >
                    {children}
                </div>
            ) : null}
        </div>
    );
}

export function DropdownItem({ children, icon, onClick, tone = 'default' }) {
    return (
        <button
            type="button"
            role="menuitem"
            onClick={onClick}
            className={cx(
                'flex w-full items-center gap-2 px-3 py-2 text-left text-sm font-medium transition',
                tone === 'danger' ? 'text-red-600 hover:bg-red-50' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900',
            )}
        >
            {icon ? <Icon name={icon} className="h-4 w-4" /> : null}
            {children}
        </button>
    );
}