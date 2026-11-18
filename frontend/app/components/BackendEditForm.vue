<template>
  <div class="mx-auto max-w-3xl space-y-6">
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
            This backend uses a limited token. Core sync should work, but identity and user
            management may be restricted.
          </p>
          <p>Use a full API key when possible.</p>
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
            <strong>Sync Backends</strong> to copy those changes to linked identities.
          </p>
        </div>
      </template>
    </UAlert>

    <UAlert
      v-if="isLoading"
      color="info"
      variant="soft"
      icon="i-lucide-loader-circle"
      title="Loading backend settings"
      :ui="{ icon: 'animate-spin' }"
    />

    <UAlert
      v-else-if="loadError"
      color="error"
      variant="soft"
      icon="i-lucide-circle-alert"
      title="Couldn't load backend settings"
      :description="loadError"
    >
      <template #actions>
        <UButton
          color="neutral"
          variant="outline"
          icon="i-lucide-refresh-cw"
          @click="() => void loadContent()"
        >
          Try again
        </UButton>
      </template>
    </UAlert>

    <form v-else :id="formId" class="space-y-4" @submit.prevent="saveContent">
      <dl class="grid gap-4 sm:grid-cols-3">
        <div>
          <dt class="text-xs font-medium text-muted">WatchState identity</dt>
          <dd class="mt-1 font-semibold text-highlighted">{{ api_user }}</dd>
        </div>
        <div>
          <dt class="text-xs font-medium text-muted">Backend</dt>
          <dd class="mt-1 font-semibold text-highlighted">{{ backend.name }}</dd>
        </div>
        <div>
          <dt class="text-xs font-medium text-muted">Server type</dt>
          <dd class="mt-1 font-semibold text-highlighted">{{ ucFirst(backend.type) }}</dd>
        </div>
      </dl>

      <nav
        aria-label="Backend settings sections"
        class="sticky top-0 z-10 flex flex-wrap gap-1 border-b border-default bg-default/90 py-2 backdrop-blur-md"
      >
        <UButton
          as="a"
          href="#backend-connection"
          external
          color="neutral"
          variant="ghost"
          size="sm"
        >
          Connection
        </UButton>
        <UButton
          as="a"
          href="#backend-remote-account"
          external
          color="neutral"
          variant="ghost"
          size="sm"
        >
          Remote account
        </UButton>
        <UButton
          as="a"
          href="#backend-sync-direction"
          external
          color="neutral"
          variant="ghost"
          size="sm"
        >
          Sync direction
        </UButton>
        <UButton
          as="a"
          href="#backend-advanced"
          external
          color="neutral"
          variant="ghost"
          size="sm"
          @click="showOptions = true"
        >
          Advanced
        </UButton>
      </nav>

      <fieldset :disabled="isSaving || isRemovingOption" class="min-w-0 space-y-4">
        <section
          id="backend-connection"
          aria-labelledby="backend-connection-title"
          class="ws-card-padded-lg scroll-mt-20 space-y-6"
        >
          <div>
            <h2 id="backend-connection-title" class="text-lg font-semibold text-highlighted">
              Connection
            </h2>
          </div>

          <div class="space-y-5">
            <UFormField label="URL" name="backend_url" required>
              <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                <USelect
                  v-if="0 < servers.length"
                  v-model="backend.url"
                  :items="serverItems"
                  value-key="value"
                  placeholder="Select server"
                  icon="i-lucide-link"
                  class="w-full"
                  @update:model-value="() => void updateIdentifier()"
                />

                <UInput v-else v-model="backend.url" icon="i-lucide-link" class="w-full" required />

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
                <template v-if="1 > servers.length">
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
                    @click="
                      () => {
                        exposeToken = !exposeToken;
                      }
                    "
                  >
                    {{ exposeToken ? 'Hide' : 'Show' }}
                  </UButton>
                </UTooltip>
              </div>

              <div class="mt-2 space-y-2 text-sm text-toned">
                <template v-if="isPlex">
                  <p>
                    Enter the <strong>X-Plex-Token</strong>. See
                    <NuxtLink
                      target="_blank"
                      to="https://support.plex.tv/articles/204059436-finding-an-authentication-token-x-plex-token/"
                      class="text-primary"
                    >
                      Plex's token guide
                    </NuxtLink>
                    for instructions.
                  </p>
                </template>

                <template v-else>
                  <p>
                    Generate an API key in
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

            <div v-if="backend?.options?.client" class="border-t border-default pt-5">
              <div class="flex items-center justify-between gap-3">
                <div class="min-w-0">
                  <label for="backend_ssl_verify" class="text-sm font-medium text-highlighted">
                    Validate SSL certificate
                  </label>
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

            <div v-if="isPlex" class="border-t border-default pt-5">
              <div class="space-y-3">
                <div>
                  <p class="text-sm font-semibold text-highlighted">Plex Authentication</p>
                  <p class="mt-1 text-sm text-toned">
                    Re-authenticate with plex.tv if the stored token is no longer valid or you need
                    to refresh admin access.
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
          <div class="space-y-4 border-t border-default pt-5">
            <UFormField label="Server unique ID" name="backend_uuid" required>
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
                Plex uses this identifier to distinguish servers for ownership and webhook matching.
              </span>
              <span v-else>
                This random identifier uniquely represents the backend and is used for webhook
                matching and filtering.
              </span>
            </p>
          </div>
        </section>

        <section
          id="backend-remote-account"
          aria-labelledby="backend-remote-account-title"
          class="ws-card-padded-lg scroll-mt-20 space-y-6"
        >
          <div>
            <h2 id="backend-remote-account-title" class="text-lg font-semibold text-highlighted">
              Remote account
            </h2>
            <p class="mt-1 text-sm text-toned">
              Choose whose {{ ucFirst(backend.type) }} watch history you want to sync.
            </p>
          </div>

          <div class="space-y-5">
            <UFormField
              :label="0 < users.length ? 'Remote user' : 'Remote user ID'"
              name="backend_user"
              :description="
                isPlex
                  ? 'Choose the Plex user this backend should operate as.'
                  : 'Choose which backend user this configuration should use.'
              "
            >
              <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                <USelect
                  v-if="0 < users.length"
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
        </section>

        <section
          id="backend-sync-direction"
          aria-labelledby="backend-sync-direction-title"
          class="ws-card-padded-lg scroll-mt-20 space-y-6"
        >
          <div>
            <h2 id="backend-sync-direction-title" class="text-lg font-semibold text-highlighted">
              Sync direction
            </h2>
            <p class="mt-1 text-sm text-toned">
              Choose whether WatchState reads from this account, writes to it, or both.
            </p>
          </div>

          <div class="space-y-4">
            <div v-if="backend.import" class="flex items-start justify-between gap-4">
              <div class="min-w-0">
                <label for="backend_import" class="text-sm font-medium text-highlighted">
                  Import · {{ ucFirst(backend.type) }} → WatchState
                </label>
                <p class="mt-1 text-sm text-toned">
                  Read watched status, playlists &amp; progress from this backend.
                </p>
                <p
                  class="mt-2 text-sm"
                  :class="backend.import.enabled ? 'text-toned' : 'text-warning'"
                >
                  {{
                    backend.import.enabled
                      ? 'Play-state import is on.'
                      : 'Only metadata is imported, not watched status, playlists or progress.'
                  }}
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
              class="flex items-start justify-between gap-4 border-t border-default pt-5"
            >
              <div class="min-w-0">
                <label for="backend_export" class="text-sm font-medium text-highlighted">
                  Export · WatchState → {{ ucFirst(backend.type) }}
                </label>
                <p class="mt-1 text-sm text-toned">
                  Send play-state updates to this remote account.
                </p>
                <p class="mt-2 text-sm text-toned">
                  {{
                    backend.export.enabled
                      ? 'Play-state export is on.'
                      : 'Play-state export is off.'
                  }}
                </p>
              </div>

              <USwitch
                id="backend_export"
                v-model="backend.export.enabled"
                :color="backend.export.enabled ? 'success' : 'neutral'"
              />
            </div>
          </div>
        </section>

        <section
          id="backend-advanced"
          aria-labelledby="backend-advanced-title"
          class="ws-card-padded-lg scroll-mt-20 space-y-6"
        >
          <h2 id="backend-advanced-title">
            <button
              type="button"
              class="flex w-full items-center justify-between gap-3 text-left"
              :aria-expanded="showOptions"
              aria-controls="backend-advanced-options"
              @click="showOptions = !showOptions"
            >
              <span class="text-lg font-semibold text-highlighted"> Advanced </span>

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
          </h2>

          <div class="space-y-4">
            <p class="text-sm text-toned">
              Change these only when required by your backend or WatchState troubleshooting
              instructions.
            </p>

            <div v-if="showOptions" id="backend-advanced-options" class="space-y-6">
              <UAlert
                color="warning"
                variant="soft"
                icon="i-lucide-info"
                title="Removed options take effect immediately"
                description="Remove now deletes saved options immediately. Use Save settings to apply new options &amp; value changes."
              />
              <div
                v-for="optionPath in flatOptionPaths"
                :key="`bo-${optionPath}`"
                class="border-b border-default pb-6"
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
                        {{ newOptions[optionPath] ? 'Discard' : 'Remove now' }}
                      </UButton>
                    </template>
                  </UFormField>
                </div>
              </div>

              <div class="space-y-4">
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

              <UEmpty
                v-if="0 === advancedOptionCount && 0 === availableOptionCount"
                size="sm"
                variant="naked"
                icon="i-lucide-info"
                title="No advanced options available"
              />
            </div>
          </div>
        </section>
      </fieldset>

      <Teleport defer :to="props.actionsTarget">
        <div class="space-y-3">
          <p v-if="saveError" role="alert" class="text-sm text-error">{{ saveError }}</p>
          <p v-if="optionMessage" role="status" class="text-sm text-success">{{ optionMessage }}</p>
          <div class="flex flex-wrap items-center justify-between gap-3">
            <p role="status" aria-live="polite" class="text-sm text-toned">
              {{
                isSaving
                  ? 'Saving settings…'
                  : isRemovingOption
                    ? 'Removing saved option…'
                    : isDirty
                      ? 'Unsaved changes'
                      : 'No unsaved changes'
              }}
            </p>
            <UButton
              type="submit"
              :form="formId"
              color="primary"
              variant="solid"
              size="sm"
              icon="i-lucide-save"
              class="justify-center"
              :loading="isSaving"
              :disabled="isSaving || isRemovingOption || !isDirty"
            >
              Save settings
            </UButton>
          </div>
        </div>
      </Teleport>
    </form>
  </div>
</template>

<script setup lang="ts">
import { useStorage } from '@vueuse/core';
import { computed, nextTick, ref, useId, watch } from 'vue';
import {
  NuxtLink,
  UAlert,
  UBadge,
  UButton,
  UEmpty,
  UFormField,
  UIcon,
  UInput,
  USelect,
  USwitch,
  UTooltip,
} from '#components';
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
    actionsTarget: string;
  }>(),
  {},
);

const formId = useId();

const emit = defineEmits<{
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
const isRemovingOption = ref<boolean>(false);
const loadError = ref<string>('');
const saveError = ref<string>('');
const optionMessage = ref<string>('');
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
const { isDirty, markClean } = useDirtyState(backend);

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
    (option) => undefined === optionsRecord[option.key] && !newOptions.value[option.key],
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
  loadError.value = '';
  saveError.value = '';
  optionMessage.value = '';
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
      loadError.value = json.error.message;
      notification('error', 'Backend settings unavailable', loadError.value);
      return;
    }

    if (!json?.options || 'object' !== typeof json.options || Array.isArray(json.options)) {
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
    loadError.value = errorMessage;
    notification('error', 'Backend settings unavailable', loadError.value);
  } finally {
    isLoading.value = false;
  }
};

const saveContent = async (): Promise<void> => {
  if (isSaving.value || isRemovingOption.value || !isDirty.value) {
    return;
  }

  const flat: Record<string, JsonValue> = {};
  for (const path of flatOptionPaths.value) {
    flat[path] = optionGet(path) ?? null;
  }

  const payload = { ...backend.value, options: flat };

  isSaving.value = true;
  saveError.value = '';
  optionMessage.value = '';

  try {
    const response = await request(`/backend/${id.value}`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });

    const json = await parse_api_response<Backend>(response);
    if (200 !== response.status || 'error' in json) {
      if ('error' in json) {
        saveError.value = `Couldn't save settings. ${json.error.message} (${json.error.code}).`;
      } else {
        saveError.value = 'Could not save backend settings.';
      }
      notification('error', 'Backend settings not saved', saveError.value);
      return;
    }

    notification('success', 'Backend settings saved', `Updated '${id.value}'.`);
    newOptions.value = {};
    markClean();
    emit('dirty-change', false);

    emit('saved', backend.value);
  } catch (error) {
    const errorMessage = error instanceof Error ? error.message : String(error);
    saveError.value = `Couldn't save settings. ${errorMessage}`;
    notification('error', 'Backend settings not saved', saveError.value);
  } finally {
    isSaving.value = false;
  }
};

const removeOption = async (key: string): Promise<void> => {
  if (isSaving.value || isRemovingOption.value) {
    return;
  }

  const wasDirty = isDirty.value;

  if (newOptions.value[key]) {
    const { [key]: _removed, ...rest } = newOptions.value;
    newOptions.value = rest;
    optionUnset(key);
    return;
  }

  const { status } = await useDialog().confirmDialog({
    title: 'Remove saved option now?',
    message: `Remove '${key}' now? Closing without saving won't restore this option. Your other edits will remain unsaved.`,
    confirmText: 'Remove option',
    confirmColor: 'error',
  });

  if (true !== status) {
    return;
  }

  isRemovingOption.value = true;
  saveError.value = '';
  optionMessage.value = '';

  try {
    const response = await request(`/backend/${id.value}/option/options.${key}`, {
      method: 'DELETE',
    });
    const json = await parse_api_response<GenericResponse>(response);

    if (!response.ok || 'error' in json) {
      saveError.value =
        'error' in json
          ? `Couldn't remove '${key}'. ${json.error.message} (${json.error.code}).`
          : `Couldn't remove '${key}'.`;
      notification('error', 'Option not removed', saveError.value);
      return;
    }

    optionMessage.value = `Removed saved option '${key}'.`;
    notification('success', 'Option removed', optionMessage.value);
    optionUnset(key);

    if (false === wasDirty) {
      markClean();
      emit('dirty-change', false);
    }
  } catch (error) {
    const errorMessage = error instanceof Error ? error.message : String(error);
    saveError.value = `Couldn't remove '${key}'. ${errorMessage}`;
    notification('error', 'Option not removed', saveError.value);
  } finally {
    isRemovingOption.value = false;
  }
};

const addOption = async (): Promise<void> => {
  if (!selectedOption.value) {
    notification('error', 'No option selected', 'Select an option to add.');
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
    notification('error', 'Backend options unavailable', json.error.message);
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
