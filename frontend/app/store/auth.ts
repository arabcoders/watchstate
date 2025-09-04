import {defineStore} from 'pinia';
import {useStorage} from '@vueuse/core'
import {reactive, toRefs} from 'vue'
import type {AuthState} from '~/types/auth'
import request from '~/utils/request'
import {parse_api_response} from '~/utils'

export const useAuthStore = defineStore('auth', () => {
    const state = reactive<AuthState>({
        token: null,
        authenticated: false,
        loading: false,
        username: null,
    });

    const has_user = async (): Promise<boolean> => {
        const req = await request('/system/auth/has_user');
        const status = req.status === 200;
        if (req.ok && req) {
            const json = await parse_api_response(req);
            if (json?.token && json?.auto_login) {
                const token = useStorage<string | null>('token', null);
                state.token = json.token;
                token.value = json.token;
                state.authenticated = true;
            }
        }
        return status;
    };

    const signup = async (username: string, password: string): Promise<boolean> => {
        if (!username || !password) {
            throw new Error('Please provide a valid username and password');
        }
        const req = await request('/system/auth/signup', {
            method: 'POST',
            body: JSON.stringify({username, password}),
        });
        if (req.status === 201) {
            return true;
        }
        const json = await parse_api_response(req);
        if (json?.error?.message) {
            throw new Error(json.error.message);
        }
        throw new Error('Signup failed');
    };

    const login = async (username: string, password: string): Promise<void> => {
        if (!username || !password) {
            throw new Error('Please provide a valid username and password');
        }

        state.loading = true;

        try {
            const response = await request(`/system/auth/login`, {
                method: 'POST',
                body: JSON.stringify({username, password}),
            });
            const json = await parse_api_response(response);
            if (response.status !== 200) {
                if (json?.error?.message) {
                    throw new Error(json.error.message);
                }
                throw new Error('Login failed');
            }
            if (!json?.token) {
                throw new Error('Error. API did not return a token.');
            }
            const token = useStorage<string | null>('token', null);
            token.value = json.token;
            state.token = json.token;
            state.authenticated = true;
            state.username = username;
        } finally {
            state.loading = false;
        }
    };

    const logout = async (): Promise<boolean> => {
        const token = useStorage<string | null>('token', null);
        state.authenticated = false;
        token.value = null;
        return true;
    };

    const validate = async (token: string): Promise<boolean> => {
        try {
            const response = await request('/system/auth/user', {
                method: 'GET',
                headers: {
                    Authorization: 'Token ' + token,
                },
            });

            if (200 !== response.status) {
                state.token = null;
                state.authenticated = false;
                return false;
            }

            const json = await response.json();

            state.token = token;

            state.username = json.username;
            state.authenticated = true;
            return true;
        } catch (e) {
            state.token = null;
            state.authenticated = false;
            return false;
        }
    };

    return {...toRefs(state), has_user, signup, login, logout, validate};
});
