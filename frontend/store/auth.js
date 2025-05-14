import {defineStore} from 'pinia';
import {useStorage} from '@vueuse/core'

export const useAuthStore = defineStore('auth', () => {
    const state = reactive({
        token: null, authenticated: false, loading: false,
    });

    const actions = {
        async has_user() {
            const req = await request('/system/auth/has_user')
            return 200 === req.status
        },
        async signup(username, password) {
            if (!username || !password) {
                throw new Error('Please provide a valid username and password');
            }

            const req = await request('/system/auth/signup', {
                method: 'POST',
                body: JSON.stringify({username: username, password: password})
            })

            if (201 === req.status) {
                return true
            }

            const json = await parse_api_response(req)
            throw new Error(json.error.message);
        },
        async login(username, password) {
            if (!username || !password) {
                throw new Error('Please provide a valid username and password');
            }

            this.loading = true;

            try {
                const response = await request(`/system/auth/login`, {
                    method: 'POST',
                    body: JSON.stringify({username: username, password: password}),
                });

                const json = await parse_api_response(response)

                if (200 !== response.status) {
                    throw new Error(json.error.message);
                }

                if (!json?.token) {
                    throw new Error('Error. API did not return a token.');
                }

                const token = useStorage('token', null);
                token.value = json.token;

                this.token = json.token;

                this.authenticated = true;
            } finally {
                this.loading = false;
            }
        }, async logout() {
            const token = useStorage('token', null);
            this.authenticated = false;
            token.value = null;
            return true
        }, async validate(token) {
            try {
                const response = await request('/system/auth/user', {
                    method: 'GET',
                    headers: {
                        Authorization: 'Token ' + token,
                    }
                });

                if (200 !== response.status) {
                    this.token = null;
                    this.authenticated = false;
                    return false;
                }

                this.token = token;
                this.authenticated = true;
                return true
            } catch (e) {
                this.token = null;
                this.authenticated = false;
                return false;
            }
        }
    }

    return {...toRefs(state), ...actions};
});
