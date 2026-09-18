import { useCallback, useEffect, useState } from 'react';
import api from '../api';
import { useAuth } from '../auth';
import ChatThread from '../components/ChatThread';
import { Avatar, Badge, Button, cx, EmptyState, Field, Icon, Input, Modal, PageHeader, PageLoader, Textarea, timeAgo, useToast } from '../components/ui';

export default function Support() {
    const { user } = useAuth();
    const toast = useToast();
    const [conversations, setConversations] = useState([]);
    const [activeId, setActiveId] = useState(null);
    const [messages, setMessages] = useState([]);
    const [loading, setLoading] = useState(true);
    const [composeOpen, setComposeOpen] = useState(false);
    const [subject, setSubject] = useState('');
    const [composeBody, setComposeBody] = useState('');
    const [composeError, setComposeError] = useState('');

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

    const openConversation = useCallback(async (id) => {
        setActiveId(id);
        setMessages([]);
        try {
            const { data } = await api.get(`/api/support/conversations/${id}`);
            setMessages(data.messages);
            setConversations((prev) => prev.map((c) => (c.id === id ? data.conversation : c)));
            loadConversations();
        } catch {
            toast('Could not open this conversation.', 'error');
        }
    }, [loadConversations, toast]);

    useEffect(() => {
        if (!activeId) return undefined;
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
    }, [activeId, loadConversations]);

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
            setComposeOpen(false);
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

    const active = conversations.find((c) => c.id === activeId) ?? null;

    if (loading) return <PageLoader label="Loading support chat…" />;

    return (
        <div className="space-y-6">
            <PageHeader
                icon="messageCircle"
                title="Support"
                subtitle="Talk to our customer service team about anything on your mind."
                actions={<Button variant="primary" icon="plus" onClick={() => setComposeOpen(true)}>New conversation</Button>}
            />

            {conversations.length === 0 ? (
                <EmptyState
                    icon="messageCircle"
                    title="No conversations yet"
                    message="Start a conversation and our customer service team will get back to you."
                    action={<Button icon="plus" onClick={() => setComposeOpen(true)}>Start a conversation</Button>}
                />
            ) : (
                <div className="grid gap-6 lg:grid-cols-[340px_1fr]">
                    <div className="order-2 h-[600px] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-2 scrollbar-slim lg:order-1">
                        {conversations.map((conversation) => {
                            const isActive = conversation.id === activeId;
                            return (
                                <button
                                    key={conversation.id}
                                    type="button"
                                    onClick={() => openConversation(conversation.id)}
                                    className={cx(
                                        'flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left transition',
                                        isActive ? 'bg-brand-50' : 'hover:bg-slate-50',
                                    )}
                                >
                                    <Avatar src={conversation.agent?.avatar_url} name={conversation.agent?.name ?? 'CS'} size="h-9 w-9" />
                                    <span className="min-w-0 flex-1">
                                        <span className="flex items-center gap-2">
                                            <span className="truncate text-sm font-semibold text-slate-900">{conversation.subject}</span>
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
                            );
                        })}
                    </div>

                    <div className="order-1 lg:order-2">
                        {active ? (
                            <div className="flex h-[600px] flex-col overflow-hidden rounded-2xl border border-slate-200 bg-slate-50 dark:bg-slate-900">
                                <div className="flex items-center gap-3 border-b border-slate-200 bg-white px-4 py-3 dark:bg-slate-800">
                                    <button
                                        type="button"
                                        onClick={() => setActiveId(null)}
                                        className="rounded-lg p-1.5 text-slate-500 transition hover:bg-slate-100 lg:hidden"
                                        aria-label="Back to conversations"
                                    >
                                        <Icon name="arrowLeft" className="h-5 w-5" />
                                    </button>
                                    <Avatar src={active.agent?.avatar_url} name={active.agent?.name ?? 'CS'} size="h-9 w-9" />
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-semibold text-slate-900">{active.subject}</p>
                                        <p className="truncate text-xs text-slate-500">{active.agent ? active.agent.name : 'Awaiting an agent'}</p>
                                    </div>
                                    <Badge color={active.status === 'open' ? 'blue' : 'slate'}>{active.status}</Badge>
                                </div>
                                <div className="min-h-0 flex-1">
                                    <ChatThread messages={messages} user={user} onSend={sendMessage} />
                                </div>
                                <div className="flex items-center justify-center gap-2 border-t border-slate-200 bg-white p-2 dark:bg-slate-800">
                                    {active.status === 'open' ? (
                                        <Button variant="secondary" icon="x" onClick={closeConversation}>
                                            Close conversation
                                        </Button>
                                    ) : (
                                        <Button variant="secondary" icon="refresh" onClick={reopenConversation}>
                                            Reopen conversation
                                        </Button>
                                    )}
                                </div>
                            </div>
                        ) : (
                            <div className="flex h-[600px] items-center justify-center rounded-2xl border border-dashed border-slate-300">
                                <div className="text-center">
                                    <Icon name="messageCircle" className="mx-auto h-10 w-10 text-slate-300" />
                                    <p className="mt-3 text-sm font-medium text-slate-500">Select a conversation to start chatting</p>
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            )}

            <Modal
                open={composeOpen}
                onClose={() => setComposeOpen(false)}
                title="New conversation"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setComposeOpen(false)}>
                            Cancel
                        </Button>
                        <Button icon="plus" onClick={createConversation}>
                            Open conversation
                        </Button>
                    </>
                }
            >
                <div className="space-y-4">
                    <Field label="Subject" required>
                        <Input value={subject} onChange={(e) => setSubject(e.target.value)} placeholder="What do you need help with?" />
                    </Field>
                    <Field label="Message" required>
                        <Textarea value={composeBody} onChange={(e) => setComposeBody(e.target.value)} rows={5} placeholder="Describe the issue or question…" />
                    </Field>
                    {composeError ? <p className="text-sm text-red-600">{composeError}</p> : null}
                </div>
            </Modal>
        </div>
    );
}