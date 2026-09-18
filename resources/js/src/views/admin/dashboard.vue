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

            <!-- ── Charts: User growth trend + Platform Health Score ── -->
            <div class="mb-6 grid grid-cols-1 gap-6 xl:grid-cols-3">

                <!-- User Growth Trend -->
                <div class="panel h-full rounded-xl xl:col-span-2">
                    <div class="mb-5 flex items-center justify-between">
                        <h5 class="text-lg font-semibold text-dark dark:text-white-light">
                            User Growth Trend
                        </h5>
                        <span class="text-xs text-white-dark">Last {{ dashStore.charts?.months ?? 12 }} months</span>
                    </div>

                    <div v-if="dashStore.chartsLoading"
                        class="h-[325px] animate-pulse rounded-lg bg-gray-200 dark:bg-gray-700"></div>

                    <div v-else-if="dashStore.chartsError"
                        class="flex h-[325px] flex-col items-center justify-center gap-3 text-sm text-danger">
                        {{ dashStore.chartsError }}
                        <button @click="dashStore.fetchCharts()"
                            class="text-xs underline hover:no-underline font-medium">
                            Retry
                        </button>
                    </div>

                    <apexchart v-else height="325" :options="growthChart" :series="growthSeries"
                        class="overflow-hidden rounded-lg bg-white dark:bg-black"></apexchart>
                </div>

                <!-- Platform Health Score -->
                <div class="panel h-full rounded-xl">
                    <div class="mb-5 flex items-center justify-between">
                        <h5 class="text-lg font-semibold text-dark dark:text-white-light">
                            Platform Health Score
                        </h5>
                    </div>

                    <div v-if="dashStore.chartsLoading"
                        class="h-[325px] animate-pulse rounded-lg bg-gray-200 dark:bg-gray-700"></div>

                    <div v-else-if="dashStore.chartsError"
                        class="flex h-[325px] items-center justify-center text-sm text-danger">
                        Score unavailable
                    </div>

                    <template v-else>
                        <apexchart height="325" :options="healthChart" :series="healthSeries"
                            class="overflow-hidden rounded-lg bg-white dark:bg-black"></apexchart>

                        <div class="grid grid-cols-3 gap-2 text-center">
                            <div v-for="part in healthBreakdown" :key="part.label">
                                <p class="text-base font-semibold text-dark dark:text-white">{{ part.value }}%</p>
                                <p class="text-xs text-white-dark">{{ part.label }}</p>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <!-- ── Quick Insights ── -->
            <div class="panel mb-6 rounded-xl">
                <h5 class="mb-5 text-lg font-semibold text-dark dark:text-white-light">Quick Insights</h5>

                <div v-if="dashStore.chartsLoading" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <div v-for="i in 4" :key="i" class="h-20 animate-pulse rounded-lg bg-gray-200 dark:bg-gray-700"></div>
                </div>

                <div v-else class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <div v-for="insight in quickInsights" :key="insight.label"
                        class="flex items-center gap-3 rounded-lg bg-[#f6f8fa] px-4 py-3 dark:bg-[#1b2e4b]">
                        <span class="h-2.5 w-2.5 shrink-0 rounded-full" :class="insight.dot"></span>
                        <div>
                            <p class="text-lg font-bold text-dark dark:text-white">{{ insight.value }}</p>
                            <p class="text-xs text-white-dark">{{ insight.label }}</p>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</template>

<script lang="ts" setup>
import { computed, onMounted } from 'vue';
import apexchart from 'vue3-apexcharts';
import { useAdminDashboardStore } from '@/stores/adminDashboard';
import { useAppStore } from '@/stores/index';
import { useMeta } from '@/composables/use-meta';

useMeta({ title: 'Admin Dashboard' });

const dashStore = useAdminDashboardStore();
const store     = useAppStore();

// Fetch stats and chart data when page loads
onMounted(() => {
    dashStore.fetchStats();
    dashStore.fetchCharts();
});


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

// ── User Growth Trend ──
const growthSeries = computed(() => {
    const trend = dashStore.charts?.user_growth;
    return [
        { name: 'Signups', data: trend?.signups ?? [] },
        { name: 'QBO Connections', data: trend?.connections ?? [] },
    ];
});

const growthChart = computed(() => {
    const isDark = store.theme === 'dark' || store.isDarkMode ? true : false;
    const isRtl = store.rtlClass === 'rtl' ? true : false;

    return {
        chart: {
            height: 325,
            type: 'area',
            fontFamily: 'Nunito, sans-serif',
            zoom: { enabled: false },
            toolbar: { show: false },
        },
        dataLabels: { enabled: false },
        stroke: { show: true, curve: 'smooth', width: 2, lineCap: 'square' },
        colors: isDark ? ['#2196f3', '#00ab55'] : ['#4361ee', '#00ab55'],
        labels: dashStore.charts?.user_growth.labels ?? [],
        xaxis: {
            axisBorder: { show: false },
            axisTicks: { show: false },
            crosshairs: { show: true },
            labels: {
                offsetX: isRtl ? 2 : 0,
                offsetY: 5,
                style: { fontSize: '12px', cssClass: 'apexcharts-xaxis-title' },
            },
        },
        yaxis: {
            tickAmount: 5,
            min: 0,
            forceNiceScale: true,
            labels: {
                formatter: (value: number) => `${Math.round(value)}`,
                offsetX: isRtl ? -30 : -10,
                style: { fontSize: '12px', cssClass: 'apexcharts-yaxis-title' },
            },
            opposite: isRtl ? true : false,
        },
        grid: {
            borderColor: isDark ? '#191e3a' : '#e0e6ed',
            strokeDashArray: 5,
            xaxis: { lines: { show: true } },
            yaxis: { lines: { show: false } },
        },
        legend: {
            position: 'top',
            horizontalAlign: 'right',
            fontSize: '14px',
            markers: { width: 10, height: 10, offsetX: -2 },
            itemMargin: { horizontal: 10, vertical: 5 },
        },
        tooltip: {
            marker: { show: true },
            y: { formatter: (value: number) => `${Math.round(value)} users` },
        },
        fill: {
            type: 'gradient',
            gradient: {
                shadeIntensity: 1,
                inverseColors: false,
                opacityFrom: isDark ? 0.19 : 0.28,
                opacityTo: 0.05,
                stops: isDark ? [100, 100] : [45, 100],
            },
        },
    };
});

// ── Platform Health Score ──
const healthSeries = computed(() => [dashStore.charts?.health_score.score ?? 0]);

const healthChart = computed(() => {
    const isDark = store.theme === 'dark' || store.isDarkMode ? true : false;
    const label = dashStore.charts?.health_score.label ?? 'Health Score';

    return {
        chart: {
            height: 325,
            type: 'radialBar',
            fontFamily: 'Nunito, sans-serif',
            toolbar: { show: false },
        },
        colors: ['#4361ee'],
        plotOptions: {
            radialBar: {
                hollow: { size: '62%' },
                track: { background: isDark ? '#191e3a' : '#e0e6ed', strokeWidth: '100%' },
                dataLabels: {
                    name: {
                        offsetY: 28,
                        fontSize: '13px',
                        color: isDark ? '#888ea8' : '#888ea8',
                    },
                    value: {
                        offsetY: -12,
                        fontSize: '34px',
                        fontWeight: 700,
                        color: isDark ? '#e0e6ed' : '#0e1726',
                        formatter: (value: number) => `${Math.round(value)}`,
                    },
                },
            },
        },
        labels: [label],
        stroke: { lineCap: 'round' },
        fill: { opacity: 0.9 },
    };
});

// Score contributors, shown under the radial chart
const healthBreakdown = computed(() => {
    const parts = dashStore.charts?.health_score.breakdown;
    return [
        { label: 'QBO Adoption', value: parts?.adoption ?? 0 },
        { label: 'Verified', value: parts?.verification ?? 0 },
        { label: 'Sync Fresh', value: parts?.freshness ?? 0 },
    ];
});

// ── Quick Insights rail ──
const quickInsights = computed(() => {
    const i = dashStore.charts?.quick_insights;
    return [
        { label: 'New Users (30d)', value: String(i?.new_users_30d ?? 0), dot: 'bg-success' },
        { label: 'Unread Messages', value: String(i?.unread_messages ?? 0), dot: 'bg-info' },
        { label: 'Connected Companies', value: String(i?.connected_companies ?? 0), dot: 'bg-primary' },
        { label: 'Stale Syncs', value: String(i?.stale_syncs ?? 0), dot: 'bg-warning' },
    ];
});
</script>