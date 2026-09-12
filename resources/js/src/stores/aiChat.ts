import { defineStore } from 'pinia';
import axios from 'axios';

// ── Types ──────────────────────────────────────────────────────────────────

export interface AiChatToolCall {
    name: string;
    arguments: Record<string, any>;
    result: Record<string, any>;
}

export interface AiChatMessage {
    id: number;
    session_id: number;
    role: 'user' | 'assistant' | 'system';
    content: string | null;
    tool_calls: AiChatToolCall[] | null;
    created_at: string;
    // Client-only flag for a message bubble that failed to send / get a reply.
    failed?: boolean;
}

export interface AiChatSession {
    id: number;
    user_id: number;
    title: string | null;
    created_at: string;
    updated_at: string;
    messages?: AiChatMessage[];
}

interface AiChatState {
    sessions: AiChatSession[];
    currentSession: AiChatSession | null;
    messages: AiChatMessage[];
    loading: boolean;
    sending: boolean;
    error: string | null;
}

// ── Store ──────────────────────────────────────────────────────────────────

export const useAiChatStore = defineStore('aiChat', {
    state: (): AiChatState => ({
        sessions: [],
        currentSession: null,
        messages: [],
        loading: false,
        sending: false,
        error: null,
    }),

    actions: {
        async fetchSessions(): Promise<void> {
            this.loading = true;
            this.error = null;
            try {
                const { data } = await axios.get('/api/ai-chat/sessions');
                this.sessions = data.data ?? data;
            } catch (err: any) {
                this.error = err.response?.data?.message ?? 'Failed to load conversations.';
            } finally {
                this.loading = false;
            }
        },

        async createSession(title?: string): Promise<AiChatSession> {
            const { data } = await axios.post('/api/ai-chat/sessions', title ? { title } : {});
            this.sessions.unshift(data);
            this.currentSession = data;
            this.messages = [];
            return data;
        },

        async loadSession(id: number): Promise<void> {
            this.loading = true;
            this.error = null;
            try {
                const { data } = await axios.get(`/api/ai-chat/sessions/${id}`);
                this.currentSession = data;
                this.messages = data.messages ?? [];
            } catch (err: any) {
                this.error = err.response?.data?.message ?? 'Failed to load conversation.';
            } finally {
                this.loading = false;
            }
        },

        async sendMessage(sessionId: number, text: string): Promise<void> {
            this.error = null;

            // Optimistically show the user's message immediately.
            const optimisticUserMessage: AiChatMessage = {
                id: Date.now(),
                session_id: sessionId,
                role: 'user',
                content: text,
                tool_calls: null,
                created_at: new Date().toISOString(),
            };
            this.messages.push(optimisticUserMessage);

            this.sending = true;
            try {
                const { data } = await axios.post(`/api/ai-chat/sessions/${sessionId}/messages`, { message: text });
                this.messages.push(data as AiChatMessage);

                // The backend titles a session from its first question — reflect
                // that in the sidebar without a separate fetch.
                const title = (data as any).session_title as string | undefined;
                if (title) {
                    if (this.currentSession?.id === sessionId) {
                        this.currentSession.title = title;
                    }
                    const listed = this.sessions.find((s) => s.id === sessionId);
                    if (listed) listed.title = title;
                }
            } catch (err: any) {
                this.error = err.response?.data?.message ?? 'Failed to send message. Please try again.';
                this.messages.push({
                    id: Date.now() + 1,
                    session_id: sessionId,
                    role: 'assistant',
                    content: this.error,
                    tool_calls: null,
                    created_at: new Date().toISOString(),
                    failed: true,
                });
            } finally {
                this.sending = false;
            }
        },

        async deleteSession(id: number): Promise<void> {
            // Deliberately does NOT clear currentSession/messages here, even when
            // deleting the active session — the caller swaps straight to the next
            // session (loadSession/createSession), and clearing first would flash
            // the empty "start a new conversation" screen for a frame in between.
            try {
                await axios.delete(`/api/ai-chat/sessions/${id}`);
                this.sessions = this.sessions.filter((s) => s.id !== id);
            } catch (err: any) {
                this.error = err.response?.data?.message ?? 'Failed to delete conversation.';
                throw err;
            }
        },
    },
});
