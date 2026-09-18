import { useEffect, useRef, useState } from 'react';
import { Avatar, Button, cx, formatDateTime } from './ui';

export default function ChatThread({ messages = [], user, onSend, placeholder = 'Write a message…', canSend = true }) {
    const scrollRef = useRef(null);
    const [draft, setDraft] = useState('');

    useEffect(() => {
        const el = scrollRef.current;
        if (el) el.scrollTop = el.scrollHeight;
    }, [messages]);

    const submit = () => {
        const body = draft.trim();
        if (!body) return;
        onSend(body);
        setDraft('');
    };

    return (
        <div className="flex h-full flex-col">
            <div ref={scrollRef} className="flex-1 space-y-4 overflow-y-auto px-4 py-4 scrollbar-slim">
                {messages.length === 0 ? (
                    <p className="py-8 text-center text-sm text-slate-400">No messages yet. Say hello!</p>
                ) : (
                    messages.map((message) => {
                        const mine = message.sender?.id === user.id;
                        return (
                            <div key={message.id} className={cx('flex items-end gap-2', mine ? 'flex-row-reverse' : '')}>
                                <Avatar src={message.sender?.avatar_url} name={message.sender?.name ?? '?'} size="h-7 w-7" />
                                <div
                                    className={cx(
                                        'max-w-[78%] rounded-2xl px-4 py-2.5 shadow-sm',
                                        mine
                                            ? 'rounded-br-md bg-brand-600 text-white'
                                            : 'rounded-bl-md bg-white ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-700',
                                    )}
                                >
                                    <p className={cx('mb-0.5 text-xs font-semibold', mine ? 'text-brand-100' : 'text-brand-700')}>
                                        {mine ? 'You' : message.sender?.name ?? 'Agent'}
                                    </p>
                                    <p className={cx('whitespace-pre-wrap text-sm leading-relaxed', mine ? 'text-white dark:text-gray-50' : 'text-slate-700 dark:text-slate-200')}>
                                        {message.body}
                                    </p>
                                    <p className={cx('mt-1 text-[10px]', mine ? 'text-brand-100/90' : 'text-slate-400')}>{formatDateTime(message.created_at)}</p>
                                </div>
                            </div>
                        );
                    })
                )}
            </div>

            <div className="border-t border-slate-200 p-3 dark:border-slate-700">
                <div className="flex items-end gap-2">
                    <textarea
                        value={draft}
                        onChange={(e) => setDraft(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter' && !e.shiftKey) {
                                e.preventDefault();
                                if (canSend) submit();
                            }
                        }}
                        rows={2}
                        className="min-h-0 flex-1 resize-none rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm outline-none transition focus:border-brand-500 focus:ring-2 focus:ring-brand-100 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100"
                        placeholder={placeholder}
                    />
                    <Button onClick={submit} disabled={!draft.trim() || !canSend} className="shrink-0">
                        Send
                    </Button>
                </div>
            </div>
        </div>
    );
}