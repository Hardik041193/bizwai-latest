import { defineStore } from 'pinia';
import axios from 'axios';

export interface AuthUser {
    id: number;
    name: string;
    email: string;
    role: 'user' | 'admin';
    phone?: string | null;
    job_title?: string | null;
    address?: string | null;
    bio?: string | null;
    avatar_url?: string | null;
    email_verified_at: string | null;
}

interface AuthState {
    user: AuthUser | null;
    token: string | null;
    requiresVerification: boolean;
    loading: boolean;
    error: string | null;
}

export const useAuthStore = defineStore('auth', {
    state: (): AuthState => ({
        user: null,
        token: localStorage.getItem('auth_token'),
        requiresVerification: false,
        loading: false,
        error: null,
    }),

    getters: {
        isAuthenticated: (state): boolean => !!state.token && !!state.user,
        isAdmin: (state): boolean => state.user?.role === 'admin',
        isVerified: (state): boolean => !!state.user?.email_verified_at,
    },

    actions: {
        async register(name: string, email: string, password: string, passwordConfirmation: string): Promise<void> {
            this.loading = true;
            this.error = null;
            try {
                const response = await axios.post('/api/register', {
                    name,
                    email,
                    password,
                    password_confirmation: passwordConfirmation,
                });
                // Store token so resend endpoint works (auth:sanctum)
                this.token = response.data.token;
                this.user = response.data.user;
                this.requiresVerification = true;
                localStorage.setItem('auth_token', this.token as string);
                axios.defaults.headers.common['Authorization'] = `Bearer ${this.token}`;
            } catch (err: any) {
                const errors = err.response?.data?.errors;
                this.error = errors
                    ? errors[Object.keys(errors)[0]]?.[0]
                    : (err.response?.data?.message ?? 'Registration failed.');
                throw err;
            } finally {
                this.loading = false;
            }
        },

        async login(email: string, password: string): Promise<void> {
            this.loading = true;
            this.error = null;
            this.requiresVerification = false;
            try {
                const response = await axios.post('/api/login', { email, password });
                this._setSession(response.data.token, response.data.user);
            } catch (err: any) {
                if (err.response?.status === 403 && err.response?.data?.requires_verification) {
                    this.requiresVerification = true;
                    this.error = 'Please verify your email before logging in.';
                } else {
                    this.error = err.response?.data?.errors?.email?.[0]
                        ?? err.response?.data?.message
                        ?? 'Login failed.';
                }
                throw err;
            } finally {
                this.loading = false;
            }
        },

        async adminLogin(email: string, password: string): Promise<void> {
            this.loading = true;
            this.error = null;
            this.requiresVerification = false;
            try {
                const response = await axios.post('/api/admin/login', { email, password });
                this._setSession(response.data.token, response.data.user);
            } catch (err: any) {
                if (err.response?.status === 403 && err.response?.data?.requires_verification) {
                    this.requiresVerification = true;
                    this.error = 'Please verify your email before logging in.';
                } else {
                    this.error = err.response?.data?.errors?.email?.[0]
                        ?? err.response?.data?.message
                        ?? 'Login failed.';
                }
                throw err;
            } finally {
                this.loading = false;
            }
        },

        async resendVerification(): Promise<void> {
            this.loading = true;
            this.error = null;
            try {
                await axios.post('/api/email/resend');
            } catch (err: any) {
                this.error = err.response?.data?.message ?? 'Failed to resend verification email.';
                throw err;
            } finally {
                this.loading = false;
            }
        },

        async logout(): Promise<void> {
            try {
                await axios.post('/api/logout');
            } catch (_) {}
            finally {
                this._clearSession();
            }
        },

        async fetchUser(): Promise<void> {
            if (!this.token) return;
            axios.defaults.headers.common['Authorization'] = `Bearer ${this.token}`;
            try {
                const response = await axios.get('/api/user');
                this.user = response.data;
            } catch (_) {
                this._clearSession();
            }
        },

        _setSession(token: string, user: AuthUser): void {
            this.token = token;
            this.user = user;
            this.requiresVerification = false;
            localStorage.setItem('auth_token', token);
            axios.defaults.headers.common['Authorization'] = `Bearer ${token}`;
        },

        _clearSession(): void {
            this.token = null;
            this.user = null;
            this.requiresVerification = false;
            localStorage.removeItem('auth_token');
            delete axios.defaults.headers.common['Authorization'];
        },
    },
});
