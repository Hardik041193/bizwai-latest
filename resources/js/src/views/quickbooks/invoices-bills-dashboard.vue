<template>
    <div>
        <!-- Breadcrumb -->
        <ul class="flex space-x-2 rtl:space-x-reverse mb-6">
            <li><router-link to="/" class="text-primary hover:underline">Dashboard</router-link></li>
            <li class="before:content-['/'] ltr:before:mr-2 rtl:before:ml-2 text-white-dark">Invoices &amp; Bills</li>
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
                    <p class="text-white-dark/70 text-sm">Connect your QuickBooks account to see your invoices and bills here.</p>
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
                    <h1 class="text-2xl font-bold">Invoices and Bills</h1>
                    <p class="text-white-dark/60 text-sm mt-1">Open invoices, AR aging, bills due, and payment planning</p>
                </div>
                <span v-if="qbStore.isConnected" class="badge badge-outline-success text-xs">QBO Connected</span>
            </div>

            <!-- Restricted notice -->
            <div v-if="data?.restricted_bills" class="panel mb-6 border-l-4 border-info">
                <div class="flex items-center gap-3 text-sm">
                    <svg class="w-5 h-5 text-info flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span>Bills are company-wide, not tied to any client. Connect as admin, or track all clients, to see Bills Due and Net AR/AP.</span>
                </div>
            </div>

            <!-- Sync warning -->
            <div v-if="data?.sync_warning" class="panel mb-6 border-l-4 border-warning">
                <div class="flex items-center gap-3 text-sm">
                    <svg class="w-5 h-5 text-warning flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M12 3a9 9 0 110 18A9 9 0 0112 3z"/>
                    </svg>
                    <span>
                        <span class="font-semibold">Data may be incomplete.</span>
                        Still syncing: {{ data.sync_warning.incomplete_entities.join(', ') }}.
                    </span>
                </div>
            </div>

            <!-- Loading -->
            <div v-if="qbStore.loading && !data" class="flex justify-center py-16">
                <span class="animate-spin border-4 border-primary border-l-transparent rounded-full w-10 h-10"></span>
            </div>

            <!-- Error -->
            <div v-else-if="qbStore.error && !data" class="panel text-center py-10 text-danger">
                {{ qbStore.error }}
            </div>

            <template v-else-if="data">
                <div class="grid xl:grid-cols-4 gap-6">
                    <!-- ── Left column ── -->
                    <div class="xl:col-span-3 space-y-6">
                        <!-- Summary cards -->
                        <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
                            <div class="panel">
                                <div class="flex items-center gap-2 mb-2">
                                    <span class="w-2.5 h-2.5 rounded-full bg-warning"></span>
                                    <span class="text-white-dark/70 text-sm">Open Invoices</span>
                                </div>
                                <div class="text-2xl font-bold">{{ data.summary_cards.open_invoices.formatted }}</div>
                                <div class="text-xs text-white-dark/50 mt-1">{{ data.summary_cards.open_invoices.subtext }}</div>
                            </div>

                            <div class="panel">
                                <div class="flex items-center gap-2 mb-2">
                                    <span class="w-2.5 h-2.5 rounded-full bg-danger"></span>
                                    <span class="text-white-dark/70 text-sm">Overdue</span>
                                </div>
                                <div class="text-2xl font-bold">{{ data.summary_cards.overdue.formatted }}</div>
                                <div class="text-xs text-white-dark/50 mt-1">{{ data.summary_cards.overdue.subtext }}</div>
                            </div>

                            <div class="panel">
                                <div class="flex items-center gap-2 mb-2">
                                    <span class="w-2.5 h-2.5 rounded-full bg-primary"></span>
                                    <span class="text-white-dark/70 text-sm">Bills Due</span>
                                </div>
                                <div class="text-2xl font-bold">{{ data.summary_cards.bills_due.formatted ?? '—' }}</div>
                                <div class="text-xs text-white-dark/50 mt-1">{{ data.summary_cards.bills_due.subtext }}</div>
                            </div>

                            <div class="panel">
                                <div class="flex items-center gap-2 mb-2">
                                    <span class="w-2.5 h-2.5 rounded-full bg-success"></span>
                                    <span class="text-white-dark/70 text-sm">Net AR/AP</span>
                                </div>
                                <div class="text-2xl font-bold">{{ data.summary_cards.net_ar_ap.formatted ?? '—' }}</div>
                                <div class="text-xs text-white-dark/50 mt-1">{{ data.summary_cards.net_ar_ap.subtext }}</div>
                            </div>
                        </div>

                        <!-- AR Aging + Collection Priority -->
                        <div class="grid sm:grid-cols-2 gap-6">
                            <div class="panel">
                                <h5 class="font-semibold text-lg mb-4">AR Aging</h5>
                                <div class="space-y-3">
                                    <div v-for="bucket in data.ar_aging" :key="bucket.bracket" class="flex items-center gap-3">
                                        <span class="text-xs text-white-dark/60 w-12 flex-shrink-0">{{ bucket.bracket }}</span>
                                        <div class="flex-1 h-3 rounded-full bg-white-dark/10 overflow-hidden">
                                            <div
                                                class="h-full rounded-full"
                                                :style="{ width: bucket.percentage + '%', backgroundColor: bucket.color }"
                                            ></div>
                                        </div>
                                        <span class="text-xs font-semibold w-14 text-right flex-shrink-0">{{ bucket.formatted }}</span>
                                    </div>
                                </div>
                            </div>

                            <div class="panel">
                                <h5 class="font-semibold text-lg mb-4">Collection Priority</h5>
                                <div v-if="!data.collection_priority.length" class="text-sm text-white-dark/50">
                                    No overdue invoices right now.
                                </div>
                                <div v-else class="space-y-3 mb-4">
                                    <div v-for="c in data.collection_priority" :key="c.rank" class="flex items-center gap-3">
                                        <span class="w-6 h-6 rounded-full bg-primary text-white text-xs flex items-center justify-center flex-shrink-0">
                                            {{ c.rank }}
                                        </span>
                                        <div class="flex-1">
                                            <div class="font-semibold text-sm">{{ c.customer }}</div>
                                            <div class="text-xs text-white-dark/50">{{ c.amount }} &middot; {{ c.detail }}</div>
                                        </div>
                                    </div>
                                </div>
                                <button type="button" class="btn btn-primary btn-sm w-full">Ask who to collect from first</button>
                            </div>
                        </div>

                        <!-- Bills due timeline -->
                        <div class="panel">
                            <h5 class="font-semibold text-lg mb-6">Bills Due Timeline</h5>
                            <div v-if="data.restricted_bills" class="text-sm text-white-dark/50">
                                Bills are company-wide — connect as admin or track all clients to see this timeline.
                            </div>
                            <div v-else class="relative flex justify-between items-center px-2">
                                <div class="absolute left-6 right-6 top-2.5 h-0.5 bg-white-dark/20"></div>
                                <div
                                    v-for="point in timelinePoints"
                                    :key="point.key"
                                    class="relative z-10 flex flex-col items-center gap-2 text-center"
                                    style="width: 25%"
                                >
                                    <span class="text-xs text-white-dark/60">{{ point.label }}</span>
                                    <span class="w-3 h-3 rounded-full bg-primary border-2 border-white dark:border-black"></span>
                                    <span class="text-sm font-semibold">{{ point.formatted ?? '—' }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── Right column: side metrics ── -->
                    <div class="panel space-y-4 h-fit">
                        <div class="flex items-center gap-3">
                            <span class="w-2.5 h-2.5 rounded-full bg-success"></span>
                            <div>
                                <div class="font-semibold">{{ data.side_metrics.revenue_mtd ?? '—' }}</div>
                                <div class="text-xs text-white-dark/50">Revenue MTD</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="w-2.5 h-2.5 rounded-full bg-info"></span>
                            <div>
                                <div class="font-semibold">{{ data.side_metrics.active_customers }}</div>
                                <div class="text-xs text-white-dark/50">Active Customers</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="w-2.5 h-2.5 rounded-full bg-primary"></span>
                            <div>
                                <div class="font-semibold">{{ data.side_metrics.cash_flow ?? '—' }}</div>
                                <div class="text-xs text-white-dark/50">Cash Flow</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="w-2.5 h-2.5 rounded-full bg-warning"></span>
                            <div>
                                <div class="font-semibold">{{ data.side_metrics.open_invoices }}</div>
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
import { useMeta } from '@/composables/use-meta';
import { useQuickBooksStore } from '@/stores/quickbooks';

useMeta({ title: 'Invoices and Bills' });

const qbStore = useQuickBooksStore();
const data = computed(() => qbStore.invoicesBills);

const timelinePoints = computed(() => {
    const t = data.value?.bills_due_timeline;
    if (!t) return [];
    return [
        { key: 'today', label: t.today.label, formatted: t.today.formatted },
        { key: 'seven_days', label: t.seven_days.label, formatted: t.seven_days.formatted },
        { key: 'fourteen_days', label: t.fourteen_days.label, formatted: t.fourteen_days.formatted },
        { key: 'thirty_days', label: t.thirty_days.label, formatted: t.thirty_days.formatted },
    ];
});

onMounted(async () => {
    await qbStore.fetchStatus(true);
    if (qbStore.isConnected) {
        await qbStore.fetchInvoicesBills();
    }
});
</script>
