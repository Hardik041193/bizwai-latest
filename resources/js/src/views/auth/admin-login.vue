<template>
    <div class="min-h-screen bg-[#0e1726] flex">
        <!-- Left panel: branding -->
        <div class="hidden lg:flex lg:w-1/2 flex-col justify-between p-12 bg-gradient-to-br from-[#0e1726] to-[#1a2744]">
            <div>
                <div class="flex items-center gap-3 mb-16">
                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-primary">
                        <svg class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                        </svg>
                    </div>
                    <span class="text-xl font-bold text-white">BizWai Admin</span>
                </div>
            </div>

            <div>
                <h1 class="text-4xl font-extrabold text-white leading-tight mb-4">
                    Admin Control<br />Panel
                </h1>
                <p class="text-white/50 text-base max-w-sm">
                    Secure administrative access. Only authorized administrators may sign in to this portal.
                </p>

                <div class="mt-12 space-y-4">
                    <div v-for="feature in features" :key="feature.text" class="flex items-center gap-3">
                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/20">
                            <svg class="h-4 w-4 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                        </div>
                        <span class="text-white/70 text-sm">{{ feature.text }}</span>
                    </div>
                </div>
            </div>

            <p class="text-white/20 text-sm">© {{ new Date().getFullYear() }} BizWai. All rights reserved.</p>
        </div>

        <!-- Right panel: login form -->
        <div class="flex w-full lg:w-1/2 flex-col items-center justify-center px-6 py-12">
            <div class="w-full max-w-md">

                <!-- Mobile logo -->
                <div class="flex lg:hidden items-center justify-center gap-3 mb-10">
                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-primary">
                        <svg class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                        </svg>
                    </div>
                    <span class="text-xl font-bold text-white">BizWai Admin</span>
                </div>

                <div class="mb-8">
                    <div class="inline-flex items-center gap-2 rounded-full bg-primary/10 border border-primary/20 px-3 py-1 mb-4">
                        <span class="h-2 w-2 rounded-full bg-primary animate-pulse"></span>
                        <span class="text-xs font-semibold uppercase tracking-widest text-primary">Admin Portal</span>
                    </div>
                    <h2 class="text-2xl font-extrabold text-white">Administrator Sign In</h2>
                    <p class="mt-1 text-sm text-white/50">Enter your admin credentials to access the control panel.</p>
                </div>

                <form @submit.prevent="handleAdminLogin" class="space-y-5">
                    <!-- Error message -->
                    <div v-if="authStore.error" class="rounded-lg bg-danger/10 border border-danger/30 px-4 py-3 text-sm text-danger flex items-start gap-2">
                        <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.062 16.5c-.77.833.192 2.5 1.732 2.5z" />
                        </svg>
                        <span>{{ authStore.error }}</span>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-white/70">Email Address</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-white/30">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207" />
                                </svg>
                            </span>
                            <input
                                v-model="form.email"
                                type="email"
                                placeholder="Enter admin email"
                                required
                                class="w-full rounded-lg border border-white/10 bg-white/5 py-3 pl-10 pr-4 text-sm text-white placeholder:text-white/30 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                            />
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-white/70">Password</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-white/30">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                </svg>
                            </span>
                            <input
                                v-model="form.password"
                                :type="showPassword ? 'text' : 'password'"
                                placeholder="Enter your password"
                                required
                                class="w-full rounded-lg border border-white/10 bg-white/5 py-3 pl-10 pr-10 text-sm text-white placeholder:text-white/30 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                            />
                            <button type="button" class="absolute inset-y-0 right-0 flex items-center pr-3 text-white/30 hover:text-white/70" @click="showPassword = !showPassword">
                                <svg v-if="!showPassword" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                </svg>
                                <svg v-else class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <button
                        type="submit"
                        :disabled="authStore.loading"
                        class="w-full rounded-lg bg-primary py-3 text-sm font-semibold uppercase tracking-wider text-white shadow-[0_10px_20px_-10px_rgba(67,97,238,0.6)] transition hover:bg-primary/90 disabled:opacity-60"
                    >
                        <span v-if="authStore.loading" class="flex items-center justify-center gap-2">
                            <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            Signing in...
                        </span>
                        <span v-else>Sign In to Admin Panel</span>
                    </button>
                </form>

                <div class="mt-8 border-t border-white/10 pt-6 text-center">
                    <router-link to="/auth/boxed-signin" class="text-sm text-white/40 transition hover:text-white/70">
                        ← Back to User Login
                    </router-link>
                </div>
            </div>
        </div>
    </div>
</template>

<script lang="ts" setup>
import { reactive, ref } from 'vue';
import { useAuthStore } from '@/stores/auth';
import { useRouter } from 'vue-router';
import { useMeta } from '@/composables/use-meta';

useMeta({ title: 'Admin Login' });

const authStore = useAuthStore();
const router = useRouter();

const form = reactive({ email: '', password: '' });
const showPassword = ref(false);

const features = [
    { text: 'Full dashboard & analytics access' },
    { text: 'User management & permissions' },
    { text: 'System configuration controls' },
    { text: 'Audit logs & activity monitoring' },
];

const handleAdminLogin = async () => {
    try {
        await authStore.adminLogin(form.email, form.password);
        router.push({ name: 'admin-home' });
    } catch (_) {}
};
</script>
