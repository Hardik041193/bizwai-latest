<template>
    <div>
        <!-- Breadcrumb -->
        <ul class="flex space-x-2 rtl:space-x-reverse mb-6">
            <li><router-link to="/" class="text-primary hover:underline">Dashboard</router-link></li>
            <li class="before:content-['/'] ltr:before:mr-2 rtl:before:ml-2 text-white-dark">Expenses &amp; Vendors</li>
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
                    <p class="text-white-dark/70 text-sm">Connect your QuickBooks account to see your expenses and vendors here.</p>
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
                    <h1 class="text-2xl font-bold">Expenses and Vendors</h1>
                    <p class="text-white-dark/60 text-sm mt-1">Expense control, vendor spending, duplicate detection, and anomaly review</p>
                </div>
                <span v-if="qbStore.isConnected" class="badge badge-outline-success text-xs">QBO Connected</span>
            </div>

            <!-- Restricted notice -->
            <!-- <div v-if="data?.restricted" class="panel mb-6 border-l-4 border-info">
                <div class="flex items-center gap-3 text-sm">
                    <svg class="w-5 h-5 text-info flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span>Vendor bills are company-wide, not tied to any client. Connect as admin, or track all clients, to see full spend, top vendor, and vendor-level checks.</span>
                </div>
            </div> -->

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
                                    <span class="w-2.5 h-2.5 rounded-full bg-danger"></span>
                                    <span class="text-white-dark/70 text-sm">Total Expenses</span>
                                </div>
                                <div class="text-2xl font-bold">{{ data.summary_cards.total_expenses.formatted ?? '—' }}</div>
                                <div class="text-xs text-white-dark/50 mt-1">{{ data.summary_cards.total_expenses.subtext }}</div>
                            </div>

                            <div class="panel">
                                <div class="flex items-center gap-2 mb-2">
                                    <span class="w-2.5 h-2.5 rounded-full bg-warning"></span>
                                    <span class="text-white-dark/70 text-sm">Top Vendor</span>
                                </div>
                                <div class="text-2xl font-bold">{{ data.summary_cards.top_vendor.formatted ?? '—' }}</div>
                                <div class="text-xs text-white-dark/50 mt-1">{{ data.summary_cards.top_vendor.name ?? 'No vendor spend yet' }}</div>
                            </div>

                            <div class="panel">
                                <div class="flex items-center gap-2 mb-2">
                                    <span class="w-2.5 h-2.5 rounded-full bg-primary"></span>
                                    <span class="text-white-dark/70 text-sm">Recurring Spend</span>
                                </div>
                                <div class="text-2xl font-bold">{{ data.summary_cards.recurring_spend.formatted ?? '—' }}</div>
                                <div class="text-xs text-white-dark/50 mt-1">{{ data.summary_cards.recurring_spend.subtext }}</div>
                            </div>

                            <div class="panel">
                                <div class="flex items-center gap-2 mb-2">
                                    <span class="w-2.5 h-2.5 rounded-full bg-secondary"></span>
                                    <span class="text-white-dark/70 text-sm">Anomalies</span>
                                </div>
                                <div class="text-2xl font-bold">{{ data.summary_cards.anomalies.formatted }}</div>
                                <div class="text-xs text-white-dark/50 mt-1">{{ data.summary_cards.anomalies.subtext }}</div>
                            </div>
                        </div>

                        <!-- Expense categories + AI checks -->
                        <div class="grid sm:grid-cols-2 gap-6">
                            <div class="panel">
                                <h5 class="font-semibold text-lg mb-4">Expense Categories</h5>
                                <div class="space-y-3">
                                    <div v-for="cat in data.expense_categories" :key="cat.category" class="flex items-center gap-3">
                                        <span class="text-xs text-white-dark/60 w-20 flex-shrink-0">{{ cat.category }}</span>
                                        <div class="flex-1 h-3 rounded-full bg-white-dark/10 overflow-hidden">
                                            <div
                                                class="h-full rounded-full"
                                                :style="{ width: cat.percentage + '%', backgroundColor: cat.color }"
                                            ></div>
                                        </div>
                                        <span class="text-xs font-semibold w-14 text-right flex-shrink-0">{{ cat.formatted }}</span>
                                    </div>
                                </div>
                            </div>

                            <div class="panel">
                                <h5 class="font-semibold text-lg mb-4">Expense AI Checks</h5>
                                <div v-if="!data.expense_ai_checks.length" class="text-sm text-white-dark/50">
                                    Nothing flagged for review right now.
                                </div>
                                <div v-else class="space-y-3">
                                    <div
                                        v-for="check in data.expense_ai_checks"
                                        :key="check.type"
                                        class="rounded-lg p-3 border-l-4"
                                        :class="checkClasses(check.severity)"
                                    >
                                        <div class="font-semibold text-sm">{{ check.title }}</div>
                                        <div class="text-xs text-white-dark/60 mt-0.5">{{ check.detail }}</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Best expense questions -->
                        <div class="panel">
                            <h5 class="font-semibold text-lg mb-4">Best Expense Questions</h5>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="q in data.suggested_questions"
                                    :key="q"
                                    type="button"
                                    class="btn btn-outline-primary btn-sm"
                                >
                                    {{ q }}
                                </button>
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

useMeta({ title: 'Expenses and Vendors' });

const qbStore = useQuickBooksStore();
const data = computed(() => qbStore.expensesVendors);

function checkClasses(severity: string): string {
    switch (severity) {
        case 'danger': return 'bg-danger/10 border-danger';
        case 'warning': return 'bg-warning/10 border-warning';
        case 'purple': return 'bg-secondary/10 border-secondary';
        default: return 'bg-info/10 border-info';
    }
}

onMounted(async () => {
    await qbStore.fetchStatus(true);
    if (qbStore.isConnected) {
        await qbStore.fetchExpensesVendors();
    }
});
</script>
