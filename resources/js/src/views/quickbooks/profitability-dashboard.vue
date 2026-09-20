<template>
    <div>
        <!-- Breadcrumb -->
        <ul class="flex space-x-2 rtl:space-x-reverse mb-6">
            <li><router-link to="/" class="text-primary hover:underline">Dashboard</router-link></li>
            <li class="before:content-['/'] ltr:before:mr-2 rtl:before:ml-2 text-white-dark">Profitability</li>
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
                    <p class="text-white-dark/70 text-sm">Connect your QuickBooks account to see your profitability here.</p>
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
                    <h1 class="text-2xl font-bold">Profitability Center</h1>
                    <p class="text-white-dark/60 text-sm mt-1">Understand whether growth is converting into margin and cash</p>
                </div>
                <span v-if="qbStore.isConnected" class="badge badge-outline-success text-xs">QBO Connected</span>
            </div>

            <!-- Sync warning -->
            <div v-if="profit?.sync_warning" class="panel mb-6 border-l-4 border-warning">
                <div class="flex items-center gap-3 text-sm">
                    <svg class="w-5 h-5 text-warning flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M12 3a9 9 0 110 18A9 9 0 0112 3z"/>
                    </svg>
                    <span>
                        <span class="font-semibold">Data may be incomplete.</span>
                        Still syncing: {{ profit.sync_warning.incomplete_entities.join(', ') }}.
                    </span>
                </div>
            </div>

            <!-- Loading -->
            <div v-if="qbStore.loading && !profit" class="flex justify-center py-16">
                <span class="animate-spin border-4 border-primary border-l-transparent rounded-full w-10 h-10"></span>
            </div>

            <!-- Error -->
            <div v-else-if="qbStore.error && !profit" class="panel text-center py-10 text-danger">
                {{ qbStore.error }}
            </div>

            <template v-else-if="profit">
                <div class="grid xl:grid-cols-4 gap-6">
                    <!-- ── Left column ── -->
                    <div class="xl:col-span-3 space-y-6">
                        <!-- Summary cards -->
                        <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
                            <div class="panel">
                                <div class="flex items-center gap-2 mb-2">
                                    <span class="w-2.5 h-2.5 rounded-full bg-success"></span>
                                    <span class="text-white-dark/70 text-sm">Gross Profit</span>
                                </div>
                                <div class="text-2xl font-bold">{{ profit.summary_cards.gross_profit.formatted ?? '—' }}</div>
                                <div class="text-xs text-white-dark/50 mt-1">{{ profit.summary_cards.gross_profit.subtext }}</div>
                            </div>

                            <div class="panel">
                                <div class="flex items-center gap-2 mb-2">
                                    <span class="w-2.5 h-2.5 rounded-full bg-primary"></span>
                                    <span class="text-white-dark/70 text-sm">Net Profit</span>
                                </div>
                                <div class="text-2xl font-bold">{{ profit.summary_cards.net_profit.formatted ?? '—' }}</div>
                                <div class="text-xs text-white-dark/50 mt-1">{{ profit.summary_cards.net_profit.subtext }}</div>
                            </div>

                            <div class="panel">
                                <div class="flex items-center gap-2 mb-2">
                                    <span class="w-2.5 h-2.5 rounded-full" :class="changeDotClass"></span>
                                    <span class="text-white-dark/70 text-sm">Profit Change</span>
                                </div>
                                <div class="text-2xl font-bold" :class="changeTextClass">{{ profit.summary_cards.profit_change.formatted ?? '—' }}</div>
                                <div class="text-xs text-white-dark/50 mt-1">{{ profit.summary_cards.profit_change.subtext }}</div>
                            </div>

                            <div class="panel">
                                <div class="flex items-center gap-2 mb-2">
                                    <span class="w-2.5 h-2.5 rounded-full bg-secondary"></span>
                                    <span class="text-white-dark/70 text-sm">Break-even</span>
                                </div>
                                <div class="text-2xl font-bold">{{ profit.summary_cards.break_even.formatted ?? '—' }}</div>
                                <div class="text-xs text-white-dark/50 mt-1">{{ profit.summary_cards.break_even.subtext }}</div>
                            </div>
                        </div>

                        <!-- Profit bridge waterfall -->
                        <div class="panel">
                            <h5 class="font-semibold text-lg mb-3">Profit Bridge - What Changed This Month</h5>
                            <div v-if="!hasWaterfallData" class="min-h-[260px] grid place-content-center text-white-dark/50 text-sm">
                                {{ profit.summary_cards.gross_profit.subtext }}
                            </div>
                            <apexchart
                                v-else
                                height="280"
                                :options="waterfallOptions"
                                :series="waterfallSeries"
                                class="bg-white dark:bg-black rounded-lg overflow-hidden"
                            ></apexchart>
                        </div>

                        <!-- Most profitable customers + questions -->
                        <div class="grid sm:grid-cols-2 gap-6">
                            <div class="panel">
                                <h5 class="font-semibold text-lg mb-3">Most Profitable Customers</h5>
                                <div v-if="!profit.most_profitable_customers.length" class="text-sm text-white-dark/50">
                                    No customer profit data yet this month.
                                </div>
                                <table v-else class="table-hover w-full text-sm">
                                    <tbody>
                                        <tr v-for="c in profit.most_profitable_customers" :key="c.name">
                                            <td class="py-1.5">{{ c.name }}</td>
                                            <td class="py-1.5 text-right font-semibold">{{ c.profit ?? '—' }}</td>
                                            <td class="py-1.5 text-right text-white-dark/60 w-16">{{ c.margin_percentage }}%</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <div class="panel">
                                <h5 class="font-semibold text-lg mb-3">Questions to Ask</h5>
                                <ul class="space-y-2 text-sm">
                                    <li v-for="q in profit.suggested_questions" :key="q">
                                        <a href="javascript:;" class="text-primary hover:underline">{{ q }}</a>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <!-- ── Right column: side metrics ── -->
                    <div class="panel space-y-4 h-fit">
                        <div class="flex items-center gap-3">
                            <span class="w-2.5 h-2.5 rounded-full bg-success"></span>
                            <div>
                                <div class="font-semibold">{{ profit.side_metrics.revenue_mtd ?? '—' }}</div>
                                <div class="text-xs text-white-dark/50">Revenue MTD</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="w-2.5 h-2.5 rounded-full bg-info"></span>
                            <div>
                                <div class="font-semibold">{{ profit.side_metrics.active_customers }}</div>
                                <div class="text-xs text-white-dark/50">Active Customers</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="w-2.5 h-2.5 rounded-full bg-primary"></span>
                            <div>
                                <div class="font-semibold">{{ profit.side_metrics.cash_flow ?? '—' }}</div>
                                <div class="text-xs text-white-dark/50">Cash Flow</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="w-2.5 h-2.5 rounded-full bg-warning"></span>
                            <div>
                                <div class="font-semibold">{{ profit.side_metrics.open_invoices }}</div>
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

useMeta({ title: 'Profitability Center' });

const store = useAppStore();
const qbStore = useQuickBooksStore();

const profit = computed(() => qbStore.profitability);

const changeDotClass = computed(() => {
    const pct = profit.value?.summary_cards.profit_change.percentage;
    if (pct === null || pct === undefined) return 'bg-white-dark';
    return pct >= 0 ? 'bg-success' : 'bg-danger';
});

const changeTextClass = computed(() => {
    const pct = profit.value?.summary_cards.profit_change.percentage;
    if (pct === null || pct === undefined) return '';
    return pct >= 0 ? 'text-success' : 'text-danger';
});

// ── Profit bridge: a floating-column "waterfall" built from cumulative
// running totals, since ApexCharts has no native waterfall type ──
const hasWaterfallData = computed(() =>
    (profit.value?.profit_bridge_waterfall.stages ?? []).some(s => s.amount !== null)
);

const waterfallSeries = computed(() => {
    const stages = profit.value?.profit_bridge_waterfall.stages ?? [];
    let running = 0;

    const data = stages.map(stage => {
        const amount = stage.amount ?? 0;

        if (stage.type === 'total') {
            return { x: stage.label, y: [Math.min(0, amount), Math.max(0, amount)], fillColor: stage.color };
        }

        const start = running;
        running += amount;

        return { x: stage.label, y: [Math.min(start, running), Math.max(start, running)], fillColor: stage.color };
    });

    return [{ data }];
});

const waterfallOptions = computed(() => {
    const isDark = store.theme === 'dark' || store.isDarkMode ? true : false;
    const stages = profit.value?.profit_bridge_waterfall.stages ?? [];

    return {
        chart: {
            height: 280,
            type: 'rangeBar',
            fontFamily: 'Plus Jakarta Sans, sans-serif',
            toolbar: { show: false },
        },
        plotOptions: {
            bar: { horizontal: false, columnWidth: '55%', borderRadius: 4 },
        },
        dataLabels: {
            enabled: true,
            formatter: (_val: any, opts: any) => stages[opts.dataPointIndex]?.display ?? '',
            style: { colors: ['#000'] },
            offsetY: -18,
        },
        xaxis: {
            axisBorder: { show: false },
            axisTicks: { show: false },
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
        legend: { show: false },
        tooltip: {
            theme: isDark ? 'dark' : 'light',
            y: { formatter: (value: number) => `$${value.toLocaleString()}` },
        },
    };
});

// ── Mount ─────────────────────────────────────────────────────────────────
onMounted(async () => {
    await qbStore.fetchStatus(true);
    if (qbStore.isConnected) {
        await qbStore.fetchProfitability();
    }
});
</script>
