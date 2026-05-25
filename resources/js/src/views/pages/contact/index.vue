<!-- resources/js/src/views/pages/contact/index.vue -->
<template>
    <div class="p-6 lg:p-8">

        <!-- Header -->
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900 dark:text-white tracking-tight">
                Contact Us
            </h1>
            <p class="text-gray-500 dark:text-gray-400 mt-1.5 text-sm">
                We're here to help. Send us a message and we'll respond shortly.
            </p>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-[320px_1fr] gap-6 max-w-6xl">

            <!-- ── Left: Contact info cards ── -->
            <div class="flex flex-col gap-4">

                <!-- Email -->
                <div class="bg-white dark:bg-[#1b2e4b] rounded-2xl border border-gray-100 dark:border-white/10 p-6 shadow-sm">
                    <div class="w-10 h-10 rounded-xl bg-blue-50 dark:bg-blue-900/30 flex items-center justify-center mb-4">
                        <svg class="w-5 h-5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                        </svg>
                    </div>
                    <h3 class="font-semibold text-gray-900 dark:text-white mb-2">Email us</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400">support@bizwai.com</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">sales@bizwai.com</p>
                </div>

                <!-- Phone -->
                <div class="bg-white dark:bg-[#1b2e4b] rounded-2xl border border-gray-100 dark:border-white/10 p-6 shadow-sm">
                    <div class="w-10 h-10 rounded-xl bg-green-50 dark:bg-green-900/30 flex items-center justify-center mb-4">
                        <svg class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.948V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                        </svg>
                    </div>
                    <h3 class="font-semibold text-gray-900 dark:text-white mb-2">Call us</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400">+1 (555) 123-4567</p>
                    <p class="text-sm text-gray-400 mt-0.5">Mon–Fri, 9am–6pm EST</p>
                </div>

                <!-- Location -->
                <div class="bg-white dark:bg-[#1b2e4b] rounded-2xl border border-gray-100 dark:border-white/10 p-6 shadow-sm">
                    <div class="w-10 h-10 rounded-xl bg-amber-50 dark:bg-amber-900/30 flex items-center justify-center mb-4">
                        <svg class="w-5 h-5 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                    </div>
                    <h3 class="font-semibold text-gray-900 dark:text-white mb-2">Visit us</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400">1234 Market Street</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">San Francisco, CA 94103</p>
                </div>
            </div>

            <!-- ── Right: Message form ── -->
            <div class="bg-white dark:bg-[#1b2e4b] rounded-2xl border border-gray-100 dark:border-white/10 p-6 shadow-sm">

                <!-- Form header -->
                <div class="flex items-center gap-3 mb-6">
                    <div class="w-9 h-9 rounded-xl bg-blue-50 dark:bg-blue-900/30 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                        </svg>
                    </div>
                    <div>
                        <h2 class="font-semibold text-gray-900 dark:text-white text-base">Send a message</h2>
                        <p class="text-sm text-gray-400">Fill out the form and we'll reach out within 24 hours.</p>
                    </div>
                </div>

                <!-- Success banner -->
                <div v-if="contactStore.success"
                    class="mb-5 flex items-start gap-3 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-xl px-4 py-3">
                    <svg class="w-5 h-5 text-green-600 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <p class="text-sm text-green-700 dark:text-green-400">{{ contactStore.success }}</p>
                </div>

                <!-- Error banner -->
                <div v-if="contactStore.serverError"
                    class="mb-5 flex items-start gap-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl px-4 py-3">
                    <svg class="w-5 h-5 text-red-600 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <p class="text-sm text-red-700 dark:text-red-400">{{ contactStore.serverError }}</p>
                </div>

                <!-- Form -->
                <form @submit.prevent="submit" class="space-y-5">

                    <!-- Name + Email -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-white/80 mb-1.5">
                                Name <span class="text-red-500">*</span>
                            </label>
                            <input
                                v-model="form.name"
                                type="text"
                                placeholder="Your full name"
                                :class="inputClass(contactStore.errors.name)"
                            />
                            <p v-if="contactStore.errors.name" class="mt-1 text-xs text-red-500">
                                {{ contactStore.errors.name[0] }}
                            </p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-white/80 mb-1.5">
                                Email <span class="text-red-500">*</span>
                            </label>
                            <input
                                v-model="form.email"
                                type="email"
                                placeholder="you@example.com"
                                :class="inputClass(contactStore.errors.email)"
                            />
                            <p v-if="contactStore.errors.email" class="mt-1 text-xs text-red-500">
                                {{ contactStore.errors.email[0] }}
                            </p>
                        </div>
                    </div>

                    <!-- Subject -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-white/80 mb-1.5">
                            Subject <span class="text-red-500">*</span>
                        </label>
                        <input
                            v-model="form.subject"
                            type="text"
                            placeholder="How can we help?"
                            :class="inputClass(contactStore.errors.subject)"
                        />
                        <p v-if="contactStore.errors.subject" class="mt-1 text-xs text-red-500">
                            {{ contactStore.errors.subject[0] }}
                        </p>
                    </div>

                    <!-- Message -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-white/80 mb-1.5">
                            Message <span class="text-red-500">*</span>
                        </label>
                        <textarea
                            v-model="form.message"
                            rows="6"
                            placeholder="Describe your issue or question in detail…"
                            :class="[inputClass(contactStore.errors.message), 'resize-none']"
                        ></textarea>
                        <div class="flex justify-between mt-1">
                            <p v-if="contactStore.errors.message" class="text-xs text-red-500">
                                {{ contactStore.errors.message[0] }}
                            </p>
                            <p class="text-xs text-gray-400 ml-auto">{{ form.message.length }} / 5000</p>
                        </div>
                    </div>

                    <!-- Submit -->
                    <div class="pt-1">
                        <button
                            type="submit"
                            :disabled="contactStore.sending"
                            class="flex items-center gap-2 bg-blue-600 hover:bg-blue-700 active:bg-blue-800 disabled:opacity-60 disabled:cursor-not-allowed text-white text-sm font-semibold px-6 py-3 rounded-xl transition shadow-sm"
                        >
                            <svg v-if="contactStore.sending" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                            </svg>
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-send w-4 h-4 mr-2" aria-hidden="true" x-file-name="ContactUs" x-line-number="128" x-column="14" x-component="Send" x-id="ContactUs_128_14" x-dynamic="false"><path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"></path><path d="m21.854 2.147-10.94 10.939"></path></svg>
                            
                            {{ contactStore.sending ? 'Sending…' : 'Send Message' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script lang="ts" setup>
import { reactive, onUnmounted } from 'vue';
import { useContactStore } from '@/stores/contact';

const contactStore = useContactStore();

const form = reactive({
    name:    '',
    email:   '',
    subject: '',
    message: '',
});

function inputClass(fieldErrors?: string[]) {
    const base = 'w-full rounded-xl border px-4 py-2.5 text-sm bg-white dark:bg-[#0f1623] dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-2 transition';
    return fieldErrors?.length
        ? `${base} border-red-400 focus:ring-red-300`
        : `${base} border-gray-200 dark:border-white/10 focus:ring-blue-300 focus:border-blue-400`;
}

async function submit() {
    const ok = await contactStore.sendMessage({ ...form });
    if (ok) {
        Object.assign(form, { name: '', email: '', subject: '', message: '' });
    }
}

onUnmounted(() => contactStore.clearState());
</script>