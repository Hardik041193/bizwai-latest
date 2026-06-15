// resources/js/src/stores/adminDashboard.ts
import { defineStore } from 'pinia';
import { ref } from 'vue';
import axios from 'axios';

interface DashboardStats {
    total_users:   number;
    active_users:  number;
    qbo_active:    number;
    pending_verif: number;
}

export const useAdminDashboardStore = defineStore('adminDashboard', () => {

    const stats   = ref<DashboardStats | null>(null);
    const loading = ref(false);
    const error   = ref<string | null>(null);

    async function fetchStats(): Promise<void> {
        loading.value = true;
        error.value   = null;
        try {
            const res   = await axios.get('/api/admin/dashboard/stats');
            stats.value = res.data;
        } catch (err: any) {
            error.value = err.response?.data?.message ?? 'Failed to load dashboard stats.';
        } finally {
            loading.value = false;
        }
    }

    return { stats, loading, error, fetchStats };
});