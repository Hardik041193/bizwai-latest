<template>
    <div>
        <div class="flex flex-wrap items-start justify-between gap-3 mb-5">
            <div>
                <div class="flex items-center gap-3">
                    <h1 class="text-2xl font-bold dark:text-white">QuickBooks Data</h1>
                    <span v-if="qbStore.isConnected" class="badge badge-outline-success">Live</span>
                </div>
                <p class="text-sm text-white-dark/70 mt-1">Connect and manage your own QuickBooks company data.</p>
            </div>

            <div v-if="qbStore.isConnected" class="flex gap-2">
                <button @click="syncData" :disabled="qbStore.syncing" class="btn btn-outline-primary btn-sm gap-2">
                    <span v-if="qbStore.syncing" class="animate-spin border-2 border-primary border-l-transparent rounded-full w-4 h-4 inline-block"></span>
                    {{ qbStore.syncing ? 'Refreshing…' : 'Refresh' }}
                </button>
                <button @click="disconnect" class="btn btn-outline-danger btn-sm">Disconnect</button>
            </div>
        </div>

        <div v-if="loadingStatus" class="flex justify-center py-20">
            <span class="animate-spin border-4 border-primary border-l-transparent rounded-full w-10 h-10 inline-block"></span>
        </div>

        <div v-else-if="!qbStore.isConnected" class="panel text-center py-16">
            <div class="w-16 h-16 mx-auto rounded-full bg-success/10 text-success flex items-center justify-center mb-4">
                <svg class="w-8 h-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <path d="M9 17H7A5 5 0 017 7h2" stroke-linecap="round"/>
                    <path d="M15 7h2a5 5 0 010 10h-2" stroke-linecap="round"/>
                    <line x1="8" y1="12" x2="16" y2="12" stroke-linecap="round"/>
                </svg>
            </div>
            <h3 class="text-xl font-semibold mb-2 dark:text-white">Connect Your QuickBooks Account</h3>
            <p class="text-white-dark/70 text-sm max-w-lg mx-auto mb-6">
                Authorize your own QuickBooks company to view invoices, customers, totals, and live sync status in your user panel.
            </p>
            <button @click="connectQuickBooks" :disabled="connecting" class="btn btn-success gap-2 mx-auto">
                <span v-if="connecting" class="animate-spin border-2 border-white border-l-transparent rounded-full w-4 h-4 inline-block"></span>
                {{ connecting ? 'Redirecting…' : 'Connect QuickBooks' }}
            </button>
        </div>

        <template v-else>
            <div class="panel bg-gradient-to-r from-primary to-[#0b5ed7] text-white mb-5">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-xl bg-white/15 flex items-center justify-center">
                            <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <path d="M3 21h18M5 21V7l8-4v18M19 21V11l-6-4" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                        <div>
                            <p class="text-xs uppercase tracking-wide text-white/70">Company</p>
                            <h2 class="text-xl font-bold">{{ companyName }}</h2>
                            <p v-if="activeClientName" class="text-sm text-white/90 mt-1 flex items-center gap-2">
                                <span class="inline-flex items-center gap-1 rounded-full bg-white/20 px-2 py-0.5 text-xs">
                                    <svg class="w-3.5 h-3.5 text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                    </svg>
                                    Client: {{ activeClientName }}
                                </span>
                                <!-- Client-selection disabled: we now use ALL clients of the connected company.
                                <button type="button" class="underline text-white/80 hover:text-white text-xs" @click="changeClient">
                                    Change client
                                </button>
                                -->
                            </p>
                            <p class="text-xs text-white/75">
                                {{ qbStore.summary?.company?.legal_name || companyName }}
                                <span v-if="qbStore.summary?.company?.country"> · {{ qbStore.summary.company.country }}</span>
                                <span v-if="qbStore.summary?.company?.email"> · {{ qbStore.summary.company.email }}</span>
                            </p>
                        </div>
                    </div>
                    <div class="text-xs text-white/75">
                        <span v-if="qbStore.summary?.last_synced_at">Last synced: {{ formatDateTime(qbStore.summary.last_synced_at) }}</span>
                        <span v-else>Sync queued after connection</span>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5 mb-5">
                <MetricCard title="Total Invoices" :value="String(qbStore.summary?.total_invoices ?? 0)" icon="invoice" />
                <MetricCard title="Total Customers" :value="String(qbStore.summary?.total_customers ?? 0)" icon="customers" />
                <MetricCard title="Open Balance" :value="formatCurrency(qbStore.summary?.outstanding_balance ?? 0)" icon="money" />
                <MetricCard title="Total Invoiced" :value="formatCurrency(qbStore.summary?.invoice_total ?? 0)" icon="money" />
            </div>

            <div class="panel">
                <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
                    <div class="flex gap-2">
                        <button @click="activeTab = 'invoices'" :class="['btn btn-sm', activeTab === 'invoices' ? 'btn-primary' : 'btn-outline-primary']">
                            Invoices ({{ qbStore.summary?.total_invoices ?? 0 }})
                        </button>
                        <button @click="activeTab = 'customers'" :class="['btn btn-sm', activeTab === 'customers' ? 'btn-primary' : 'btn-outline-primary']">
                            Customers ({{ qbStore.summary?.total_customers ?? 0 }})
                        </button>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <input v-model="search" @input="debouncedSearch" type="text" class="form-input w-60 text-sm" :placeholder="activeTab === 'invoices' ? 'Search customer…' : 'Search customers…'" />
                        <select v-if="activeTab === 'invoices'" v-model="statusFilter" @change="() => loadInvoices(1)" class="form-select text-sm w-36">
                            <option value="">All Status</option>
                            <option value="Open">Open</option>
                            <option value="Paid">Paid</option>
                            <option value="Overdue">Overdue</option>
                        </select>
                    </div>
                </div>

                <div v-if="qbStore.loading" class="flex justify-center py-12">
                    <span class="animate-spin border-4 border-primary border-l-transparent rounded-full w-10 h-10 inline-block"></span>
                </div>

                <template v-else-if="activeTab === 'invoices'">
                    <div class="table-responsive">
                        <table class="table-hover">
                            <thead>
                                <tr>
                                    <th>Invoice #</th>
                                    <th>Customer</th>
                                    <th>Date</th>
                                    <th class="text-right">Amount</th>
                                    <th class="text-right">Balance</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="!qbStore.invoices?.data?.length">
                                    <td colspan="6" class="text-center text-white-dark/50 py-8">No invoices found.</td>
                                </tr>
                                <tr v-for="inv in qbStore.invoices?.data" :key="inv.id">
                                    <td class="font-medium text-primary">{{ inv.doc_number || `#${inv.qbo_id}` }}</td>
                                    <td>
                                        <p class="font-medium">{{ inv.customer_name || '—' }}</p>
                                        <p v-if="inv.customer_email" class="text-xs text-white-dark/60">{{ inv.customer_email }}</p>
                                    </td>
                                    <td>{{ formatDate(inv.txn_date) }}</td>
                                    <td class="text-right font-semibold">{{ formatCurrency(inv.total_amount) }}</td>
                                    <td class="text-right">{{ formatCurrency(inv.balance) }}</td>
                                    <td><span :class="statusBadge(inv.status)">{{ inv.status }}</span></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <Pagination v-if="qbStore.invoices" :current="qbStore.invoices.current_page" :last="qbStore.invoices.last_page" :total="qbStore.invoices.total" @change="loadInvoices" />
                </template>

                <template v-else>
                    <div class="table-responsive">
                        <table class="table-hover">
                            <thead>
                                <tr>
                                    <th>Customer</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th class="text-right">Balance</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="!qbStore.customers?.data?.length">
                                    <td colspan="5" class="text-center text-white-dark/50 py-8">No customers found.</td>
                                </tr>
                                <tr v-for="customer in qbStore.customers?.data" :key="customer.id">
                                    <td>
                                        <p class="font-medium">{{ customer.display_name || customer.company_name || '—' }}</p>
                                        <p v-if="customer.company_name" class="text-xs text-white-dark/60">{{ customer.company_name }}</p>
                                    </td>
                                    <td>{{ customer.email || '—' }}</td>
                                    <td>{{ customer.phone || '—' }}</td>
                                    <td class="text-right font-semibold">{{ formatCurrency(customer.balance) }}</td>
                                    <td>
                                        <span :class="customer.active ? 'badge badge-outline-success' : 'badge badge-outline-danger'">
                                            {{ customer.active ? 'Active' : 'Inactive' }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <Pagination v-if="qbStore.customers" :current="qbStore.customers.current_page" :last="qbStore.customers.last_page" :total="qbStore.customers.total" @change="loadCustomers" />
                </template>
            </div>
        </template>
    </div>
</template>

<script lang="ts" setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useQuickBooksStore } from '@/stores/quickbooks';
import { useToast } from '@/composables/use-toast';

const router = useRouter();
const qbStore = useQuickBooksStore();
const { showToast, confirmDialog } = useToast();

const loadingStatus = ref(true);
const connecting = ref(false);
const activeTab = ref<'invoices' | 'customers'>('invoices');
const statusFilter = ref('');
const search = ref('');
let searchTimer: ReturnType<typeof setTimeout>;

const companyName = computed(() => qbStore.summary?.company?.name || qbStore.status?.company_name || 'QuickBooks Company');
const activeClientName = computed(() => {
    const status = qbStore.status;
    if (!status) {
        return null;
    }
    if (status.all_clients) {
        return 'All clients';
    }
    const list = status.selected_clients ?? [];
    if (list.length > 1) {
        return `${list.length} clients`;
    }
    if (list.length === 1) {
        return list[0].name ?? status.selected_client?.display_name ?? null;
    }
    return status.selected_client?.display_name ?? null;
});

function changeClient() {
    router.push({ name: 'quickbooks-select-client', query: { change: '1' } });
}

async function connectQuickBooks() {
    connecting.value = true;
    try {
        const url = await qbStore.getConnectUrl();
        window.location.href = url;
    } catch (err: any) {
        showToast(err.response?.data?.message ?? 'Failed to start QuickBooks connection.', 'error');
        connecting.value = false;
    }
}

async function loadDashboard() {
    await qbStore.fetchSummary();
    await loadInvoices();
    if (qbStore.error) showToast(qbStore.error, 'error');
}

async function loadInvoices(page = 1) {
    await qbStore.fetchInvoices({
        status: statusFilter.value || undefined,
        customer: search.value || undefined,
        page,
        per_page: 25,
    });
    if (qbStore.error) showToast(qbStore.error, 'error');
}

async function loadCustomers(page = 1) {
    await qbStore.fetchCustomers({
        search: search.value || undefined,
        page,
        per_page: 25,
    });
    if (qbStore.error) showToast(qbStore.error, 'error');
}

function debouncedSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        activeTab.value === 'invoices' ? loadInvoices(1) : loadCustomers(1);
    }, 400);
}

watch(activeTab, async () => {
    search.value = '';
    if (activeTab.value === 'customers' && !qbStore.customers) {
        await loadCustomers();
    }
});

async function syncData() {
    try {
        await qbStore.triggerSync();
        showToast('QuickBooks sync queued successfully.', 'success');
        setTimeout(loadDashboard, 2000);
    } catch {
        showToast(qbStore.error ?? 'Failed to refresh QuickBooks data.', 'error');
    }
}

async function disconnect() {
    const ok = await confirmDialog({
        title: 'Disconnect QuickBooks?',
        text: 'This will remove your QuickBooks connection and purge synced data for this company.',
        confirmText: 'Yes, Disconnect',
    });
    if (!ok) return;

    try {
        await qbStore.disconnect();
        showToast('QuickBooks disconnected successfully.', 'success');
    } catch {
        showToast(qbStore.error ?? 'Failed to disconnect QuickBooks.', 'error');
    }
}

function formatDate(val: string | null | undefined): string {
    if (!val) return '—';
    return new Date(val).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
}

function formatDateTime(val: string | null | undefined): string {
    if (!val) return '—';
    return new Date(val).toLocaleString();
}

function formatCurrency(val: string | number): string {
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(Number(val) || 0);
}

function statusBadge(status: string): string {
    const map: Record<string, string> = {
        Paid:    'badge badge-outline-success',
        Open:    'badge badge-outline-warning',
        Overdue: 'badge badge-outline-danger',
    };
    return map[status] ?? 'badge badge-outline-secondary';
}

onMounted(async () => {
    loadingStatus.value = true;
    await qbStore.fetchStatus(true);
    loadingStatus.value = false;

    if (qbStore.needsClientSelection) {
        router.replace({ name: 'quickbooks-select-client' });
        return;
    }

    if (qbStore.isConnected) {
        await loadDashboard();
    }
});
</script>

<script lang="ts">
import { defineComponent, h } from 'vue';

const MetricCard = defineComponent({
    name: 'MetricCard',
    props: {
        title: { type: String, required: true },
        value: { type: String, required: true },
        icon:  { type: String, required: true },
    },
    setup(props) {
        return () => h('div', { class: 'panel' }, [
            h('div', { class: 'flex items-center gap-4' }, [
                h('div', { class: 'w-10 h-10 rounded-lg bg-primary/10 text-primary flex items-center justify-center' }, [
                    h('span', { class: 'text-lg font-bold' }, props.icon === 'money' ? '$' : props.icon === 'customers' ? 'C' : '#'),
                ]),
                h('div', [
                    h('p', { class: 'text-xs text-white-dark/60 uppercase tracking-wide' }, props.title),
                    h('p', { class: 'text-xl font-bold dark:text-white' }, props.value),
                ]),
            ]),
        ]);
    },
});

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
            return h('div', { class: 'flex items-center justify-between mt-4 text-sm text-white-dark/70 flex-wrap gap-3' }, [
                h('span', `Page ${props.current} of ${props.last} (${props.total} total)`),
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

export default {
    components: { MetricCard, Pagination },
};
</script>
