<template>
  <div class="space-y-6">
    <UAlert
      v-if="isLimitedToken"
      color="warning"
      variant="soft"
      icon="i-lucide-info"
      title="Limited token mode"
    >
      <template #description>
        <div class="space-y-2 text-sm text-default">
          <p>
            This backend currently relies on an access token instead of a full API key. Core sync
            operations should work, but identity and user-management actions may be restricted.
          </p>
          <p>
            Please use caution when editing this backend, and prefer a full API key when possible.
          </p>
        </div>
      </template>
    </UAlert>

    <UAlert
      v-if="showIdentitySyncWarning"
      color="warning"
      variant="soft"
      icon="i-lucide-refresh-cw"
      title="Sync identities after saving"
    >
      <template #description>
        <div class="space-y-2 text-sm text-default">
          <p>You are editing a main identity backend while additional identities exist.</p>
          <p>
            After saving shared backend changes like URL, API key, UUID, or import/export settings,
            go to <NuxtLink to="/identities" class="text-primary">Identities</NuxtLink> and use
            <strong>Sync Backends</strong> to propagate those changes safely.
          </p>
        </div>
      </template>
    </UAlert>

    <UAlert
      v-if="isLoading"
      color="info"
      variant="soft"
      icon="i-lucide-loader-circle"
      title="Loading"
      description="Loading backend settings. Please wait..."
      :ui="{ icon: 'animate-spin' }"
    />

    <form v-else id="backend_edit_form" class="space-y-6" @submit.prevent="saveContent">
      <div class="grid gap-6 xl:grid-cols-[minmax(0,1.15fr)_minmax(0,0.85fr)]">
        <div class="space-y-6">
          <UCard class="border border-default/70 shadow-sm" :ui="summaryCardUi">
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
              <div class="rounded-md border border-default bg-elevated/20 px-3 py-3">
                <div class="mb-1 inline-flex items-center gap-2 text-xs font-medium text-toned">
                  <UIcon name="i-lucide-user" class="size-4" />
                  <span>Local User</span>
                </div>
                <p class="font-medium text-highlighted">{{ api_user }}</p>
              </div>

              <div class="rounded-md border border-default bg-elevated/20 px-3 py-3">
                <div class="mb-1 inline-flex items-center gap-2 text-xs font-medium text-toned">
                  <UIcon name="i-lucide-id-card" class="size-4" />
                  <span>Backend Name</span>
                </div>
                <p class="font-medium text-highlighted">{{ backend.name }}</p>
              </div>

              <div class="rounded-md border border-default bg-elevated/20 px-3 py-3">
                <div class="mb-1 inline-flex items-center gap-2 text-xs font-medium text-toned">
                  <UIcon name="i-lucide-server" class="size-4" />
                  <span>Type</span>
                </div>
                <p class="font-medium text-highlighted">{{ ucFirst(backend.type) }}</p>
              </div>
            </div>
          </UCard>

          <UCard class="border border-default/70 shadow-sm" :ui="sectionCardUi">
            <template #header>
              <div class="flex items-center gap-2 text-sm font-semibold text-highlighted">
                <UIcon name="i-lucide-link" class="size-4 text-toned" />
                <span>Connection & Authentication</span>
              </div>
            </template>

            <div class="space-y-5">
              <UFormField label="URL" name="backend_url" required>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                  <USelect
                    v-if="servers.length > 0"
                    v-model="backend.url"
                    :items="serverItems"
                    value-key="value"
                    placeholder="Select server"
                    icon="i-lucide-link"
                    class="w-full"
                    @update:model-value="() => void updateIdentifier()"
                  />

                  <UInput
                    v-else
                    v-model="backend.url"
                    icon="i-lucide-link"
                    class="w-full"
                    required
                  />

                  <UTooltip v-if="isPlex && !isLimitedToken" text="Reload server discovery">
                    <UButton
                      type="button"
                      color="neutral"
                      variant="outline"
                      size="sm"
                      :loading="serversLoading"
                      :disabled="serversLoading"
                      icon="i-lucide-refresh-cw"
                      class="whitespace-nowrap"
                      @click="() => void getServers()"
                    >
                      Reload
                    </UButton>
                  </UTooltip>
                </div>

                <p class="mt-2 text-sm text-toned">
                  <template v-if="servers.length < 1">
                    Enter the backend URL. For example
                    <strong
                      >http://192.168.8.100:{{ 'plex' === backend.type ? '32400' : '8096' }}</strong
                    >.
                  </template>
                  <template v-else>Select the discovered server you want to use.</template>
                </p>
              </UFormField>

              <UFormField
                :label="'plex' !== backend.type ? 'API Key' : 'X-Plex-Token'"
                name="backend_token"
                required
              >
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                  <UInput
                    v-model="backend.token"
                    icon="i-lucide-key-round"
                    class="w-full"
                    required
                    :type="false === exposeToken ? 'password' : 'text'"
                  />

                  <UTooltip text="Toggle token visibility">
                    <UButton
                      type="button"
                      color="neutral"
                      variant="outline"
                      size="sm"
                      :icon="exposeToken ? 'i-lucide-eye-off' : 'i-lucide-eye'"
                      :aria-label="exposeToken ? 'Hide token' : 'Show token'"
                      class="whitespace-nowrap"
                      @click="exposeToken = !exposeToken"
                    >
                      {{ exposeToken ? 'Hide' : 'Show' }}
                    </UButton>
                  </UTooltip>
                </div>

                <div class="mt-2 space-y-2 text-sm text-toned">
                  <template v-if="isPlex">
                    <p>
                      Enter the <strong>X-Plex-Token</strong>.
                      <NuxtLink
                        target="_blank"
                        to="https://support.plex.tv/articles/204059436-finding-an-authentication-token-x-plex-token/"
                        class="text-primary"
                      >
                        Visit this article
                      </NuxtLink>
                      for more information.
                    </p>
                  </template>

                  <template v-else>
                    <p>
                      You can generate a new API key from
                      <strong>Dashboard &gt; Settings &gt; API Keys</strong>.
                    </p>
                  </template>
                </div>
              </UFormField>

              <UFormField
                v-if="isPlex"
                label="User PIN"
                name="plex_user_pin"
                description="If the selected Plex user has a PIN enabled, enter it here or token generation can fail."
              >
                <UInput
                  v-model="backend.options.PLEX_USER_PIN"
                  icon="i-lucide-key-round"
                  class="w-full"
                />
              </UFormField>

              <div
                v-if="backend?.options?.client"
                class="rounded-md border border-default bg-elevated/20 px-3 py-3"
              >
                <div class="flex items-center justify-between gap-3">
                  <div class="min-w-0">
                    <div class="text-sm font-medium text-highlighted">Validate SSL certificate</div>
                    <p class="mt-1 text-sm text-toned">
                      Disable this only if the backend is using a self-signed certificate.
                    </p>
                  </div>

                  <USwitch
                    id="backend_ssl_verify"
                    v-model="backend.options.client.verify_host"
                    color="neutral"
                  />
                </div>
              </div>

              <div v-if="isPlex" class="rounded-md border border-default bg-elevated/20 p-4">
                <div class="space-y-3">
                  <div>
                    <p class="text-sm font-semibold text-highlighted">Plex Authentication</p>
                    <p class="mt-1 text-sm text-toned">
                      Re-authenticate with plex.tv if the stored token is no longer valid or you
                      need to refresh admin access.
                    </p>
                  </div>

                  <UButton
                    v-if="!hasPlexOauth"
                    type="button"
                    color="neutral"
                    variant="outline"
                    size="sm"
                    :loading="plexOauthLoading"
                    :disabled="plexOauthLoading"
                    icon="i-lucide-external-link"
                    @click="generatePlexAuthRequest"
                  >
                    {{ plexOauthLoading ? 'Generating link' : 'Re-authenticate with plex.tv' }}
                  </UButton>

                  <div v-if="plexOauthUrl" class="flex flex-wrap gap-3">
                    <UButton
                      type="button"
                      color="neutral"
                      variant="outline"
                      size="sm"
                      :loading="plexOauthLoading"
                      :disabled="plexOauthLoading"
                      icon="i-lucide-badge-check"
                      @click="() => void plexGetToken()"
                    >
                      Check auth request
                    </UButton>

                    <UButton
                      as="a"
                      :href="plexOauthUrl"
                      target="_blank"
                      color="neutral"
                      variant="outline"
                      size="sm"
                      icon="i-lucide-external-link"
                    >
                      Open Plex Auth Link
                    </UButton>
                  </div>
                </div>
              </div>
            </div>
          </UCard>

          <UCard class="border border-default/70 shadow-sm" :ui="sectionCardUi">
            <template #header>
              <div class="flex items-center gap-2 text-sm font-semibold text-highlighted">
                <UIcon name="i-lucide-fingerprint" class="size-4 text-toned" />
                <span>Backend Identity</span>
              </div>
            </template>

            <div class="space-y-4">
              <UFormField label="Backend Unique ID" name="backend_uuid" required>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                  <UInput
                    v-model="backend.uuid"
                    :icon="uuidLoading ? 'i-lucide-loader-circle' : 'i-lucide-circle-help'"
                    :ui="uuidLoading ? { leadingIcon: 'animate-spin' } : undefined"
                    class="w-full"
                    required
                    :disabled="isLimitedToken"
                  />

                  <UTooltip v-if="!isLimitedToken" text="Reload backend UUID">
                    <UButton
                      type="button"
                      color="neutral"
                      variant="outline"
                      size="sm"
                      :loading="uuidLoading"
                      :disabled="uuidLoading"
                      icon="i-lucide-refresh-cw"
                      class="whitespace-nowrap"
                      @click="() => void getUUid()"
                    >
                      Reload
                    </UButton>
                  </UTooltip>
                </div>
              </UFormField>

              <p class="text-sm text-toned">
                <span v-if="isPlex">
                  Plex uses this identifier to scope server ownership and webhook matching. If you
                  are a member of multiple Plex servers, this value is what distinguishes them.
                </span>
                <span v-else>
                  This random identifier uniquely represents the backend and is used for webhook
                  matching and filtering.
                </span>
              </p>
            </div>
          </UCard>

          <UCard class="border border-default/70 shadow-sm" :ui="sectionCardUi">
            <template #header>
              <div class="flex items-center gap-2 text-sm font-semibold text-highlighted">
                <UIcon name="i-lucide-user-round-cog" class="size-4 text-toned" />
                <span>User Binding</span>
              </div>
            </template>

            <div class="space-y-5">
              <UFormField
                :label="users.length > 0 ? 'User' : 'User ID'"
                name="backend_user"
                :description="
                  isPlex
                    ? 'Choose the Plex user this backend should operate as.'
                    : 'Choose which backend user this configuration should use.'
                "
              >
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                  <USelect
                    v-if="users.length > 0"
                    v-model="backend.user"
                    :items="userItems"
                    value-key="value"
                    placeholder="Select user"
                    :icon="usersLoading ? 'i-lucide-loader-circle' : 'i-lucide-user-round-cog'"
                    :ui="usersLoading ? { leadingIcon: 'animate-spin' } : undefined"
                    class="min-w-0 flex-1"
                    :disabled="isLimitedToken"
                  />

                  <UInput
                    v-else
                    v-model="backend.user"
                    :icon="usersLoading ? 'i-lucide-loader-circle' : 'i-lucide-user-round-cog'"
                    :ui="usersLoading ? { leadingIcon: 'animate-spin' } : undefined"
                    class="min-w-0 flex-1"
                  />

                  <div class="flex flex-wrap gap-3 sm:shrink-0">
                    <UTooltip v-if="!isLimitedToken" text="Reload backend users">
                      <UButton
                        type="button"
                        color="neutral"
                        variant="outline"
                        size="sm"
                        class="whitespace-nowrap"
                        :loading="usersLoading"
                        :disabled="usersLoading"
                        icon="i-lucide-refresh-cw"
                        @click="() => void getUsers(false, true)"
                      >
                        Reload
                      </UButton>
                    </UTooltip>

                    <UTooltip
                      v-if="isPlex && !isLimitedToken"
                      text="Generate a token for the currently selected Plex user"
                    >
                      <UButton
                        type="button"
                        color="neutral"
                        variant="outline"
                        size="sm"
                        class="whitespace-nowrap"
                        icon="i-lucide-key-round"
                        :disabled="usersLoading || !backend.user"
                        @click="() => void generateUserToken()"
                      >
                        Generate Token
                      </UButton>
                    </UTooltip>
                  </div>
                </div>
              </UFormField>

              <UAlert
                v-if="isPlex"
                color="info"
                variant="soft"
                icon="i-lucide-info"
                title="Token replacement behavior"
                description="Selecting or refreshing a Plex user can replace the current token with a user-scoped token. WatchState preserves the previous admin token in advanced options when needed."
              />
            </div>
          </UCard>

          <UCard class="border border-default/70 shadow-sm" :ui="sectionCardUi">
            <template #header>
              <div class="flex items-center gap-2 text-sm font-semibold text-highlighted">
                <UIcon name="i-lucide-repeat-2" class="size-4 text-toned" />
                <span>Sync Behavior</span>
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
            </div>
          </UCard>
        </div>

        <div class="space-y-6">
          <UCard class="border border-default/70 shadow-sm" :ui="sectionCardUi">
            <template #header>
              <button
                type="button"
                class="flex w-full items-center justify-between gap-3 text-left"
                @click="showOptions = !showOptions"
              >
                <span class="inline-flex items-center gap-2 text-sm font-semibold text-highlighted">
                  <UIcon name="i-lucide-sliders-horizontal" class="size-4 text-toned" />
                  <span>Advanced Options</span>
                </span>

                <span class="inline-flex items-center gap-2 text-xs font-medium text-toned">
                  <UBadge color="neutral" variant="soft" size="sm">
                    {{ advancedOptionCount }} active
                  </UBadge>
                  <UIcon
                    :name="showOptions ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
                    class="size-4"
                  />
                </span>
              </button>
            </template>

            <div class="space-y-4">
              <p class="text-sm text-toned">
                These are low-level options. Only change them if you know the backend requires it or
                you were asked to by the developers.
              </p>

              <div v-if="showOptions" class="space-y-4">
                <div
                  v-for="optionPath in flatOptionPaths"
                  :key="`bo-${optionPath}`"
                  class="rounded-md border border-default bg-elevated/20 p-4"
                >
                  <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:gap-4">
                    <UFormField
                      :label="optionPath"
                      :name="`option-${optionPath}`"
                      class="min-w-0 flex-1"
                    >
                      <template #description v-if="optionDescribe(optionPath)">
                        <div class="space-y-1">
                          <p class="text-sm text-toned">
                            {{ optionDescribe(optionPath) }}
                          </p>
                        </div>
                      </template>

                      <UInput
                        :model-value="String(optionGet(optionPath) ?? '')"
                        class="w-full"
                        required
                        @update:model-value="
                          (value: string | number) => optionSet(optionPath, String(value))
                        "
                      />
                      <template #hint>
                        <UButton
                          type="button"
                          color="neutral"
                          variant="outline"
                          size="sm"
                          icon="i-lucide-trash-2"
                          class="whitespace-nowrap"
                          @click.prevent="() => void removeOption(optionPath)"
                        >
                          Remove
                        </UButton>
                      </template>
                    </UFormField>
                  </div>
                </div>

                <div class="space-y-4 rounded-md border border-dashed border-default p-4">
                  <UFormField label="Add new option" name="selected_option">
                    <USelect
                      v-model="selectedOption"
                      :items="availableOptionItems"
                      value-key="value"
                      placeholder="Select option"
                      class="w-full"
                    />
                  </UFormField>

                  <UFormField
                    label="Description"
                    name="selected_option_help"
                    v-if="selectedOptionHelp"
                  >
                    {{ selectedOptionHelp }}
                  </UFormField>

                  <div class="flex justify-end">
                    <UButton
                      type="button"
                      color="neutral"
                      variant="outline"
                      size="sm"
                      icon="i-lucide-plus"
                      class="justify-center"
                      :disabled="!selectedOption"
                      @click.prevent="() => void addOption()"
                    >
                      Add
                    </UButton>
                  </div>
                </div>

                <UAlert
                  v-if="0 === advancedOptionCount && 0 === availableOptionCount"
                  color="info"
                  variant="soft"
                  icon="i-lucide-info"
                  title="No advanced options available"
                  description="This backend currently has no active advanced options and no additional spec options to add."
                />
              </div>
            </div>
          </UCard>
        </div>
      </div>

      <div class="flex flex-col gap-3 sm:flex-row sm:justify-end">
        <UButton
          type="button"
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-x"
          class="justify-center"
          @click="() => void handleClose()"
        >
          Cancel
        </UButton>

        <UButton
          type="submit"
          color="primary"
          variant="solid"
          size="sm"
          icon="i-lucide-save"
          class="justify-center"
          :loading="isSaving"
          :disabled="isSaving"
        >
          Save Settings
        </UButton>
      </div>
    </form>
  </div>
</template>

<script setup lang="ts">
import { useStorage } from '@vueuse/core';
import { computed, nextTick, ref, toRaw, watch } from 'vue';
import { useBackendSetup } from '~/composables/useBackendSetup';
import { useDialog } from '~/composables/useDialog';
import { useDirtyState } from '~/composables/useDirtyState';
import { notification, parse_api_response, request, ucFirst } from '~/utils';
import type {
  Backend,
  BackendSpecOption,
  GenericResponse,
  IdentityListItem,
  JsonObject,
  JsonValue,
} from '~/types';

type BackendOptionMap = Record<string, JsonValue>;

type SelectItem = {
  label: string;
  value: string;
};

const props = withDefaults(
  defineProps<{
    backendName: string;
  }>(),
  {},
);

const emit = defineEmits<{
  close: [];
  saved: [backend: Backend];
  'dirty-change': [dirty: boolean];
}>();

const createEmptyBackend = (): Backend => ({
  name: '',
  type: '',
  url: '',
  token: '',
  uuid: '',
  user: '',
  import: { enabled: false },
  export: { enabled: false },
  options: {},
});

const backend = ref<Backend>(createEmptyBackend());

const api_user = useStorage('api_user', 'main');
const isLoading = ref<boolean>(true);
const isSaving = ref<boolean>(false);
const hasAdditionalIdentities = ref<boolean>(false);
const showOptions = ref<boolean>(false);
const optionsList = ref<Array<BackendSpecOption>>([]);
const selectedOption = ref<string>('');
const newOptions = ref<Record<string, boolean>>({});
const optionsVersion = ref<number>(0);

const {
  exposeToken,
  generatePlexAuthRequest,
  generateUserToken,
  getServers,
  getUUid,
  getUsers,
  isLimitedToken,
  plexGetToken,
  plexOauth,
  plexOauthLoading,
  plexOauthUrl,
  serverItems,
  servers,
  serversLoading,
  updateIdentifier,
  userItems,
  users,
  usersLoading,
  uuidLoading,
} = useBackendSetup(backend);

const id = computed<string>(() => props.backendName);
const isPlex = computed<boolean>(() => 'plex' === backend.value.type);
const hasPlexOauth = computed<boolean>(() => null !== plexOauth.value);
const showIdentitySyncWarning = computed<boolean>(
  () => 'main' === api_user.value && true === hasAdditionalIdentities.value,
);
const dirtySource = computed(() => ({
  backend: backend.value,
  selectedOption: selectedOption.value,
  showOptions: showOptions.value,
}));
const { isDirty, markClean } = useDirtyState(dirtySource);

const sectionCardUi = {
  header: 'p-4 sm:p-5',
  body: 'px-4 pb-4 pt-0 sm:px-5 sm:pb-5',
};

const summaryCardUi = {
  body: 'p-4 sm:p-5',
};

const optionLabel = (path: string): string => {
  return path
    .split('.')
    .map((segment) => segment.toLowerCase().replace(/_/g, ' '))
    .join(' ')
    .replace(/\b\w/g, (char) => char.toUpperCase());
};

const filteredOptions = (options: Array<BackendSpecOption> | null): Array<BackendSpecOption> => {
  if (!options) {
    return [];
  }

  const optionsRecord = backend.value.options as BackendOptionMap;
  return options.filter(
    (option) => optionsRecord[option.key] === undefined && !newOptions.value[option.key],
  );
};

const availableOptionItems = computed<Array<SelectItem>>(() =>
  filteredOptions(optionsList.value).map((option) => ({
    label: optionLabel(option.key),
    value: option.key,
  })),
);

const selectedOptionHelp = computed((): string => {
  const option = optionsList.value.find((value) => selectedOption.value === value.key);
  return option ? option.description : '';
});

const flattenOptions = (obj: JsonObject, prefix = ''): Array<string> => {
  const output: Array<string> = [];

  for (const [key, value] of Object.entries(obj)) {
    const path = prefix ? `${prefix}.${key}` : key;

    if (Array.isArray(value)) {
      if (0 === value.length) {
        continue;
      }

      output.push(path);
      continue;
    }

    if (null !== value && 'object' === typeof value) {
      if (0 === Object.keys(value).length) {
        continue;
      }

      output.push(...flattenOptions(value as JsonObject, path));
      continue;
    }

    output.push(path);
  }

  return output;
};

const flatOptionPaths = computed<Array<string>>(() => {
  if (0 <= optionsVersion.value) {
    return flattenOptions(backend.value.options as unknown as JsonObject);
  }

  return [];
});

const advancedOptionCount = computed<number>(() => flatOptionPaths.value.length);
const availableOptionCount = computed<number>(() => availableOptionItems.value.length);

const optionGet = (path: string): JsonValue | undefined => {
  return path.split('.').reduce(
    (obj: JsonValue | JsonObject | undefined, key: string) => {
      if (undefined === obj || null === obj) {
        return undefined;
      }

      if ('object' !== typeof obj || Array.isArray(obj)) {
        return undefined;
      }

      return (obj as JsonObject)[key];
    },
    backend.value.options as unknown as JsonObject,
  );
};

const optionSet = (path: string, value: JsonValue): void => {
  const keys = path.split('.');
  const last = keys.pop();
  let target: JsonObject = backend.value.options as unknown as JsonObject;

  for (const key of keys) {
    if (null == target[key] || 'object' !== typeof target[key] || Array.isArray(target[key])) {
      target[key] = {};
    }

    target = target[key] as JsonObject;
  }

  if (last) {
    target[last] = value;
  }

  optionsVersion.value++;
};

const optionUnset = (path: string): void => {
  const unset = (obj: JsonObject, keys: Array<string>): JsonObject => {
    const [current, ...rest] = keys;

    if (!current || false === Object.prototype.hasOwnProperty.call(obj, current)) {
      return obj;
    }

    if (0 === rest.length) {
      const { [current]: _removed, ...remaining } = obj;
      return remaining as JsonObject;
    }

    const next = obj[current];
    if (null == next || 'object' !== typeof next || Array.isArray(next)) {
      return obj;
    }

    const updatedChild = unset(next as JsonObject, rest);
    if (updatedChild === next) {
      return obj;
    }

    if (0 === Object.keys(updatedChild).length) {
      const { [current]: _removed, ...remaining } = obj;
      return remaining as JsonObject;
    }

    return {
      ...obj,
      [current]: updatedChild,
    };
  };

  backend.value.options = unset(backend.value.options as JsonObject, path.split('.'));
  optionsVersion.value++;
};

const optionDescribe = (path: string): string => {
  const option = optionsList.value.find((value) => path === value.key);
  return option ? option.description : '';
};

const resetEditorState = (): void => {
  backend.value = createEmptyBackend();
  exposeToken.value = false;
  servers.value = [];
  users.value = [];
  plexOauth.value = null;
  showOptions.value = false;
  optionsList.value = [];
  selectedOption.value = '';
  newOptions.value = {};
  optionsVersion.value = 0;
  hasAdditionalIdentities.value = false;
};

const loadIdentityWarningState = async (): Promise<void> => {
  if ('main' !== api_user.value) {
    hasAdditionalIdentities.value = false;
    return;
  }

  const response = await request('/identities');
  const json = await parse_api_response<{ identities: Array<IdentityListItem> }>(response);

  if ('error' in json) {
    hasAdditionalIdentities.value = false;
    return;
  }

  hasAdditionalIdentities.value = json.identities.some((identity) => 'main' !== identity.identity);
};

const loadContent = async (): Promise<void> => {
  if (!id.value) {
    return;
  }

  resetEditorState();
  isLoading.value = true;

  try {
    const response = await request(`/backend/${id.value}`);
    const json = await parse_api_response<Backend>(response);

    if ('error' in json) {
      notification('error', 'Error', `Failed to load backend: ${json.error.message}`);
      return;
    }

    if (!json?.options || 'object' !== typeof json.options) {
      json.options = {};
    }

    backend.value = json;
    await loadIdentityWarningState();

    if (isPlex.value) {
      await getServers();
    }

    await getUsers(false);
    await nextTick();
    markClean();
    emit('dirty-change', false);
  } catch (error) {
    const errorMessage = error instanceof Error ? error.message : String(error);
    notification('error', 'Error', `Failed to load backend. ${errorMessage}`);
  } finally {
    isLoading.value = false;
  }
};

const handleClose = async (): Promise<void> => {
  emit('close');
};

const saveContent = async (): Promise<void> => {
  const payload = toRaw(backend.value) as Backend & { options: JsonObject };

  const flat: Record<string, JsonValue> = {};
  for (const path of flatOptionPaths.value) {
    flat[path] = optionGet(path) ?? null;
  }

  if (0 < Object.keys(flat).length) {
    payload.options = flat;
  }

  isSaving.value = true;

  try {
    const response = await request(`/backend/${id.value}`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });

    const json = await parse_api_response<GenericResponse>(response);
    if (200 !== response.status) {
      if ('error' in json) {
        notification(
          'error',
          'Error',
          `Failed to save backend settings. (${json.error.code}: ${json.error.message}).`,
        );
      } else {
        notification('error', 'Error', 'Failed to save backend settings.');
      }
      return;
    }

    notification('success', 'Success', `Successfully updated '${id.value}' settings.`);
    markClean();
    emit('dirty-change', false);

    emit('saved', backend.value);
  } catch (error) {
    const errorMessage = error instanceof Error ? error.message : String(error);
    notification('error', 'Error', `Request error. ${errorMessage}`);
  } finally {
    isSaving.value = false;
  }
};

const removeOption = async (key: string): Promise<void> => {
  const wasDirty = isDirty.value;

  if (newOptions.value[key]) {
    const { [key]: _removed, ...rest } = newOptions.value;
    newOptions.value = rest;
    optionUnset(key);
    return;
  }

  const { status } = await useDialog().confirmDialog({
    title: 'Option removal',
    message: `Delete the option '${key}'? This action cannot be undone.`,
    confirmColor: 'error',
  });

  if (true !== status) {
    return;
  }

  const response = await request(`/backend/${id.value}/option/options.${key}`, {
    method: 'DELETE',
  });

  if (!response.ok) {
    const json = await parse_api_response<GenericResponse>(response);
    if ('error' in json) {
      notification(
        'error',
        'Error',
        `Failed to remove the option. (${json.error.code}: ${json.error.message}).`,
      );
    } else {
      notification('error', 'Error', 'Failed to remove the option.');
    }
    return;
  }

  notification('success', 'Information', `Option [${key}] removed successfully.`);
  optionUnset(key);

  if (false === wasDirty) {
    markClean();
    emit('dirty-change', false);
  }
};

const addOption = async (): Promise<void> => {
  if (!selectedOption.value) {
    notification('error', 'Error', 'Please select an option to add.');
    return;
  }

  backend.value.options = backend.value.options || {};
  optionSet(selectedOption.value, '');
  newOptions.value[selectedOption.value] = true;
  selectedOption.value = '';
};

watch(isDirty, (value: boolean) => emit('dirty-change', value));

watch(showOptions, async (value: boolean) => {
  if (!value || 0 < optionsList.value.length) {
    return;
  }

  const response = await request('/backends/spec');
  const json = await parse_api_response<Array<BackendSpecOption>>(response);

  if ('error' in json) {
    notification('error', 'Error', `Failed to load options: ${json.error.message}`);
    return;
  }

  for (const option of json) {
    if (false === option.key.startsWith('options.')) {
      continue;
    }

    optionsList.value.push({ ...option, key: option.key.replace('options.', '') });
  }
});

watch(
  () => props.backendName,
  async (value: string, oldValue: string | undefined) => {
    if (!value || value === oldValue) {
      return;
    }

    await loadContent();
  },
  { immediate: true },
);
</script>
