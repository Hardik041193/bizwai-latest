<template>
    <div class="flex gap-5 h-[calc(100vh-150px)] min-h-[500px]">
        <!-- Sidebar: sessions list -->
        <div class="hidden md:flex flex-col w-72 flex-shrink-0 panel p-0 overflow-hidden">
            <div class="p-4 border-b border-white-dark/10">
                <button @click="startNewConversation" class="btn btn-primary w-full gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    New conversation
                </button>
            </div>
            <div class="flex-1 overflow-y-auto">
                <div v-if="chatStore.loading && !chatStore.sessions.length" class="flex justify-center py-8">
                    <span class="animate-spin border-4 border-primary border-l-transparent rounded-full w-8 h-8"></span>
                </div>
                <div v-else-if="!chatStore.sessions.length" class="text-center text-white-dark/50 text-sm py-8 px-4">
                    No conversations yet. Start one to ask about your QuickBooks data.
                </div>
                <ul v-else>
                    <li
                        v-for="session in chatStore.sessions"
                        :key="session.id"
                        class="group flex items-center gap-2 px-4 py-3 cursor-pointer border-b border-white-dark/5 hover:bg-primary/5"
                        :class="{ 'bg-primary/10': chatStore.currentSession?.id === session.id }"
                        @click="selectSession(session.id)"
                    >
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-medium truncate">{{ sessionLabel(session) }}</div>
                        </div>
                        <button
                            @click.stop="removeSession(session.id)"
                            class="opacity-0 group-hover:opacity-100 text-white-dark/50 hover:text-danger flex-shrink-0"
                            title="Delete conversation"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                            </svg>
                        </button>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Main panel -->
        <div class="flex-1 panel p-0 flex flex-col overflow-hidden">
            <!-- Header -->
            <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-white-dark/10">
                <div>
                    <h5 class="font-semibold text-base">AI Financial Assistant</h5>
                    <p class="text-xs text-white-dark/60">Ask questions about your QuickBooks data</p>
                </div>
                <button
                    v-if="chatStore.currentSession"
                    @click="clearConversation"
                    class="btn btn-outline-danger btn-sm gap-2"
                >
                    Clear conversation
                </button>
            </div>

            <!-- Messages -->
            <div ref="messageListEl" class="flex-1 overflow-y-auto px-5 py-4 space-y-4">
                <div v-if="!chatStore.currentSession" class="h-full flex items-center justify-center text-center text-white-dark/50 px-6">
                    <div>
                        <p class="mb-3">Start a new conversation to ask about your revenue, expenses, invoices, or customers.</p>
                        <button @click="startNewConversation" class="btn btn-primary btn-sm">New conversation</button>
                    </div>
                </div>

                <template v-else>
                    <div v-if="!chatStore.messages.length" class="text-center text-white-dark/50 text-sm py-8">
                        Ask me something like "What's my revenue this month?" or "Show my overdue invoices."
                    </div>

                    <div
                        v-for="message in chatStore.messages"
                        :key="message.id"
                        class="flex"
                        :class="message.role === 'user' ? 'justify-end' : 'justify-start'"
                    >
                        <div
                            class="max-w-[75%] rounded-lg px-4 py-2.5 text-sm whitespace-pre-wrap break-words"
                            :class="bubbleClass(message)"
                        >
                            {{ message.content }}
                        </div>
                    </div>

                    <!-- Typing indicator -->
                    <div v-if="chatStore.sending" class="flex justify-start">
                        <div class="rounded-lg px-4 py-2.5 bg-[#1b2e4b]/5 dark:bg-white/5">
                            <span class="flex gap-1">
                                <span class="w-1.5 h-1.5 rounded-full bg-white-dark/50 animate-pulse"></span>
                                <span class="w-1.5 h-1.5 rounded-full bg-white-dark/50 animate-pulse [animation-delay:0.15s]"></span>
                                <span class="w-1.5 h-1.5 rounded-full bg-white-dark/50 animate-pulse [animation-delay:0.3s]"></span>
                            </span>
                        </div>
                    </div>
                </template>
            </div>

            <!-- Inline error -->
            <div v-if="chatStore.error" class="px-5 py-2 text-sm text-danger bg-danger/10 border-t border-danger/20">
                {{ chatStore.error }}
            </div>

            <!-- Input bar -->
            <div class="border-t border-white-dark/10 p-4">
                <form @submit.prevent="submitMessage" class="flex items-end gap-3">
                    <textarea
                        v-model="draft"
                        @keydown.enter.exact.prevent="submitMessage"
                        rows="1"
                        placeholder="Ask about your revenue, expenses, invoices…"
                        class="form-textarea flex-1 resize-none"
                        :disabled="chatStore.sending || !chatStore.currentSession"
                    ></textarea>
                    <button
                        type="submit"
                        class="btn btn-primary gap-2 flex-shrink-0"
                        :disabled="chatStore.sending || !draft.trim() || !chatStore.currentSession"
                    >
                        Send
                    </button>
                </form>
            </div>
        </div>
    </div>
</template>

<script lang="ts" setup>
import { ref, nextTick, onMounted } from 'vue';
import { useMeta } from '@/composables/use-meta';
import { useToast } from '@/composables/use-toast';
import { useAiChatStore, type AiChatSession, type AiChatMessage } from '@/stores/aiChat';

useMeta({ title: 'AI Financial Assistant' });

const chatStore = useAiChatStore();
const { confirmDialog } = useToast();

const draft = ref('');
const messageListEl = ref<HTMLElement | null>(null);

// ChatGPT-style sidebar label: the session's title once it has one (set from
// its first question), falling back to the latest message's text for older
// sessions saved before titling existed, and only "New chat" when truly empty.
function sessionLabel(session: AiChatSession): string {
    if (session.title) return session.title;

    const last = session.messages?.[session.messages.length - 1];
    if (last?.content) {
        return last.content.length > 60 ? last.content.slice(0, 60) + '…' : last.content;
    }

    return 'New chat';
}

function bubbleClass(message: AiChatMessage): string {
    if (message.failed) {
        return 'bg-danger/10 text-danger';
    }
    return message.role === 'user'
        ? 'bg-primary text-white'
        : 'bg-[#1b2e4b]/5 dark:bg-white/5 text-black dark:text-white-dark';
}

async function scrollToBottom() {
    await nextTick();
    if (messageListEl.value) {
        messageListEl.value.scrollTop = messageListEl.value.scrollHeight;
    }
}

async function startNewConversation() {
    await chatStore.createSession();
    await scrollToBottom();
}

async function selectSession(id: number) {
    await chatStore.loadSession(id);
    await scrollToBottom();
}

async function removeSession(id: number) {
    const ok = await confirmDialog({
        title: 'Delete this conversation?',
        text: 'This cannot be undone.',
        confirmText: 'Yes, Delete',
        cancelText: 'Cancel',
    });
    if (!ok) return;

    const wasCurrent = chatStore.currentSession?.id === id;
    await chatStore.deleteSession(id);

    // Deleting the open conversation would otherwise drop into the empty
    // "start a new conversation" screen — land on another one instead, same
    // as ChatGPT falling back to the next chat in the list.
    if (wasCurrent) {
        if (chatStore.sessions.length) {
            await selectSession(chatStore.sessions[0].id);
        } else {
            await startNewConversation();
        }
    }
}

async function clearConversation() {
    if (!chatStore.currentSession) return;
    const id = chatStore.currentSession.id;
    await chatStore.deleteSession(id);
    await startNewConversation();
}

async function submitMessage() {
    const text = draft.value.trim();
    if (!text || !chatStore.currentSession || chatStore.sending) return;

    draft.value = '';
    await scrollToBottom();
    await chatStore.sendMessage(chatStore.currentSession.id, text);
    await scrollToBottom();
}

onMounted(async () => {
    await chatStore.fetchSessions();

    // Land on the most recently active conversation by default, ChatGPT-style,
    // instead of an empty "start a new conversation" screen.
    if (!chatStore.currentSession && chatStore.sessions.length) {
        await selectSession(chatStore.sessions[0].id);
    }
});
</script>
