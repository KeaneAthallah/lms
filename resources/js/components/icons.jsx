export function Icon({ name, className = 'h-5 w-5', strokeWidth = 1.8 }) {
    const paths = {
        home: <><path d="M3 10.5 12 3l9 7.5" /><path d="M5 9.5V21h5v-6h4v6h5V9.5" /></>,
        book: <><path d="M4 5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2z" /><path d="M4 19a2 2 0 0 1 2-2h13" /></>,
        puzzle: <><path d="M10 4a2 2 0 1 1 4 0 2 2 0 0 1 2 2 2 2 0 0 1 0 4l5 5v6h-6l-5-5a2 2 0 0 1-4 0 2 2 0 0 1-2-2 2 2 0 0 1-2-2v-3h3a2 2 0 1 0 0-4z" /></>,
        file: <><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z" /><path d="M14 3v6h6" /></>,
        chart: <><path d="M4 20V10" /><path d="M10 20V4" /><path d="M16 20v-8" /><path d="M22 20H2" /></>,
        users: <><path d="M17 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" /><circle cx="9.5" cy="7" r="4" /><path d="M22 21v-2a4 4 0 0 0-3-3.87" /><path d="M15 3.13a4 4 0 0 1 0 7.75" /></>,
        bell: <><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9" /><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" /></>,
        check: <path d="M20 6 9 17l-5-5" />,
        checkCircle: <><circle cx="12" cy="12" r="9" /><path d="m8.5 12 2.5 2.5 5-5" /></>,
        x: <><path d="M18 6 6 18" /><path d="m6 6 12 12" /></>,
        chevronLeft: <path d="m15 18-6-6 6-6" />,
        chevronRight: <path d="m9 18 6-6-6-6" />,
        chevronDown: <path d="m6 9 6 6 6-6" />,
        menu: <><path d="M3 6h18" /><path d="M3 12h18" /><path d="M3 18h18" /></>,
        star: <path d="M12 2 15 9l7 .3-5.5 4.6 1.8 6.9-6.3-4-6.3 4 1.8-6.9L2 9.3 9 9z" />,
        clock: <><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 3" /></>,
        play: <path d="M6 4 20 12 6 20z" />,
        download: <><path d="M12 3v12" /><path d="m7 10 5 5 5-5" /><path d="M4 21h16" /></>,
        search: <><circle cx="11" cy="11" r="7" /><path d="m21 21-4.3-4.3" /></>,
        logout: <><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" /><path d="m16 17 5-5-5-5" /><path d="M21 12H9" /></>,
        settings: <><circle cx="12" cy="12" r="3" /><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.87l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.7 1.7 0 0 0-1.87-.34 1.7 1.7 0 0 0-1 1.55V21a2 2 0 1 1-4 0v-.09A1.7 1.7 0 0 0 9 19.36a1.7 1.7 0 0 0-1.87.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.7 1.7 0 0 0 .34-1.87 1.7 1.7 0 0 0-1.55-1H3a2 2 0 1 1 0-4h.09A1.7 1.7 0 0 0 4.64 9a1.7 1.7 0 0 0-.34-1.87l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.7 1.7 0 0 0 1.87.34H9a1.7 1.7 0 0 0 1-1.55V3a2 2 0 1 1 4 0v.09a1.7 1.7 0 0 0 1 1.55 1.7 1.7 0 0 0 1.87-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.7 1.7 0 0 0-.34 1.87V9a1.7 1.7 0 0 0 1.55 1H21a2 2 0 1 1 0 4h-.09a1.7 1.7 0 0 0-1.55 1z" /></>,
        plus: <><path d="M12 5v14" /><path d="M5 12h14" /></>,
        pencil: <><path d="M17 3a2.8 2.8 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5z" /></>,
        trash: <><path d="M3 6h18" /><path d="M8 6V4h8v2" /><path d="M19 6l-1 14H6L5 6" /><path d="M10 11v6" /><path d="M14 11v6" /></>,
        award: <><circle cx="12" cy="8" r="6" /><path d="M15.5 13 17 21l-5-3-5 3 1.5-8" /></>,
        arrowRight: <><path d="M5 12h14" /><path d="m12 5 7 7-7 7" /></>,
        arrowLeft: <><path d="M19 12H5" /><path d="m12 19-7-7 7-7" /></>,
        mail: <><rect x="3" y="5" width="18" height="14" rx="2" /><path d="m3 7 9 6 9-6" /></>,
        lock: <><rect x="4" y="11" width="16" height="10" rx="2" /><path d="M8 11V7a4 4 0 0 1 8 0v4" /></>,
        user: <><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" /><circle cx="12" cy="7" r="4" /></>,
        eye: <><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z" /><circle cx="12" cy="12" r="3" /></>,
        grid: <><rect x="3" y="3" width="7" height="7" rx="1" /><rect x="14" y="3" width="7" height="7" rx="1" /><rect x="14" y="14" width="7" height="7" rx="1" /><rect x="3" y="14" width="7" height="7" rx="1" /></>,
        certificate: <><circle cx="12" cy="9" r="6" /><path d="M9 14 7 21l5-2.5L17 21l-2-7" /></>,
        clipboard: <><rect x="5" y="4" width="14" height="17" rx="2" /><path d="M9 4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2" /><path d="M9 11h6" /><path d="M9 15h4" /></>,
        trophy: <><path d="M8 21h8" /><path d="M12 17v4" /><path d="M7 4h10v6a5 5 0 0 1-10 0z" /><path d="M7 6H4a3 3 0 0 0 3 5" /><path d="M17 6h3a3 3 0 0 1-3 5" /></>,
        eyeOff: <><path d="M9.9 4.2A9.1 9.1 0 0 1 12 4c6.5 0 10 8 10 8a17.9 17.9 0 0 1-2.4 3.4" /><path d="M6.6 6.6A17.6 17.6 0 0 0 2 12s3.5 8 10 8a9.4 9.4 0 0 0 5-1.4" /><path d="m2 2 20 20" /></>,
        link: <><path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7" /><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7" /></>,
        image: <><rect x="3" y="5" width="18" height="14" rx="2" /><circle cx="8.5" cy="10" r="1.5" /><path d="m21 15-5-5L5 21" /></>,
        filter: <><path d="M4 5h16" /><path d="M7 12h10" /><path d="M10 19h4" /></>,
        doc: <><path d="M9 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z" /><path d="M9 3v6h6" /></>,
        alert: <><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z" /><path d="M12 9v4" /><path d="M12 17h.01" /></>,
        info: <><circle cx="12" cy="12" r="9" /><path d="M12 16v-4" /><path d="M12 8h.01" /></>,
        video: <><path d="m22 8-6 4 6 4z" /><rect x="2" y="6" width="14" height="12" rx="2" /></>,
        heart: <path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21.2l7.8-7.8 1-1a5.5 5.5 0 0 0 0-7.8z" />,
        shield: <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />,
        upload: <><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><path d="m17 8-5-5-5 5" /><path d="M12 3v12" /></>,
        code: <><path d="m16 18 6-6-6-6" /><path d="m8 6-6 6 6 6" /></>,
        layers: <><path d="M12 2 2 7l10 5 10-5-10-5z" /><path d="m2 17 10 5 10-5" /><path d="m2 12 10 5 10-5" /></>,
        bookOpen: <><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z" /><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z" /></>,
        graduation: <><path d="M22 10 12 5 2 10l10 5z" /><path d="M6 12v5c0 1 2.5 3 6 3s6-2 6-3v-5" /><path d="M22 10v6" /></>,
        target: <><circle cx="12" cy="12" r="9" /><circle cx="12" cy="12" r="5" /><circle cx="12" cy="12" r="1" /></>,
        flask: <><path d="M10 2v6L4.7 17.5a2 2 0 0 0 1.8 3h11a2 2 0 0 0 1.8-3L14 8V2" /><path d="M8.5 2h7" /><path d="M7 15h10" /></>,
        palette: <><circle cx="13.5" cy="6.5" r=".5" /><circle cx="17.5" cy="10.5" r=".5" /><circle cx="8.5" cy="7.5" r=".5" /><circle cx="6.5" cy="12.5" r=".5" /><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.9 0 1.5-.6 1.5-1.5 0-.4-.2-.7-.4-1-.2-.2-.4-.6-.4-1 0-.9.7-1.5 1.5-1.5H16c3.3 0 6-2.7 6-6 0-4.4-4.5-8-10-8z" /></>,
        briefcase: <><rect x="2" y="7" width="20" height="14" rx="2" /><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16" /></>,
        megaphone: <><path d="m3 11 18-5v12L3 13z" /><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6" /></>,
        listChecks: <><path d="m3 17 2 2 4-4" /><path d="m3 7 2 2 4-4" /><path d="M13 6h8" /><path d="M13 12h8" /><path d="M13 18h8" /></>,
        fileText: <><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z" /><path d="M14 3v6h6" /><path d="M8 13h8" /><path d="M8 17h8" /></>,
        monitor: <><rect x="2" y="3" width="20" height="14" rx="2" /><path d="M8 21h8" /><path d="M12 17v4" /></>,
        building: <><rect x="4" y="2" width="16" height="20" rx="2" /><path d="M9 22v-4h6v4" /><path d="M8 9h.01" /><path d="M12 9h.01" /><path d="M16 9h.01" /></>,
        compass: <><circle cx="12" cy="12" r="9" /><path d="m16 8-2 6-6 2 2-6z" /></>,
        refresh: <><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.7 2.6L3 8" /><path d="M3 3v5h5" /><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.7-2.6L21 16" /><path d="M21 21v-5h-5" /></>,
        shieldCheck: <><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" /><path d="m9 12 2 2 4-4" /></>,
        badgeCheck: <><path d="M3.85 8.62a4 4 0 0 1 4.78-4.77 4 4 0 0 1 6.74 0 4 4 0 0 1 4.78 4.78 4 4 0 0 1 0 6.74 4 4 0 0 1-4.77 4.78 4 4 0 0 1-6.75 0 4 4 0 0 1-4.78-4.77 4 4 0 0 1 0-6.76z" /><path d="m9 12 2 2 4-4" /></>,
        messageCircle: <><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" /></>,
        externalLink: <><path d="M15 3h6v6" /><path d="M10 14 21 3" /><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" /></>,
        globe: <><circle cx="12" cy="12" r="9" /><path d="M3 12h18" /><path d="M12 3a15 15 0 0 1 0 18 15 15 0 0 1 0-18z" /></>,
        playCircle: <><circle cx="12" cy="12" r="9" /><path d="m10 8 6 4-6 4z" /></>,
        trendingUp: <><path d="M3 3v18h18" /><path d="m7 15 4-4 3 3 5-6" /></>,
        cpu: <><rect x="5" y="5" width="14" height="14" rx="1" /><rect x="9" y="9" width="6" height="6" /><path d="M9 2v3" /><path d="M15 2v3" /><path d="M9 19v3" /><path d="M15 19v3" /><path d="M2 9h3" /><path d="M2 15h3" /><path d="M19 9h3" /><path d="M19 15h3" /></>,
        camera: <><path d="M4 7h3l2-3h6l2 3h3a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V8a1 1 0 0 1 1-1z" /><circle cx="12" cy="14" r="3.5" /></>,
        music: <><path d="M9 18V5l12-2v13" /><circle cx="6" cy="18" r="3" /><circle cx="18" cy="16" r="3" /></>,
        tools: <><path d="M14.7 6.3a4.5 4.5 0 0 1 6 6L13 20H7l-4 4-3-3 4-4v-6z" /><path d="m9 4 2-2 6 6-2 2" /></>,
        sparkles: <><path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z" /><path d="M19 15l.9 2.1L22 18l-2.1.9L19 21l-.9-2.1L16 18l2.1-.9z" /></>,
        folder: <><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" /></>,
        barChart: <><path d="M3 20V10" /><path d="M9 20V4" /><path d="M15 20v-8" /><path d="M21 20H2" /></>,
        moreVertical: <><circle cx="12" cy="5" r="1" /><circle cx="12" cy="12" r="1" /><circle cx="12" cy="19" r="1" /></>,
    };

    return (
        <svg
            xmlns="http://www.w3.org/2000/svg"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth={strokeWidth}
            strokeLinecap="round"
            strokeLinejoin="round"
            className={className}
            aria-hidden="true"
        >
            {paths[name] ?? paths.info}
        </svg>
    );
}

const categoryIconMap = {
    'web-development': 'code',
    'ui-ux-design': 'palette',
    'data-science': 'flask',
    'business': 'briefcase',
    'marketing': 'megaphone',
};

export function categoryIcon(slug) {
    return categoryIconMap[slug] ?? 'grid';
}