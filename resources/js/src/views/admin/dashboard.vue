<template>
    <div>
        <ul class="flex space-x-2 rtl:space-x-reverse">
            <li>
                <a href="javascript:;" class="text-primary hover:underline">Admin</a>
            </li>
            <li class="before:content-['/'] ltr:before:mr-2 rtl:before:ml-2">
                <span>Dashboard</span>
            </li>
        </ul>

        <div class="pt-5">
            <!-- Welcome banner -->
            <div class="mb-6 flex items-center justify-between rounded-xl bg-gradient-to-r from-primary/90 to-blue-700 p-6 text-white shadow-[0_10px_30px_-10px_rgba(67,97,238,0.5)]">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-widest text-white/70">Welcome back,</p>
                    <h1 class="mt-1 text-2xl font-extrabold">{{ authStore.user?.name }}</h1>
                    <p class="mt-1 text-sm text-white/70">Administrator · BizWai Control Panel</p>
                </div>
                <div class="hidden sm:flex h-16 w-16 items-center justify-center rounded-full bg-white/20">
                    <svg class="h-8 w-8 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                            d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                    </svg>
                </div>
            </div>

            <!-- Stats cards -->
            <div class="mb-6 grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-4">
                <div v-for="stat in stats" :key="stat.label"
                    class="panel flex items-center justify-between rounded-xl p-5">
                    <div>
                        <p class="text-sm font-semibold text-white-dark">{{ stat.label }}</p>
                        <p class="mt-1 text-2xl font-bold text-dark dark:text-white">{{ stat.value }}</p>
                        <p class="mt-1 flex items-center gap-1 text-xs"
                            :class="stat.up ? 'text-success' : 'text-danger'">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    :d="stat.up ? 'M5 10l7-7m0 0l7 7m-7-7v18' : 'M19 14l-7 7m0 0l-7-7m7 7V3'" />
                            </svg>
                            {{ stat.change }}
                        </p>
                    </div>
                    <div class="flex h-12 w-12 items-center justify-center rounded-full"
                        :class="stat.iconBg">
                        <svg class="h-6 w-6" :class="stat.iconColor" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" :d="stat.iconPath" />
                        </svg>
                    </div>
                </div>
            </div>

            <!-- Quick access grid -->
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <!-- Recent activity -->
                <div class="panel col-span-2 rounded-xl p-5">
                    <div class="mb-5 flex items-center justify-between">
                        <h5 class="text-lg font-semibold dark:text-white">Recent Activity</h5>
                        <span class="badge bg-primary/10 text-primary">Live</span>
                    </div>
                    <div class="space-y-4">
                        <div v-for="item in activity" :key="item.text"
                            class="flex items-start gap-3 border-b border-[#f1f3f4] pb-4 dark:border-[#1b2e4b] last:border-0 last:pb-0">
                            <div class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full"
                                :class="item.iconBg">
                                <svg class="h-4 w-4" :class="item.iconColor" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="item.iconPath" />
                                </svg>
                            </div>
                            <div class="flex-1">
                                <p class="text-sm font-medium dark:text-white">{{ item.text }}</p>
                                <p class="text-xs text-white-dark">{{ item.time }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick links -->
                <div class="panel rounded-xl p-5">
                    <h5 class="mb-5 text-lg font-semibold dark:text-white">Quick Actions</h5>
                    <div class="space-y-3">
                        <button v-for="action in quickActions" :key="action.label"
                            class="flex w-full items-center gap-3 rounded-lg border border-[#e0e6ed] p-3 text-sm font-medium transition hover:border-primary hover:text-primary dark:border-[#1b2e4b] dark:hover:border-primary">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="action.iconPath" />
                            </svg>
                            {{ action.label }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script lang="ts" setup>
import { useAuthStore } from '@/stores/auth';
import { useMeta } from '@/composables/use-meta';

useMeta({ title: 'Admin Dashboard' });

const authStore = useAuthStore();

const stats = [
    { label: 'Total Users', value: '1,284', change: '+12% this month', up: true, iconBg: 'bg-primary/10', iconColor: 'text-primary', iconPath: 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z' },
    { label: 'Active Sessions', value: '342', change: '+5% today', up: true, iconBg: 'bg-success/10', iconColor: 'text-success', iconPath: 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z' },
    { label: 'Pending Verif.', value: '47', change: '-3 since yesterday', up: false, iconBg: 'bg-warning/10', iconColor: 'text-warning', iconPath: 'M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z' },
    { label: 'System Alerts', value: '2', change: '+2 new alerts', up: false, iconBg: 'bg-danger/10', iconColor: 'text-danger', iconPath: 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z' },
];

const activity = [
    { text: 'New user registered: john@example.com', time: '2 minutes ago', iconBg: 'bg-primary/10', iconColor: 'text-primary', iconPath: 'M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z' },
    { text: 'Email verification completed', time: '14 minutes ago', iconBg: 'bg-success/10', iconColor: 'text-success', iconPath: 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z' },
    { text: 'Admin login from 192.168.1.1', time: '1 hour ago', iconBg: 'bg-warning/10', iconColor: 'text-warning', iconPath: 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z' },
    { text: 'Database backup completed', time: '3 hours ago', iconBg: 'bg-info/10', iconColor: 'text-info', iconPath: 'M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4' },
];

const quickActions = [
    { label: 'Manage Users', iconPath: 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z' },
    { label: 'System Settings', iconPath: 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z' },
    { label: 'View Audit Logs', iconPath: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01' },
    { label: 'Email Broadcast', iconPath: 'M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z' },
    { label: 'Reports & Analytics', iconPath: 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z' },
];
</script>
