<template>
    <div>
        <!-- Breadcrumb -->
        <ul class="flex space-x-2 rtl:space-x-reverse mb-6">
            <li><router-link to="/" class="text-primary hover:underline">Dashboard</router-link></li>
            <li class="before:content-['/'] ltr:before:mr-2 rtl:before:ml-2 text-white-dark">QuickBooks</li>
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
                    <p class="text-white-dark/70 text-sm">Connect your QuickBooks account to see your financial data here.</p>
                </div>
                <router-link to="/quickbooks/connect" class="btn btn-success btn-sm gap-2 flex-shrink-0">
                    Connect Now
                </router-link>
            </div>
        </div>

        <!-- Header row -->
        <div v-if="qbStore.isConnected" class="flex flex-wrap items-center justify-between gap-3 mb-6">
            <div>
                <h1 class="text-2xl font-bold">QuickBooks Dashboard</h1>
                <p v-if="qbStore.summary?.last_synced_at" class="text-sm text-white-dark/60 mt-1">
                    Last synced: {{ formatDate(qbStore.summary.last_synced_at) }}
                </p>
            </div>
            <div class="flex gap-2">
                <button @click="syncData" :disabled="qbStore.syncing" class="btn btn-outline-success btn-sm gap-2">
                    <svg class="w-4 h-4" :class="{ 'animate-spin': qbStore.syncing }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                    </svg>
                    {{ qbStore.syncing ? 'Syncing…' : 'Sync Now' }}
                </button>
                <button @click="confirmDisconnect" class="btn btn-outline-danger btn-sm gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                    </svg>
                    Disconnect
                </button>
            </div>
        </div>

        <!-- Summary Cards -->
        <div v-if="qbStore.isConnected" class="grid grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
            <div class="panel">
                <div class="flex items-center gap-3 mb-3">
                    <div class="w-9 h-9 rounded-lg bg-success/20 flex items-center justify-center">
                        <svg class="w-5 h-5 text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <span class="text-white-dark/70 text-sm">Total Revenue</span>
                </div>
                <div class="text-2xl font-bold text-success">{{ formatCurrency(qbStore.summary?.total_revenue) }}</div>
                <div class="text-xs text-white-dark/50 mt-1">Paid invoices</div>
            </div>

            <div class="panel">
                <div class="flex items-center gap-3 mb-3">
                    <div class="w-9 h-9 rounded-lg bg-warning/20 flex items-center justify-center">
                        <svg class="w-5 h-5 text-warning" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                        </svg>
                    </div>
                    <span class="text-white-dark/70 text-sm">Outstanding</span>
                </div>
                <div class="text-2xl font-bold text-warning">{{ formatCurrency(qbStore.summary?.outstanding_balance) }}</div>
                <div class="text-xs text-white-dark/50 mt-1">Unpaid invoices</div>
            </div>

            <div class="panel">
                <div class="flex items-center gap-3 mb-3">
                    <div class="w-9 h-9 rounded-lg bg-danger/20 flex items-center justify-center">
                        <svg class="w-5 h-5 text-danger" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/>
                        </svg>
                    </div>
                    <span class="text-white-dark/70 text-sm">Total Expenses</span>
                </div>
                <div class="text-2xl font-bold text-danger">{{ formatCurrency(qbStore.summary?.total_expenses) }}</div>
                <div class="text-xs text-white-dark/50 mt-1">All purchases</div>
            </div>

            <div class="panel">
                <div class="flex items-center gap-3 mb-3">
                    <div class="w-9 h-9 rounded-lg bg-secondary/20 flex items-center justify-center">
                        <svg class="w-5 h-5 text-secondary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <span class="text-white-dark/70 text-sm">Overdue</span>
                </div>
                <div class="text-2xl font-bold text-secondary">{{ qbStore.summary?.overdue_invoices ?? 0 }}</div>
                <div class="text-xs text-white-dark/50 mt-1">Overdue invoices</div>
            </div>
        </div>

        <!-- Tabs -->
        <div v-if="qbStore.isConnected" class="panel">
            <!-- Tab headers -->
            <div class="flex border-b border-white-dark/10 mb-5 gap-1 overflow-x-auto">
                <button
                    v-for="tab in tabs"
                    :key="tab.id"
                    @click="activeTab = tab.id"
                    class="px-4 py-2.5 text-sm font-medium rounded-t-lg transition-colors whitespace-nowrap"
                    :class="activeTab === tab.id
                        ? 'border-b-2 border-primary text-primary bg-primary/5'
                        : 'text-white-dark hover:text-primary'"
                >
                    {{ tab.label }}
                    <span v-if="tab.badge !== undefined" class="ml-1.5 text-xs bg-primary/20 text-primary rounded-full px-1.5 py-0.5">
                        {{ tab.badge }}
                    </span>
                </button>
            </div>

            <!-- Loading state -->
            <div v-if="qbStore.loading" class="flex justify-center py-12">
                <span class="animate-spin border-4 border-primary border-l-transparent rounded-full w-10 h-10"></span>
            </div>

            <!-- Error state -->
            <div v-else-if="qbStore.error" class="text-center py-10 text-danger">
                {{ qbStore.error }}
            </div>

            <!-- ── Invoices Tab ── -->
            <div v-else-if="activeTab === 'invoices'">
                <div class="flex flex-wrap gap-3 mb-4">
                    <select v-model="invoiceFilters.status" @change="() => loadInvoices(1)" class="form-select w-36 text-sm">
                        <option value="">All Status</option>
                        <option value="Open">Open</option>
                        <option value="Paid">Paid</option>
                        <option value="Overdue">Overdue</option>
                    </select>
                    <input v-model="invoiceFilters.customer" @input="debouncedLoadInvoices" type="text" placeholder="Search customer…" class="form-input w-44 text-sm"/>
                </div>

                <div class="table-responsive">
                    <table class="table-striped table-hover">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Customer</th>
                                <th>Date</th>
                                <th>Due Date</th>
                                <th class="text-right">Total</th>
                                <th class="text-right">Balance</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!qbStore.invoices?.data?.length">
                                <td colspan="7" class="text-center text-white-dark/50 py-8">No invoices found.</td>
                            </tr>
                            <tr v-for="inv in qbStore.invoices?.data" :key="inv.id">
                                <td class="font-mono text-xs">{{ inv.doc_number }}</td>
                                <td>
                                    <div class="font-semibold">{{ inv.customer_name }}</div>
                                    <div class="text-xs text-white-dark/50">{{ inv.customer_email }}</div>
                                </td>
                                <td class="text-sm">{{ inv.txn_date }}</td>
                                <td class="text-sm">{{ inv.due_date }}</td>
                                <td class="text-right font-medium">{{ formatCurrency(inv.total_amount) }}</td>
                                <td class="text-right">{{ formatCurrency(inv.balance) }}</td>
                                <td>
                                    <span class="badge text-xs" :class="statusBadge(inv.status)">{{ inv.status }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Pagination
                    v-if="qbStore.invoices"
                    :current="qbStore.invoices.current_page"
                    :last="qbStore.invoices.last_page"
                    :total="qbStore.invoices.total"
                    @change="(p) => loadInvoices(p)"
                />
            </div>

            <!-- ── Transactions Tab ── -->
            <div v-else-if="activeTab === 'transactions'">
                <div class="flex flex-wrap gap-3 mb-4">
                    <input v-model="txnFilters.account" @input="debouncedLoadTxns" type="text" placeholder="Search account…" class="form-input w-44 text-sm"/>
                </div>

                <div class="table-responsive">
                    <table class="table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Account</th>
                                <th>Vendor / Entity</th>
                                <th>Description</th>
                                <th class="text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!qbStore.transactions?.data?.length">
                                <td colspan="6" class="text-center text-white-dark/50 py-8">No transactions found.</td>
                            </tr>
                            <tr v-for="txn in qbStore.transactions?.data" :key="txn.id">
                                <td class="text-sm">{{ txn.txn_date }}</td>
                                <td><span class="badge badge-outline-info text-xs">{{ txn.txn_type }}</span></td>
                                <td class="text-sm">{{ txn.account_name }}</td>
                                <td class="text-sm">{{ txn.entity_name }}</td>
                                <td class="text-sm text-white-dark/70 max-w-[200px] truncate">{{ txn.description }}</td>
                                <td class="text-right font-medium text-danger">{{ formatCurrency(txn.amount) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Pagination
                    v-if="qbStore.transactions"
                    :current="qbStore.transactions.current_page"
                    :last="qbStore.transactions.last_page"
                    :total="qbStore.transactions.total"
                    @change="(p) => loadTransactions(p)"
                />
            </div>

            <!-- ── Accounts Tab ── -->
            <div v-else-if="activeTab === 'accounts'">
                <div class="flex flex-wrap gap-3 mb-4">
                    <select v-model="accountFilters.type" @change="() => loadAccounts(1)" class="form-select w-44 text-sm">
                        <option value="">All Types</option>
                        <option value="Bank">Bank</option>
                        <option value="Accounts Receivable">Accounts Receivable</option>
                        <option value="Accounts Payable">Accounts Payable</option>
                        <option value="Income">Income</option>
                        <option value="Expense">Expense</option>
                        <option value="Other Current Asset">Other Current Asset</option>
                    </select>
                </div>

                <div class="table-responsive">
                    <table class="table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Account Name</th>
                                <th>Type</th>
                                <th>Sub-Type</th>
                                <th>Classification</th>
                                <th class="text-right">Balance</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!qbStore.accounts?.data?.length">
                                <td colspan="6" class="text-center text-white-dark/50 py-8">No accounts found.</td>
                            </tr>
                            <tr v-for="acc in qbStore.accounts?.data" :key="acc.id">
                                <td class="font-medium">{{ acc.name }}</td>
                                <td class="text-sm">{{ acc.account_type }}</td>
                                <td class="text-sm text-white-dark/70">{{ acc.account_sub_type }}</td>
                                <td class="text-sm">{{ acc.classification }}</td>
                                <td class="text-right font-medium" :class="parseFloat(acc.current_balance) < 0 ? 'text-danger' : 'text-success'">
                                    {{ formatCurrency(acc.current_balance) }}
                                </td>
                                <td>
                                    <span class="badge text-xs" :class="acc.active ? 'badge-outline-success' : 'badge-outline-danger'">
                                        {{ acc.active ? 'Active' : 'Inactive' }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Pagination
                    v-if="qbStore.accounts"
                    :current="qbStore.accounts.current_page"
                    :last="qbStore.accounts.last_page"
                    :total="qbStore.accounts.total"
                    @change="(p) => loadAccounts(p)"
                />
            </div>
        </div>
    </div>
</template>

<script lang="ts" setup>
import { ref, reactive, onMounted, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useMeta }           from '@/composables/use-meta';
import { useToast }          from '@/composables/use-toast';
import { useQuickBooksStore } from '@/stores/quickbooks';

useMeta({ title: 'QuickBooks Dashboard' });

const router   = useRouter();
const qbStore  = useQuickBooksStore();
const { showToast, confirmDialog } = useToast();
const activeTab = ref<'invoices' | 'transactions' | 'accounts'>('invoices');

const tabs = [
    { id: 'invoices',     label: 'Invoices',     badge: undefined },
    { id: 'transactions', label: 'Transactions',  badge: undefined },
    { id: 'accounts',     label: 'Accounts',      badge: undefined },
];

// ── Filters ──────────────────────────────────────────────────────────────
const invoiceFilters  = reactive({ status: '', customer: '', page: 1 });
const txnFilters      = reactive({ account: '', page: 1 });
const accountFilters  = reactive({ type: '', page: 1 });

// ── Debounce helper ───────────────────────────────────────────────────────
let invoiceTimer: ReturnType<typeof setTimeout>;
let txnTimer:     ReturnType<typeof setTimeout>;

function debouncedLoadInvoices() {
    clearTimeout(invoiceTimer);
    invoiceTimer = setTimeout(() => loadInvoices(), 400);
}
function debouncedLoadTxns() {
    clearTimeout(txnTimer);
    txnTimer = setTimeout(() => loadTransactions(), 400);
}

// ── Data loaders ──────────────────────────────────────────────────────────
async function loadInvoices(page = 1) {
    invoiceFilters.page = page;
    await qbStore.fetchInvoices({
        page,
        per_page: 15,
        status:   invoiceFilters.status   || undefined,
        customer: invoiceFilters.customer || undefined,
    });
}

async function loadTransactions(page = 1) {
    txnFilters.page = page;
    await qbStore.fetchTransactions({
        page,
        per_page: 15,
        account: txnFilters.account || undefined,
    });
}

async function loadAccounts(page = 1) {
    accountFilters.page = page;
    await qbStore.fetchAccounts({
        page,
        per_page: 20,
        type: accountFilters.type || undefined,
    });
}

// Reload data whenever the active tab changes
watch(activeTab, (tab) => {
    if (tab === 'invoices'     && !qbStore.invoices)     loadInvoices();
    if (tab === 'transactions' && !qbStore.transactions) loadTransactions();
    if (tab === 'accounts'     && !qbStore.accounts)     loadAccounts();
});

// ── Sync ──────────────────────────────────────────────────────────────────
async function syncData() {
    try {
        await qbStore.triggerSync();
        showToast('Sync started — data will refresh shortly.', 'success');
        setTimeout(async () => {
            await qbStore.fetchSummary();
            await loadInvoices();
        }, 1500);
    } catch (_) {
        showToast(qbStore.error ?? 'Failed to trigger sync.', 'error');
    }
}

// ── Disconnect ────────────────────────────────────────────────────────────
async function confirmDisconnect() {
    const ok = await confirmDialog({
        title:       'Disconnect QuickBooks?',
        text:        'Synced data will remain but no new syncs will occur. You can reconnect at any time.',
        confirmText: 'Yes, Disconnect',
        cancelText:  'Cancel',
    });
    if (!ok) return;
    try {
        await qbStore.disconnect();
        showToast('QuickBooks disconnected successfully.', 'success');
        router.push('/quickbooks/connect');
    } catch (_) {
        showToast(qbStore.error ?? 'Failed to disconnect.', 'error');
    }
}

// ── Formatters ────────────────────────────────────────────────────────────
function formatCurrency(value: any): string {
    const num = parseFloat(value) || 0;
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(num);
}

function formatDate(value: string | null): string {
    if (!value) return '—';
    return new Date(value).toLocaleString();
}

function statusBadge(status: string): string {
    switch (status) {
        case 'Paid':    return 'badge-outline-success';
        case 'Overdue': return 'badge-outline-danger';
        default:        return 'badge-outline-warning';
    }
}

// ── Mount ─────────────────────────────────────────────────────────────────
onMounted(async () => {
    await qbStore.fetchStatus();
    if (qbStore.isConnected) {
        await qbStore.fetchSummary();
        await loadInvoices();
    }
});
</script>

<!-- Pagination sub-component (inline) -->
<script lang="ts">
import { defineComponent, h } from 'vue';

const Pagination = defineComponent({
    name: 'Pagination',
    props: {
        current: { type: Number, required: true },
        last:    { type: Number, required: true },
        total:   { type: Number, required: true },
    },
    emits: ['change'],
    setup(props, { emit }) {
        return () => {
            if (props.last <= 1) return null;
            return h('div', { class: 'flex items-center justify-between mt-4 text-sm text-white-dark/70' }, [
                h('span', `Showing page ${props.current} of ${props.last} (${props.total} total)`),
                h('div', { class: 'flex gap-1' }, [
                    h('button', {
                        class: 'btn btn-sm btn-outline-primary',
                        disabled: props.current <= 1,
                        onClick: () => emit('change', props.current - 1),
                    }, 'Prev'),
                    h('button', {
                        class: 'btn btn-sm btn-outline-primary',
                        disabled: props.current >= props.last,
                        onClick: () => emit('change', props.current + 1),
                    }, 'Next'),
                ]),
            ]);
        };
    },
});
export { Pagination };
</script>
