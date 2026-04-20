<template>
    <div>
        <div class="absolute inset-0">
            <img src="/assets/images/auth/bg-gradient.png" alt="image" class="h-full w-full object-cover" />
        </div>

        <div class="relative flex min-h-screen items-center justify-center bg-[url(/assets/images/auth/map.png)] bg-cover bg-center bg-no-repeat px-6 py-10 dark:bg-[#060818] sm:px-16">
            <img src="/assets/images/auth/coming-soon-object1.png" alt="image" class="absolute left-0 top-1/2 h-full max-h-[893px] -translate-y-1/2" />
            <img src="/assets/images/auth/coming-soon-object3.png" alt="image" class="absolute right-0 top-0 h-[300px]" />

            <div class="relative w-full max-w-[870px] rounded-md bg-[linear-gradient(45deg,#fff9f9_0%,rgba(255,255,255,0)_25%,rgba(255,255,255,0)_75%,_#fff9f9_100%)] p-2 dark:bg-[linear-gradient(52.22deg,#0E1726_0%,rgba(14,23,38,0)_18.66%,rgba(14,23,38,0)_51.04%,rgba(14,23,38,0)_80.07%,#0E1726_100%)]">
                <div class="relative flex flex-col items-center justify-center rounded-md bg-white/60 backdrop-blur-lg dark:bg-black/50 px-6 py-16 text-center">

                    <!-- ══════════════════════════════════════════════
                         STATE 1: Auto-verifying (user clicked email link)
                         ══════════════════════════════════════════════ -->
                    <template v-if="isVerifyingLink">
                        <!-- Loading -->
                        <template v-if="verifyState === 'loading'">
                            <div class="mb-6 flex h-20 w-20 items-center justify-center rounded-full bg-primary/10">
                                <svg class="h-10 w-10 animate-spin text-primary" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                            </div>
                            <h1 class="text-2xl font-extrabold uppercase text-primary">Verifying Your Email…</h1>
                            <p class="mt-2 text-sm text-white-dark">Please wait a moment.</p>
                        </template>

                        <!-- Success -->
                        <template v-else-if="verifyState === 'success'">
                            <div class="mb-6 flex h-20 w-20 items-center justify-center rounded-full bg-success/15">
                                <svg class="h-10 w-10 text-success" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                </svg>
                            </div>
                            <h1 class="text-2xl font-extrabold uppercase text-success">Email Verified!</h1>
                            <p class="mt-2 text-base text-white-dark">Your email has been verified successfully.</p>
                            <p class="mt-1 text-sm text-white-dark">You can now sign in with your credentials.</p>
                            <div class="mt-8">
                                <router-link to="/auth/boxed-signin"
                                    class="btn btn-gradient border-0 uppercase shadow-[0_10px_20px_-10px_rgba(67,97,238,0.44)] px-10">
                                    Sign In Now
                                </router-link>
                            </div>
                            <p class="mt-4 text-xs text-white-dark">
                                Redirecting to sign in in {{ countdown }}s…
                            </p>
                        </template>

                        <!-- Error -->
                        <template v-else-if="verifyState === 'error'">
                            <div class="mb-6 flex h-20 w-20 items-center justify-center rounded-full bg-danger/10">
                                <svg class="h-10 w-10 text-danger" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </div>
                            <h1 class="text-2xl font-extrabold uppercase text-danger">Verification Failed</h1>
                            <p class="mt-2 text-sm text-white-dark">{{ verifyError }}</p>
                            <div class="mt-8 flex flex-col items-center gap-3">
                                <router-link to="/auth/verify-email"
                                    class="btn btn-gradient border-0 uppercase shadow-[0_10px_20px_-10px_rgba(67,97,238,0.44)] px-10">
                                    Request New Link
                                </router-link>
                                <router-link to="/auth/boxed-signin" class="text-sm text-primary underline">
                                    Back to Sign In
                                </router-link>
                            </div>
                        </template>
                    </template>

                    <!-- ══════════════════════════════════════════════
                         STATE 2: Waiting for user to check email
                         ══════════════════════════════════════════════ -->
                    <template v-else>
                        <!-- Email icon -->
                        <div class="mb-8 flex h-24 w-24 items-center justify-center rounded-full bg-primary/10">
                            <svg class="h-12 w-12 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                    d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                            </svg>
                        </div>

                        <h1 class="mb-3 text-3xl font-extrabold uppercase text-primary md:text-4xl">Verify Your Email</h1>
                        <p class="mb-2 text-base font-semibold text-white-dark">
                            A verification link has been sent to
                        </p>
                        <p class="mb-6 text-base font-bold text-dark dark:text-white">
                            {{ authStore.user?.email ?? 'your email address' }}
                        </p>
                        <p class="mb-8 text-sm text-white-dark max-w-md">
                            Please check your inbox and click the verification link to activate your account.
                            Once verified, you can log in with your credentials.
                        </p>

                        <!-- Step guide -->
                        <div class="mb-8 w-full max-w-sm space-y-3 text-left">
                            <div v-for="(step, i) in steps" :key="i" class="flex items-center gap-3">
                                <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-bold text-white">
                                    {{ i + 1 }}
                                </div>
                                <p class="text-sm text-white-dark">{{ step }}</p>
                            </div>
                        </div>

                        <!-- Success message after resend -->
                        <div v-if="resendSuccess" class="mb-4 w-full max-w-sm rounded-md bg-success/10 border border-success/30 px-4 py-3 text-sm text-success">
                            Verification email resent! Check your inbox.
                        </div>

                        <!-- Error -->
                        <div v-if="authStore.error" class="mb-4 w-full max-w-sm rounded-md bg-danger/10 border border-danger/30 px-4 py-3 text-sm text-danger">
                            {{ authStore.error }}
                        </div>

                        <!-- Actions -->
                        <div class="flex w-full max-w-sm flex-col items-center gap-4">
                            <button
                                type="button"
                                :disabled="authStore.loading || resendCooldown > 0"
                                class="btn btn-gradient w-full border-0 uppercase shadow-[0_10px_20px_-10px_rgba(67,97,238,0.44)]"
                                @click="handleResend"
                            >
                                <span v-if="authStore.loading">Sending…</span>
                                <span v-else-if="resendCooldown > 0">Resend in {{ resendCooldown }}s</span>
                                <span v-else>Resend Verification Email</span>
                            </button>

                            <router-link to="/auth/boxed-signin" class="text-sm text-primary underline hover:text-black dark:hover:text-white transition">
                                Back to Sign In
                            </router-link>
                        </div>

                        <!-- Dev helper -->
                        <div class="mt-10 w-full max-w-md rounded-lg border border-warning/40 bg-warning/5 p-4 text-left">
                            <div class="mb-2 flex items-center gap-2">
                                <span class="inline-block h-2 w-2 rounded-full bg-warning"></span>
                                <p class="text-xs font-bold uppercase tracking-widest text-warning">Development Mode — No Real Email Sent</p>
                            </div>
                            <p class="mb-3 text-xs text-white-dark">
                                Emails are written to <code class="rounded bg-black/5 px-1 py-0.5 dark:bg-white/5">storage/logs/laravel.log</code>.
                                Run this command in the project terminal to get the verification link:
                            </p>
                            <div class="relative rounded bg-black/80 px-3 py-2 font-mono text-xs text-green-400">
                                <code>{{ devCommand }}</code>
                                <button type="button"
                                    class="absolute right-2 top-1/2 -translate-y-1/2 rounded px-2 py-0.5 text-[10px] font-bold uppercase text-white/60 hover:text-white"
                                    @click="copyDevCommand">
                                    {{ copied ? '✓ Copied' : 'Copy' }}
                                </button>
                            </div>
                            <p class="mt-2 text-xs text-white-dark">
                                Open the printed URL in your browser — the SPA will auto-verify your email.
                            </p>
                        </div>
                    </template>

                </div>
            </div>
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

// ── Route params (present when user clicked the email link) ──
const routeId   = computed(() => route.params.id   as string | undefined);
const routeHash = computed(() => route.params.hash as string | undefined);
const isVerifyingLink = computed(() => !!routeId.value && !!routeHash.value);

// ── Auto-verify state ──
type VerifyState = 'loading' | 'success' | 'error';
const verifyState = ref<VerifyState>('loading');
const verifyError = ref('');
const countdown   = ref(5);
let countdownTimer: ReturnType<typeof setInterval> | null = null;

// ── Resend state ──
const resendSuccess  = ref(false);
const resendCooldown = ref(0);
let cooldownTimer: ReturnType<typeof setInterval> | null = null;
const copied = ref(false);

const steps = [
    'Open your email inbox for ' + (authStore.user?.email ?? 'the address you registered with'),
    'Find the email from BizWai with subject "Verify Email Address"',
    'Click the "Verify Email Address" button in the email',
    'You will be redirected back here and automatically signed in',
];

const appOrigin  = window.location.origin;
const devCommand = `grep -oP '${appOrigin}/auth/verify-email/[^"&<\\\\s]+' storage/logs/laravel.log | tail -1`;

onMounted(async () => {
    if (isVerifyingLink.value) {
        await performVerification();
    }
});

onUnmounted(() => {
    if (countdownTimer) clearInterval(countdownTimer);
    if (cooldownTimer)  clearInterval(cooldownTimer);
});

async function performVerification(): Promise<void> {
    verifyState.value = 'loading';
    try {
        // Forward the full query string (expires + signature) to the backend
        const params = new URLSearchParams({
            expires:   route.query.expires   as string ?? '',
            signature: route.query.signature as string ?? '',
        });
        await axios.get(
            `/api/email/verify/${routeId.value}/${routeHash.value}?${params.toString()}`
        );
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

const handleResend = async () => {
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
};

const copyDevCommand = () => {
    navigator.clipboard.writeText(devCommand).then(() => {
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    });
};
</script>
