<template>
    <div>
        <!-- Breadcrumb -->
        <ul class="flex space-x-2 rtl:space-x-reverse mb-6">
            <li><router-link to="/" class="text-primary hover:underline">Dashboard</router-link></li>
            <li class="before:content-['/'] ltr:before:mr-2 rtl:before:ml-2 text-white-dark">Executive Dashboard</li>
        </ul>

        <!-- Not Connected Banner -->
        <div v-if="!qbStore.isConnected" class="panel mb-6">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-full bg-warning/20 flex items-center justify-center flex-shrink-0">
                    <svg class="w-6 h-6 text-warning" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M12 3a9 9 0 110 18A9 9 0 0112 3z"/>
                    </svg>
                </div>
                <div class="flex-1">
                    <h5 class="font-semibold text-base">QuickBooks Not Connected</h5>
                    <p class="text-white-dark/70 text-sm">Connect your QuickBooks account to see the executive dashboard.</p>
                </div>
                <router-link to="/quickbooks/connect" class="btn btn-success btn-sm gap-2 flex-shrink-0">
                    Connect Now
                </router-link>
            </div>
        </div>

        <template v-else>
            <!-- Header -->
            <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
                <div>
                    <h1 class="text-2xl font-bold">Executive Dashboard</h1>
                    <p class="text-white-dark/60 text-sm mt-1">High-level business health snapshot for owners and CEOs</p>
                </div>
                <div class="flex items-center gap-2">
                    <span v-if="qbStore.isConnected" class="badge badge-outline-success text-xs">QBO Connected</span>
                    <span v-if="qbStore.summary?.last_synced_at" class="text-xs text-white-dark/60">
                        Last sync: {{ formatTime(qbStore.summary.last_synced_at) }}
                    </span>
                </div>
            </div>

            <!-- Sync warning -->
            <div v-if="dash?.sync_warning" class="panel mb-6 border-l-4 border-warning">
                <div class="flex items-center gap-3">
                    <svg class="w-5 h-5 text-warning flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M12 3a9 9 0 110 18A9 9 0 0112 3z"/>
                    </svg>
                    <div class="text-sm">
                        <span class="font-semibold">Data may be incomplete.</span>
                        <span v-if="dash.sync_warning.failed_entities.length">
                            Failed to sync: {{ dash.sync_warning.failed_entities.join(', ') }}.
                        </span>
                        <span v-if="dash.sync_warning.pending_entities.length">
                            Still syncing: {{ dash.sync_warning.pending_entities.join(', ') }}.
                        </span>
                    </div>
                </div>
            </div>

            <!-- Loading -->
            <div v-if="qbStore.loading && !dash" class="flex justify-center py-16">
                <span class="animate-spin border-4 border-primary border-l-transparent rounded-full w-10 h-10"></span>
            </div>

            <!-- Error -->
            <div v-else-if="qbStore.error && !dash" class="panel text-center py-10 text-danger">
                {{ qbStore.error }}
            </div>

            <template v-else-if="dash">
                <div class="grid xl:grid-cols-4 gap-6">
                    <!-- ── Left column: overview, trend, questions ── -->
                    <div class="xl:col-span-3 space-y-6">
                        <h5 class="font-semibold text-lg">CEO Overview</h5>

                        <!-- CEO Overview cards -->
                        <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
                            <div class="panel">
                                <div class="flex items-center gap-2 mb-2">
                                    <span class="w-2.5 h-2.5 rounded-full bg-primary"></span>
                                    <span class="text-white-dark/70 text-sm">Cash</span>
                                </div>
                                <div class="text-2xl font-bold">{{ dash.ceo_overview.cash.formatted ?? '—' }}</div>
                                <div class="text-xs text-white-dark/50 mt-1">{{ dash.ceo_overview.cash.subtext ?? 'Company-wide figure' }}</div>
                            </div>

                            <div class="panel">
                                <div class="flex items-center gap-2 mb-2">
                                    <span class="w-2.5 h-2.5 rounded-full bg-success"></span>
                                    <span class="text-white-dark/70 text-sm">Revenue MTD</span>
                                </div>
                                <div class="text-2xl font-bold">{{ dash.ceo_overview.revenue_mtd.formatted ?? '—' }}</div>
                                <div class="text-xs text-white-dark/50 mt-1">{{ dash.ceo_overview.revenue_mtd.subtext }}</div>
                            </div>

                            <div class="panel">
                                <div class="flex items-center gap-2 mb-2">
                                    <span class="w-2.5 h-2.5 rounded-full bg-secondary"></span>
                                    <span class="text-white-dark/70 text-sm">Net Profit</span>
                                </div>
                                <div class="text-2xl font-bold">{{ dash.ceo_overview.net_profit.formatted ?? '—' }}</div>
                                <div class="text-xs text-white-dark/50 mt-1">{{ dash.ceo_overview.net_profit.subtext }}</div>
                            </div>

                            <div class="panel">
                                <div class="flex items-center gap-2 mb-2">
                                    <span class="w-2.5 h-2.5 rounded-full bg-warning"></span>
                                    <span class="text-white-dark/70 text-sm">AR Overdue</span>
                                </div>
                                <div class="text-2xl font-bold">{{ dash.ceo_overview.ar_overdue.formatted ?? '—' }}</div>
                                <div class="text-xs text-white-dark/50 mt-1">{{ dash.ceo_overview.ar_overdue.subtext }}</div>
                            </div>
                        </div>

                        <!-- Trend + Health score -->
                        <div class="grid lg:grid-cols-3 gap-6">
                            <div class="panel lg:col-span-2">
                                <div class="flex items-center justify-between mb-3">
                                    <h5 class="font-semibold text-lg">Revenue and Profit Trend</h5>
                                </div>
                                <p v-if="trendError" class="text-xs text-white-dark/60 mb-2">{{ trendError }}</p>
                                <apexchart
                                    height="300"
                                    :options="trendChartOptions"
                                    :series="trendSeries"
                                    class="bg-white dark:bg-black rounded-lg overflow-hidden"
                                ></apexchart>
                            </div>

                            <div class="panel flex flex-col items-center justify-center">
                                <h5 class="font-semibold text-lg self-start mb-3">Business Health Score</h5>
                                <apexchart
                                    height="220"
                                    :options="healthChartOptions"
                                    :series="healthSeries"
                                    class="w-full"
                                ></apexchart>
                                <div class="text-sm text-white-dark/70 -mt-2">{{ dash.charts.business_health_score.status }}</div>
                            </div>
                        </div>
                    </div>

                    <!-- ── Right column: side metrics + AI insights ── -->
                    <div class="space-y-6">
                        <div class="panel space-y-4">
                            <div class="flex items-center gap-3">
                                <span class="w-2.5 h-2.5 rounded-full bg-success"></span>
                                <div>
                                    <div class="font-semibold">{{ dash.side_metrics.revenue_mtd ?? '—' }}</div>
                                    <div class="text-xs text-white-dark/50">Revenue MTD</div>
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="w-2.5 h-2.5 rounded-full bg-info"></span>
                                <div>
                                    <div class="font-semibold">{{ dash.side_metrics.active_customers }}</div>
                                    <div class="text-xs text-white-dark/50">Active Customers</div>
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="w-2.5 h-2.5 rounded-full bg-primary"></span>
                                <div>
                                    <div class="font-semibold">{{ dash.side_metrics.cash_flow ?? '—' }}</div>
                                    <div class="text-xs text-white-dark/50">Cash Flow</div>
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="w-2.5 h-2.5 rounded-full bg-warning"></span>
                                <div>
                                    <div class="font-semibold">{{ dash.side_metrics.open_invoices }}</div>
                                    <div class="text-xs text-white-dark/50">Open Invoices</div>
                                </div>
                            </div>
                        </div>

                        <div class="panel">
                            <h5 class="font-semibold text-lg mb-4">AI Insights</h5>
                            <div class="space-y-3">
                                <div
                                    v-for="insight in insights"
                                    :key="insight.title"
                                    class="rounded-lg p-3 border-l-4"
                                    :class="insight.classes"
                                >
                                    <div class="flex items-center gap-2">
                                        <span class="w-2 h-2 rounded-full flex-shrink-0" :class="insight.dot"></span>
                                        <span class="font-semibold text-sm">{{ insight.title }}</span>
                                    </div>
                                    <div class="text-xs text-white-dark/60 mt-1">{{ insight.detail }}</div>
                                </div>
                                <div v-if="!insights.length" class="text-sm text-white-dark/50">
                                    No insights yet — figures are still syncing.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </template>
    </div>
</template>

<script lang="ts" setup>
import { computed, onMounted } from 'vue';
import apexchart from 'vue3-apexcharts';
import { useMeta } from '@/composables/use-meta';
import { useAppStore } from '@/stores/index';
import { useQuickBooksStore } from '@/stores/quickbooks';

useMeta({ title: 'Executive Dashboard' });

const store = useAppStore();
const qbStore = useQuickBooksStore();

const dash = computed(() => qbStore.executiveDashboard);
const trendError = computed(() => dash.value?.charts.revenue_profit_trend.error ?? null);

// ── Revenue/Profit trend chart ──────────────────────────────────────────
const trendSeries = computed(() => {
    const datasets = dash.value?.charts.revenue_profit_trend.datasets ?? [];
    return datasets.map(d => ({ name: d.name, data: d.data }));
});

const trendChartOptions = computed(() => {
    const isDark = store.theme === 'dark' || store.isDarkMode ? true : false;
    const datasets = dash.value?.charts.revenue_profit_trend.datasets ?? [];
    const colors = datasets.map(d => d.color) || ['#2563eb', '#10b981'];

    return {
        chart: {
            height: 300,
            type: 'line',
            fontFamily: 'Plus Jakarta Sans, sans-serif',
            zoom: { enabled: false },
            toolbar: { show: false },
        },
        dataLabels: { enabled: false },
        stroke: { show: true, curve: 'smooth', width: 3 },
        colors,
        labels: dash.value?.charts.revenue_profit_trend.labels ?? [],
        xaxis: {
            categories: dash.value?.charts.revenue_profit_trend.labels ?? [],
            axisBorder: { show: false },
            axisTicks: { show: false },
            labels: { style: { fontSize: '12px' } },
        },
        yaxis: {
            labels: {
                formatter: (value: number) => (Math.abs(value) >= 1000 ? `${Math.round(value / 1000)}K` : `${Math.round(value)}`),
            },
        },
        grid: {
            borderColor: isDark ? '#191e3a' : '#e0e6ed',
            strokeDashArray: 5,
        },
        legend: {
            position: 'top',
            horizontalAlign: 'right',
        },
        tooltip: {
            theme: isDark ? 'dark' : 'light',
            y: { formatter: (value: number) => `$${value.toLocaleString()}` },
        },
    };
});

// ── Business health gauge ───────────────────────────────────────────────
const healthSeries = computed(() => [dash.value?.charts.business_health_score.score ?? 0]);

const healthChartOptions = computed(() => {
    const score = dash.value?.charts.business_health_score.score ?? 0;
    const color = score >= 80 ? '#00ab55' : score >= 65 ? '#1b55e2' : score >= 45 ? '#e2a03f' : '#e7515a';

    return {
        chart: { height: 220, type: 'radialBar', sparkline: { enabled: true } },
        colors: [color],
        plotOptions: {
            radialBar: {
                hollow: { size: '65%' },
                track: { background: '#e0e6ed' },
                dataLabels: {
                    name: { show: false },
                    value: {
                        fontSize: '28px',
                        fontWeight: 700,
                        offsetY: 8,
                        formatter: () => `${score}`,
                    },
                },
            },
        },
        stroke: { lineCap: 'round' },
    };
});

// ── AI insights (derived heuristically from the payload) ────────────────
const insights = computed(() => {
    const list: Array<{ title: string; detail: string; dot: string; classes: string }> = [];
    const d = dash.value;
    if (!d) return list;

    const arOverdue = d.ceo_overview.ar_overdue.value ?? 0;
    if (arOverdue > 0) {
        list.push({
            title: 'Invoices overdue',
            detail: `${d.ceo_overview.ar_overdue.formatted} past due — ask who to collect from`,
            dot: 'bg-warning',
            classes: 'bg-warning/10 border-warning',
        });
    }

    const margin = d.ceo_overview.net_profit.margin_percentage;
    if (margin !== null && margin < 10) {
        list.push({
            title: 'Thin profit margin',
            detail: `${margin}% margin this month — ask what changed`,
            dot: 'bg-danger',
            classes: 'bg-danger/10 border-danger',
        });
    }

    const score = d.charts.business_health_score.score;
    if (score !== null && score < 65) {
        list.push({
            title: 'Business health needs attention',
            detail: `${d.charts.business_health_score.status} — ask what to do`,
            dot: 'bg-secondary',
            classes: 'bg-secondary/10 border-secondary',
        });
    }

    if (d.side_metrics.open_invoices > 0) {
        list.push({
            title: 'Open invoices',
            detail: `${d.side_metrics.open_invoices} invoices awaiting payment`,
            dot: 'bg-info',
            classes: 'bg-info/10 border-info',
        });
    }

    return list;
});

// ── Formatters ────────────────────────────────────────────────────────────
function formatTime(value: string | null): string {
    if (!value) return '—';
    return new Date(value).toLocaleString();
}

// ── Mount ─────────────────────────────────────────────────────────────────
onMounted(async () => {
    await qbStore.fetchStatus(true);
    if (qbStore.isConnected) {
        await Promise.all([qbStore.fetchSummary(), qbStore.fetchExecutiveDashboard()]);
    }
});
</script>
