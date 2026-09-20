<template>
    <div>
        <!-- Breadcrumb -->
        <ul class="flex space-x-2 rtl:space-x-reverse mb-6">
            <li><router-link to="/" class="text-primary hover:underline">Dashboard</router-link></li>
            <li class="before:content-['/'] ltr:before:mr-2 rtl:before:ml-2 text-white-dark">Cash Flow</li>
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
                    <p class="text-white-dark/70 text-sm">Connect your QuickBooks account to see your cash flow here.</p>
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
                    <h1 class="text-2xl font-bold">Cash Flow Command Center</h1>
                    <p class="text-white-dark/60 text-sm mt-1">Cash balance, runway, AR/AP timing, and 30-day forecast</p>
                </div>
                <span v-if="qbStore.isConnected" class="badge badge-outline-success text-xs">QBO Connected</span>
            </div>

            <!-- Restricted notice -->
            <!-- <div v-if="cash?.restricted" class="panel mb-6 border-l-4 border-info">
                <div class="flex items-center gap-3 text-sm">
                    <svg class="w-5 h-5 text-info flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span>Bank cash and bills are company-wide figures. Connect as admin, or track all clients, to see the cash forecast and AP drivers.</span>
                </div>
            </div> -->

            <!-- Sync warning -->
            <div v-if="cash?.sync_warning" class="panel mb-6 border-l-4 border-warning">
                <div class="flex items-center gap-3 text-sm">
                    <svg class="w-5 h-5 text-warning flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M12 3a9 9 0 110 18A9 9 0 0112 3z"/>
                    </svg>
                    <span>
                        <span class="font-semibold">Data may be incomplete.</span>
                        Still syncing: {{ cash.sync_warning.incomplete_entities.join(', ') }}.
                    </span>
                </div>
            </div>

            <!-- Loading -->
            <div v-if="qbStore.loading && !cash" class="flex justify-center py-16">
                <span class="animate-spin border-4 border-primary border-l-transparent rounded-full w-10 h-10"></span>
            </div>

            <!-- Error -->
            <div v-else-if="qbStore.error && !cash" class="panel text-center py-10 text-danger">
                {{ qbStore.error }}
            </div>

            <template v-else-if="cash">
                <div class="grid xl:grid-cols-4 gap-6">
                    <!-- ── Left column ── -->
                    <div class="xl:col-span-3 space-y-6">
                        <!-- Top cards -->
                        <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
                            <div class="panel">
                                <div class="flex items-center gap-2 mb-2">
                                    <span class="w-2.5 h-2.5 rounded-full bg-primary"></span>
                                    <span class="text-white-dark/70 text-sm">Cash Today</span>
                                </div>
                                <div class="text-2xl font-bold">{{ cash.top_cards.cash_today.formatted ?? '—' }}</div>
                                <div class="text-xs text-white-dark/50 mt-1">{{ cash.top_cards.cash_today.subtext }}</div>
                            </div>

                            <div class="panel">
                                <div class="flex items-center gap-2 mb-2">
                                    <span class="w-2.5 h-2.5 rounded-full bg-info"></span>
                                    <span class="text-white-dark/70 text-sm">30-Day Ending</span>
                                </div>
                                <div class="text-2xl font-bold">{{ cash.top_cards.thirty_day_ending.formatted ?? '—' }}</div>
                                <div class="text-xs text-white-dark/50 mt-1">{{ cash.top_cards.thirty_day_ending.subtext }}</div>
                            </div>

                            <div class="panel">
                                <div class="flex items-center gap-2 mb-2">
                                    <span class="w-2.5 h-2.5 rounded-full bg-success"></span>
                                    <span class="text-white-dark/70 text-sm">Runway</span>
                                </div>
                                <div class="text-2xl font-bold">{{ cash.top_cards.runway.formatted }}</div>
                                <div class="text-xs text-white-dark/50 mt-1">{{ cash.top_cards.runway.subtext }}</div>
                            </div>

                            <div class="panel">
                                <div class="flex items-center gap-2 mb-2">
                                    <span class="w-2.5 h-2.5 rounded-full" :class="riskDotClass"></span>
                                    <span class="text-white-dark/70 text-sm">Cash Risk</span>
                                </div>
                                <div class="text-2xl font-bold">{{ cash.top_cards.cash_risk.status }}</div>
                                <div class="text-xs text-white-dark/50 mt-1">{{ cash.top_cards.cash_risk.subtext }}</div>
                            </div>
                        </div>

                        <!-- Forecast + Actions -->
                        <div class="grid lg:grid-cols-3 gap-6">
                            <div class="panel lg:col-span-2">
                                <h5 class="font-semibold text-lg mb-3">30-Day Cash Forecast</h5>
                                <div v-if="!forecastSeries[0].data.length" class="min-h-[260px] grid place-content-center text-white-dark/50 text-sm">
                                    Cash forecast needs admin or all-clients access.
                                </div>
                                <apexchart
                                    v-else
                                    height="280"
                                    :options="forecastChartOptions"
                                    :series="forecastSeries"
                                    class="bg-white dark:bg-black rounded-lg overflow-hidden"
                                ></apexchart>
                            </div>

                            <div class="panel">
                                <h5 class="font-semibold text-lg mb-3">Cash Actions</h5>
                                <div class="space-y-3">
                                    <div v-for="action in cash.cash_actions" :key="action.action_payload" class="flex items-center gap-3">
                                        <span class="badge text-xs flex-shrink-0" :class="actionBadgeClass(action.type)">{{ action.type }}</span>
                                        <span class="text-sm">{{ action.message }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Cash drivers -->
                        <div>
                            <h5 class="font-semibold text-lg mb-3">Cash Drivers</h5>
                            <div class="grid sm:grid-cols-3 gap-4">
                                <div class="panel border-l-4 border-success">
                                    <h6 class="font-semibold text-success mb-2">Money Coming In</h6>
                                    <div class="text-sm space-y-1 text-white-dark/80">
                                        <div>Open invoices: {{ formatCurrency(cash.cash_drivers.money_coming_in.open_invoices) }}</div>
                                        <div>Overdue: {{ formatCurrency(cash.cash_drivers.money_coming_in.overdue) }}</div>
                                        <div>Expected this week: {{ formatCurrency(cash.cash_drivers.money_coming_in.expected_this_week) }}</div>
                                    </div>
                                </div>

                                <div class="panel border-l-4 border-warning">
                                    <h6 class="font-semibold text-warning mb-2">Money Going Out</h6>
                                    <div class="text-sm space-y-1 text-white-dark/80">
                                        <div>Bills due 14 days: {{ formatMaybeCurrency(cash.cash_drivers.money_going_out.bills_due_14_days) }}</div>
                                        <div>Payroll estimate: {{ formatCurrency(cash.cash_drivers.money_going_out.payroll_estimate) }}</div>
                                        <div>Vendor payments: {{ formatMaybeCurrency(cash.cash_drivers.money_going_out.vendor_payments) }}</div>
                                    </div>
                                </div>

                                <div class="panel border-l-4 border-primary">
                                    <h6 class="font-semibold text-primary mb-2">Questions to Ask</h6>
                                    <div class="text-sm space-y-1 text-white-dark/80">
                                        <div v-for="q in cash.cash_drivers.questions_to_ask" :key="q">{{ q }}</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── Right column: side rail ── -->
                    <div class="panel space-y-4 h-fit">
                        <div class="flex items-center gap-3">
                            <span class="w-2.5 h-2.5 rounded-full bg-success"></span>
                            <div>
                                <div class="font-semibold">{{ cash.side_rail.revenue_mtd ?? '—' }}</div>
                                <div class="text-xs text-white-dark/50">Revenue MTD</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="w-2.5 h-2.5 rounded-full bg-info"></span>
                            <div>
                                <div class="font-semibold">{{ cash.side_rail.active_customers }}</div>
                                <div class="text-xs text-white-dark/50">Active Customers</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="w-2.5 h-2.5 rounded-full bg-primary"></span>
                            <div>
                                <div class="font-semibold">{{ cash.side_rail.cash_flow ?? '—' }}</div>
                                <div class="text-xs text-white-dark/50">Cash Flow</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="w-2.5 h-2.5 rounded-full bg-warning"></span>
                            <div>
                                <div class="font-semibold">{{ cash.side_rail.open_invoices }}</div>
                                <div class="text-xs text-white-dark/50">Open Invoices</div>
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

useMeta({ title: 'Cash Flow Command Center' });

const store = useAppStore();
const qbStore = useQuickBooksStore();

const cash = computed(() => qbStore.cashFlow);

const riskDotClass = computed(() => {
    switch (cash.value?.top_cards.cash_risk.status) {
        case 'Watch': return 'bg-warning';
        case 'Healthy': return 'bg-success';
        default: return 'bg-white-dark';
    }
});

function actionBadgeClass(type: string): string {
    switch (type) {
        case 'Collect': return 'badge-outline-warning';
        case 'Delay': return 'badge-outline-info';
        case 'Review': return 'badge-outline-danger';
        default: return 'badge-outline-primary';
    }
}

// ── 30-day forecast chart ────────────────────────────────────────────────
const forecastSeries = computed(() => [
    {
        name: 'Projected Cash',
        data: (cash.value?.cash_forecast_chart.data_points ?? []).map(p => p.projected_balance),
    },
]);

const forecastChartOptions = computed(() => {
    const isDark = store.theme === 'dark' || store.isDarkMode ? true : false;
    const points = cash.value?.cash_forecast_chart.data_points ?? [];
    const minSafe = cash.value?.cash_forecast_chart.minimum_safe_cash ?? 0;

    return {
        chart: {
            height: 280,
            type: 'line',
            fontFamily: 'Plus Jakarta Sans, sans-serif',
            zoom: { enabled: false },
            toolbar: { show: false },
        },
        dataLabels: { enabled: false },
        stroke: { curve: 'smooth', width: 3 },
        colors: ['#1b55e2'],
        xaxis: {
            categories: points.map(p => `Day ${p.day}`),
            tickAmount: 6,
            axisBorder: { show: false },
            axisTicks: { show: false },
        },
        yaxis: {
            labels: {
                formatter: (value: number) => (Math.abs(value) >= 1000 ? `${Math.round(value / 1000)}K` : `${Math.round(value)}`),
            },
        },
        annotations: {
            yaxis: [{
                y: minSafe,
                borderColor: '#e7515a',
                strokeDashArray: 4,
                label: {
                    text: 'minimum safe cash',
                    style: { color: '#fff', background: '#e7515a', fontSize: '10px' },
                },
            }],
        },
        grid: {
            borderColor: isDark ? '#191e3a' : '#e0e6ed',
            strokeDashArray: 5,
        },
        tooltip: {
            theme: isDark ? 'dark' : 'light',
            y: { formatter: (value: number) => `$${value.toLocaleString()}` },
        },
    };
});

// ── Formatters ────────────────────────────────────────────────────────────
function formatCurrency(value: number): string {
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(value ?? 0);
}

function formatMaybeCurrency(value: number | null): string {
    // null here means the token isn't admin AND isn't tracking all clients —
    // never just "not an admin" — so the label must say both conditions.
    return value === null ? 'Company-wide only' : formatCurrency(value);
}

// ── Mount ─────────────────────────────────────────────────────────────────
onMounted(async () => {
    await qbStore.fetchStatus(true);
    if (qbStore.isConnected) {
        await qbStore.fetchCashFlow();
    }
});
</script>
