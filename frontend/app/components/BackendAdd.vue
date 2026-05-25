<template>
  <div class="space-y-5">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <div class="space-y-1">
        <p class="text-sm font-semibold text-highlighted">Add backend to {{ api_user }}</p>
        <p class="text-sm text-toned">Work through the staged setup and keep the current flow.</p>
      </div>

      <div
        class="inline-flex items-center gap-2 rounded-md border border-default bg-elevated/60 px-3 py-2 text-sm text-toned"
      >
        <UIcon name="i-lucide-list-checks" class="size-4" />
        <span>Step {{ stage + 1 }} of {{ maxStages + 1 }}</span>
      </div>
    </div>

    <UAlert color="warning" variant="soft" icon="i-lucide-info" title="Important">
      <template #description>
        <ul class="list-disc space-y-2 pl-5 text-sm text-default">
          <li v-if="pathGuidEnvMissing">
            Path matching is disabled. If this backend shares media files with your existing
            backends, consider enabling
            <NuxtLink
              to="/env?edit=WS_GUID_PATH_ENABLED&value=true"
              class="inline-flex items-center gap-1 text-primary"
            >
              <UIcon name="i-lucide-sliders-horizontal" class="size-4" />
              <span>WS_GUID_PATH_ENABLED</span>
            </NuxtLink>
            before adding it, so future imports can store path GUIDs without a forced reimport. See
            the
            <NuxtLink to="/help/path-match" class="inline-flex items-center gap-1 text-primary">
              <UIcon name="i-lucide-circle-help" class="size-4" />
              <span>path matching guide</span>
            </NuxtLink>
            for details.
          </li>
          <li>
            If you are adding new backend that is fresh and doesn't have your current watch state,
            you should turn off import and enable only metadata import at the start to prevent
            overriding your current play state. Visit the following guide
            <NuxtLink to="/help/one-way-sync" class="inline-flex items-center gap-1 text-primary">
              <UIcon name="i-lucide-circle-help" class="size-4" />
              <span>One-way sync</span>
            </NuxtLink>
            to learn more.
          </li>
          <li v-if="api_user === 'main'">
            Do not add identity backends manually after finishing the main identity backend setup.
            Visit
            <NuxtLink
              target="_blank"
              to="/identities/provision"
              class="inline-flex items-center gap-1 text-primary"
            >
              <UIcon name="i-lucide-users" class="size-4" />
              <span>Identities</span>
              <span>&gt;</span>
              <span>Match &amp; Provision</span>
            </NuxtLink>
            page to create their own identities and backends automatically.
          </li>
        </ul>
      </template>
    </UAlert>

    <form
      id="backend_add_form"
      class="space-y-5"
      @submit.prevent="stage < 4 ? changeStep() : addBackend()"
    >
      <UAlert
        v-if="error"
        id="backend_error"
        color="error"
        variant="soft"
        icon="i-lucide-triangle-alert"
        title="Backend Error"
        :description="error"
        close
        @update:open="(open) => (false === open ? (error = null) : null)"
      />

      <UCard class="border border-default/70 shadow-sm" :ui="cardUi">
        <template #header>
          <div class="flex items-center gap-2 text-sm font-semibold text-highlighted">
            <UIcon name="i-lucide-plug-zap" class="size-4 text-toned" />
            <span>Basics</span>
          </div>
        </template>

        <div class="space-y-5">
          <div class="grid gap-4 lg:grid-cols-2">
            <UFormField
              label="Local User"
              name="local_user"
              description="The local user which this backend will be associated with."
            >
              <UInput :model-value="api_user" icon="i-lucide-users" class="w-full" disabled />
            </UFormField>

            <UFormField label="Type" name="backend_type" description="The backend type." required>
              <USelect
                v-model="backend.type"
                :items="backendTypeItems"
                value-key="value"
                class="w-full"
                icon="i-lucide-server"
                :disabled="stage > 0"
              />
            </UFormField>
          </div>

          <UFormField label="Name" name="backend_name" required>
            <UInput
              v-model="backend.name"
              icon="i-lucide-id-card"
              class="w-full"
              :disabled="stage > 0"
            />

            <p class="mt-2 text-sm text-toned">
              Choose a unique name for this backend.
              <strong>You cannot change it later.</strong> Backend name must be in
              <strong>lower case a-z, 0-9 and _</strong> and cannot start with number.
            </p>
          </UFormField>

          <UFormField
            :label="'plex' !== backend.type ? 'API Key' : 'X-Plex-Token'"
            name="backend_token"
            required
          >
            <div class="flex items-start gap-2">
              <UInput
                v-model="backend.token"
                icon="i-lucide-key-round"
                class="w-full"
                :disabled="stage > 1"
                :type="false === exposeToken ? 'password' : 'text'"
              />

              <UTooltip text="Toggle token">
                <UButton
                  type="button"
                  color="neutral"
                  variant="outline"
                  :icon="!exposeToken ? 'i-lucide-eye' : 'i-lucide-eye-off'"
                  :aria-label="!exposeToken ? 'Show token' : 'Hide token'"
                  class="whitespace-nowrap"
                  @click="exposeToken = !exposeToken"
                >
                  {{ exposeToken ? 'Hide' : 'Show' }}
                </UButton>
              </UTooltip>
            </div>

            <div class="mt-2 space-y-2 text-sm text-toned">
              <template v-if="'plex' === backend.type">
                <p>
                  Enter the <strong>X-Plex-Token</strong>.
                  <NuxtLink
                    target="_blank"
                    to="https://support.plex.tv/articles/204059436"
                    class="text-primary"
                  >
                    Visit This link
                  </NuxtLink>
                  to learn how to get the token.
                </p>
                <p class="font-semibold text-warning">
                  If you plan to provision identities, YOU MUST use an admin level token.
                </p>
              </template>

              <template v-else>
                <p>
                  Generate a new API Key from
                  <strong>Dashboard &gt; Settings &gt; API Keys</strong>.
                </p>
                <p>
                  You can use <strong>username:password</strong> as API key and we will
                  automatically generate limited token if you are unable to generate API Key. This
                  should be used as last resort, and it's mostly untested.
                </p>
                <p class="font-semibold text-error">
                  If you plan to provision identities, YOU MUST use API KEY and not
                  username:password.
                </p>
              </template>
            </div>

            <div v-if="'plex' === backend.type && !backend.token" class="mt-4 space-y-3">
              <UButton
                v-if="!hasPlexOauth"
                type="button"
                color="neutral"
                variant="outline"
                :loading="plex_oauth_loading"
                :disabled="plex_oauth_loading"
                icon="i-lucide-external-link"
                @click="generate_plex_auth_request"
              >
                {{ plex_oauth_loading ? 'Generating link' : 'Sign-in via Plex' }}
              </UButton>

              <div v-if="plex_oauth_url" class="flex flex-wrap gap-3">
                <UButton
                  type="button"
                  color="neutral"
                  variant="outline"
                  :loading="plex_oauth_loading"
                  :disabled="plex_oauth_loading"
                  icon="i-lucide-badge-check"
                  @click="() => plex_get_token()"
                >
                  Check auth request.
                </UButton>

                <UButton
                  as="a"
                  :href="plex_oauth_url"
                  target="_blank"
                  color="neutral"
                  variant="outline"
                  icon="i-lucide-external-link"
                >
                  Open Plex Auth Link
                </UButton>
              </div>
            </div>
          </UFormField>

          <UFormField
            v-if="'plex' === backend.type"
            label="User PIN"
            name="plex_user_pin"
            description="If the user you are going to select has PIN enabled, you need to enter the pin here. Otherwise it will fail to authenticate."
          >
            <UInput
              v-model="backend.options.PLEX_USER_PIN"
              icon="i-lucide-key-round"
              class="w-full"
              :disabled="stage > 1"
            />
          </UFormField>
        </div>
      </UCard>

      <UCard v-if="stage >= 1" class="border border-default/70 shadow-sm" :ui="cardUi">
        <template #header>
          <div class="flex items-center gap-2 text-sm font-semibold text-highlighted">
            <UIcon name="i-lucide-link" class="size-4 text-toned" />
            <span>Connection</span>
          </div>
        </template>

        <div class="space-y-5">
          <UFormField
            v-if="'plex' !== backend.type"
            label="URL"
            name="backend_url"
            description="Enter the URL of the backend. For example http://192.168.8.200:8096."
            required
          >
            <UInput
              v-model="backend.url"
              icon="i-lucide-link"
              class="w-full"
              :disabled="stage > 1"
            />
          </UFormField>

          <template v-if="'plex' === backend.type">
            <UFormField label="Plex Server URL" name="plex_server_url" required>
              <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                <USelect
                  v-model="backend.url"
                  :items="serverItems"
                  value-key="value"
                  placeholder="Select Server URL"
                  class="w-full"
                  :icon="serversLoading ? 'i-lucide-loader-circle' : 'i-lucide-link'"
                  :disabled="stage > 1"
                  @update:model-value="
                    () => {
                      stage = 1;
                      updateIdentifier();
                    }
                  "
                />

                <UTooltip text="Reload Plex server list">
                  <UButton
                    type="button"
                    color="neutral"
                    variant="outline"
                    :loading="serversLoading"
                    :disabled="serversLoading || stage > 2"
                    icon="i-lucide-refresh-cw"
                    class="whitespace-nowrap"
                    @click="() => void getServers()"
                  >
                    Reload
                  </UButton>
                </UTooltip>
              </div>

              <p class="mt-2 text-sm text-toned">
                Try to use non <strong>.plex.direct</strong> urls if possible, as they often have
                problems working in docker. If your custom domain is missing, add it in Plex server
                settings under custom server access URLs.
              </p>
            </UFormField>

            <div class="rounded-md border border-default bg-elevated/20 px-3 py-3">
              <div class="flex items-center justify-between gap-3">
                <div class="min-w-0">
                  <div class="text-sm font-medium text-highlighted">Invited guest</div>
                  <p class="mt-1 text-sm text-toned">
                    This stops WatchState from attempting to generate access-tokens for different
                    users.
                  </p>
                </div>

                <USwitch
                  id="backend_ownership"
                  v-model="backend.options.plex_guest_user"
                  color="neutral"
                  :disabled="stage > 2"
                />
              </div>
            </div>
          </template>

          <div
            v-if="backend?.options?.client"
            class="rounded-md border border-default bg-elevated/20 px-3 py-3"
          >
            <div class="flex items-center justify-between gap-3">
              <div class="min-w-0">
                <div class="text-sm font-medium text-highlighted">Validate SSL certificate</div>
                <p class="mt-1 text-sm text-toned">
                  Whether to validate SSL certificate of the backend server. Disable this if you are
                  using self-signed certificates.
                </p>
              </div>

              <USwitch
                id="backend_ssl_verify"
                v-model="backend.options.client.verify_host"
                color="neutral"
                :disabled="stage > 1"
              />
            </div>
          </div>
        </div>
      </UCard>

      <UCard v-if="stage >= 2" class="border border-default/70 shadow-sm" :ui="cardUi">
        <template #header>
          <div class="flex items-center gap-2 text-sm font-semibold text-highlighted">
            <UIcon name="i-lucide-fingerprint" class="size-4 text-toned" />
            <span>Identity</span>
          </div>
        </template>

        <UFormField
          label="Backend Unique ID"
          name="backend_uuid"
          description="This backend identifier is used for webhook matching and filtering."
          required
        >
          <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
            <UInput
              v-model="backend.uuid"
              :icon="uuidLoading ? 'i-lucide-loader-circle' : 'i-lucide-circle-help'"
              :ui="uuidLoading ? { leadingIcon: 'animate-spin' } : undefined"
              class="w-full"
              required
              :disabled="isLimitedToken || stage > 2"
            />

            <UTooltip v-if="!isLimitedToken" text="Reload backend UUID">
              <UButton
                type="button"
                color="neutral"
                variant="outline"
                :loading="uuidLoading"
                :disabled="uuidLoading || stage > 2"
                icon="i-lucide-refresh-cw"
                @click="() => void getUUid()"
              >
                <span class="hidden sm:inline">Reload</span>
              </UButton>
            </UTooltip>
          </div>
        </UFormField>
      </UCard>

      <UCard v-if="stage >= 3" class="border border-default/70 shadow-sm" :ui="cardUi">
        <template #header>
          <div class="flex items-center gap-2 text-sm font-semibold text-highlighted">
            <UIcon name="i-lucide-user-round-cog" class="size-4 text-toned" />
            <span>User Binding</span>
          </div>
        </template>

        <UFormField
          label="User"
          name="backend_user"
          description="Which user we should associate this backend with?"
        >
          <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
            <USelect
              v-model="backend.user"
              :items="userItems"
              value-key="value"
              placeholder="Select User"
              class="w-full"
              :icon="usersLoading ? 'i-lucide-loader-circle' : 'i-lucide-user-round-cog'"
              :disabled="stage > 3"
            />

            <UTooltip text="Reload backend users">
              <UButton
                type="button"
                color="neutral"
                variant="outline"
                :loading="usersLoading"
                :disabled="usersLoading || stage > 3"
                icon="i-lucide-refresh-cw"
                class="whitespace-nowrap"
                @click="() => void getUsers(true, true)"
              >
                Reload
              </UButton>
            </UTooltip>
          </div>
        </UFormField>
      </UCard>

      <UCard v-if="stage >= 4" class="border border-default/70 shadow-sm" :ui="cardUi">
        <template #header>
          <div class="flex items-center gap-2 text-sm font-semibold text-highlighted">
            <UIcon name="i-lucide-repeat-2" class="size-4 text-toned" />
            <span>Sync & One-Time Operations</span>
          </div>
        </template>

        <div class="space-y-4">
          <div
            v-if="backend.import"
            class="flex items-start justify-between gap-4 rounded-md border border-default bg-elevated/20 px-3 py-3"
          >
            <div class="min-w-0">
              <p class="text-sm font-medium text-highlighted">Enable Import</p>
              <p class="mt-1 text-sm">
                Get
                <template v-if="backend.import.enabled">
                  <UTooltip text="Watched status, playlists, progress and metadata">
                    <span class="text-success underline cursor-help">everything</span>
                  </UTooltip>
                </template>
                <template v-else>
                  <UTooltip
                    text="Import only metadata no watched status, playlists or progress will be imported"
                  >
                    <span class="text-error underline cursor-help">metadata</span>
                  </UTooltip>
                </template>
                from this backend.
              </p>
            </div>

            <USwitch
              id="backend_import"
              v-model="backend.import.enabled"
              :color="backend.import.enabled ? 'success' : 'warning'"
            />
          </div>

          <div
            v-if="backend.export"
            class="flex items-start justify-between gap-4 rounded-md border border-default bg-elevated/20 px-3 py-3"
          >
            <div class="min-w-0">
              <p class="text-sm font-medium text-highlighted">Enable Export</p>
              <p class="mt-1 text-sm text-toned">Send state updates to this backend.</p>
            </div>

            <USwitch
              id="backend_export"
              v-model="backend.export.enabled"
              :color="backend.export.enabled ? 'success' : 'neutral'"
            />
          </div>

          <USeparator label="One Time Operations" />

          <div class="rounded-md border border-default bg-elevated/20 px-3 py-3">
            <div class="flex items-center justify-between gap-3">
              <div class="min-w-0">
                <p class="text-sm font-medium text-highlighted">
                  Create backup for this backend data
                </p>
                <p class="mt-1 text-sm text-toned">
                  This will run a one time backup for the backend data. It runs before the forced
                  export if both are enabled.
                </p>
              </div>

              <USwitch id="backup_data" v-model="backup_data" color="neutral" />
            </div>
          </div>

          <div
            v-if="backends.length < 1"
            class="rounded-md border border-default bg-elevated/20 px-3 py-3"
          >
            <div class="flex items-center justify-between gap-3">
              <div class="min-w-0">
                <p class="text-sm font-medium text-highlighted">
                  Force one time import from this backend
                </p>
                <p class="mt-1 text-sm text-toned">
                  Run a one time import from this backend after adding it.
                </p>
              </div>

              <USwitch id="force_import" v-model="force_import" color="neutral" />
            </div>
          </div>

          <div
            v-if="backends.length > 0"
            class="rounded-md border border-default bg-elevated/20 px-3 py-3"
          >
            <div class="flex items-center justify-between gap-3">
              <div class="min-w-0">
                <p class="text-sm font-medium text-highlighted">
                  Force export local data to this backend
                </p>
                <p class="mt-1 text-sm text-toned">
                  THIS WILL SEND CURRENT WATCHSTATE DATA TO THE BACKEND OVERRIDING ANY EXISTING
                  DATA.
                </p>
              </div>

              <USwitch id="force_export" v-model="force_export" color="warning" />
            </div>
          </div>
        </div>
      </UCard>

      <div class="flex flex-col gap-3 sm:flex-row sm:justify-between">
        <UButton
          type="button"
          color="neutral"
          variant="outline"
          icon="i-lucide-x"
          class="justify-center"
          @click="emit('close')"
        >
          Cancel
        </UButton>

        <div class="flex flex-col gap-3 sm:flex-row sm:justify-end">
          <UButton
            v-if="stage >= 1"
            type="button"
            color="neutral"
            variant="outline"
            icon="i-lucide-chevron-left"
            class="justify-center"
            @click="stage = stage - 1"
          >
            Previous Step
          </UButton>

          <UButton
            v-if="stage < maxStages"
            type="button"
            color="primary"
            variant="solid"
            trailing-icon="i-lucide-chevron-right"
            class="justify-center"
            @click="changeStep()"
          >
            Next Step
          </UButton>

          <UButton
            v-else
            type="submit"
            color="primary"
            variant="solid"
            icon="i-lucide-plus"
            class="justify-center"
          >
            Add Backend
          </UButton>
        </div>
      </div>
    </form>
  </div>
</template>

<script setup lang="ts">
import { useStorage } from '@vueuse/core';
import { computed, onMounted, ref, toRaw, watch } from 'vue';
import { useBackendSetup } from '~/composables/useBackendSetup';
import { useDirtyState } from '~/composables/useDirtyState';
import { ag, awaitElement, notification, parse_api_response, request } from '~/utils';
import type {
  Backend,
  BackendAccessTokenResponse,
  BackendOptions,
  GenericResponse,
  JsonObject,
} from '~/types';

const emit = defineEmits<{
  addBackend: [backend: Backend];
  backupData: [backend: Backend];
  forceExport: [backend: Backend];
  forceImport: [backend: Backend];
  close: [];
  'dirty-change': [dirty: boolean];
}>();

const props = defineProps<{ backends: Array<Backend> }>();

const backend = ref<Backend>({
  name: '',
  type: 'plex',
  url: '',
  token: '',
  uuid: '',
  user: '',
  import: {
    enabled: true,
  },
  export: {
    enabled: true,
  },
  options: {
    client: {
      verify_host: true,
    },
  } as BackendOptions,
});

const api_user = useStorage<string>('api_user', 'main');

const maxStages = 5;
const stage = ref<number>(0);
const error = ref<string | null>(null);
const backup_data = ref<boolean>(true);
const force_export = ref<boolean>(false);
const force_import = ref<boolean>(false);
const pathGuidEnvMissing = ref<boolean>(false);

type NotificationType = 'info' | 'success' | 'warning' | 'error';

const accessTokenResponse = ref<BackendAccessTokenResponse | null>(null);

const cardUi = {
  root: 'border border-default/70 shadow-sm',
  header: 'px-5 py-4 sm:px-6',
  body: 'px-5 pb-5 sm:px-6 sm:pb-6',
  footer: 'px-5 py-4 sm:px-6',
};

const onSharedError = (message: string, err: unknown = undefined): void => {
  error.value = message;

  if (undefined !== err) {
    console.error(err);
  }
};

const {
  backendTypeItems,
  exposeToken,
  generatePlexAuthRequest: sharedGeneratePlexAuthRequest,
  getServers: sharedGetServers,
  getUUid: sharedGetUUid,
  getUsers: sharedGetUsers,
  isLimitedToken,
  loadSupported,
  plexGetToken: sharedPlexGetToken,
  plexOauth,
  plexOauthLoading: plex_oauth_loading,
  plexOauthUrl: plex_oauth_url,
  serverItems,
  servers,
  serversLoading,
  supported,
  userItems,
  users,
  usersLoading,
  uuidLoading,
} = useBackendSetup(backend, { onError: onSharedError });

const hasPlexOauth = computed<boolean>(
  () => null !== plexOauth.value && 0 < Object.keys(plexOauth.value).length,
);

const dirtySource = computed(() => ({
  backend: backend.value,
  backup_data: backup_data.value,
  force_export: force_export.value,
  force_import: force_import.value,
  stage: stage.value,
}));

const { isDirty, markClean } = useDirtyState(dirtySource);

const clearError = (): void => {
  error.value = null;
};

const generate_plex_auth_request = async (): Promise<void> => {
  clearError();
  await sharedGeneratePlexAuthRequest();
};

const plex_get_token = async (notify: boolean = true): Promise<void> => {
  clearError();
  await sharedPlexGetToken(notify);
};

const getUUid = async (): Promise<string | undefined> => {
  const required_values = ['type', 'token', 'url'];

  if (true === isLimitedToken.value || accessTokenResponse.value) {
    return backend.value.uuid || undefined;
  }

  if (required_values.some((v) => !backend.value[v as keyof Backend])) {
    notification(
      'error',
      'Error',
      `Please fill all the required fields. ${required_values.join(', ')}.`,
    );
    return;
  }

  clearError();
  await sharedGetUUid();
  return backend.value.uuid || undefined;
};

const getAccessToken = async (): Promise<boolean | undefined> => {
  const required_values = ['type', 'token', 'url'];

  if (required_values.some((v) => !backend.value[v as keyof Backend])) {
    notification(
      'error',
      'Error',
      `Please fill all the required fields. ${required_values.join(', ')}.`,
    );
    return;
  }

  if (accessTokenResponse.value) {
    return;
  }

  const [username, password] = explode(':', backend.value.token, 2);

  if (!username || !password) {
    return;
  }

  try {
    clearError();

    const data: JsonObject = {
      name: backend.value?.name,
      url: backend.value.url,
      username: username,
      password: password,
    };

    const backendRaw = toRaw(backend.value) as unknown as JsonObject;
    const verifyHost = ag<boolean>(backendRaw, 'options.client.verify_host', true);
    if (false === verifyHost) {
      data.options = { client: { verify_host: false } };
    }

    const response = await request(`/backends/accesstoken/${backend.value.type}`, {
      method: 'POST',
      body: JSON.stringify(data),
    });

    const json = await parse_api_response<BackendAccessTokenResponse>(response);

    if ('error' in json) {
      n_proxy('error', 'Error', `${json.error.code}: ${json.error.message}`);
      return;
    }

    accessTokenResponse.value = json;
    if (json.accesstoken) {
      backend.value.token = json.accesstoken;
    }
    if (json.user) {
      backend.value.user = json.user;
    }
    if (json.identifier) {
      backend.value.uuid = json.identifier;
    }
    users.value = [
      {
        id: json.user ?? '',
        name: username,
      },
    ];

    backend.value.options.is_limited_token = true;
    return true;
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Request error.';
    n_proxy('error', 'Error', `Request error. ${message}`, error);
    return false;
  }
};

const getUsers = async (
  showAlert: boolean = true,
  forceReload: boolean = false,
  withTokens: boolean = false,
  targetUser: string | null = null,
): Promise<Array<(typeof users.value)[number]> | undefined> => {
  const required_values = ['type', 'token', 'url', 'uuid'];

  if (required_values.some((v) => !backend.value[v as keyof Backend])) {
    if (showAlert) {
      notification(
        'error',
        'Error',
        `Please fill all the required fields. ${required_values.join(', ')}.`,
      );
    }
    return;
  }

  clearError();
  return await sharedGetUsers(showAlert, forceReload, withTokens, targetUser);
};

const getServers = async (): Promise<void> => {
  clearError();
  await sharedGetServers();
};

const updateIdentifier = (): void => {
  const server = servers.value.find((value) => value.uri === backend.value.url);
  if (!server) {
    return;
  }

  backend.value.uuid = server.identifier;
};

onMounted(async () => {
  clearError();
  await loadSupported();

  try {
    const response = await request('/system/env/WS_GUID_PATH_ENABLED');
    pathGuidEnvMissing.value = 200 !== response.status;
  } catch (error) {
    console.error(error);
    pathGuidEnvMissing.value = false;
  }

  if (supported.value.length > 0 && supported.value[0]) {
    backend.value.type = supported.value[0];
  }
  markClean();
  emit('dirty-change', false);
});

watch(isDirty, (value: boolean) => emit('dirty-change', value));

watch(stage, (v) => {
  if (v < 3) {
    users.value = [];
    backend.value.user = '';
  }
  if (v < 1) {
    servers.value = [];
    backend.value.uuid = '';
    backend.value.url = '';
  }
});

const changeStep = async (): Promise<void> => {
  if (stage.value <= 0) {
    // -- basic validation.
    const required = ['name', 'type', 'token'];
    if (required.some((v) => !backend.value[v as keyof Backend])) {
      required.forEach((v) => {
        if (!backend.value[v as keyof Backend]) {
          notification('error', 'Error', `Please fill the required field: ${v}.`);
        }
      });
      return;
    }

    if (false === /^[a-z_0-9]+$/.test(backend.value.name)) {
      notification('error', 'Error', 'Backend name must be in lower case a-z, 0-9 and _ only.');
      return;
    }

    if (props.backends.find((b) => b.name === backend.value.name)) {
      notification('error', 'Error', `Backend with name '${backend.value.name}' already exists.`);
      return;
    }

    stage.value = 1;
  }

  if (stage.value <= 1) {
    if ('plex' === backend.value.type && servers.value.length < 1) {
      await getServers();
      if (servers.value.length < 1) {
        stage.value = 0;
        return;
      }
    }

    if (!backend.value.url) {
      return;
    }

    if (false === isLimitedToken.value && backend.value.token.includes(':')) {
      await getAccessToken();
      if (!accessTokenResponse.value) {
        stage.value = 0;
        return;
      }
    }

    if (backend.value.token.includes(':')) {
      return;
    }

    stage.value = 2;
  }

  if (stage.value <= 2) {
    if (!backend.value.uuid) {
      await getUUid();
      if (!backend.value.uuid) {
        stage.value = 1;
        return;
      }
    }

    stage.value = 3;
  }

  if (stage.value <= 3) {
    if (false === isLimitedToken.value && users.value.length < 1) {
      await getUsers();
      if (users.value.length < 1) {
        stage.value = 1;
        return;
      }
    }

    if (!backend.value.user) {
      return;
    }

    if ('plex' === backend.value.type) {
      const selected = users.value.find((u) => u.id === backend.value.user);
      if (!selected) {
        notification('error', 'Error', 'Selected user not found.');
        return;
      }

      const usersResponse = await getUsers(true, true, true, selected.uuid ?? selected.id);
      const updated = usersResponse?.find((u) => u.id === selected.id);
      const token = updated?.token ?? selected.token;

      if (!token) {
        notification('error', 'Error', 'Selected user does not have a valid token.');
        return;
      }

      if (!backend.value.options?.ADMIN_TOKEN) {
        backend.value.options.ADMIN_TOKEN = backend.value.token;
      }
      backend.value.token = token;
      backend.value.options.plex_user_name = updated?.name ?? selected.name;
      backend.value.options.plex_user_uuid = updated?.uuid ?? selected.uuid ?? '';
    }

    stage.value = 4;
  }

  if (stage.value <= 4) {
    stage.value = 5;
  }
};

const addBackend = async (): Promise<boolean> => {
  const required_values = ['name', 'type', 'token', 'url', 'uuid', 'user'];

  if (required_values.some((v) => !backend.value[v as keyof Backend])) {
    required_values.forEach((v) => {
      if (!backend.value[v as keyof Backend]) {
        notification('error', 'Error', `Please fill the required field: ${v}.`);
      }
    });
    return false;
  }

  if ('plex' === backend.value.type) {
    const selectedUser = users.value.find((u) => u.id === backend.value.user);
    const token = selectedUser?.token;
    if (token && token !== backend.value.token) {
      if (!backend.value.options?.ADMIN_TOKEN) {
        backend.value.options.ADMIN_TOKEN = backend.value.token;
      }
      backend.value.token = token;
    }
    if (selectedUser) {
      backend.value.options.plex_user_name = selectedUser.name;
      backend.value.options.plex_user_uuid = selectedUser.uuid ?? '';
    }
  }

  if (isLimitedToken.value) {
    backend.value.options.is_limited_token = true;
  }

  const response = await request('/backends/', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
    },
    body: JSON.stringify(backend.value),
  });

  const json = await parse_api_response<GenericResponse>(response);
  if ('error' in json) {
    notification(
      'error',
      'Error',
      `Failed to Add backend. (${json.error.code}: ${json.error.message}).`,
    );
    return false;
  }

  notification('success', 'Information', `Backend ${backend.value.name} added successfully.`);

  if (true === Boolean(backup_data?.value ?? false)) {
    emit('backupData', backend.value);
  }

  if (true === Boolean(force_export?.value ?? false)) {
    emit('forceExport', backend.value);
  }

  if (true === Boolean(force_import?.value ?? false)) {
    emit('forceImport', backend.value);
  }

  emit('addBackend', backend.value);
  emit('dirty-change', false);

  return true;
};

const n_proxy = (
  type: NotificationType,
  title: string,
  message: string,
  err: unknown = null,
): void => {
  if ('error' === type) {
    error.value = message;
  }

  if (err) {
    console.error(err);
  }

  notification(type, title, message);
};

const explode = (
  delimiter: string,
  string: string,
  limit: number | undefined = undefined,
): string[] => {
  if ('' === delimiter) {
    return [string];
  }

  const parts = string.split(delimiter);

  if (undefined === limit || 0 === limit) {
    return parts;
  }

  if (limit > 0) {
    return parts.slice(0, limit - 1).concat(parts.slice(limit - 1).join(delimiter));
  }

  if (limit < 0) {
    return parts.slice(0, limit);
  }

  return parts;
};

watch(error, (v) =>
  v ? awaitElement('#backend_error', (_, e) => e.scrollIntoView({ behavior: 'smooth' })) : null,
);
</script>
