import { useState } from 'react';
import { Link } from 'react-router-dom';
import { categoryIcon, Icon } from './icons';
import { ButtonLink, cx } from './ui';

/* --------------------------- Section heading ------------------------------ */

export function SectionHeading({ eyebrow, title, description, align = 'left', className, id }) {
    return (
        <div className={cx('max-w-3xl', align === 'center' && 'mx-auto text-center', className)}>
            {eyebrow ? (
                <p className="flex items-center gap-3 text-xs font-bold uppercase tracking-[0.18em] text-brand-700">
                    {eyebrow}
                    <span className="h-px w-8 bg-brand-300" aria-hidden="true" />
                </p>
            ) : null}
            <h2 id={id} className="mt-3 text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">
                {title}
            </h2>
            {description ? <p className="mt-3 text-base leading-relaxed text-slate-600">{description}</p> : null}
        </div>
    );
}

/* --------------------------- Intro / info strip ---------------------------- */

export function IntroStrip({ eyebrow, title, description, items }) {
    return (
        <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-card">
            <div className="grid lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
                <div className="border-b border-slate-200 bg-slate-50 px-6 py-8 sm:px-8 lg:border-b-0 lg:border-r">
                    <SectionHeading eyebrow={eyebrow ?? 'Informasi'} title={title} />
                </div>
                <div className="flex flex-col justify-center gap-4 px-6 py-8 sm:px-8">
                    <p className="text-sm leading-relaxed text-slate-600 sm:text-[15px]">{description}</p>
                    {items?.length ? (
                        <ul className="grid gap-x-8 gap-y-2 sm:grid-cols-2">
                            {items.map((item) => (
                                <li key={item} className="flex items-start gap-2 text-sm text-slate-700">
                                    <Icon name="check" className="mt-0.5 h-4 w-4 shrink-0 text-brand-600" strokeWidth={2.5} />
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

/* ------------------------------ Services ---------------------------------- */

export function ServiceCell({ number, icon, title, description }) {
    return (
        <article className="border-b border-slate-200 bg-white p-6 lg:border-b-0">
            <div className="flex items-start justify-between gap-3">
                <span className="flex h-10 w-10 items-center justify-center rounded-lg border border-brand-100 bg-brand-50 text-brand-700" aria-hidden="true">
                    <Icon name={icon} className="h-5 w-5" />
                </span>
                <span className="text-2xl font-bold leading-none text-slate-200" aria-hidden="true">
                    {String(number).padStart(2, '0')}
                </span>
            </div>
            <h3 className="mt-4 font-semibold text-slate-900">{title}</h3>
            <p className="mt-1.5 text-sm leading-relaxed text-slate-600">{description}</p>
        </article>
    );
}

export function ServicesGrid({ services }) {
    return (
        <div className="grid gap-px overflow-hidden rounded-lg border border-slate-200 bg-slate-200 shadow-card sm:grid-cols-2 lg:grid-cols-3">
            {services.map((service) => (
                <ServiceCell key={service.number} {...service} />
            ))}
        </div>
    );
}

/* ------------------------------ Benefit strip ------------------------------ */

export function BenefitStrip({ benefits }) {
    return (
        <div className="grid overflow-hidden rounded-lg border border-slate-200 bg-white shadow-card sm:grid-cols-2 lg:grid-cols-4 lg:divide-x lg:divide-slate-200 sm:divide-y sm:divide-slate-200 lg:divide-y-0">
            {benefits.map((benefit) => (
                <div key={benefit.title} className="flex flex-col gap-1.5 p-5">
                    <p className="flex items-center gap-2 text-sm font-bold text-slate-900">
                        <Icon name="check" className="h-4 w-4 shrink-0 text-brand-600" strokeWidth={2.5} />
                        {benefit.title}
                    </p>
                    <p className="text-sm leading-relaxed text-slate-600">{benefit.description}</p>
                </div>
            ))}
        </div>
    );
}

/* ---------------------------- Category directory --------------------------- */

export function CategoryDirectory({ categories }) {
    return (
        <ul className="grid gap-px overflow-hidden rounded-lg border border-slate-200 bg-slate-200 shadow-card sm:grid-cols-2">
            {categories.map((category) => {
                const count = category.courses_count ?? 0;
                return (
                    <li key={category.id}>
                        <Link
                            to={`/browse?category=${category.slug}`}
                            className="group flex h-full items-center gap-4 bg-white px-5 py-5 transition hover:bg-brand-50/40"
                        >
                            <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-brand-100 bg-brand-50 text-brand-700" aria-hidden="true">
                                <Icon name={category.icon || categoryIcon(category.slug)} className="h-5 w-5" />
                            </span>
                            <span className="min-w-0 flex-1">
                                <span className="block truncate font-semibold text-slate-900 group-hover:text-brand-700">
                                    {category.name}
                                </span>
                                {category.description ? (
                                    <span className="mt-0.5 block line-clamp-1 text-xs text-slate-500">{category.description}</span>
                                ) : null}
                                <span className="mt-1 block text-xs font-semibold text-brand-700">{count} pembelajaran</span>
                            </span>
                            <Icon name="chevronRight" className="h-4 w-4 shrink-0 text-slate-300 group-hover:text-brand-500" />
                        </Link>
                    </li>
                );
            })}
        </ul>
    );
}

/* ------------------------------- Process ---------------------------------- */

export function ProcessSteps({ steps }) {
    return (
        <ol className="grid gap-px overflow-hidden rounded-lg border border-slate-200 bg-slate-200 shadow-card sm:grid-cols-2 lg:grid-cols-3">
            {steps.map((step, i) => (
                <li key={step.title} className="flex gap-4 bg-white p-5">
                    <span
                        className="flex h-9 min-w-9 items-center justify-center rounded-md border border-brand-200 bg-brand-50 px-1 text-sm font-bold text-brand-700"
                        aria-hidden="true"
                    >
                        {String(step.number ?? i + 1).padStart(2, '0')}
                    </span>
                    <div>
                        <h3 className="text-sm font-bold text-slate-900">{step.title}</h3>
                        <p className="mt-1 text-sm leading-relaxed text-slate-600">{step.description}</p>
                    </div>
                </li>
            ))}
        </ol>
    );
}

/* -------------------------- Resource & info lists ------------------------- */

export function ResourceRow({ icon, title, description }) {
    return (
        <li className="flex items-start gap-4 border-b border-slate-200 bg-white px-5 py-4 last:border-b-0 sm:items-center">
            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-brand-100 bg-brand-50 text-brand-700" aria-hidden="true">
                <Icon name={icon} className="h-4.5 w-4.5" />
            </span>
            <div className="min-w-0 flex-1">
                <h3 className="text-sm font-bold text-slate-900">{title}</h3>
                <p className="mt-0.5 text-sm leading-relaxed text-slate-600">{description}</p>
            </div>
        </li>
    );
}

export function ResourceList({ items }) {
    return <ul className="divide-y-0 overflow-hidden rounded-lg border border-slate-200 shadow-card">{items.map((item) => <ResourceRow key={item.title} {...item} />)}</ul>;
}

/* ---------------------------- Role directory ------------------------------- */

export function RolePanel({ icon, title, eyebrow, items, accent }) {
    return (
        <article className="flex flex-col rounded-lg border border-slate-200 bg-white p-6 shadow-card">
            <div className="flex items-center gap-3">
                <span className={cx('flex h-10 w-10 items-center justify-center rounded-md', accent ?? 'bg-brand-50 text-brand-700')} aria-hidden="true">
                    <Icon name={icon} className="h-5 w-5" />
                </span>
                <div>
                    {eyebrow ? <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">{eyebrow}</p> : null}
                    <h3 className="font-bold text-slate-900">{title}</h3>
                </div>
            </div>
            <ul className="mt-4 space-y-2.5">
                {items.map((item) => (
                    <li key={item} className="flex items-start gap-2 text-sm text-slate-600">
                        <Icon name="check" className="mt-0.5 h-4 w-4 shrink-0 text-brand-600" strokeWidth={2.5} />
                        {item}
                    </li>
                ))}
            </ul>
        </article>
    );
}

/* --------------------------- Notice / info list --------------------------- */

export function NoticeList({ items }) {
    return (
        <ul className="grid gap-px overflow-hidden rounded-lg border border-slate-200 bg-slate-200 shadow-card sm:grid-cols-3">
            {items.map((item) => (
                <li key={item.title} className="flex flex-col gap-2 bg-white p-5">
                    <span className="flex h-9 w-9 items-center justify-center rounded-md bg-slate-100 text-slate-600" aria-hidden="true">
                        <Icon name={item.icon} className="h-4.5 w-4.5" />
                    </span>
                    <h3 className="text-sm font-bold text-slate-900">{item.title}</h3>
                    <p className="text-sm leading-relaxed text-slate-600">{item.description}</p>
                </li>
            ))}
        </ul>
    );
}

/* --------------------------------- FAQ ----------------------------------- */

export function FAQItem({ question, answer }) {
    const [open, setOpen] = useState(false);
    const itemId = `faq-${question.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`;

    return (
        <div className="rounded-lg border border-slate-200 bg-white shadow-card">
            <h3>
                <button
                    type="button"
                    onClick={() => setOpen((v) => !v)}
                    aria-expanded={open}
                    aria-controls={`${itemId}-panel`}
                    id={`${itemId}-button`}
                    className="flex w-full items-center justify-between gap-4 px-5 py-4 text-left font-semibold text-slate-900 transition hover:text-brand-700"
                >
                    {question}
                    <span
                        className={cx(
                            'flex h-7 w-7 shrink-0 items-center justify-center rounded-md border border-slate-200 text-slate-500 transition',
                            open ? 'rotate-180 border-brand-200 bg-brand-50 text-brand-700' : '',
                        )}
                        aria-hidden="true"
                    >
                        <Icon name="chevronDown" className="h-4 w-4" />
                    </span>
                </button>
            </h3>
            <div id={`${itemId}-panel`} role="region" aria-labelledby={`${itemId}-button`} hidden={!open} className="border-t border-slate-100 px-5 py-4">
                <p className="text-sm leading-relaxed text-slate-600">{answer}</p>
            </div>
        </div>
    );
}

/* ------------------------------ Panel links -------------------------------- */

export function PanelLink({ icon, title, description, to, href, cta }) {
    const inner = (
        <>
            <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-md border border-brand-100 bg-brand-50 text-brand-700" aria-hidden="true">
                <Icon name={icon} className="h-5 w-5" />
            </span>
            <div className="min-w-0 flex-1">
                <h3 className="font-bold text-slate-900">{title}</h3>
                {description ? <p className="mt-0.5 text-sm leading-relaxed text-slate-600">{description}</p> : null}
            </div>
            <Icon name="chevronRight" className="h-4 w-4 shrink-0 text-slate-300 transition group-hover:text-brand-500" />
        </>
    );
    const className = 'group flex w-full items-center gap-4 border-b border-slate-200 bg-white px-5 py-4 text-left transition last:border-b-0 hover:bg-brand-50/40';

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

/* ------------------------------ CTA band ---------------------------------- */

export function CTAButton({ to, children, icon }) {
    return (
        <ButtonLink to={to} variant="primary" className="px-6 py-3 text-base" icon={icon}>
            {children}
        </ButtonLink>
    );
}