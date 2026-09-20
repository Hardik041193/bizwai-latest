<template>
    <div>
        <!-- Breadcrumb -->
        <ul class="flex space-x-2 rtl:space-x-reverse mb-6">
            <li><router-link to="/" class="text-primary hover:underline">Dashboard</router-link></li>
            <li class="before:content-['/'] ltr:before:mr-2 rtl:before:ml-2 text-white-dark">Customers &amp; Collections</li>
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
                    <p class="text-white-dark/70 text-sm">Connect your QuickBooks account to see your customers and collections here.</p>
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
                    <h1 class="text-2xl font-bold">Customers and Collections</h1>
                    <p class="text-white-dark/60 text-sm mt-1">Customer revenue, collections, payment behavior, and concentration risk</p>
                </div>
                <span v-if="qbStore.isConnected" class="badge badge-outline-success text-xs">QBO Connected</span>
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
                                    <span class="w-2.5 h-2.5 rounded-full bg-info"></span>
                                    <span class="text-white-dark/70 text-sm">Active Customers</span>
                                </div>
                                <div class="text-2xl font-bold">{{ data.summary_cards.active_customers.value }}</div>
                                <div class="text-xs text-white-dark/50 mt-1">{{ data.summary_cards.active_customers.change_text }}</div>
                            </div>

                            <div class="panel">
                                <div class="flex items-center gap-2 mb-2">
                                    <span class="w-2.5 h-2.5 rounded-full bg-warning"></span>
                                    <span class="text-white-dark/70 text-sm">Top 5 Share</span>
                                </div>
                                <div class="text-2xl font-bold">{{ data.summary_cards.top_5_share.formatted ?? '—' }}</div>
                                <div class="text-xs text-white-dark/50 mt-1">{{ data.summary_cards.top_5_share.subtext }}</div>
                            </div>

                            <div class="panel">
                                <div class="flex items-center gap-2 mb-2">
                                    <span class="w-2.5 h-2.5 rounded-full bg-danger"></span>
                                    <span class="text-white-dark/70 text-sm">Overdue AR</span>
                                </div>
                                <div class="text-2xl font-bold">{{ data.summary_cards.overdue_ar.formatted ?? '—' }}</div>
                                <div class="text-xs text-white-dark/50 mt-1">{{ data.summary_cards.overdue_ar.subtext }}</div>
                            </div>

                            <div class="panel">
                                <div class="flex items-center gap-2 mb-2">
                                    <span class="w-2.5 h-2.5 rounded-full bg-primary"></span>
                                    <span class="text-white-dark/70 text-sm">Avg Collection</span>
                                </div>
                                <div class="text-2xl font-bold">{{ data.summary_cards.avg_collection.formatted }}</div>
                                <div class="text-xs text-white-dark/50 mt-1">{{ data.summary_cards.avg_collection.subtext }}</div>
                            </div>
                        </div>

                        <!-- Priority list + questions -->
                        <div class="grid sm:grid-cols-2 gap-6">
                            <div class="panel">
                                <h5 class="font-semibold text-lg mb-3">Customer Priority List</h5>
                                <div v-if="!data.customer_priority_list.length" class="text-sm text-white-dark/50">
                                    No customer revenue yet this month.
                                </div>
                                <div v-else class="table-responsive">
                                    <table class="table-hover w-full text-sm">
                                        <thead>
                                            <tr class="text-xs text-white-dark/60">
                                                <th class="text-left font-normal pb-2">Customer</th>
                                                <th class="text-right font-normal pb-2">Revenue</th>
                                                <th class="text-right font-normal pb-2">Open AR</th>
                                                <th class="text-right font-normal pb-2">Risk</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="c in data.customer_priority_list" :key="c.customer">
                                                <td class="py-1.5">{{ c.customer }}</td>
                                                <td class="py-1.5 text-right">{{ c.revenue ?? '—' }}</td>
                                                <td class="py-1.5 text-right">{{ c.open_ar ?? '—' }}</td>
                                                <td class="py-1.5 text-right">
                                                    <span class="badge text-xs" :class="riskBadgeClass(c.risk)">{{ c.risk }}</span>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div class="panel">
                                <h5 class="font-semibold text-lg mb-3">Ask About Customers</h5>
                                <div class="space-y-2">
                                    <button
                                        v-for="q in data.suggested_questions"
                                        :key="q"
                                        type="button"
                                        class="btn btn-outline-primary btn-sm w-full justify-start text-left"
                                    >
                                        {{ q }}
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Concentration risk -->
                        <div class="panel">
                            <h5 class="font-semibold text-lg mb-3">Customer Concentration Risk</h5>
                            <div class="w-full h-8 rounded-lg overflow-hidden flex text-xs text-white font-semibold">
                                <div
                                    class="bg-primary flex items-center justify-center"
                                    :style="{ width: data.customer_concentration_risk.top_1_percentage + '%' }"
                                >
                                    <span v-if="data.customer_concentration_risk.top_1_percentage >= 8">Top 1</span>
                                </div>
                                <div
                                    class="bg-info flex items-center justify-center"
                                    :style="{ width: data.customer_concentration_risk.top_2_to_5_percentage + '%' }"
                                >
                                    <span v-if="data.customer_concentration_risk.top_2_to_5_percentage >= 8">Top 2-5</span>
                                </div>
                                <div
                                    class="bg-white-dark/30 flex items-center justify-center text-black/60"
                                    :style="{ width: data.customer_concentration_risk.other_percentage + '%' }"
                                >
                                    <span v-if="data.customer_concentration_risk.other_percentage >= 8">Other</span>
                                </div>
                            </div>
                            <p class="text-xs text-white-dark/50 mt-3">Use this section to ask: "What happens if my top customer leaves?"</p>
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

useMeta({ title: 'Customers and Collections' });

const qbStore = useQuickBooksStore();
const data = computed(() => qbStore.customersCollections);

function riskBadgeClass(risk: string): string {
    switch (risk) {
        case 'High': return 'badge-outline-danger';
        case 'Med': return 'badge-outline-warning';
        default: return 'badge-outline-success';
    }
}

onMounted(async () => {
    await qbStore.fetchStatus(true);
    if (qbStore.isConnected) {
        await qbStore.fetchCustomersCollections();
    }
});
</script>
