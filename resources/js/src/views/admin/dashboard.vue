<!-- resources/js/src/views/admin/dashboard.vue -->
<template>
    <div>
        <ul class="flex space-x-2 rtl:space-x-reverse text-base">
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
                    <p class="text-base tracking-widest text-white/70">Welcome back,</p>
                    <h1 class="mt-1 text-3xl font-bold">Dashboard</h1>
                    <p class="mt-1 text-base text-white/70">Administrator · BizWai Control Panel</p>
                </div>
                <div class="hidden sm:flex h-16 w-16 items-center justify-center rounded-full bg-white/20">
                    <svg class="h-8 w-8 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                            d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                    </svg>
                </div>
            </div>

            <!-- ── Loading skeleton ── -->
            <div v-if="dashStore.loading"
                class="mb-6 grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-4">
                <div v-for="i in 4" :key="i"
                    class="panel rounded-xl p-5 animate-pulse">
                    <div class="flex items-center justify-between">
                        <div class="space-y-3">
                            <div class="h-3 w-24 rounded bg-gray-200 dark:bg-gray-700"></div>
                            <div class="h-7 w-12 rounded bg-gray-200 dark:bg-gray-700"></div>
                            <div class="h-3 w-20 rounded bg-gray-200 dark:bg-gray-700"></div>
                        </div>
                        <div class="h-12 w-12 rounded-full bg-gray-200 dark:bg-gray-700"></div>
                    </div>
                </div>
            </div>

            <!-- ── Error state ── -->
            <div v-else-if="dashStore.error"
                class="mb-6 flex items-center gap-3 rounded-xl bg-danger/10 border border-danger/30 px-4 py-3 text-sm text-danger">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                {{ dashStore.error }}
                <button @click="dashStore.fetchStats()"
                    class="ml-auto text-xs underline hover:no-underline font-medium">
                    Retry
                </button>
            </div>

            <!-- ── Stats cards ── -->
            <div v-else
                class="mb-6 grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-4">
                <div v-for="stat in statCards" :key="stat.label"
                    class="panel flex items-center justify-between rounded-xl p-5">
                    <div>
                        <p class="text-sm text-white-dark">{{ stat.label }}</p>
                        <p class="mt-1 text-2xl font-bold text-dark dark:text-white">
                            {{ stat.value }}
                        </p>
                        <p class="mt-1.5 flex items-center gap-1 text-xs"
                            :class="stat.up ? 'text-success' : 'text-warning'">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    :d="stat.up
                                        ? 'M5 10l7-7m0 0l7 7m-7-7v18'
                                        : 'M19 14l-7 7m0 0l-7-7m7 7V3'"/>
                            </svg>
                            {{ stat.subtitle }}
                        </p>
                    </div>
                    <div class="flex h-12 w-12 items-center justify-center rounded-full"
                        :class="stat.iconBg">
                        <svg class="h-6 w-6" :class="stat.iconColor"
                            fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                stroke-width="1.5" :d="stat.iconPath"/>
                        </svg>
                    </div>
                </div>
            </div>

        </div>
    </div>
</template>

<script lang="ts" setup>
import { computed, onMounted } from 'vue';
import { useAdminDashboardStore } from '@/stores/adminDashboard';
import { useMeta } from '@/composables/use-meta';

useMeta({ title: 'Admin Dashboard' });

const dashStore = useAdminDashboardStore();

// Fetch stats when page loads
onMounted(() => dashStore.fetchStats());

// Build stat cards from live API data
const statCards = computed(() => {
    const s = dashStore.stats;
    return [
        {
            label:     'Total Users',
            value:     s?.total_users   ?? '—',
            subtitle:  'All registered users',
            up:        true,
            iconBg:    'bg-primary/10',
            iconColor: 'text-primary',
            iconPath:  'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z',
        },
        {
            label:     'Active Users',
            value:     s?.active_users  ?? '—',
            subtitle:  'Status approved',
            up:        true,
            iconBg:    'bg-success/10',
            iconColor: 'text-success',
            iconPath:  'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
        },
        {
            label:     'QBO Active Users',
            value:     s?.qbo_active    ?? '—',
            subtitle:  'QuickBooks connected',
            up:        true,
            iconBg:    'bg-info/10',
            iconColor: 'text-info',
            iconPath:  'M13 10V3L4 14h7v7l9-11h-7z',
        },
        {
            label:     'Pending Verif.',
            value:     s?.pending_verif ?? '—',
            subtitle:  'Email not verified',
            up:        false,
            iconBg:    'bg-warning/10',
            iconColor: 'text-warning',
            iconPath:  'M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z',
        },
    ];
});
</script>