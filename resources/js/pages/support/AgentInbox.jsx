import { useCallback, useEffect, useState } from 'react';
import api from '../../api';
import { useAuth } from '../../auth';
import ChatThread from '../../components/ChatThread';
import { Avatar, Badge, Button, cx, EmptyState, Icon, PageHeader, PageLoader, timeAgo, useToast } from '../../components/ui';

export default function AgentInbox() {
    const { user } = useAuth();
    const toast = useToast();
    const [conversations, setConversations] = useState([]);
    const [activeId, setActiveId] = useState(null);
    const [messages, setMessages] = useState([]);
    const [loading, setLoading] = useState(true);

    const loadConversations = useCallback(async () => {
        try {
            const { data } = await api.get('/api/support/agent/conversations');
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

    const assignToMe = async () => {
        await api.put(`/api/support/conversations/${activeId}/assign`);
        toast('You are now handling this conversation.', 'success');
        setConversations((prev) => prev.map((c) => (c.id === activeId ? { ...c, agent: { id: user.id, name: user.name, avatar_url: user.avatar_url } } : c)));
        loadConversations();
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

    const active = conversations.find((c) => c.id === activeId) ?? null;

    if (loading) return <PageLoader label="Loading support inbox…" />;

    return (
        <div className="space-y-6">
            <PageHeader icon="messageCircle" title="Support inbox" subtitle="Conversations from students, newest activity first." />

            {conversations.length === 0 ? (
                <EmptyState icon="messageCircle" title="No conversations" message="New conversations from students will appear here." />
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
                                    <Avatar src={conversation.user?.avatar_url} name={conversation.user?.name ?? 'Student'} size="h-9 w-9" />
                                    <span className="min-w-0 flex-1">
                                        <span className="flex items-center gap-2">
                                            <span className={cx('truncate text-sm font-semibold', conversation.status === 'open' ? 'text-slate-900' : 'text-slate-400')}>
                                                {conversation.subject}
                                            </span>
                                            {conversation.unread > 0 ? (
                                                <span className="flex h-5 min-w-5 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white">
                                                    {conversation.unread}
                                                </span>
                                            ) : null}
                                        </span>
                                        <span className="block truncate text-xs text-slate-500">
                                            {conversation.user?.name} · {timeAgo(conversation.last_message_at)}
                                        </span>
                                        <span className="block truncate text-xs text-slate-400">
                                            {conversation.agent ? conversation.agent.name : 'Unassigned'}
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
                                    <Avatar src={active.user?.avatar_url} name={active.user?.name ?? 'Student'} size="h-9 w-9" />
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-semibold text-slate-900">{active.subject}</p>
                                        <p className="truncate text-xs text-slate-500">{active.user?.name}</p>
                                    </div>
                                    {!active.agent ? (
                                        <Button variant="secondary" icon="user" onClick={assignToMe}>
                                            Assign to me
                                        </Button>
                                    ) : (
                                        <Badge color="green">{active.agent.name}</Badge>
                                    )}
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
                                    <p className="mt-3 text-sm font-medium text-slate-500">Select a conversation to reply</p>
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}