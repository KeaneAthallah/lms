import { useState } from 'react';
import { Link } from 'react-router-dom';
import { categoryIcon, Icon } from './icons';
import { cx } from './ui';

/* --------------------------- Section heading ------------------------------ */

export function SectionHeading({ eyebrow, title, description, align = 'left', className, id }) {
    return (
        <div className={cx('max-w-3xl', align === 'center' && 'mx-auto text-center', className)}>
            {eyebrow ? (
                <p className={cx('text-eyebrow flex items-center gap-3', align === 'center' && 'justify-center')}>
                    {eyebrow}
                    <span className="h-px w-10 bg-brand-400" aria-hidden="true" />
                </p>
            ) : null}
            <h2 id={id} className="mt-3 text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">
                {title}
            </h2>
            {description ? (
                <p className={cx('mt-3 leading-relaxed text-slate-600', align === 'center' && 'mx-auto')}>{description}</p>
            ) : null}
        </div>
    );
}

/* --------------------------- Intro / info panel --------------------------- */

export function IntroStrip({ eyebrow, title, description, items }) {
    return (
        <div className="overflow-hidden border border-slate-200 bg-white">
            <div className="grid lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
                <div className="border-b border-slate-200 bg-slate-50 px-6 py-8 sm:px-8 lg:border-b-0 lg:border-r">
                    <p className="text-eyebrow">{eyebrow ?? 'Informasi'}</p>
                    <h2 className="mt-2 text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">{title}</h2>
                    <span className="mt-4 block h-px w-12 bg-brand-600" aria-hidden="true" />
                </div>
                <div className="flex flex-col justify-center gap-5 px-6 py-8 sm:px-8">
                    <p className="text-sm leading-relaxed text-slate-600 sm:text-[15px]">{description}</p>
                    {items?.length ? (
                        <ul className="grid gap-x-6 gap-y-2 sm:grid-cols-2">
                            {items.map((item) => (
                                <li key={item} className="flex items-start gap-2.5 text-sm text-slate-700">
                                    <span className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-sm bg-brand-50 text-brand-700 ring-1 ring-inset ring-brand-200">
                                        <Icon name="check" className="h-3 w-3" strokeWidth={2.5} />
                                    </span>
                                    {item}
                                </li>
                            ))}
                        </ul>
                    ) : null}
                </div>
            </div>
        </div>
    );
}

/* ----------------------- Services (official registry) --------------------- */

export function ServicesGrid({ services }) {
    return (
        <ol className="registry-rule">
            {services.map((service) => (
                <li
                    key={service.number}
                    className="grid gap-3 border-b border-slate-200 px-1 py-6 sm:grid-cols-[minmax(0,1fr)_minmax(0,2fr)] sm:gap-8"
                >
                    <div className="flex items-center gap-3">
                        <span
                            className="flex h-10 w-10 shrink-0 items-center justify-center rounded-md border border-slate-200 bg-slate-50 text-brand-700"
                            aria-hidden="true"
                        >
                            <Icon name={service.icon} className="h-5 w-5" />
                        </span>
                        <span className="text-sm font-bold tabular-nums text-slate-400" aria-hidden="true">
                            {String(service.number).padStart(2, '0')}
                        </span>
                    </div>
                    <div>
                        <h3 className="font-bold tracking-tight text-slate-900">{service.title}</h3>
                        <p className="mt-1 max-w-2xl text-sm leading-relaxed text-slate-600">{service.description}</p>
                    </div>
                </li>
            ))}
        </ol>
    );
}

/* ---------------------------- Category directory --------------------------- */

export function CategoryDirectory({ categories }) {
    return (
        <ul className="grid gap-3 lg:grid-cols-2">
            {categories.map((category) => {
                const count = category.courses_count ?? 0;
                return (
                    <li key={category.id} className="min-w-0">
                        <Link
                            to={`/browse?category=${category.slug}`}
                            className="group flex h-full items-center gap-4 border border-slate-200 bg-white px-5 py-4 transition hover:border-brand-300"
                        >
                            <span
                                className="flex h-10 w-10 shrink-0 items-center justify-center rounded-md border border-slate-200 bg-slate-50 text-brand-700"
                                aria-hidden="true"
                            >
                                <Icon name={category.icon || categoryIcon(category.slug)} className="h-5 w-5" />
                            </span>
                            <span className="min-w-0 flex-1">
                                <span className="block truncate font-semibold text-slate-900 group-hover:text-brand-700">
                                    {category.name}
                                </span>
                                {category.description ? (
                                    <span className="mt-0.5 block line-clamp-1 text-xs text-slate-500">{category.description}</span>
                                ) : null}
                            </span>
                            <span className="shrink-0 text-xs font-semibold text-slate-500">{count} pembelajaran</span>
                            <Icon name="chevronRight" className="h-4 w-4 shrink-0 text-slate-300" />
                        </Link>
                    </li>
                );
            })}
        </ul>
    );
}

/* ------------------------- Process steps (registry) ------------------------ */

export function ProcessSteps({ steps }) {
    return (
        <ol className="grid gap-px border border-slate-200 bg-slate-200 sm:grid-cols-2 lg:grid-cols-3">
            {steps.map((step, i) => (
                <li key={step.title} className="bg-white p-6">
                    <p className="text-[11px] font-bold uppercase tracking-wider text-brand-700">
                        Langkah {String(i + 1).padStart(2, '0')}
                    </p>
                    <span className="mt-3 block h-px w-10 bg-brand-600" aria-hidden="true" />
                    <h3 className="mt-3 font-bold tracking-tight text-slate-900">{step.title}</h3>
                    <p className="mt-1.5 text-sm leading-relaxed text-slate-600">{step.description}</p>
                </li>
            ))}
        </ol>
    );
}

/* -------------------------- Resource list (directory) ---------------------- */

export function ResourceRow({ icon, title, description }) {
    return (
        <li className="flex items-start gap-4 border-b border-slate-200 px-5 py-4 last:border-b-0 sm:items-center">
            <span
                className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-slate-200 bg-slate-50 text-brand-700"
                aria-hidden="true"
            >
                <Icon name={icon} className="h-4.5 w-4.5" />
            </span>
            <div className="min-w-0 flex-1">
                <h3 className="text-sm font-bold text-slate-900">{title}</h3>
                <p className="mt-0.5 text-sm leading-relaxed text-slate-600">{description}</p>
            </div>
        </li>
    );
}

export function ResourceList({ items, className }) {
    return (
        <ul className={cx('overflow-hidden border border-slate-200 bg-white', className)}>
            {items.map((item) => (
                <ResourceRow key={item.title} {...item} />
            ))}
        </ul>
    );
}

/* ------------------------- Vertical evaluation flow ------------------------- */

export function FlowList({ items }) {
    return (
        <ul>
            {items.flatMap((item, i) => {
                const rows = [
                    <li key={`step-${i}`} className="flex items-start gap-4 border border-slate-200 bg-white px-5 py-4">
                        <span
                            className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-brand-200 bg-brand-50 text-sm font-bold text-brand-700"
                            aria-hidden="true"
                        >
                            {String(i + 1).padStart(2, '0')}
                        </span>
                        <div className="min-w-0">
                            <h3 className="text-sm font-bold text-slate-900">{item.title}</h3>
                            <p className="mt-0.5 text-sm leading-relaxed text-slate-600">{item.description}</p>
                        </div>
                    </li>,
                ];
                if (i < items.length - 1) {
                    rows.push(
                        <li key={`join-${i}`} className="flex justify-center py-0.5 text-slate-300" aria-hidden="true">
                            <Icon name="chevronDown" className="h-4 w-4" />
                        </li>,
                    );
                }
                return rows;
            })}
        </ul>
    );
}

/* ---------------------------- Role directory ------------------------------- */

export function RolePanel({ icon, title, eyebrow, description, items, index }) {
    return (
        <article className="flex flex-col border border-slate-200 bg-white">
            <div className="flex items-start justify-between gap-3 border-b border-slate-200 px-6 py-5">
                <div className="min-w-0">
                    <p className="text-[11px] font-bold uppercase tracking-wider text-brand-700">
                        {eyebrow ?? `Peran ${index}`}
                    </p>
                    <h3 className="mt-1 flex items-center gap-2 text-lg font-bold tracking-tight text-slate-900">
                        <Icon name={icon} className="h-5 w-5 shrink-0 text-brand-600" strokeWidth={2} />
                        {title}
                    </h3>
                </div>
                <span className="shrink-0 text-xs font-bold tabular-nums text-slate-300" aria-hidden="true">
                    {String(index).padStart(2, '0')}
                </span>
            </div>
            <div className="flex flex-1 flex-col px-6 py-5">
                {description ? <p className="text-sm leading-relaxed text-slate-600">{description}</p> : null}
                <ul className="mt-4 space-y-2.5">
                    {items.map((item) => (
                        <li key={item} className="flex items-start gap-2.5 text-sm text-slate-600">
                            <Icon name="check" className="mt-0.5 h-4 w-4 shrink-0 text-brand-600" strokeWidth={2.5} />
                            {item}
                        </li>
                    ))}
                </ul>
            </div>
        </article>
    );
}

/* --------------------------- Notice / info list --------------------------- */

export function NoticeList({ items }) {
    return (
        <ul className="grid gap-px border border-slate-200 bg-slate-200 sm:grid-cols-2 lg:grid-cols-4">
            {items.map((item) => (
                <li key={item.title} className="flex flex-col gap-2 bg-white p-6">
                    <span className="flex h-9 w-9 items-center justify-center rounded-md bg-slate-100 text-slate-600" aria-hidden="true">
                        <Icon name={item.icon} className="h-4.5 w-4.5" />
                    </span>
                    <h3 className="mt-1 text-sm font-bold text-slate-900">{item.title}</h3>
                    <p className="text-sm leading-relaxed text-slate-600">{item.description}</p>
                    {item.to ? (
                        <Link to={item.to} className="mt-auto inline-flex items-center gap-1 pt-1 text-sm font-semibold text-brand-700 hover:text-brand-800">
                            {item.cta ?? 'Selengkapnya'}
                            <Icon name="arrowRight" className="h-3.5 w-3.5" />
                        </Link>
                    ) : null}
                </li>
            ))}
        </ul>
    );
}

/* --------------------------------- FAQ ------------------------------------ */

export function FAQItem({ question, answer }) {
    const [open, setOpen] = useState(false);
    const itemId = `faq-${question.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`;

    return (
        <div>
            <h3>
                <button
                    type="button"
                    onClick={() => setOpen((v) => !v)}
                    aria-expanded={open}
                    aria-controls={`${itemId}-panel`}
                    id={`${itemId}-button`}
                    className="flex w-full items-start justify-between gap-4 px-5 py-4 text-left text-sm font-semibold text-slate-900 transition hover:text-brand-700 sm:text-[15px]"
                >
                    {question}
                    <span
                        className={cx(
                            'mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-md border transition',
                            open ? 'rotate-180 border-brand-200 bg-brand-50 text-brand-700' : 'border-slate-300 text-slate-400',
                        )}
                        aria-hidden="true"
                    >
                        <Icon name="chevronDown" className="h-4 w-4" />
                    </span>
                </button>
            </h3>
            <div id={`${itemId}-panel`} role="region" aria-labelledby={`${itemId}-button`} hidden={!open} className="px-5 pb-5">
                <p className="text-sm leading-relaxed text-slate-600">{answer}</p>
            </div>
        </div>
    );
}

/* ------------------------------ Panel links -------------------------------- */

export function PanelLink({ icon, title, description, to, href, cta }) {
    const inner = (
        <>
            <span
                className="flex h-10 w-10 shrink-0 items-center justify-center rounded-md border border-slate-200 bg-slate-50 text-brand-700"
                aria-hidden="true"
            >
                <Icon name={icon} className="h-5 w-5" />
            </span>
            <div className="min-w-0 flex-1">
                <h3 className="font-bold text-slate-900">{title}</h3>
                {description ? <p className="mt-0.5 text-sm leading-relaxed text-slate-600">{description}</p> : null}
            </div>
            <Icon name="chevronRight" className="h-4 w-4 shrink-0 text-slate-300" />
        </>
    );
    const className = 'group flex w-full items-center gap-4 border-b border-slate-200 bg-white px-5 py-4 text-left transition last:border-b-0 hover:bg-slate-50';

    if (to) {
        return (
            <li>
                <Link to={to} className={className}>
                    {inner}
                </Link>
            </li>
        );
    }
    return (
        <li>
            <a href={href} className={className} {...(href ? { target: '_blank', rel: 'noreferrer' } : {})}>
                {inner}
            </a>
        </li>
    );
}

/* ------------------------------ CTA button --------------------------------- */

export function CTAButton({ to, children, icon }) {
    return (
        <Link to={to} className="inline-flex items-center justify-center gap-2 rounded-md bg-brand-600 px-6 py-3 text-base font-semibold text-white shadow-sm transition hover:bg-brand-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 dark:text-gray-50">
            {icon ? <Icon name={icon} className="h-4 w-4" /> : null}
            {children}
        </Link>
    );
}