<template>
    <div class="min-h-screen flex">

        <!-- ── Left Panel ── -->
        <div class="hidden lg:flex lg:w-[52%] xl:w-[50%] flex-col justify-between px-12 py-10 relative overflow-hidden bg-gradient-to-br from-[#1877F2] to-[#0D5DCC]">

            <!-- Background decorative circles -->
            <div class="absolute top-[-80px] right-[-80px] w-[320px] h-[320px] rounded-full bg-white/5"></div>
            <div class="absolute bottom-[-100px] left-[-60px] w-[400px] h-[400px] rounded-full bg-white/5"></div>
            <div class="absolute top-1/2 right-[-40px] w-[200px] h-[200px] rounded-full bg-white/5 -translate-y-1/2"></div>
            <div class="absolute bottom-[15%] right-[10%] w-[260px] h-[260px] rounded-full bg-white/5"></div>

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
                <p class="text-white/50 text-sm">© 2026 BizWai. All rights reserved.</p>
            </div>
        </div>

        <!-- ── Right Panel ── -->
        <div class="flex-1 flex flex-col items-center justify-center bg-white dark:bg-[#060818] px-6 py-12 sm:px-12">
            <div class="w-full max-w-[440px]">

                <!-- USER PORTAL badge -->
                <div class="mb-6">
                    <span class="inline-flex items-center gap-1.5 bg-primary/10 text-primary text-xs font-semibold px-3 py-1.5 rounded-full">
                        <span class="w-1.5 h-1.5 rounded-full bg-primary inline-block"></span>
                        USER PORTAL
                    </span>
                </div>

                <!-- Heading -->
                <div class="mb-8">
                    <h1 class="text-3xl font-bold text-gray-900 mb-2">Sign Up</h1>
                    <p class="text-gray-500 text-lg">Enter your email and password to register</p>
                </div>

                <!-- Form -->
                <form class="space-y-5 dark:text-white" @submit.prevent="handleRegister">

                    <!-- Error banner -->
                    <div v-if="authStore.error" class="rounded-md bg-danger/10 border border-danger/30 px-4 py-3 text-sm text-danger">
                        {{ authStore.error }}
                    </div>

                    <!-- Name -->
                    <div>
                        <label for="Name" class="mb-1 block text-sm font-medium text-gray-500">Name</label>
                        <div class="relative text-white-dark">
                            <input
                                id="Name"
                                v-model="form.name"
                                type="text"
                                placeholder="Enter Name"
                                class="w-full rounded-lg border border-gray-300 bg-white py-3 pl-10 pr-4 text-sm text-gray-900 placeholder:text-gray-500 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                required
                            />
                            <span class="absolute start-4 top-1/2 -translate-y-1/2">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-user w-4 h-4" aria-hidden="true"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                            </span>
                        </div>
                    </div>

                    <!-- Email -->
                    <div>
                        <label for="Email" class="mb-1 block text-sm font-medium text-gray-500">Email</label>
                        <div class="relative text-white-dark">
                            <input
                                id="Email"
                                v-model="form.email"
                                type="email"
                                placeholder="Enter Email"
                                class="w-full rounded-lg border border-gray-300 bg-white py-3 pl-10 pr-4 text-sm text-gray-900 placeholder:text-gray-500 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                required
                            />
                            <span class="absolute start-4 top-1/2 -translate-y-1/2">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-mail w-4 h-4" aria-hidden="true" x-file-name="Signup" x-line-number="156" x-column="20" x-component="Mail" x-id="Signup_156_20" x-dynamic="false"><path d="m22 7-8.991 5.727a2 2 0 0 1-2.009 0L2 7"></path><rect x="2" y="4" width="20" height="16" rx="2"></rect></svg>
                            </span>
                        </div>
                    </div>

                    <!-- Phone Number -->
                    <div>
                        <label for="Phone" class="mb-1 block text-sm font-medium text-gray-500">Phone Number</label>
                        <div class="relative text-white-dark">
                            <input
                                id="Phone"
                                v-model="form.phone"
                                type="tel"
                                placeholder="+1 (555) 000-0000"
                                class="w-full rounded-lg border border-gray-300 bg-white py-3 pl-10 pr-4 text-sm text-gray-900 placeholder:text-gray-500 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                required
                            />
                            <span class="absolute start-4 top-1/2 -translate-y-1/2">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-phone w-4 h-4" aria-hidden="true" x-file-name="Signup" x-line-number="166" x-column="20" x-component="Phone" x-id="Signup_166_20" x-dynamic="false"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                            </span>
                        </div>
                    </div>

                    <!-- Company name -->
                    <div>
                        <label for="Company" class="mb-1 block text-sm font-medium text-gray-500">Company Name</label>
                        <div class="relative text-white-dark">
                            <input
                                id="Company"
                                v-model="form.company_name"
                                type="text"
                                placeholder="Enter Company Name"
                                class="w-full rounded-lg border border-gray-300 bg-white py-3 pl-10 pr-4 text-sm text-gray-900 placeholder:text-gray-500 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                required
                            />
                            <span class="absolute start-4 top-1/2 -translate-y-1/2">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-building2 lucide-building-2 w-4 h-4" aria-hidden="true" x-file-name="Signup" x-line-number="176" x-column="20" x-component="Building2" x-id="Signup_176_20" x-dynamic="false"><path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"></path><path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"></path><path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"></path><path d="M10 6h4"></path><path d="M10 10h4"></path><path d="M10 14h4"></path><path d="M10 18h4"></path></svg>
                            </span>
                        </div>
                    </div>

                    <!-- Password -->
                    <div>
                        <label for="Password" class="mb-1 block text-sm font-medium text-gray-500">Password</label>
                        <div class="relative text-white-dark">
                            <input
                                id="Password"
                                v-model="form.password"
                                type="password"
                                placeholder="Enter Password"
                                class="w-full rounded-lg border border-gray-300 bg-white py-3 pl-10 pr-4 text-sm text-gray-900 placeholder:text-gray-500 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                required
                                minlength="8"
                            />
                            <span class="absolute start-4 top-1/2 -translate-y-1/2">
                                <svg width="18" height="18" viewBox="0 0 18 18" fill="none">
                                    <path opacity="0.5" d="M1.5 12C1.5 9.87868 1.5 8.81802 2.15901 8.15901C2.81802 7.5 3.87868 7.5 6 7.5H12C14.1213 7.5 15.182 7.5 15.841 8.15901C16.5 8.81802 16.5 9.87868 16.5 12C16.5 14.1213 16.5 15.182 15.841 15.841C15.182 16.5 14.1213 16.5 12 16.5H6C3.87868 16.5 2.81802 16.5 2.15901 15.841C1.5 15.182 1.5 14.1213 1.5 12Z" fill="currentColor"/>
                                    <path d="M6 12.75C6.41421 12.75 6.75 12.4142 6.75 12C6.75 11.5858 6.41421 11.25 6 11.25C5.58579 11.25 5.25 11.5858 5.25 12C5.25 12.4142 5.58579 12.75 6 12.75Z" fill="currentColor"/>
                                    <path d="M9 12.75C9.41421 12.75 9.75 12.4142 9.75 12C9.75 11.5858 9.41421 11.25 9 11.25C8.58579 11.25 8.25 11.5858 8.25 12C8.25 12.4142 8.58579 12.75 9 12.75Z" fill="currentColor"/>
                                    <path d="M12.75 12C12.75 12.4142 12.4142 12.75 12 12.75C11.5858 12.75 11.25 12.4142 11.25 12C11.25 11.5858 11.5858 11.25 12 11.25C12.4142 11.25 12.75 11.5858 12.75 12Z" fill="currentColor"/>
                                    <path d="M5.0625 6C5.0625 3.82538 6.82538 2.0625 9 2.0625C11.1746 2.0625 12.9375 3.82538 12.9375 6V7.50268C13.363 7.50665 13.7351 7.51651 14.0625 7.54096V6C14.0625 3.20406 11.7959 0.9375 9 0.9375C6.20406 0.9375 3.9375 3.20406 3.9375 6V7.54096C4.26488 7.51651 4.63698 7.50665 5.0625 7.50268V6Z" fill="currentColor"/>
                                </svg>
                            </span>
                        </div>
                    </div>

                    <!-- Confirm Password -->
                    <div>
                        <label for="ConfirmPassword" class="mb-1 block text-sm font-medium text-gray-500">Confirm Password</label>
                        <div class="relative text-white-dark">
                            <input
                                id="ConfirmPassword"
                                v-model="form.password_confirmation"
                                type="password"
                                placeholder="Confirm Password"
                                class="w-full rounded-lg border border-gray-300 bg-white py-3 pl-10 pr-4 text-sm text-gray-900 placeholder:text-gray-500 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                required
                                minlength="8"
                            />
                            <span class="absolute start-4 top-1/2 -translate-y-1/2">
                                <svg width="18" height="18" viewBox="0 0 18 18" fill="none">
                                    <path opacity="0.5" d="M1.5 12C1.5 9.87868 1.5 8.81802 2.15901 8.15901C2.81802 7.5 3.87868 7.5 6 7.5H12C14.1213 7.5 15.182 7.5 15.841 8.15901C16.5 8.81802 16.5 9.87868 16.5 12C16.5 14.1213 16.5 15.182 15.841 15.841C15.182 16.5 14.1213 16.5 12 16.5H6C3.87868 16.5 2.81802 16.5 2.15901 15.841C1.5 15.182 1.5 14.1213 1.5 12Z" fill="currentColor"/>
                                    <path d="M5.0625 6C5.0625 3.82538 6.82538 2.0625 9 2.0625C11.1746 2.0625 12.9375 3.82538 12.9375 6V7.50268C13.363 7.50665 13.7351 7.51651 14.0625 7.54096V6C14.0625 3.20406 11.7959 0.9375 9 0.9375C6.20406 0.9375 3.9375 3.20406 3.9375 6V7.54096C4.26488 7.51651 4.63698 7.50665 5.0625 7.50268V6Z" fill="currentColor"/>
                                </svg>
                            </span>
                        </div>
                        <p v-if="passwordMismatch" class="mt-1 text-xs text-danger">Passwords do not match.</p>
                    </div>

                    <!-- Submit -->
                    <button
                        type="submit"
                        :disabled="authStore.loading"
                        class="w-full rounded-lg bg-primary py-3 text-sm font-semibold uppercase tracking-wider text-white shadow-[0_10px_20px_-10px_rgba(67,97,238,0.6)] transition hover:bg-primary/90 disabled:opacity-60"
                    >
                        <span v-if="authStore.loading" class="animate-spin border-2 border-white border-l-transparent rounded-full w-4 h-4 inline-block mr-2"></span>
                        <span v-if="authStore.loading">Creating account...</span>
                        <span v-else>Sign Up</span>
                    </button>
                </form>

                <!-- Sign in link -->
                <div class="mt-6 text-center dark:text-white">
                    Already have an account ?
                    <router-link to="/auth/boxed-signin" class="uppercase text-primary underline transition hover:text-black dark:hover:text-white">
                        SIGN IN
                    </router-link>
                </div>

            </div>
        </div>

    </div>
</template>

<script lang="ts" setup>
import { computed, reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import appSetting from '@/app-setting';
import { useAppStore } from '@/stores/index';
import { useAuthStore } from '@/stores/auth';
import { useRouter } from 'vue-router';
import { useMeta } from '@/composables/use-meta';

useMeta({ title: 'Sign Up' });

const router    = useRouter();
const store     = useAppStore();
const authStore = useAuthStore();

const form = reactive({
    name:                  '',
    email:                 '',
    phone:                 '',       // → DB column: phone
    company_name:          '',       // → DB column: company_name
    password:              '',
    password_confirmation: '',
});

const passwordMismatch = computed(
    () => form.password_confirmation.length > 0 && form.password !== form.password_confirmation
);

const handleRegister = async () => {
    if (form.password !== form.password_confirmation) {
        authStore.error = 'Passwords do not match.';
        return;
    }
    try {
        await authStore.register(
            form.name,
            form.email,
            form.password,
            form.password_confirmation,
            form.phone,         // passed as 5th arg
            form.company_name,  // passed as 6th arg
        );
        router.push({ name: 'verify-email' });
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