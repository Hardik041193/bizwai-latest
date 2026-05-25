<template>
    <div class="min-h-screen flex">

        <!-- ── Left Panel ── -->
        <div class="hidden lg:flex lg:w-1/2 bg-gradient-to-br from-[#1877F2] via-[#1B74E4] to-[#0D5DCC] p-12 flex-col justify-between relative overflow-hidden">

            <!-- Decorative circles -->
            <div class="absolute top-[-60px] right-[-60px] w-[280px] h-[280px] rounded-full bg-white/5"></div>
            <div class="absolute top-[15%] right-[5%] w-[180px] h-[180px] rounded-full bg-white/5"></div>
            <div class="absolute bottom-[-80px] left-[-40px] w-[340px] h-[340px] rounded-full bg-white/5"></div>

            <!-- Logo -->
            <div class="relative z-10">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-white rounded-xl flex items-center justify-center shadow-md">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-chart-column w-6 h-6 text-[#1877F2]" aria-hidden="true">
                            <path d="M3 3v16a2 2 0 0 0 2 2h16"></path>
                            <path d="M18 17V9"></path>
                            <path d="M13 17V5"></path>
                            <path d="M8 17v-3"></path>
                        </svg>
                    </div>
                    <span class="text-white text-3xl font-bold tracking-tight">BizWai</span>
                </div>
            </div>

            <!-- Center content -->
            <div class="relative z-10">
                <h2 class="text-white font-extrabold text-4xl xl:text-5xl leading-tight mb-5">
                    Simplify. Analyze.<br />
                    Grow with <span class="text-blue-200">BizWai.</span>
                </h2>
                <p class="text-white/75 text-base leading-relaxed max-w-sm mb-10">
                    BizWai helps you manage your QuickBooks data, visualize insights, and make smarter decisions — all in one place.
                </p>
            </div>

            <!-- Footer -->
            <div class="relative z-10">
                <p class="text-white/40 text-sm">© 2026 BizWai. All rights reserved.</p>
            </div>
        </div>

        <!-- ── Right Panel ── -->
        <div class="flex-1 flex flex-col items-center justify-center bg-[#f0f4ff] dark:bg-[#060818] px-6 py-12 sm:px-12">
            <div class="w-full max-w-[460px]">

                <!-- Card -->
                <div class="bg-white rounded-2xl shadow-xl px-8 py-10">

                    <!-- Key icon -->
                    <div class="flex justify-center mb-6">
                        <div class="w-16 h-16 rounded-full bg-primary/10 flex items-center justify-center">
                            <svg class="w-7 h-7 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
                            </svg>
                        </div>
                    </div>

                    <!-- Heading -->
                    <div class="text-center mb-8">
                        <h1 class="text-2xl font-bold text-gray-900 mb-2">Create New Password</h1>
                        <p class="text-gray-400 text-sm leading-relaxed max-w-xs mx-auto">
                            Enter your new password below to regain access to your account.
                        </p>
                    </div>

                    <!-- Success banner -->
                    <div v-if="successMessage" class="mb-5 rounded-xl bg-success/10 border border-success/30 px-4 py-3 text-sm text-success flex items-center gap-2">
                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                        {{ successMessage }}
                    </div>

                    <!-- Error banner -->
                    <div v-if="authStore.error" class="mb-5 rounded-xl bg-danger/10 border border-danger/30 px-4 py-3 text-sm text-danger flex items-center gap-2">
                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.062 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                        </svg>
                        {{ authStore.error }}
                    </div>

                    <!-- Form -->
                    <form class="space-y-5" @submit.prevent="submitResetPassword">

                        <!-- Email -->
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-500">Email Address</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-gray-400">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                                    </svg>
                                </span>
                                <input
                                    id="Email"
                                    v-model="form.email"
                                    type="email"
                                    placeholder="Enter your email"
                                    required
                                    class="w-full rounded-xl border border-gray-200 bg-gray-50 py-3 pl-10 pr-4 text-sm text-gray-900 placeholder:text-gray-400 focus:border-primary focus:bg-white focus:outline-none focus:ring-2 focus:ring-primary/20 transition"
                                />
                            </div>
                        </div>

                        <!-- New Password -->
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-500">New Password</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-gray-400">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                                    </svg>
                                </span>
                                <input
                                    id="Password"
                                    v-model="form.password"
                                    :type="showPassword ? 'text' : 'password'"
                                    placeholder="Minimum 8 characters"
                                    required
                                    autocomplete="new-password"
                                    class="w-full rounded-xl border border-gray-200 bg-gray-50 py-3 pl-10 pr-10 text-sm text-gray-900 placeholder:text-gray-400 focus:border-primary focus:bg-white focus:outline-none focus:ring-2 focus:ring-primary/20 transition"
                                />
                                <button
                                    type="button"
                                    @click="showPassword = !showPassword"
                                    class="absolute inset-y-0 right-0 flex items-center pr-3.5 text-gray-400 hover:text-gray-600 transition"
                                >
                                    <svg v-if="!showPassword" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                    </svg>
                                    <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <!-- Confirm Password -->
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-500">Confirm Password</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-gray-400">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                                    </svg>
                                </span>
                                <input
                                    id="ConfirmPassword"
                                    v-model="form.password_confirmation"
                                    :type="showConfirmPassword ? 'text' : 'password'"
                                    placeholder="Confirm new password"
                                    required
                                    autocomplete="new-password"
                                    class="w-full rounded-xl border border-gray-200 bg-gray-50 py-3 pl-10 pr-10 text-sm text-gray-900 placeholder:text-gray-400 focus:border-primary focus:bg-white focus:outline-none focus:ring-2 focus:ring-primary/20 transition"
                                    :class="{ 'border-danger focus:border-danger focus:ring-danger/20': passwordMismatch }"
                                />
                                <button
                                    type="button"
                                    @click="showConfirmPassword = !showConfirmPassword"
                                    class="absolute inset-y-0 right-0 flex items-center pr-3.5 text-gray-400 hover:text-gray-600 transition"
                                >
                                    <svg v-if="!showConfirmPassword" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                    </svg>
                                    <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>
                                    </svg>
                                </button>
                            </div>
                            <p v-if="passwordMismatch" class="mt-1.5 text-xs text-danger flex items-center gap-1">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01"/>
                                </svg>
                                Passwords do not match.
                            </p>
                        </div>

                        <!-- Submit -->
                        <button
                            type="submit"
                            :disabled="authStore.loading || passwordMismatch"
                            class="w-full bg-primary hover:bg-primary/90 disabled:opacity-60 disabled:cursor-not-allowed text-white font-semibold py-3 rounded-xl shadow-[0_8px_24px_-8px_rgba(67,97,238,0.5)] transition flex items-center justify-center gap-2"
                        >
                            <svg v-if="authStore.loading" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                            </svg>
                            <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            {{ authStore.loading ? 'Resetting...' : 'Reset Password' }}
                        </button>
                    </form>

                    <!-- Divider -->
                    <div class="flex items-center gap-3 my-5">
                        <div class="flex-1 h-px bg-gray-200"></div>
                        <span class="text-xs text-gray-400">or</span>
                        <div class="flex-1 h-px bg-gray-200"></div>
                    </div>

                    <!-- Back to sign in -->
                    <router-link
                        to="/auth/boxed-signin"
                        class="flex items-center justify-center gap-2 text-sm font-medium text-primary hover:text-primary/80 transition"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                        </svg>
                        Back to Sign In
                    </router-link>
                </div>

                <!-- Bottom trust badges -->
                <!-- <div class="grid grid-cols-3 gap-4 mt-8">
                    <div class="text-center">
                        <div class="flex justify-center mb-2">
                            <div class="w-9 h-9 bg-primary/10 rounded-full flex items-center justify-center">
                                <svg class="w-4 h-4 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                                </svg>
                            </div>
                        </div>
                        <p class="text-sm font-semibold text-gray-700">Secure</p>
                        <p class="text-xs text-gray-400 mt-0.5">Your data is always protected.</p>
                    </div>
                    <div class="text-center">
                        <div class="flex justify-center mb-2">
                            <div class="w-9 h-9 bg-primary/10 rounded-full flex items-center justify-center">
                                <svg class="w-4 h-4 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                                </svg>
                            </div>
                        </div>
                        <p class="text-sm font-semibold text-gray-700">Fast</p>
                        <p class="text-xs text-gray-400 mt-0.5">Quick reset in just a few clicks.</p>
                    </div>
                    <div class="text-center">
                        <div class="flex justify-center mb-2">
                            <div class="w-9 h-9 bg-primary/10 rounded-full flex items-center justify-center">
                                <svg class="w-4 h-4 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                </svg>
                            </div>
                        </div>
                        <p class="text-sm font-semibold text-gray-700">Support</p>
                        <p class="text-xs text-gray-400 mt-0.5">Need help? Our team is here for you.</p>
                    </div>
                </div> -->

            </div>
        </div>

    </div>
</template>

<script lang="ts" setup>
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import appSetting from '@/app-setting';
import { useAppStore } from '@/stores/index';
import { useAuthStore } from '@/stores/auth';
import { useMeta } from '@/composables/use-meta';

useMeta({ title: 'Reset Password' });

const route    = useRoute();
const router   = useRouter();
const store    = useAppStore();
const authStore = useAuthStore();
const successMessage = ref('');
const showPassword        = ref(false);
const showConfirmPassword = ref(false);

const form = reactive({
    token:                 String(route.params.token || ''),
    email:                 String(route.query.email  || ''),
    password:              '',
    password_confirmation: '',
});

const passwordMismatch = computed(
    () => form.password_confirmation.length > 0 && form.password !== form.password_confirmation
);

const submitResetPassword = async () => {
    successMessage.value = '';

    if (passwordMismatch.value) {
        authStore.error = 'Passwords do not match.';
        return;
    }

    try {
        successMessage.value = await authStore.resetPassword(form);
        setTimeout(() => router.push({ name: 'boxed-signin', query: { reset: '1' } }), 1200);
    } catch (_) {}
};

// multi language (kept intact)
const i18n = reactive(useI18n());
const changeLanguage = (item: any) => {
    i18n.locale = item.code;
    appSetting.toggleLanguage(item);
};
const currentFlag = computed(() => `/assets/images/flags/${i18n.locale.toUpperCase()}.svg`);
</script>