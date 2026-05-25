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
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-chart-column w-6 h-6 text-[#1877F2]" aria-hidden="true"><path d="M3 3v16a2 2 0 0 0 2 2h16"></path><path d="M18 17V9"></path><path d="M13 17V5"></path><path d="M8 17v-3"></path></svg>
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

                <!-- Dashboard preview card -->
                <!-- <div class="rounded-2xl overflow-hidden bg-[#0e1726]/80 border border-white/10 shadow-2xl">
                    <div class="flex items-center gap-2 px-4 py-3 bg-[#1a2744] border-b border-white/10">
                        <div class="flex gap-1.5">
                            <span class="w-2.5 h-2.5 rounded-full bg-white/20"></span>
                            <span class="w-2.5 h-2.5 rounded-full bg-white/20"></span>
                            <span class="w-2.5 h-2.5 rounded-full bg-white/20"></span>
                        </div>
                        <div class="flex-1 mx-3 h-2 bg-white/10 rounded-full"></div>
                    </div>

                    <div class="flex">
                        <div class="w-12 bg-[#111c36] border-r border-white/5 py-4 flex flex-col items-center gap-4">
                            <div class="w-6 h-6 bg-[#4f8ef7]/40 rounded-md flex items-center justify-center">
                                <svg class="w-3 h-3 text-[#4f8ef7]" fill="currentColor" viewBox="0 0 24 24"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></svg>
                            </div>
                            <div class="w-6 h-6 bg-white/10 rounded-md flex items-center justify-center">
                                <svg class="w-3 h-3 text-white/40" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                            </div>
                            <div class="w-6 h-6 bg-white/10 rounded-md flex items-center justify-center">
                                <svg class="w-3 h-3 text-white/40" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                            </div>
                            <div class="w-6 h-6 bg-white/10 rounded-md flex items-center justify-center">
                                <svg class="w-3 h-3 text-white/40" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            </div>
                        </div>

                        <div class="flex-1 p-3 space-y-2.5 bg-[#0e1726]">
                            <div class="grid grid-cols-2 gap-2.5">
                                <div class="bg-[#1a2744] rounded-xl p-2.5">
                                    <p class="text-white/40 text-[9px] mb-0.5">Total Revenue</p>
                                    <p class="text-white font-bold text-sm">$128,540</p>
                                    <p class="text-green-400 text-[9px]">▲ 12.5%</p>
                                    <svg class="w-full mt-1.5" height="24" viewBox="0 0 100 24" preserveAspectRatio="none">
                                        <polyline points="0,20 20,14 40,16 60,8 80,11 100,3" fill="none" stroke="#4f8ef7" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </div>
                                <div class="bg-[#1a2744] rounded-xl p-2.5">
                                    <p class="text-white/40 text-[9px] mb-0.5">Total Expenses</p>
                                    <p class="text-white font-bold text-sm">$64,230</p>
                                    <p class="text-red-400 text-[9px]">▼ 8.3%</p>
                                    <svg class="w-full mt-1.5" height="24" viewBox="0 0 100 24" preserveAspectRatio="none">
                                        <polyline points="0,6 20,12 40,8 60,16 80,12 100,18" fill="none" stroke="#f97316" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-2.5">
                                <div class="bg-[#1a2744] rounded-xl p-2.5">
                                    <p class="text-white/40 text-[9px] mb-0.5">Profit</p>
                                    <p class="text-white font-bold text-sm">$64,310</p>
                                    <p class="text-green-400 text-[9px]">▲ 18.7%</p>
                                    <svg class="w-full mt-1.5" height="24" viewBox="0 0 100 24" preserveAspectRatio="none">
                                        <polyline points="0,20 20,14 40,16 60,6 80,10 100,3" fill="none" stroke="#22c55e" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </div>
                                <div class="bg-[#1a2744] rounded-xl p-2.5 flex items-center justify-center gap-2">
                                    <svg width="44" height="44" viewBox="0 0 44 44">
                                        <circle cx="22" cy="22" r="16" fill="none" stroke="#1e3a5f" stroke-width="8"/>
                                        <circle cx="22" cy="22" r="16" fill="none" stroke="#4f8ef7" stroke-width="8" stroke-dasharray="40 60.5" stroke-dashoffset="20" stroke-linecap="round"/>
                                        <circle cx="22" cy="22" r="16" fill="none" stroke="#22c55e" stroke-width="8" stroke-dasharray="22 60.5" stroke-dashoffset="-20" stroke-linecap="round"/>
                                        <circle cx="22" cy="22" r="16" fill="none" stroke="#f97316" stroke-width="8" stroke-dasharray="16 60.5" stroke-dashoffset="-42" stroke-linecap="round"/>
                                    </svg>
                                    <div class="space-y-0.5">
                                        <div class="flex items-center gap-1"><span class="w-1.5 h-1.5 rounded-full bg-[#4f8ef7] shrink-0"></span><span class="text-white/50 text-[8px]">Revenue</span></div>
                                        <div class="flex items-center gap-1"><span class="w-1.5 h-1.5 rounded-full bg-green-400 shrink-0"></span><span class="text-white/50 text-[8px]">Profit</span></div>
                                        <div class="flex items-center gap-1"><span class="w-1.5 h-1.5 rounded-full bg-orange-400 shrink-0"></span><span class="text-white/50 text-[8px]">Expenses</span></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div> -->
            </div>

            <!-- Footer -->
            <div class="relative z-10 space-y-3">
                <!-- <div class="flex items-center gap-2 text-white/60 text-sm">
                    <svg class="w-4 h-4 text-white/50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                    </svg>
                    Secure. Reliable. Built for your business.
                </div> -->
                <p class="text-white/40 text-sm">© 2026 BizWai. All rights reserved.</p>
            </div>
        </div>

        <!-- ── Right Panel ── -->
        <div class="flex-1 flex flex-col items-center justify-center bg-[#f0f4ff] dark:bg-[#060818] px-6 py-12 sm:px-12">
            <div class="w-full max-w-[460px]">

                <!-- Card -->
                <div class="bg-white rounded-2xl shadow-xl px-8 py-10">

                    <!-- Lock icon -->
                    <div class="flex justify-center mb-6">
                        <div class="w-16 h-16 rounded-full bg-primary/10 flex items-center justify-center">
                            <svg class="w-7 h-7 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                            </svg>
                        </div>
                    </div>

                    <!-- Heading -->
                    <div class="text-center mb-8">
                        <h1 class="text-2xl font-bold text-gray-900 mb-2">Forgot Password?</h1>
                        <p class="text-gray-400 text-sm leading-relaxed max-w-xs mx-auto">
                            No worries! Enter your email address and we'll send you a secure link to reset your password.
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
                    <form class="space-y-5" @submit.prevent="submitForgotPassword">
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
                                    v-model="email"
                                    type="email"
                                    placeholder="Enter your registered email"
                                    required
                                    class="w-full rounded-lg border border-gray-300 bg-white py-3 pl-10 pr-4 text-sm text-gray-900 placeholder:text-gray-500 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                />
                            </div>
                        </div>

                        <!-- Submit button -->
                        <button
                            type="submit"
                            :disabled="authStore.loading"
                            class="w-full bg-primary hover:bg-primary/90 disabled:opacity-60 disabled:cursor-not-allowed text-white font-semibold py-3 rounded-xl shadow-[0_8px_24px_-8px_rgba(67,97,238,0.5)] transition flex items-center justify-center gap-2"
                        >
                            {{ authStore.loading ? 'Sending...' : 'Send Reset Link →' }}
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
import appSetting from '@/app-setting';
import { useAppStore } from '@/stores/index';
import { useAuthStore } from '@/stores/auth';
import { useMeta } from '@/composables/use-meta';

useMeta({ title: 'Forgot Password' });

const store      = useAppStore();
const authStore  = useAuthStore();
const email      = ref('');
const successMessage = ref('');

const submitForgotPassword = async () => {
    successMessage.value = '';
    try {
        successMessage.value = await authStore.forgotPassword(email.value);
    } catch (_) {}
};

// multi language (kept intact)
const i18n = reactive(useI18n());
const changeLanguage = (item: any) => {
    i18n.locale = item.code;
    appSetting.toggleLanguage(item);
};
const currentFlag = computed(() => {
    return `/assets/images/flags/${i18n.locale.toUpperCase()}.svg`;
});
</script>