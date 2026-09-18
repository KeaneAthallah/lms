import { useCallback, useEffect, useState } from 'react';
import api from '../api';
import { useAuth } from '../auth';
import ChatThread from './ChatThread';
import { Avatar, Badge, Button, cx, Field, Icon, Input, Textarea, timeAgo, useToast } from './ui';

export default function ChatWidget() {
    const { user } = useAuth();
    const toast = useToast();
    const [open, setOpen] = useState(false);
    const [conversations, setConversations] = useState([]);
    const [activeId, setActiveId] = useState(null);
    const [messages, setMessages] = useState([]);
    const [view, setView] = useState('list');
    const [subject, setSubject] = useState('');
    const [composeBody, setComposeBody] = useState('');
    const [composeError, setComposeError] = useState('');
    const [loading, setLoading] = useState(true);

    const loadConversations = useCallback(async () => {
        try {
            const { data } = await api.get('/api/support/conversations');
            setConversations(data.conversations);
        } catch {
            /* keep last data */
        }
    }, []);

    useEffect(() => {
        loadConversations().finally(() => setLoading(false));
    }, [loadConversations]);

    useEffect(() => {
        const timer = window.setInterval(loadConversations, 8000);
        return () => window.clearInterval(timer);
    }, [loadConversations]);

    const openConversation = useCallback(
        async (id) => {
            setActiveId(id);
            setMessages([]);
            setView('thread');
            try {
                const { data } = await api.get(`/api/support/conversations/${id}`);
                setMessages(data.messages);
                setConversations((prev) => prev.map((c) => (c.id === id ? data.conversation : c)));
                loadConversations();
            } catch {
                toast('Could not open this conversation.', 'error');
            }
        },
        [loadConversations, toast],
    );

    useEffect(() => {
        if (!open || !activeId) return undefined;
        const timer = window.setInterval(async () => {
            try {
                const { data } = await api.get(`/api/support/conversations/${activeId}`);
                setMessages(data.messages);
                loadConversations();
            } catch {
                /* ignore */
            }
        }, 8000);
        return () => window.clearInterval(timer);
    }, [open, activeId, loadConversations]);

    const sendMessage = async (body) => {
        if (!activeId) return;
        try {
            const { data } = await api.post(`/api/support/conversations/${activeId}/messages`, { message: body });
            setMessages((prev) => [...prev, data.message]);
            loadConversations();
        } catch (error) {
            toast(error.response?.data?.message ?? 'Could not send your message.', 'error');
        }
    };

    const closeConversation = async () => {
        await api.put(`/api/support/conversations/${activeId}/close`);
        toast('Conversation closed.', 'success');
        loadConversations();
    };

    const reopenConversation = async () => {
        await api.put(`/api/support/conversations/${activeId}/reopen`);
        toast('Conversation reopened.', 'success');
        loadConversations();
    };

    const createConversation = async () => {
        if (!subject.trim() || !composeBody.trim()) {
            setComposeError('Please fill in a subject and a message.');
            return;
        }
        try {
            const { data } = await api.post('/api/support/conversations', {
                subject: subject.trim(),
                message: composeBody.trim(),
            });
            setSubject('');
            setComposeBody('');
            setComposeError('');
            setConversations((prev) => [data.conversation, ...prev]);
            openConversation(data.conversation.id);
            toast('Conversation opened.', 'success');
        } catch (error) {
            setComposeError(error.response?.data?.message ?? 'Could not open a conversation.');
        }
    };

    const close = () => {
        setOpen(false);
        setView('list');
        setActiveId(null);
        setMessages([]);
    };

    const unreadTotal = conversations.reduce((sum, c) => sum + (c.unread ?? 0), 0);
    const active = conversations.find((c) => c.id === activeId) ?? null;

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                className="fixed bottom-4 right-4 z-40 flex h-14 w-14 items-center justify-center rounded-full bg-brand-600 text-white shadow-lift transition hover:bg-brand-700"
                aria-label={open ? 'Close customer support chat' : 'Open customer support chat'}
            >
                <Icon name={open ? 'x' : 'messageCircle'} className="h-6 w-6" strokeWidth={2} />
                {!open && unreadTotal > 0 ? (
                    <span className="absolute -right-0.5 -top-0.5 flex h-5 min-w-5 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white ring-2 ring-white">
                        {unreadTotal > 9 ? '9+' : unreadTotal}
                    </span>
                ) : null}
            </button>

            {open ? (
                <div
                    role="dialog"
                    aria-label="Customer support chat"
                    className="fixed bottom-20 right-4 z-40 flex h-[30rem] w-[calc(100vw-2rem)] max-w-sm flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-lift dark:border-slate-700 dark:bg-slate-900"
                >
                    <div className="flex items-center gap-3 border-b border-slate-200 bg-brand-600 px-4 py-3 text-white dark:border-slate-700">
                        <span className="flex h-9 w-9 items-center justify-center rounded-full bg-white/15">
                            <Icon name="messageCircle" className="h-5 w-5" />
                        </span>
                        <div className="min-w-0 flex-1">
                            <p className="truncate text-sm font-semibold">Customer support</p>
                            <p className="truncate text-xs text-brand-100">We usually reply in a few minutes</p>
                        </div>
                        <button
                            type="button"
                            onClick={close}
                            className="rounded-lg p-1.5 text-brand-100 transition hover:bg-white/10"
                            aria-label="Close chat"
                        >
                            <Icon name="x" className="h-5 w-5" />
                        </button>
                    </div>

                    {loading ? (
                        <div className="flex flex-1 items-center justify-center text-sm text-slate-400">Loading…</div>
                    ) : view === 'thread' && active ? (
                        <>
                            <div className="flex items-center gap-3 border-b border-slate-200 bg-white px-4 py-2.5 dark:border-slate-700 dark:bg-slate-800">
                                <button
                                    type="button"
                                    onClick={() => setView('list')}
                                    className="rounded-lg p-1.5 text-slate-500 transition hover:bg-slate-100 dark:hover:bg-slate-700"
                                    aria-label="Back to conversations"
                                >
                                    <Icon name="arrowLeft" className="h-5 w-5" />
                                </button>
                                <Avatar src={active.agent?.avatar_url} name={active.agent?.name ?? 'CS'} size="h-8 w-8" />
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-semibold text-slate-900 dark:text-slate-100">{active.subject}</p>
                                    <p className="truncate text-xs text-slate-500">
                                        {active.agent ? active.agent.name : 'Awaiting an agent'}
                                    </p>
                                </div>
                                <Badge color={active.status === 'open' ? 'blue' : 'slate'}>{active.status}</Badge>
                            </div>
                            <div className="min-h-0 flex-1">
                                <ChatThread messages={messages} user={user} onSend={sendMessage} />
                            </div>
                            <div className="flex items-center justify-center gap-2 border-t border-slate-200 bg-white p-2 dark:border-slate-700 dark:bg-slate-800">
                                {active.status === 'open' ? (
                                    <Button variant="secondary" size="sm" icon="x" onClick={closeConversation}>
                                        Close conversation
                                    </Button>
                                ) : (
                                    <Button variant="secondary" size="sm" icon="refresh" onClick={reopenConversation}>
                                        Reopen conversation
                                    </Button>
                                )}
                            </div>
                        </>
                    ) : view === 'compose' ? (
                        <>
                            <div className="flex items-center gap-3 border-b border-slate-200 bg-white px-4 py-2.5 dark:border-slate-700 dark:bg-slate-800">
                                <button
                                    type="button"
                                    onClick={() => setView('list')}
                                    className="rounded-lg p-1.5 text-slate-500 transition hover:bg-slate-100 dark:hover:bg-slate-700"
                                    aria-label="Back"
                                >
                                    <Icon name="arrowLeft" className="h-5 w-5" />
                                </button>
                                <p className="text-sm font-semibold text-slate-900 dark:text-slate-100">New conversation</p>
                            </div>
                            <div className="flex-1 space-y-4 overflow-y-auto p-4 scrollbar-slim">
                                <Field label="Subject" required>
                                    <Input value={subject} onChange={(e) => setSubject(e.target.value)} placeholder="What do you need help with?" />
                                </Field>
                                <Field label="Message" required>
                                    <Textarea value={composeBody} onChange={(e) => setComposeBody(e.target.value)} rows={5} placeholder="Describe the issue or question…" />
                                </Field>
                                {composeError ? <p className="text-sm text-red-600">{composeError}</p> : null}
                            </div>
                            <div className="border-t border-slate-200 p-3 dark:border-slate-700">
                                <Button className="w-full" icon="plus" onClick={createConversation}>
                                    Open conversation
                                </Button>
                            </div>
                        </>
                    ) : (
                        <>
                            <div className="flex-1 overflow-y-auto p-3 scrollbar-slim">
                                {conversations.length === 0 ? (
                                    <div className="flex h-full flex-col items-center justify-center gap-2 text-center">
                                        <Icon name="messageCircle" className="h-10 w-10 text-slate-300 dark:text-slate-600" />
                                        <p className="text-sm font-medium text-slate-600 dark:text-slate-300">No conversations yet</p>
                                        <p className="text-xs text-slate-400">Start a conversation and our team will help you out.</p>
                                    </div>
                                ) : (
                                    <div className="space-y-1">
                                        {conversations.map((conversation) => (
                                            <button
                                                key={conversation.id}
                                                type="button"
                                                onClick={() => openConversation(conversation.id)}
                                                className={cx(
                                                    'flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left transition',
                                                    'hover:bg-slate-50 dark:hover:bg-slate-800',
                                                )}
                                            >
                                                <Avatar src={conversation.agent?.avatar_url} name={conversation.agent?.name ?? 'CS'} size="h-9 w-9" />
                                                <span className="min-w-0 flex-1">
                                                    <span className="flex items-center gap-2">
                                                        <span className="truncate text-sm font-semibold text-slate-900 dark:text-slate-100">
                                                            {conversation.subject}
                                                        </span>
                                                        {conversation.unread > 0 ? (
                                                            <span className="flex h-5 min-w-5 items-center justify-center rounded-full bg-brand-600 px-1 text-[10px] font-bold text-white">
                                                                {conversation.unread}
                                                            </span>
                                                        ) : null}
                                                    </span>
                                                    <span className="block truncate text-xs text-slate-500">
                                                        {conversation.agent ? conversation.agent.name : 'Awaiting an agent'}
                                                        {' · '}
                                                        {timeAgo(conversation.last_message_at)}
                                                    </span>
                                                </span>
                                                <Badge color={conversation.status === 'open' ? 'blue' : 'slate'}>{conversation.status}</Badge>
                                            </button>
                                        ))}
                                    </div>
                                )}
                            </div>
                            <div className="border-t border-slate-200 p-3 dark:border-slate-700">
                                <Button className="w-full" icon="plus" onClick={() => setView('compose')}>
                                    New conversation
                                </Button>
                            </div>
                        </>
                    )}
                </div>
            ) : null}
        </>
    );
}