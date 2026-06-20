<template>
    <div class="flex min-h-screen">

        <!-- ── Left Panel ── -->
        <div class="hidden lg:flex w-[420px] shrink-0 flex-col justify-between bg-[#1a6ef5] px-10 py-10 relative overflow-hidden">
            <!-- Decorative blobs -->
            <div class="absolute -top-16 -right-16 w-64 h-64 rounded-full bg-white/[0.07]"></div>
            <div class="absolute bottom-10 -left-20 w-80 h-80 rounded-full bg-white/[0.05]"></div>
            <div class="absolute -bottom-8 right-6 w-40 h-40 rounded-full bg-white/[0.06]"></div>

            <div class="relative z-10">
                <!-- Logo -->
                <div class="flex items-center gap-2.5 mb-12">
                    <div class="w-9 h-9 bg-white rounded-lg flex items-center justify-center">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="rgb(37 99 235)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-chart-column w-6 h-6" aria-hidden="true"><path d="M3 3v16a2 2 0 0 0 2 2h16"></path><path d="M18 17V9"></path><path d="M13 17V5"></path><path d="M8 17v-3"></path></svg>
                    </div>
                    <span class="text-white text-xl font-bold tracking-tight">BizWai</span>
                </div>

                <h2 class="text-white text-2xl font-bold leading-snug mb-3 tracking-tight">
                    One more step<br>to get started
                </h2>
                <p class="text-white/70 text-sm leading-relaxed mb-8">
                    We sent a verification link to your inbox. Click it to activate your account and start using BizWai.
                </p>

                <!-- Feature bullets -->
                <div class="space-y-3.5">
                    <div v-for="feat in features" :key="feat.label" class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-white/15 flex items-center justify-center shrink-0">
                            <component :is="'svg'" class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="feat.icon"/>
                            </component>
                        </div>
                        <span class="text-white/80 text-sm">{{ feat.label }}</span>
                    </div>
                </div>
            </div>

            <p class="relative z-10 text-white/35 text-xs">© 2026 BizWai. All rights reserved.</p>
        </div>

        <!-- ── Right Panel ── -->
        <div class="flex-1 flex items-center justify-center bg-white dark:bg-[#0f1623] px-6 py-10">

            <!-- ── STATE: Verifying link (user clicked email link) ── -->
            <template v-if="isVerifyingLink">

                <!-- Loading -->
                <div v-if="verifyState === 'loading'" class="text-center max-w-sm w-full">
                    <div class="w-18 h-18 mx-auto mb-6 rounded-full bg-blue-50 flex items-center justify-center w-[72px] h-[72px]">
                        <svg class="w-9 h-9 text-[#1a6ef5] animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                        </svg>
                    </div>
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-2 tracking-tight">Verifying your email…</h1>
                    <p class="text-sm text-gray-500">Please wait a moment.</p>
                </div>

                <!-- Success -->
                <div v-else-if="verifyState === 'success'" class="text-center max-w-sm w-full">
                    <div class="w-[72px] h-[72px] mx-auto mb-6 rounded-full bg-green-50 flex items-center justify-center">
                        <svg class="w-9 h-9 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>

                    <div class="inline-flex items-center gap-1.5 bg-green-50 text-green-700 text-[11px] font-semibold uppercase tracking-wide px-3 py-1 rounded-full mb-4">
                        <span class="w-1.5 h-1.5 rounded-full bg-green-600 inline-block"></span>
                        Verified
                    </div>

                    <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-2 tracking-tight">Email verified!</h1>
                    <p class="text-sm text-gray-500 mb-1">Your account is now active.</p>
                    <p class="text-sm text-gray-500 mb-8">You can sign in with your credentials.</p>

                    <router-link to="/auth/boxed-signin"
                        class="block w-full bg-[#1a6ef5] hover:bg-blue-600 text-white text-sm font-semibold py-3 rounded-lg text-center transition mb-4">
                        Sign in now →
                    </router-link>
                    <p class="text-xs text-gray-400">Redirecting to sign in in {{ countdown }}s…</p>
                </div>

                <!-- Error -->
                <div v-else-if="verifyState === 'error'" class="text-center max-w-sm w-full">
                    <div class="w-[72px] h-[72px] mx-auto mb-6 rounded-full bg-red-50 flex items-center justify-center">
                        <svg class="w-9 h-9 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </div>

                    <div class="inline-flex items-center gap-1.5 bg-red-50 text-red-700 text-[11px] font-semibold uppercase tracking-wide px-3 py-1 rounded-full mb-4">
                        <span class="w-1.5 h-1.5 rounded-full bg-red-600 inline-block"></span>
                        Failed
                    </div>

                    <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-2 tracking-tight">Verification failed</h1>
                    <p class="text-sm text-gray-500 mb-8 leading-relaxed">{{ verifyError }}</p>

                    <router-link to="/auth/verify-email"
                        class="block w-full bg-[#1a6ef5] hover:bg-blue-600 text-white text-sm font-semibold py-3 rounded-lg text-center transition mb-3">
                        Request new link
                    </router-link>
                    <router-link to="/auth/boxed-signin" class="text-sm text-[#1a6ef5] font-medium hover:underline">
                        ← Back to sign in
                    </router-link>
                </div>

            </template>

            <!-- ── STATE: Waiting for user to check email ── -->
            <template v-else>
                <div class="text-center max-w-sm w-full">

                    <div class="w-[72px] h-[72px] mx-auto mb-6 rounded-full bg-blue-50 flex items-center justify-center">
                        <svg class="w-9 h-9 text-[#1a6ef5]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                        </svg>
                    </div>

                    <!-- Status pill -->
                    <div class="inline-flex items-center gap-1.5 bg-blue-50 text-[#1a6ef5] text-[11px] font-semibold uppercase tracking-wide px-3 py-1 rounded-full mb-4">
                        <span class="w-1.5 h-1.5 rounded-full bg-[#1a6ef5] inline-block"></span>
                        Check your email
                    </div>

                    <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-2 tracking-tight">Verify your email</h1>
                    <p class="text-sm text-gray-500 mb-0.5">A verification link was sent to</p>
                    <p class="text-sm font-semibold text-gray-900 dark:text-white mb-6">
                        {{ authStore.user?.email ?? 'your email address' }}
                    </p>

                    <!-- Steps -->
                    <div class="text-left space-y-3 mb-6">
                        <div v-for="(step, i) in steps" :key="i" class="flex items-start gap-3">
                            <div class="w-6 h-6 rounded-full bg-[#1a6ef5] text-white text-[11px] font-bold flex items-center justify-center shrink-0 mt-0.5">
                                {{ i + 1 }}
                            </div>
                            <p class="text-sm text-gray-500 leading-relaxed" v-html="step"></p>
                        </div>
                    </div>

                    <!-- Resend success -->
                    <div v-if="resendSuccess"
                        class="mb-4 rounded-lg bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-700 text-left flex items-center gap-2">
                        <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                        Verification email resent! Check your inbox.
                    </div>

                    <!-- Error -->
                    <div v-if="authStore.error"
                        class="mb-4 rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700 text-left">
                        {{ authStore.error }}
                    </div>

                    <button
                        type="button"
                        :disabled="authStore.loading || resendCooldown > 0"
                        class="w-full bg-[#1a6ef5] hover:bg-blue-600 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm font-semibold py-3 rounded-lg transition mb-3"
                        @click="handleResend"
                    >
                        <span v-if="authStore.loading">Sending…</span>
                        <span v-else-if="resendCooldown > 0">Resend in {{ resendCooldown }}s</span>
                        <span v-else>Resend verification email</span>
                    </button>

                    <router-link to="/auth/boxed-signin"
                        class="text-sm text-[#1a6ef5] font-medium hover:underline">
                        ← Back to sign in
                    </router-link>

                    <p class="mt-6 text-xs text-gray-400 leading-relaxed">
                        Can't find it? Check your spam or junk folder.
                    </p>
                </div>
            </template>
        </div>
    </div>
</template>

<script lang="ts" setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { useMeta } from '@/composables/use-meta';
import axios from 'axios';

useMeta({ title: 'Verify Email' });

const authStore = useAuthStore();
const route     = useRoute();
const router    = useRouter();

const routeId   = computed(() => route.params.id   as string | undefined);
const routeHash = computed(() => route.params.hash as string | undefined);
const isVerifyingLink = computed(() => !!routeId.value && !!routeHash.value);

type VerifyState = 'loading' | 'success' | 'error';
const verifyState = ref<VerifyState>('loading');
const verifyError = ref('');
const countdown   = ref(5);
let countdownTimer: ReturnType<typeof setInterval> | null = null;

const resendSuccess  = ref(false);
const resendCooldown = ref(0);
let cooldownTimer: ReturnType<typeof setInterval> | null = null;

const features = [
    { label: 'One-click QuickBooks connect',  icon: 'M13 10V3L4 14h7v7l9-11h-7z' },
    { label: 'Live AI-powered insights',       icon: 'M13 7h8m0 0v8m0-8l-8 8-4-4-6 6' },
    { label: 'Secure, encrypted data',         icon: 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z' },
];

const email = authStore.user?.email ?? 'the address you registered with';
const steps = [
    `Open your inbox for <strong class="text-gray-800 dark:text-white">${email}</strong>`,
    `Find the email with subject <strong class="text-gray-800 dark:text-white">"Verify Email Address"</strong>`,
    `Click <strong class="text-gray-800 dark:text-white">"Verify Email Address"</strong> in the email`,
];

onMounted(async () => {
    if (isVerifyingLink.value) await performVerification();
});

onUnmounted(() => {
    if (countdownTimer) clearInterval(countdownTimer);
    if (cooldownTimer)  clearInterval(cooldownTimer);
});

async function performVerification(): Promise<void> {
    verifyState.value = 'loading';
    try {
        const params = new URLSearchParams({
            expires:   route.query.expires   as string ?? '',
            signature: route.query.signature as string ?? '',
        });
        await axios.get(`/api/email/verify/${routeId.value}/${routeHash.value}?${params.toString()}`);
        verifyState.value = 'success';
        startCountdown();
    } catch (err: any) {
        verifyState.value = 'error';
        verifyError.value = err.response?.data?.message
            ?? 'The verification link is invalid or has expired. Please request a new one.';
    }
}

function startCountdown(): void {
    countdown.value = 5;
    countdownTimer = setInterval(() => {
        countdown.value--;
        if (countdown.value <= 0) {
            clearInterval(countdownTimer!);
            router.push('/auth/boxed-signin?verified=1');
        }
    }, 1000);
}

async function handleResend() {
    resendSuccess.value = false;
    try {
        await authStore.resendVerification();
        resendSuccess.value = true;
        resendCooldown.value = 60;
        cooldownTimer = setInterval(() => {
            resendCooldown.value--;
            if (resendCooldown.value <= 0) {
                clearInterval(cooldownTimer!);
                cooldownTimer = null;
            }
        }, 1000);
    } catch (_) {}
}
</script>