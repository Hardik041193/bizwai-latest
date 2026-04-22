<template>
    <div>
        <!-- Page Header -->
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-2xl font-semibold dark:text-white">My Invoices</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Your invoices and billing history from QuickBooks</p>
            </div>
            <span v-if="qbStore.status?.last_synced_at" class="text-xs text-gray-400">
                Last synced: {{ formatDate(qbStore.status?.last_synced_at) }}
            </span>
        </div>

        <!-- Not connected state -->
        <div v-if="!qbStore.isConnected" class="panel text-center py-16">
            <svg class="w-16 h-16 mx-auto text-gray-300 mb-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M9 17H7A5 5 0 017 7h2" stroke-linecap="round"/>
                <path d="M15 7h2a5 5 0 010 10h-2" stroke-linecap="round"/>
                <line x1="8" y1="12" x2="16" y2="12" stroke-linecap="round"/>
            </svg>
            <h3 class="text-lg font-semibold mb-2 dark:text-white">QuickBooks Not Connected</h3>
            <p class="text-gray-500 dark:text-gray-400 text-sm">Your account administrator hasn't connected QuickBooks yet. Please contact your admin.</p>
        </div>

        <template v-else>
            <!-- Personal Summary Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-5 mb-6">
                <!-- Total Paid -->
                <div class="panel">
                    <div class="flex items-center">
                        <div class="w-12 h-12 rounded-full flex items-center justify-center bg-success/10 text-success mr-4 shrink-0">
                            <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <path d="M12 2v20M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 uppercase tracking-wide">Total Paid</p>
                            <p class="text-2xl font-bold text-success">${{ formatAmount(qbStore.summary?.total_revenue ?? 0) }}</p>
                            <p class="text-xs text-gray-400">Paid invoices</p>
                        </div>
                    </div>
                </div>

                <!-- Outstanding Balance -->
                <div class="panel">
                    <div class="flex items-center">
                        <div class="w-12 h-12 rounded-full flex items-center justify-center bg-warning/10 text-warning mr-4 shrink-0">
                            <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <rect x="2" y="7" width="20" height="14" rx="2" ry="2"/>
                                <path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16" opacity="0.5"/>
                            </svg>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 uppercase tracking-wide">Outstanding</p>
                            <p class="text-2xl font-bold text-warning">${{ formatAmount(qbStore.summary?.outstanding_balance ?? 0) }}</p>
                            <p class="text-xs text-gray-400">Unpaid invoices</p>
                        </div>
                    </div>
                </div>

                <!-- Overdue -->
                <div class="panel">
                    <div class="flex items-center">
                        <div class="w-12 h-12 rounded-full flex items-center justify-center bg-danger/10 text-danger mr-4 shrink-0">
                            <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <circle cx="12" cy="12" r="10"/>
                                <line x1="12" y1="8" x2="12" y2="12" stroke-linecap="round"/>
                                <line x1="12" y1="16" x2="12.01" y2="16" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 uppercase tracking-wide">Overdue</p>
                            <p class="text-2xl font-bold text-danger">{{ qbStore.summary?.overdue_invoices ?? 0 }}</p>
                            <p class="text-xs text-gray-400">Overdue invoices</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Invoices Table -->
            <div class="panel">
                <div class="flex items-center justify-between mb-5">
                    <h5 class="font-semibold text-lg dark:text-white-light">Invoice History</h5>
                    <!-- Status filter -->
                    <div class="flex gap-2">
                        <select v-model="statusFilter" @change="() => loadInvoices(1)" class="form-select text-sm w-36">
                            <option value="">All Statuses</option>
                            <option value="Open">Open</option>
                            <option value="Paid">Paid</option>
                            <option value="Overdue">Overdue</option>
                        </select>
                    </div>
                </div>

                <!-- Loading -->
                <div v-if="loading" class="flex justify-center py-10">
                    <span class="animate-spin border-4 border-primary border-l-transparent rounded-full w-10 h-10 inline-block"></span>
                </div>

                <!-- Error -->
                <div v-else-if="error" class="text-center py-8 text-danger">{{ error }}</div>

                <!-- No data -->
                <div v-else-if="!invoices?.data?.length" class="text-center py-12">
                    <svg class="w-12 h-12 mx-auto text-gray-300 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                        <line x1="16" y1="13" x2="8" y2="13"/>
                        <line x1="16" y1="17" x2="8" y2="17"/>
                        <polyline points="10 9 9 9 8 9"/>
                    </svg>
                    <p class="text-gray-400 text-sm">No invoices found for your account.</p>
                    <p class="text-gray-400 text-xs mt-1">Make sure your email matches the one used in QuickBooks.</p>
                </div>

                <!-- Table -->
                <div v-else class="table-responsive">
                    <table class="table-hover">
                        <thead>
                            <tr>
                                <th>Invoice #</th>
                                <th>Date</th>
                                <th>Due Date</th>
                                <th>Amount</th>
                                <th>Balance</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="inv in invoices.data" :key="inv.id">
                                <td class="font-medium text-primary">{{ inv.doc_number || '—' }}</td>
                                <td>{{ formatDate(inv.txn_date) }}</td>
                                <td>{{ inv.due_date ? formatDate(inv.due_date) : '—' }}</td>
                                <td>${{ formatAmount(inv.total_amount) }}</td>
                                <td>${{ formatAmount(inv.balance) }}</td>
                                <td>
                                    <span :class="statusBadge(inv.status)">{{ inv.status }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="invoices && invoices.last_page > 1" class="flex justify-center mt-4 gap-2">
                    <button
                        v-for="page in invoices.last_page"
                        :key="page"
                        @click="loadInvoices(page)"
                        :class="['btn btn-sm', page === invoices.current_page ? 'btn-primary' : 'btn-outline-primary']"
                    >
                        {{ page }}
                    </button>
                </div>
            </div>
        </template>
    </div>
</template>

<script lang="ts" setup>
import { ref, onMounted } from 'vue';
import { useQuickBooksStore, type Paginated, type QBInvoice } from '@/stores/quickbooks';

const qbStore  = useQuickBooksStore();
const loading  = ref(false);
const error    = ref<string | null>(null);
const invoices = ref<Paginated<QBInvoice> | null>(null);
const statusFilter = ref('');

async function loadInvoices(page = 1) {
    loading.value = true;
    error.value   = null;
    try {
        await qbStore.fetchInvoices({ status: statusFilter.value || undefined, page });
        invoices.value = qbStore.invoices;
    } catch {
        error.value = 'Failed to load invoices.';
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    await qbStore.fetchStatus();
    if (qbStore.isConnected) {
        await qbStore.fetchSummary();
        await loadInvoices();
    }
});

function formatDate(val: string | null | undefined): string {
    if (!val) return '—';
    return new Date(val).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
}

function formatAmount(val: string | number): string {
    return Number(val).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function statusBadge(status: string): string {
    const map: Record<string, string> = {
        Paid:    'badge badge-outline-success',
        Open:    'badge badge-outline-warning',
        Overdue: 'badge badge-outline-danger',
    };
    return map[status] ?? 'badge badge-outline-secondary';
}
</script>
