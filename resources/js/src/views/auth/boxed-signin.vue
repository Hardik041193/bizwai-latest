<template>
    <div class="min-h-screen flex">

        <!-- ── Left Panel ── -->
        <div class="hidden lg:flex lg:w-1/2 bg-gradient-to-br from-[#1877F2] via-[#1B74E4] to-[#0D5DCC] p-12 flex-col justify-between relative overflow-hidden">

            <!-- Background decorative circles -->
            <div class="absolute top-[-80px] right-[-80px] w-[320px] h-[320px] rounded-full bg-white/5"></div>
            <div class="absolute bottom-[-100px] left-[-60px] w-[400px] h-[400px] rounded-full bg-white/5"></div>
            <div class="absolute top-1/2 right-[-40px] w-[200px] h-[200px] rounded-full bg-white/5 -translate-y-1/2"></div>

            <!-- Logo -->
            <div class="relative z-10">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-white rounded-xl flex items-center justify-center shadow-md">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-chart-column w-6 h-6 text-[#1877F2]" aria-hidden="true"><path d="M3 3v16a2 2 0 0 0 2 2h16"></path><path d="M18 17V9"></path><path d="M13 17V5"></path><path d="M8 17v-3"></path></svg>
                    </div>
                    <span class="text-white text-3xl font-bold tracking-tight">BizWai</span>
                </div>
            </div>

            <!-- Center content -->
            <div class="relative z-10">
                <h2 class="text-5xl font-bold text-white leading-tight mb-4">
                    Your QuickBooks data,<br />supercharged with AI.
                </h2>
                <p class="text-xl text-white/80 max-w-md">
                    Connect QuickBooks, visualize your finances, and get AI-powered insights — all in one clean dashboard.
                </p>

                <!-- Feature pills -->
                <div class="flex mt-12 gap-8 pt-4"><div class="flex items-center gap-2 text-white/90"><div class="w-9 h-9 bg-white/20 rounded-lg flex items-center justify-center backdrop-blur-sm"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-zap w-5 h-5" aria-hidden="true"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"></path></svg></div><span class="text-sm font-medium">One-click connect</span></div><div class="flex items-center gap-2 text-white/90"><div class="w-9 h-9 bg-white/20 rounded-lg flex items-center justify-center backdrop-blur-sm"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-trending-up w-5 h-5" aria-hidden="true"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"></polyline><polyline points="16 7 22 7 22 13"></polyline></svg></div><span class="text-sm font-medium">Live insights</span></div></div>
            </div>

            <!-- Footer -->
            <div class="relative z-10">
                <p class="text-white/60 text-sm">© 2026 BizWai. All rights reserved.</p>
            </div>
        </div>

        <!-- ── Right Panel ── -->
        <div class="flex-1 flex flex-col items-center justify-center bg-white dark:bg-[#060818] px-6 py-12 sm:px-12">
            <div class="w-full max-w-[440px]">

                <!-- Admin badge -->
                <div class="mb-6">
                    <span class="inline-flex items-center gap-1.5 bg-primary/10 text-primary text-xs font-semibold px-3 py-1.5 rounded-full">
                        <span class="w-1.5 h-1.5 rounded-full bg-primary inline-block"></span>
                        USER PORTAL
                    </span>
                </div>

                <!-- Heading -->
                <div class="mb-8">
                    <h1 class="text-3xl font-bold text-gray-900 mb-2">Sign in</h1>
                    <p class="text-gray-500 text-lg">Welcome back — please enter your credentials.</p>
                </div>

                <!-- Verified success banner -->
                <div v-if="justVerified" class="mb-5 rounded-md bg-success/10 border border-success/30 px-4 py-3 text-sm text-success flex items-center gap-2">
                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                    Email verified successfully! You can now sign in.
                </div>

                <!-- Form -->
                <form class="space-y-5 dark:text-white" @submit.prevent="handleLogin">

                    <!-- Error banner -->
                    <div v-if="authStore.error" class="rounded-md px-4 py-3 text-sm"
                        :class="authStore.requiresVerification
                            ? 'bg-warning/10 border border-warning/30 text-warning'
                            : 'bg-danger/10 border border-danger/30 text-danger'">
                        {{ authStore.error }}
                        <div v-if="authStore.requiresVerification" class="mt-2">
                            <router-link to="/auth/verify-email" class="font-semibold underline">
                                Go to verification page →
                            </router-link>
                        </div>
                    </div>

                    <!-- Email -->
                    <div>
                        <label for="Email" class="mb-1 block text-sm font-medium text-gray-500">Email Address</label>
                        <div class="relative text-white-dark">
                            <input
                                id="Email"
                                v-model="form.email"
                                type="email"
                                placeholder="Enter admin email"
                                class="w-full rounded-lg border border-gray-300 bg-white py-3 pl-10 pr-4 text-sm text-gray-900 placeholder:text-gray-500 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                required
                            />
                            <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-gray-500"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207"></path></svg></span>
                        </div>
                    </div>

                    <!-- Password -->
                    <div>
                        <label for="Password" class="mb-1 block text-sm font-medium text-gray-500">Password</label>
                        <div class="relative text-white-dark">
                            <input
                                id="Password"
                                v-model="form.password"
                                :type="showPassword ? 'text' : 'password'"
                                placeholder="Enter your password"
                                class="w-full rounded-lg border border-gray-300 bg-white py-3 pl-10 pr-10 text-sm text-gray-900 placeholder:text-gray-500 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                required
                            />
                            <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-gray-500"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg></span>
                            <!-- Eye toggle -->
                            <button
                                type="button"
                                tabindex="-1"
                                class="absolute inset-y-0 right-0 z-10 flex items-center pr-3 text-gray-500 hover:text-gray-700 cursor-pointer"
                                :aria-label="showPassword ? 'Hide password' : 'Show password'"
                                @click.prevent.stop="showPassword = !showPassword"
                            >
                                <svg v-if="!showPassword" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                </svg>
                                <svg v-else class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div class="flex items-center justify-between">
                        <a href="/auth/forgot-password" class="forgot-link">Forgot password?</a>
                    </div>
                    <!-- Submit -->
                    <button
                        type="submit"
                        :disabled="authStore.loading"
                        class="w-full rounded-lg bg-primary py-3 text-sm font-semibold uppercase tracking-wider text-white shadow-[0_10px_20px_-10px_rgba(67,97,238,0.6)] transition hover:bg-primary/90 disabled:opacity-60"
                    >
                        <span v-if="authStore.loading" class="animate-spin border-2 border-white border-l-transparent rounded-full w-4 h-4 inline-block mr-2"></span>
                        <span v-if="authStore.loading">Signing in...</span>
                        <span v-else>Sign in →</span>
                    </button>
                </form>

                <!-- Back to user login -->
                <div class="mt-8 text-center dark:text-white">
                    Don't have an account?
                    <router-link to="/auth/boxed-signup" class="uppercase text-primary underline transition hover:text-black dark:hover:text-white">
                        SIGN UP
                    </router-link>
                </div>

                <!-- <div class="mt-4 text-center">
                    <router-link to="/auth/boxed-signin" class="inline-flex items-center gap-1.5 text-xs text-white-dark transition hover:text-primary">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                        </svg>
                        Back to User Login
                    </router-link>
                </div> -->

            </div>
        </div>

    </div>
</template>

<script lang="ts" setup>
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import appSetting from '@/app-setting';
import { useAppStore } from '@/stores/index';
import { useAuthStore } from '@/stores/auth';
import { useRouter, useRoute } from 'vue-router';
import { useMeta } from '@/composables/use-meta';

useMeta({ title: 'User Sign In' });

const router    = useRouter();
const route     = useRoute();
const store     = useAppStore();
const authStore = useAuthStore();

const justVerified = computed(() => route.query.verified === '1');

const form         = reactive({ email: '', password: '' });
const showPassword = ref(false);

const handleLogin = async () => {
    try {
        await authStore.login(form.email, form.password);
        router.push({ name: 'home' });
    } catch (_) {}
};

const i18n = reactive(useI18n());
const changeLanguage = (item: any) => {
    i18n.locale = item.code;
    appSetting.toggleLanguage(item);
};
const currentFlag = computed(() => {
    return `/assets/images/flags/${i18n.locale.toUpperCase()}.svg`;
});
</script>
