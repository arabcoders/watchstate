import { computed, nextTick, onBeforeUnmount, ref, watch, type Ref } from 'vue';
import { api_error_message, notification, parse_api_response, request, ucFirst } from '~/utils';
import type {
  Backend,
  BackendEditUser,
  BackendServer,
  BackendUuidResponse,
  JsonObject,
  JsonValue,
  PlexOAuthData,
  PlexOAuthTokenResponse,
} from '~/types';

type BackendOptionMap = Record<string, JsonValue>;

type SelectItem = {
  label: string;
  value: string;
};

type UseBackendSetupOptions = {
  onError?: (message: string, error?: unknown) => void;
};

export const useBackendSetup = (backend: Ref<Backend>, setupOptions?: UseBackendSetupOptions) => {
  const users = ref<Array<BackendEditUser>>([]);
  const supported = ref<Array<string>>([]);
  const usersLoading = ref<boolean>(false);
  const uuidLoading = ref<boolean>(false);
  const servers = ref<Array<BackendServer>>([]);
  const serversLoading = ref<boolean>(false);
  const exposeToken = ref<boolean>(false);

  const plexOauth = ref<PlexOAuthData | null>(null);
  const plexOauthLoading = ref<boolean>(false);
  const plexTimeout = ref<ReturnType<typeof setTimeout> | null>(null);
  const plexWindow = ref<Window | null>(null);

  const isLimitedToken = computed(() => Boolean(backend.value.options?.is_limited_token));

  const backendTypeItems = computed<Array<SelectItem>>(() =>
    supported.value.map((type) => ({ label: ucFirst(type), value: type })),
  );

  const serverItems = computed<Array<SelectItem>>(() =>
    servers.value.map((server) => ({
      label: `${server.name} - ${server.uri}`,
      value: server.uri,
    })),
  );

  const userItems = computed<Array<SelectItem>>(() =>
    users.value.map((user) => ({
      label:
        'plex' === backend.value.type
          ? `[${user.type}:${user.id}] ${user.name}${user.token_error ? ` - ${user.token_error}` : ''}`
          : `${user.name}${user.token_error ? ` - ${user.token_error}` : ''}`,
      value: user.id,
    })),
  );

  const plexOauthUrl = computed<string | undefined>(() => {
    if (!plexOauth.value) {
      return;
    }

    const url = new URL('https://app.plex.tv/auth');
    const params = new URLSearchParams();
    params.set('code', plexOauth.value.code);
    params.set('clientID', plexOauth.value['X-Plex-Client-Identifier']);
    params.set('context[device][product]', plexOauth.value['X-Plex-Product']);
    url.hash = '?' + params.toString();
    return url.toString();
  });

  const ensureBackendOptions = (): BackendOptionMap => {
    if (!backend.value.options || 'object' !== typeof backend.value.options) {
      backend.value.options = {};
    }

    return backend.value.options as BackendOptionMap;
  };

  const notifyError = (message: string, error?: unknown): void => {
    if (undefined !== error) {
      console.error(error);
    }

    setupOptions?.onError?.(message, error);
    notification('error', 'Error', message);
  };

  const appendClientOptions = (payload: JsonObject & { options?: JsonObject }): void => {
    const options = ensureBackendOptions();
    const clientOptions = options.client;

    if (!clientOptions || 'object' !== typeof clientOptions || Array.isArray(clientOptions)) {
      return;
    }

    if (false !== (clientOptions as JsonObject).verify_host) {
      return;
    }

    if (!payload.options) {
      payload.options = {};
    }

    payload.options.client = {
      verify_host: false,
    };
  };

  const loadSupported = async (): Promise<void> => {
    const supportedResponse = await request('/system/supported');
    const supportedData = await parse_api_response<Array<string>>(supportedResponse);

    if ('error' in supportedData) {
      notifyError(`Failed to load supported backends: ${supportedData.error.message}`);
      return;
    }

    supported.value = supportedData;
  };

  const getUUid = async (): Promise<void> => {
    const requiredValues: Array<keyof Backend> = ['type', 'token', 'url'];

    if (requiredValues.some((value) => !backend.value[value])) {
      notification(
        'error',
        'Error',
        `Please fill all the required fields. ${requiredValues.join(', ')}.`,
      );
      return;
    }

    uuidLoading.value = true;

    try {
      const payload: JsonObject & { options?: JsonObject } = {
        name: backend.value.name,
        token: backend.value.token,
        url: backend.value.url,
      };

      if (backend.value.user) {
        payload.user = backend.value.user;
      }

      appendClientOptions(payload);

      const response = await request(`/backends/uuid/${backend.value.type}`, {
        method: 'POST',
        body: JSON.stringify(payload),
      });

      const json = await parse_api_response<BackendUuidResponse>(response);

      if (!response.ok) {
        notifyError(api_error_message(json, response, 'Failed to get the UUID from the backend.'));
        return;
      }

      if ('error' in json) {
        notifyError(`Failed to get UUID: ${json.error.message}`);
        return;
      }

      backend.value.uuid = json.identifier;
    } finally {
      uuidLoading.value = false;
    }
  };

  const getUsers = async (
    showAlert: boolean = true,
    forceReload: boolean = false,
    withTokens: boolean = false,
    targetUser: string | null = null,
  ): Promise<Array<BackendEditUser> | undefined> => {
    const requiredValues: Array<keyof Backend> = ['type', 'token', 'url', 'uuid'];

    if (requiredValues.some((value) => !backend.value[value])) {
      if (showAlert) {
        notification(
          'error',
          'Error',
          `Please fill all the required fields. ${requiredValues.join(', ')}.`,
        );
      }
      return;
    }

    usersLoading.value = true;

    try {
      const payload: JsonObject & { options: JsonObject } = {
        name: backend.value.name,
        token: backend.value.token,
        url: backend.value.url,
        uuid: backend.value.uuid,
        user: backend.value.user,
        options: {},
      };

      const options = ensureBackendOptions();
      const requiredOptions = [
        'ADMIN_TOKEN',
        'plex_guest_user',
        'PLEX_USER_PIN',
        'is_limited_token',
      ];
      for (const option of requiredOptions) {
        if (undefined !== options[option]) {
          payload.options[option] = options[option];
        }
      }

      appendClientOptions(payload);

      const query = new URLSearchParams();
      if (withTokens) {
        query.append('tokens', '1');
        if (targetUser) {
          query.append('target_user', targetUser);
        }
      }

      if (forceReload) {
        query.append('no_cache', '1');
      }

      const response = await request(`/backends/users/${backend.value.type}?${query.toString()}`, {
        method: 'POST',
        body: JSON.stringify(payload),
      });

      const json = await parse_api_response<Array<BackendEditUser>>(response);

      if (!response.ok) {
        notifyError(api_error_message(json, response, 'Failed to load users'));
        return;
      }

      if ('error' in json) {
        notifyError(`Failed to load users: ${json.error.message}`);
        return;
      }

      users.value = json;
      return users.value;
    } finally {
      usersLoading.value = false;
    }
  };

  const generateUserToken = async (): Promise<void> => {
    if ('plex' !== backend.value.type) {
      return;
    }

    if (!backend.value.user) {
      notification('error', 'Error', 'Select a user to generate a token.');
      return;
    }

    const selectedUser = users.value.find((user) => user.id === backend.value.user);
    if (!selectedUser) {
      notification('error', 'Error', 'Selected user not found.');
      return;
    }

    const usersResponse = await getUsers(true, true, true, selectedUser.uuid ?? selectedUser.id);
    const updated = usersResponse?.find((user) => user.id === selectedUser.id);
    const token = updated?.token ?? selectedUser.token;
    if (!token) {
      notification('error', 'Error', 'User token not found');
      return;
    }

    const options = ensureBackendOptions();
    if (!options.ADMIN_TOKEN) {
      options.ADMIN_TOKEN = backend.value.token;
    }

    backend.value.token = token;
  };

  const getServers = async (): Promise<void> => {
    if ('plex' !== backend.value.type) {
      return;
    }

    if (!backend.value.token) {
      notification('error', 'Error', 'Token is required to get list of servers.');
      return;
    }

    serversLoading.value = true;

    try {
      const data: JsonObject = {
        name: backend.value.name,
        token: backend.value.token,
        url: window.location.origin,
      };

      const options = ensureBackendOptions();
      if (options.ADMIN_TOKEN) {
        data.options = {
          ADMIN_TOKEN: options.ADMIN_TOKEN,
        };
      }

      appendClientOptions(data as JsonObject & { options?: JsonObject });

      const response = await request(`/backends/discover/${backend.value.type}`, {
        method: 'POST',
        body: JSON.stringify(data),
      });

      const json = await parse_api_response<Array<BackendServer>>(response);

      if (!response.ok) {
        notifyError(api_error_message(json, response, 'Failed to load servers'));
        return;
      }

      if ('error' in json) {
        notifyError(`Failed to load servers: ${json.error.message}`);
        return;
      }

      servers.value = json;
    } finally {
      serversLoading.value = false;
    }
  };

  const updateIdentifier = async (): Promise<void> => {
    const server = servers.value.find((current) => backend.value.url === current.uri);
    if (!server) {
      return;
    }

    backend.value.uuid = server.identifier;
    await getUsers();
  };

  const generatePlexAuthRequest = async (): Promise<void> => {
    if (plexOauthLoading.value) {
      return;
    }

    plexOauthLoading.value = true;

    try {
      const response = await request('/backends/plex/generate', { method: 'POST' });
      const json = await parse_api_response<PlexOAuthData>(response);

      if (!response.ok) {
        notifyError(api_error_message(json, response, 'Failed to generate Plex auth request'));
        return;
      }

      if ('error' in json) {
        notifyError(`Failed to generate auth: ${json.error.message}`);
        return;
      }

      plexOauth.value = json;

      await nextTick();

      try {
        const width = 500;
        const height = 600;
        const features = [
          `width=${width}`,
          `height=${height}`,
          `top=${window.screen.height / 2 - height / 2}`,
          `left=${window.screen.width / 2 - width / 2}`,
          'resizable=yes',
          'scrollbars=yes',
        ].join(',');

        plexWindow.value = window.open(plexOauthUrl.value, 'plex_auth', features);
        plexTimeout.value = setTimeout(() => {
          void plexGetToken(false);
        }, 3000);
        await nextTick();

        if (!plexWindow.value) {
          notifyError('Popup blocked. Please allow popups for this site.');
        }
      } catch (error) {
        notifyError('Failed to open popup. Please manually click the link.', error);
      }
    } catch (error) {
      const errorMessage = error instanceof Error ? error.message : String(error);
      notifyError(`Request error. ${errorMessage}`, error);
    } finally {
      plexOauthLoading.value = false;
    }
  };

  const plexGetToken = async (notify = true): Promise<void> => {
    if (plexOauthLoading.value || !plexOauth.value) {
      return;
    }

    plexOauthLoading.value = true;

    try {
      if (plexTimeout.value) {
        clearTimeout(plexTimeout.value);
        plexTimeout.value = null;
      }

      const response = await request('/backends/plex/check', {
        method: 'POST',
        body: JSON.stringify({ id: plexOauth.value.id, code: plexOauth.value.code }),
      });

      const json = await parse_api_response<PlexOAuthTokenResponse>(response);

      if (!response.ok) {
        notifyError(api_error_message(json, response, 'Failed to check auth status'));
        return;
      }

      if ('error' in json) {
        notifyError(`Auth check failed: ${json.error.message}`);
        return;
      }

      if (json.authToken) {
        const options = ensureBackendOptions();
        backend.value.token = json.authToken;
        options.ADMIN_TOKEN = json.authToken;
        await nextTick();
        plexOauth.value = null;
        notification('success', 'Success', 'Successfully authenticated with plex.tv.');

        if (plexWindow.value) {
          try {
            plexWindow.value.close();
            plexWindow.value = null;
          } catch {}
        }

        await getUsers(true, true);
        return;
      }

      if (true === notify) {
        notification(
          'warning',
          'Warning',
          'Not authenticated yet. Login via the given link to authorize WatchState.',
        );
      }

      await nextTick();
      plexTimeout.value = setTimeout(() => {
        void plexGetToken(false);
      }, 3000);
    } catch (error) {
      const errorMessage = error instanceof Error ? error.message : String(error);
      notifyError(`Request error. ${errorMessage}`, error);
    } finally {
      plexOauthLoading.value = false;
    }
  };

  watch(
    () => users.value,
    (newUsers) => {
      if (
        'plex' !== backend.value.type ||
        !newUsers ||
        0 === newUsers.length ||
        !backend.value.user
      ) {
        return;
      }

      const selectedUser = newUsers.find((user) => user.id === backend.value.user);
      if (!selectedUser) {
        notification('warning', 'Warning', 'Selected user not found in updated user list');
        return;
      }

      const options = ensureBackendOptions();

      if (selectedUser.guest) {
        options.plex_external_user = true;
      } else if (options.plex_external_user) {
        delete options.plex_external_user;
      }

      options.plex_user_name = selectedUser.name;
      options.plex_user_uuid = selectedUser.uuid ?? '';

      if (!selectedUser.token) {
        return;
      }

      if (selectedUser.token !== backend.value.token) {
        if (!options.ADMIN_TOKEN) {
          options.ADMIN_TOKEN = backend.value.token;
        }
        backend.value.token = selectedUser.token;
        notification('info', 'Information', `Token updated for user: ${selectedUser.name}`);
      }
    },
    { deep: true },
  );

  watch(
    () => backend.value.user,
    () => {
      if (0 === users.value.length || 'plex' !== backend.value.type) {
        return;
      }

      const selectedUser = users.value.find((user) => user.id === backend.value.user);
      if (!selectedUser) {
        notification('warning', 'Warning', 'Selected user not found');
        return;
      }

      const options = ensureBackendOptions();

      if (selectedUser.guest) {
        options.plex_external_user = true;
      } else if (options.plex_external_user) {
        delete options.plex_external_user;
      }

      options.plex_user_name = selectedUser.name;
      options.plex_user_uuid = selectedUser.uuid ?? '';

      if (!selectedUser.token) {
        return;
      }

      if (selectedUser.token !== backend.value.token) {
        options.ADMIN_TOKEN = backend.value.token;
        backend.value.token = selectedUser.token;
        notification('info', 'Information', `Token updated for user: ${selectedUser.name}`);
      }
    },
  );

  onBeforeUnmount(() => {
    if (plexTimeout.value) {
      clearTimeout(plexTimeout.value);
      plexTimeout.value = null;
    }

    if (plexWindow.value) {
      try {
        plexWindow.value.close();
      } catch {}

      plexWindow.value = null;
    }
  });

  return {
    backendTypeItems,
    exposeToken,
    generatePlexAuthRequest,
    generateUserToken,
    getServers,
    getUUid,
    getUsers,
    isLimitedToken,
    loadSupported,
    plexGetToken,
    plexOauth,
    plexOauthLoading,
    plexOauthUrl,
    serverItems,
    servers,
    serversLoading,
    supported,
    updateIdentifier,
    userItems,
    users,
    usersLoading,
    uuidLoading,
  };
};
