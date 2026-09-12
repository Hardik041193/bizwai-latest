import { defineStore } from 'pinia';
import axios from 'axios';

// ── Types ──────────────────────────────────────────────────────────────────

export interface QBSelectedClient {
    qbo_id: string;
    display_name: string;
}

export interface QBSelectedClientEntry {
    qbo_id: string;
    name: string | null;
}

export interface QBStatus {
    connected: boolean;
    role?: 'admin' | 'user';
    realm_id?: string;
    company_name?: string | null;
    legal_name?: string | null;
    company_email?: string | null;
    country?: string | null;
    needs_client_selection?: boolean;
    all_clients?: boolean;
    selected_clients?: QBSelectedClientEntry[];
    selected_client?: QBSelectedClient | null;
    token_expires_at?: string;
    refresh_token_expires_at?: string;
    access_token_expired?: boolean;
    is_company_account?: boolean;
    last_synced_at?: string | null;
}

export type QBSyncEntityStatus = 'idle' | 'pending' | 'syncing' | 'complete' | 'failed';

export interface QBSyncEntity {
    entity: string;
    label: string;
    status: QBSyncEntityStatus;
    records_synced: number;
    last_synced_at: string | null;
    error: string | null;
}

export interface QBSyncProgress {
    realm_id?: string;
    entities: QBSyncEntity[];
    /** idle = never synced, partial = finished with at least one failure. */
    status: 'idle' | 'syncing' | 'partial' | 'complete';
    complete: boolean;
    progress: number;
    entities_total: number;
    entities_finished: number;
    entities_failed: number;
    pending_entities: string[];
    last_synced_at: string | null;
}

export interface QBSummary {
    total_revenue: number;
    outstanding_balance: number;
    total_expenses: number;
    overdue_invoices: number;
    total_invoices: number;
    total_customers: number;
    invoice_total: number;
    all_clients?: boolean;
    selected_clients?: QBSelectedClientEntry[];
    last_synced_at: string | null;
    company?: {
        name: string | null;
        legal_name: string | null;
        email: string | null;
        country: string | null;
        realm_id: string;
    };
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

export interface QBCustomer {
    id: number;
    qbo_id: string;
    display_name: string | null;
    company_name: string | null;
    email: string | null;
    phone: string | null;
    balance: string;
    active: boolean;
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

const STATUS_CACHE_MS = 30_000;

interface QBState {
    status: QBStatus | null;
    statusFetchedAt: number | null;
    summary: QBSummary | null;
    accounts: Paginated<QBAccount> | null;
    customers: Paginated<QBCustomer> | null;
    invoices: Paginated<QBInvoice> | null;
    transactions: Paginated<QBTransaction> | null;
    loading: boolean;
    syncing: boolean;
    syncProgress: QBSyncProgress | null;
    error: string | null;
}

// ── Store ──────────────────────────────────────────────────────────────────

export const useQuickBooksStore = defineStore('quickbooks', {
    state: (): QBState => ({
        status: null,
        statusFetchedAt: null,
        summary: null,
        accounts: null,
        customers: null,
        invoices: null,
        transactions: null,
        loading: false,
        syncing: false,
        syncProgress: null,
        error: null,
    }),

    getters: {
        isConnected: (state): boolean => state.status?.connected === true,
        // Client-selection step disabled: we now use ALL clients of the company
        // selected at QuickBooks integration time. Force `false` so the app never
        // redirects to /quickbooks/select-client. To re-enable, restore the line below.
        // needsClientSelection: (state): boolean =>
        //     state.status?.connected === true && state.status?.needs_client_selection === true,
        needsClientSelection: (): boolean => false,

        /** 0-100. Drives the onboarding progress bar. */
        syncPercent: (state): number => state.syncProgress?.progress ?? 0,

        /** Entities still importing, for "Invoices ✓, Expenses importing…" copy. */
        syncPendingLabels: (state): string[] =>
            (state.syncProgress?.entities ?? [])
                .filter(e => e.status === 'pending' || e.status === 'syncing')
                .map(e => e.label),

        /** True when the last sync finished but some entities failed. */
        syncHadFailures: (state): boolean => (state.syncProgress?.entities_failed ?? 0) > 0,
    },

    actions: {
        async fetchStatus(force = false): Promise<void> {
            if (
                ! force
                && this.statusFetchedAt
                && Date.now() - this.statusFetchedAt < STATUS_CACHE_MS
            ) {
                return;
            }

            try {
                const { data } = await axios.get('/api/quickbooks/status');
                this.status = data;
                this.statusFetchedAt = Date.now();
            } catch (err: any) {
                this.status = { connected: false };
                this.statusFetchedAt = Date.now();
            }
        },

        async getConnectUrl(): Promise<string> {
            const { data } = await axios.get('/api/quickbooks/connect');
            return data.url as string;
        },

        async fetchSelectionClients(search?: string): Promise<{
            company_name: string | null;
            realm_id: string;
            clients: Array<{ qbo_id: string; display_name: string; company_name: string | null }>;
        }> {
            const { data } = await axios.get('/api/quickbooks/selection/clients', {
                params: search ? { search } : {},
            });
            return data;
        },

        async saveClientSelection(payload: {
            clients?: Array<{ qbo_id: string; display_name: string }>;
            selectAll?: boolean;
        }): Promise<void> {
            await axios.post('/api/quickbooks/selection/client', {
                select_all: payload.selectAll ?? false,
                clients: payload.clients ?? [],
            });
        },

        async clearClientSelection(): Promise<void> {
            await axios.delete('/api/quickbooks/selection/client');
            await this.fetchStatus(true);
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

        async fetchCustomers(params: Record<string, any> = {}): Promise<void> {
            this.loading = true;
            this.error = null;
            try {
                const { data } = await axios.get('/api/quickbooks/customers', { params });
                this.customers = data;
            } catch (err: any) {
                this.error = err.response?.data?.message ?? 'Failed to load customers.';
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

        async fetchSyncProgress(): Promise<QBSyncProgress | null> {
            try {
                const { data } = await axios.get('/api/quickbooks/sync/progress');
                this.syncProgress = data;
                return data as QBSyncProgress;
            } catch {
                // A failed poll is not a failed sync; keep the last known
                // progress and let the next tick try again.
                return null;
            }
        },

        /**
         * Queue a sync and follow it to completion.
         *
         * The POST only queues work now that jobs run on a worker, so `syncing`
         * cannot be cleared when it returns — that would show "done" over an
         * empty dashboard. It stays true until the progress endpoint reports
         * every entity finished.
         */
        async triggerSync(options: { poll?: boolean } = {}): Promise<void> {
            const { poll = true } = options;

            this.syncing = true;
            this.error = null;

            try {
                const { data } = await axios.post('/api/quickbooks/sync');
                if (data?.progress) {
                    this.syncProgress = data.progress;
                }
            } catch (err: any) {
                this.syncing = false;
                this.error = err.response?.data?.message ?? 'Sync failed.';
                throw err;
            }

            if (! poll) {
                return;
            }

            try {
                await this.pollSyncProgress();
            } finally {
                this.syncing = false;
            }
        },

        /**
         * Follow a sync that is already running (the OAuth callback dispatches
         * one server-side) without queueing another.
         */
        async followSync(): Promise<void> {
            this.syncing = true;
            this.error = null;

            try {
                await this.pollSyncProgress();
            } finally {
                this.syncing = false;
            }
        },

        /**
         * Poll until the sync finishes, or until the ceiling is hit.
         *
         * The ceiling exists so a worker that is not running, or one that died
         * without reaching the job's failed() handler, cannot leave the UI
         * spinning forever. Hitting it surfaces a message naming the likely
         * cause rather than failing silently.
         */
        async pollSyncProgress(intervalMs = 2000, maxMs = 10 * 60 * 1000): Promise<void> {
            const startedAt = Date.now();

            while (Date.now() - startedAt < maxMs) {
                const progress = await this.fetchSyncProgress();

                if (progress?.complete) {
                    if (progress.entities_failed > 0) {
                        const failed = progress.entities
                            .filter(e => e.status === 'failed')
                            .map(e => e.label)
                            .join(', ');
                        this.error = `Some data could not be imported: ${failed}.`;
                    }

                    // Figures on screen are stale until the sync lands.
                    await this.fetchSummary();
                    await this.fetchStatus(true);
                    return;
                }

                await new Promise(resolve => setTimeout(resolve, intervalMs));
            }

            this.error = 'Sync is taking longer than expected. '
                + 'It may still be running in the background, or the queue worker may be stopped.';
        },

        async disconnect(): Promise<void> {
            try {
                await axios.post('/api/quickbooks/disconnect');
                this.$reset();
                this.status = { connected: false };
                this.statusFetchedAt = Date.now();
            } catch (err: any) {
                this.error = err.response?.data?.message ?? 'Disconnect failed.';
                throw err;
            }
        },
    },
});
