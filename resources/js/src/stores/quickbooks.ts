import { defineStore } from 'pinia';
import axios from 'axios';

// ── Types ──────────────────────────────────────────────────────────────────

export interface QBStatus {
    connected: boolean;
    role?: 'admin' | 'user';
    realm_id?: string;
    token_expires_at?: string;
    refresh_token_expires_at?: string;
    access_token_expired?: boolean;
    is_company_account?: boolean;
    last_synced_at?: string | null;
}

export interface QBSummary {
    total_revenue: number;
    outstanding_balance: number;
    total_expenses: number;
    overdue_invoices: number;
    last_synced_at: string | null;
}

export interface QBAccount {
    id: number;
    qbo_id: string;
    name: string;
    account_type: string;
    account_sub_type: string;
    classification: string;
    current_balance: string;
    currency_ref: string;
    active: boolean;
}

export interface QBInvoice {
    id: number;
    qbo_id: string;
    doc_number: string;
    customer_name: string;
    customer_email: string;
    txn_date: string;
    due_date: string;
    total_amount: string;
    balance: string;
    status: 'Open' | 'Paid' | 'Overdue';
    line_items: any[];
}

export interface QBTransaction {
    id: number;
    qbo_id: string;
    txn_type: string;
    txn_date: string;
    account_name: string;
    entity_name: string;
    amount: string;
    description: string;
}

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface QBState {
    status: QBStatus | null;
    summary: QBSummary | null;
    accounts: Paginated<QBAccount> | null;
    invoices: Paginated<QBInvoice> | null;
    transactions: Paginated<QBTransaction> | null;
    loading: boolean;
    syncing: boolean;
    error: string | null;
}

// ── Store ──────────────────────────────────────────────────────────────────

export const useQuickBooksStore = defineStore('quickbooks', {
    state: (): QBState => ({
        status: null,
        summary: null,
        accounts: null,
        invoices: null,
        transactions: null,
        loading: false,
        syncing: false,
        error: null,
    }),

    getters: {
        isConnected: (state): boolean => state.status?.connected === true,
    },

    actions: {
        async fetchStatus(): Promise<void> {
            try {
                const { data } = await axios.get('/api/quickbooks/status');
                this.status = data;
            } catch (err: any) {
                this.status = { connected: false };
            }
        },

        async getConnectUrl(): Promise<string> {
            const { data } = await axios.get('/api/quickbooks/connect');
            return data.url as string;
        },

        async fetchSummary(): Promise<void> {
            this.loading = true;
            this.error = null;
            try {
                const { data } = await axios.get('/api/quickbooks/summary');
                this.summary = data;
            } catch (err: any) {
                this.error = err.response?.data?.message ?? 'Failed to load summary.';
            } finally {
                this.loading = false;
            }
        },

        async fetchAccounts(params: Record<string, any> = {}): Promise<void> {
            this.loading = true;
            this.error = null;
            try {
                const { data } = await axios.get('/api/quickbooks/accounts', { params });
                this.accounts = data;
            } catch (err: any) {
                this.error = err.response?.data?.message ?? 'Failed to load accounts.';
            } finally {
                this.loading = false;
            }
        },

        async fetchInvoices(params: Record<string, any> = {}): Promise<void> {
            this.loading = true;
            this.error = null;
            try {
                const { data } = await axios.get('/api/quickbooks/invoices', { params });
                this.invoices = data;
            } catch (err: any) {
                this.error = err.response?.data?.message ?? 'Failed to load invoices.';
            } finally {
                this.loading = false;
            }
        },

        async fetchTransactions(params: Record<string, any> = {}): Promise<void> {
            this.loading = true;
            this.error = null;
            try {
                const { data } = await axios.get('/api/quickbooks/transactions', { params });
                this.transactions = data;
            } catch (err: any) {
                this.error = err.response?.data?.message ?? 'Failed to load transactions.';
            } finally {
                this.loading = false;
            }
        },

        async triggerSync(): Promise<void> {
            this.syncing = true;
            this.error = null;
            try {
                await axios.post('/api/quickbooks/sync');
            } catch (err: any) {
                this.error = err.response?.data?.message ?? 'Sync failed.';
                throw err;
            } finally {
                this.syncing = false;
            }
        },

        async disconnect(): Promise<void> {
            try {
                await axios.post('/api/quickbooks/disconnect');
                this.$reset();
                this.status = { connected: false };
            } catch (err: any) {
                this.error = err.response?.data?.message ?? 'Disconnect failed.';
                throw err;
            }
        },
    },
});
