import { useStorage } from '@vueuse/core';
import { reactive, toRefs } from 'vue';
import { request, parse_api_response } from '~/utils';
import type { AuthRefreshResponse, AuthUserResponse, GenericError, GenericResponse } from '~/types';

type HasUserResponse = {
  token?: string;
  auto_login?: boolean;
};

type LoginResponse = {
  token?: string;
};

const state = reactive<{
  token: string | null;
  authenticated: boolean;
  loading: boolean;
  username: string | null;
  expiresAt: string | null;
}>({
  token: null,
  authenticated: false,
  loading: false,
  username: null,
  expiresAt: null,
});

const token = useStorage<string | null>('token', null);

export const useAuth = () => {
  const clearAuth = (): void => {
    state.token = null;
    state.authenticated = false;
    state.username = null;
    state.expiresAt = null;
    token.value = null;
  };

  const applySession = (
    nextToken: string,
    username: string,
    expiresAt: string | null = null,
  ): void => {
    token.value = nextToken;
    state.token = nextToken;
    state.username = username;
    state.expiresAt = expiresAt;
    state.authenticated = true;
  };

  const refresh = async (): Promise<string> => {
    const response = await request('/system/auth/refresh', {
      method: 'POST',
    });

    const json = await parse_api_response<AuthRefreshResponse>(response);
    if (response.status !== 200) {
      if ('error' in json) {
        throw new Error(json.error.message);
      }
      throw new Error('Failed to refresh token');
    }

    if ('error' in json || !json.token) {
      throw new Error('Error. API did not return a refresh token.');
    }

    applySession(json.token, json.username, json.expires_at);

    return json.token;
  };

  const has_user = async (no_cache: boolean = false): Promise<boolean> => {
    let url = '/system/auth/has_user';
    if (no_cache) {
      url += '?_=' + new Date().getTime();
    }
    const req = await request(url, no_cache ? { cache: 'no-store' } : {});
    const status = req.status === 200;
    if (req.ok && req) {
      const json = await parse_api_response<HasUserResponse>(req);
      if ('error' in json) {
        return status;
      }
      if (json.token && json.auto_login) {
        state.token = json.token;
        token.value = json.token;
        state.authenticated = true;
        state.expiresAt = null;
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
      body: JSON.stringify({ username, password }),
    });
    if (req.status === 201) {
      return true;
    }
    const json = await parse_api_response<GenericResponse>(req);
    if ('error' in json) {
      const errorJson = json as GenericError;
      throw new Error(errorJson.error.message);
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
        body: JSON.stringify({ username, password }),
      });
      const json = await parse_api_response<LoginResponse>(response);
      if (response.status !== 200) {
        if ('error' in json) {
          const errorJson = json as GenericError;
          throw new Error(errorJson.error.message);
        }
        throw new Error('Login failed');
      }
      if ('error' in json || !json.token) {
        throw new Error('Error. API did not return a token.');
      }
      applySession(json.token, username);
    } finally {
      state.loading = false;
    }
  };

  const logout = async (): Promise<boolean> => {
    clearAuth();
    return true;
  };

  const validate = async (): Promise<boolean> => {
    try {
      const response = await request('/system/auth/user');

      const json = await parse_api_response<AuthUserResponse>(response);

      if (200 !== response.status || 'error' in json) {
        clearAuth();
        return false;
      }

      if (!token.value) {
        clearAuth();
        return false;
      }

      applySession(token.value, json.username, json.expires_at);

      if (json.refresh_required) {
        await refresh();
      }

      return true;
    } catch {
      clearAuth();
      return false;
    }
  };

  return { ...toRefs(state), token, has_user, signup, login, logout, refresh, validate };
};
