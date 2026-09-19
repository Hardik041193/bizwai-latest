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

interface UserGrowth {
    labels:      string[];
    signups:     number[];
    connections: number[];
}

interface HealthScore {
    score:     number;
    label:     string;
    breakdown: {
        adoption:     number;
        verification: number;
        freshness:    number;
    };
}

interface QuickInsights {
    new_users_30d:       number;
    unread_messages:     number;
    connected_companies: number;
    stale_syncs:         number;
}

interface DashboardCharts {
    months:         number;
    user_growth:    UserGrowth;
    health_score:   HealthScore;
    quick_insights: QuickInsights;
}

export const useAdminDashboardStore = defineStore('adminDashboard', () => {

    const stats   = ref<DashboardStats | null>(null);
    const loading = ref(false);
    const error   = ref<string | null>(null);

    const charts        = ref<DashboardCharts | null>(null);
    const chartsLoading = ref(false);
    const chartsError   = ref<string | null>(null);

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

    async function fetchCharts(months = 12): Promise<void> {
        chartsLoading.value = true;
        chartsError.value   = null;
        try {
            const res    = await axios.get('/api/admin/dashboard/charts', { params: { months } });
            charts.value = res.data;
        } catch (err: any) {
            chartsError.value = err.response?.data?.message ?? 'Failed to load dashboard charts.';
        } finally {
            chartsLoading.value = false;
        }
    }

    return {
        stats, loading, error, fetchStats,
        charts, chartsLoading, chartsError, fetchCharts,
    };
});
