import { defineStore } from 'pinia';
import axios from 'axios';

export interface AdminUser {
    id: number;
    name: string;
    email: string;
    role: 'admin' | 'user';
    phone: string | null;
    job_title: string | null;
    address: string | null;
    avatar_url: string | null;
    email_verified_at: string | null;
    created_at: string | null;
}

export interface UsersPaginated {
    data: AdminUser[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

export interface UserFormData {
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
    role: 'admin' | 'user';
    phone: string;
    job_title: string;
    address: string;
}

interface UsersState {
    users: UsersPaginated | null;
    loading: boolean;
    saving: boolean;
    deleting: boolean;
    errors: Record<string, string[]>;
    error: string | null;
}

export const useUsersStore = defineStore('users', {
    state: (): UsersState => ({
        users:    null,
        loading:  false,
        saving:   false,
        deleting: false,
        errors:   {},
        error:    null,
    }),

    actions: {
        async fetchUsers(params: Record<string, any> = {}): Promise<void> {
            this.loading = true;
            this.error   = null;
            try {
                const { data } = await axios.get<UsersPaginated>('/api/admin/users', { params });
                this.users = data;
            } catch (err: any) {
                this.error = err.response?.data?.message ?? 'Failed to load users.';
            } finally {
                this.loading = false;
            }
        },

        async createUser(payload: UserFormData): Promise<AdminUser | null> {
            this.saving = true;
            this.errors = {};
            this.error  = null;
            try {
                const { data } = await axios.post('/api/admin/users', payload);
                return data.user as AdminUser;
            } catch (err: any) {
                if (err.response?.status === 422) {
                    this.errors = err.response.data.errors ?? {};
                }
                this.error = err.response?.data?.message ?? 'Failed to create user.';
                return null;
            } finally {
                this.saving = false;
            }
        },

        async updateUser(id: number, payload: Partial<UserFormData>): Promise<AdminUser | null> {
            this.saving = true;
            this.errors = {};
            this.error  = null;
            try {
                const { data } = await axios.put(`/api/admin/users/${id}`, payload);
                return data.user as AdminUser;
            } catch (err: any) {
                if (err.response?.status === 422) {
                    this.errors = err.response.data.errors ?? {};
                }
                this.error = err.response?.data?.message ?? 'Failed to update user.';
                return null;
            } finally {
                this.saving = false;
            }
        },

        async deleteUser(id: number): Promise<boolean> {
            this.deleting = true;
            this.error    = null;
            try {
                await axios.delete(`/api/admin/users/${id}`);
                return true;
            } catch (err: any) {
                this.error = err.response?.data?.message ?? 'Failed to delete user.';
                return false;
            } finally {
                this.deleting = false;
            }
        },

        clearErrors(): void {
            this.errors = {};
            this.error  = null;
        },
    },
});
