<template>
    <div class="min-h-screen bg-[#0e1726] flex">

        <!-- ── Left Panel ── -->
        <div class="hidden lg:flex lg:w-[40%] flex-col justify-between p-12 bg-gradient-to-br from-[#182051] to-[#161e4e] relative overflow-hidden">

            <!-- Logo -->
            <div class="relative z-10">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-white rounded-xl flex items-center justify-center shadow-md">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-6 h-6 text-[#1877F2]">
                            <path d="M3 3v16a2 2 0 0 0 2 2h16"></path>
                            <path d="M18 17V9"></path>
                            <path d="M13 17V5"></path>
                            <path d="M8 17v-3"></path>
                        </svg>
                    </div>
                    <span class="text-white text-2xl font-bold tracking-tight">BizWai</span>
                </div>
            </div>

            <!-- Center content -->
            <div class="relative z-10 flex-1 flex flex-col justify-center">

                <!-- ADMIN PORTAL label -->
                <p class="text-[#4f8ef7] text-xs font-bold uppercase tracking-widest mb-4">Admin Portal</p>

                <!-- Headline -->
                <h1 class="text-4xl xl:text-5xl font-bold text-white leading-tight mb-2">
                    Welcome back,<br />Admin 👋
                </h1>

                <!-- Blue underline accent -->
                <div class="w-10 h-1 bg-[#4f8ef7] rounded-full mt-4 mb-6"></div>

                <!-- Subtext -->
                <p class="text-white/70 text-base leading-relaxed max-w-xs mb-10">
                    Manage your QuickBooks data, visualize insights, and make smarter decisions — all in one place.
                </p>

                <!-- Dashboard preview card -->
                <div class="rounded-2xl overflow-hidden bg-[#0e1726]/80 border border-white/10 shadow-2xl">
                    <!-- Mock top bar -->
                    <div class="flex items-center gap-2 px-4 py-3 bg-[#1a2744] border-b border-white/10">
                        <div class="flex gap-1.5">
                            <span class="w-2.5 h-2.5 rounded-full bg-white/20"></span>
                            <span class="w-2.5 h-2.5 rounded-full bg-white/20"></span>
                            <span class="w-2.5 h-2.5 rounded-full bg-white/20"></span>
                        </div>
                        <div class="flex-1 mx-3 h-2 bg-white/10 rounded-full"></div>
                    </div>

                    <!-- Mock dashboard body -->
                    <div class="flex">
                        <!-- Sidebar -->
                        <div class="w-14 bg-[#111c36] border-r border-white/5 py-4 flex flex-col items-center gap-3">
                            <div class="w-7 h-2 bg-[#4f8ef7] rounded-full"></div>
                            <div class="w-7 h-2 bg-white/20 rounded-full"></div>
                            <div class="w-7 h-2 bg-white/20 rounded-full"></div>
                            <div class="w-7 h-2 bg-white/20 rounded-full"></div>
                            <div class="w-7 h-2 bg-white/20 rounded-full"></div>
                            <div class="w-7 h-2 bg-white/20 rounded-full"></div>
                        </div>

                        <!-- Main content -->
                        <div class="flex-1 p-4 space-y-3">
                            <!-- Stat cards row -->
                            <div class="grid grid-cols-2 gap-3">
                                <!-- Total Revenue -->
                                <div class="bg-[#1a2744] rounded-xl p-3">
                                    <p class="text-white/40 text-[10px] mb-1">Total Revenue</p>
                                    <p class="text-white font-bold text-base">$128,540</p>
                                    <p class="text-green-400 text-[10px] mt-0.5">▲ 12.5%</p>
                                    <!-- Mini line chart -->
                                    <svg class="w-full mt-2" height="28" viewBox="0 0 100 28" preserveAspectRatio="none">
                                        <polyline points="0,24 20,18 40,20 60,10 80,14 100,4" fill="none" stroke="#4f8ef7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </div>

                                <!-- Total Expenses -->
                                <div class="bg-[#1a2744] rounded-xl p-3">
                                    <p class="text-white/40 text-[10px] mb-1">Total Expenses</p>
                                    <p class="text-white font-bold text-base">$64,230</p>
                                    <p class="text-red-400 text-[10px] mt-0.5">▼ 8.3%</p>
                                    <!-- Mini line chart -->
                                    <svg class="w-full mt-2" height="28" viewBox="0 0 100 28" preserveAspectRatio="none">
                                        <polyline points="0,8 20,14 40,10 60,18 80,14 100,22" fill="none" stroke="#f97316" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </div>
                            </div>

                            <!-- Profit + Donut row -->
                            <div class="grid grid-cols-2 gap-3">
                                <!-- Profit -->
                                <div class="bg-[#1a2744] rounded-xl p-3">
                                    <p class="text-white/40 text-[10px] mb-1">Profit</p>
                                    <p class="text-white font-bold text-base">$64,310</p>
                                    <p class="text-green-400 text-[10px] mt-0.5">▲ 18.7%</p>
                                    <!-- Mini line chart -->
                                    <svg class="w-full mt-2" height="28" viewBox="0 0 100 28" preserveAspectRatio="none">
                                        <polyline points="0,22 20,16 40,18 60,8 80,12 100,4" fill="none" stroke="#22c55e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </div>

                                <!-- Donut chart -->
                                <div class="bg-[#1a2744] rounded-xl p-3 flex items-center justify-center gap-3">
                                    <svg width="52" height="52" viewBox="0 0 52 52">
                                        <circle cx="26" cy="26" r="20" fill="none" stroke="#1e3a5f" stroke-width="10"/>
                                        <circle cx="26" cy="26" r="20" fill="none" stroke="#4f8ef7" stroke-width="10"
                                            stroke-dasharray="50 75.4" stroke-dashoffset="25" stroke-linecap="round"/>
                                        <circle cx="26" cy="26" r="20" fill="none" stroke="#22c55e" stroke-width="10"
                                            stroke-dasharray="28 75.4" stroke-dashoffset="-25" stroke-linecap="round"/>
                                        <circle cx="26" cy="26" r="20" fill="none" stroke="#f97316" stroke-width="10"
                                            stroke-dasharray="20 75.4" stroke-dashoffset="-53" stroke-linecap="round"/>
                                    </svg>
                                    <div class="space-y-1">
                                        <div class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-[#4f8ef7] shrink-0"></span><span class="text-white/50 text-[9px]">Revenue</span></div>
                                        <div class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-green-400 shrink-0"></span><span class="text-white/50 text-[9px]">Profit</span></div>
                                        <div class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-orange-400 shrink-0"></span><span class="text-white/50 text-[9px]">Expenses</span></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="relative z-10">
                <p class="text-white/40 text-sm">© {{ new Date().getFullYear() }} BizWai. All rights reserved.</p>
            </div>
        </div>

        <!-- ── Right Panel ── -->
        <div class="flex w-full lg:w-[60%] flex-col items-center justify-center px-6 bg-[#f0f4ff]">
            <div class="w-full max-w-md">

                <!-- Card -->
                <div class="bg-white rounded-2xl shadow-xl px-8 py-10">

                    <!-- Shield icon -->
                    <div class="flex justify-center mb-6">
                        <div class="w-16 h-16 rounded-full bg-primary/10 flex items-center justify-center">
                            <svg class="w-8 h-8 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                            </svg>
                        </div>
                    </div>

                    <!-- Heading -->
                    <div class="text-center mb-8">
                        <h2 class="text-2xl font-bold text-gray-900 mb-1">Administrator Sign In</h2>
                        <p class="text-gray-400 text-sm">Please enter your credentials to continue.</p>
                    </div>

                    <!-- Form -->
                    <form @submit.prevent="handleAdminLogin" class="space-y-5">

                        <!-- Error -->
                        <div v-if="authStore.error" class="rounded-lg bg-danger/10 border border-danger/30 px-4 py-3 text-sm text-danger flex items-start gap-2">
                            <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.062 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                            </svg>
                            <span>{{ authStore.error }}</span>
                        </div>

                        <!-- Email -->
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-gray-700">Email Address</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-gray-400">
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                                    </svg>
                                </span>
                                <input
                                    v-model="form.email"
                                    type="email"
                                    placeholder="Enter admin email"
                                    required
                                    class="w-full rounded-xl border border-gray-200 bg-gray-50 py-3 pl-11 pr-4 text-sm text-gray-900 placeholder:text-gray-400 focus:border-primary focus:bg-white focus:outline-none focus:ring-2 focus:ring-primary/20 transition"
                                />
                            </div>
                        </div>

                        <!-- Password -->
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-gray-700">Password</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-gray-400">
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                                    </svg>
                                </span>
                                <input
                                    v-model="form.password"
                                    :type="showPassword ? 'text' : 'password'"
                                    placeholder="Enter your password"
                                    required
                                    class="w-full rounded-xl border border-gray-200 bg-gray-50 py-3 pl-11 pr-11 text-sm text-gray-900 placeholder:text-gray-400 focus:border-primary focus:bg-white focus:outline-none focus:ring-2 focus:ring-primary/20 transition"
                                />
                                <button
                                    type="button"
                                    class="absolute inset-y-0 right-0 flex items-center pr-3.5 text-gray-400 hover:text-gray-600 transition"
                                    @click="showPassword = !showPassword"
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

                        <!-- Remember me + Forgot password -->
                        <div class="flex items-center justify-between">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" class="w-4 h-4 rounded border-gray-300 text-primary accent-primary" />
                                <span class="text-sm text-gray-600">Remember me</span>
                            </label>
                            <!-- <a href="javascript:" class="text-sm text-primary font-medium hover:underline">Forgot password?</a> -->
                        </div>

                        <!-- Submit -->
                        <button
                            type="submit"
                            :disabled="authStore.loading"
                            class="w-full rounded-xl bg-primary py-3 text-sm font-bold uppercase tracking-widest text-white shadow-[0_8px_24px_-8px_rgba(67,97,238,0.6)] transition hover:bg-primary/90 disabled:opacity-60 flex items-center justify-center gap-2"
                        >
                            <svg v-if="authStore.loading" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <span v-if="authStore.loading">Signing in...</span>
                            <span v-else>Sign In to Admin Panel →</span>
                        </button>

                        <!-- Divider -->
                        <!-- <div class="flex items-center gap-3">
                            <div class="flex-1 h-px bg-gray-200"></div>
                            <span class="text-xs text-gray-400 font-medium uppercase tracking-wider">or</span>
                            <div class="flex-1 h-px bg-gray-200"></div>
                        </div> -->

                       

                    </form>
                </div>

                <!-- Secure access note -->
                <div class="mt-5 flex items-center justify-center gap-2 text-gray-400 text-sm">
                    <svg class="w-4 h-4 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                    </svg>
                    Secure access to your dashboard
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

const handleAdminLogin = async () => {
    try {
        await authStore.adminLogin(form.email, form.password);
        router.push({ name: 'admin-home' });
    } catch (_) {}
};
</script>