<template>
  <main class="w-full min-w-0 max-w-full space-y-6">
    <PageHeader v-bind="pageShell" :description="headerDescription">
      <template #kicker>
        <span>{{ pageShell.sectionLabel }}</span>
        <span>/</span>
        <NuxtLink to="/identities" class="hover:text-primary">{{ pageShell.pageLabel }}</NuxtLink>
        <span>/</span>
        <span class="text-highlighted normal-case tracking-normal">Match &amp; Provision</span>
      </template>
      <template #actions>
        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-file-output"
          :disabled="membersWithNoPin.length > 0"
          @click="generateFile"
          label="Export"
        />

        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-plus"
          @click="addNewIdentity"
          label="Add identity"
        />

        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-refresh-cw"
          :loading="isLoading"
          :disabled="isLoading"
          @click="loadContent(true)"
          label="Reload"
        />
      </template>
    </PageHeader>

    <UAlert
      v-if="isLoading"
      color="info"
      variant="soft"
      icon="i-lucide-loader-circle"
      title="Loading"
      description="Loading data. Please wait..."
      :ui="{ icon: 'animate-spin' }"
    />

    <UAlert
      v-if="!isLoading && membersWithNoPin.length > 0"
      color="warning"
      variant="soft"
      icon="i-lucide-triangle-alert"
      title="Members missing PIN"
    >
      <template #description>
        <div class="space-y-2 text-sm text-default">
          <p>
            The following members are missing a PIN. Click on
            <UIcon name="i-lucide-lock-open" class="inline size-4 align-text-bottom" /> to set the
            member PIN. Otherwise you will not be able to proceed.
          </p>
          <div class="flex flex-wrap gap-2">
            <UBadge
              v-for="(member, index) in membersWithNoPin"
              :key="index"
              color="warning"
              variant="soft"
            >
              {{ member }}
            </UBadge>
          </div>
        </div>
      </template>
    </UAlert>

    <UAlert
      v-if="matched?.length < 1 && !isLoading && !allowSingleBackendIdentities"
      color="error"
      variant="soft"
      icon="i-lucide-triangle-alert"
      title="No matched identities."
      description="Click on the add button to add an identity group."
    />

    <div v-if="matched.length > 0" class="grid gap-4 xl:grid-cols-2">
      <UCard
        v-for="(group, index) in matched"
        :key="index"
        class="h-full border shadow-sm"
        :class="group.members.length >= 2 ? 'border-success/50' : 'border-warning/50'"
        :ui="groupCardUi"
      >
        <template #header>
          <div class="flex items-center justify-between gap-3">
            <div class="min-w-0 flex-1">
              <UTooltip :text="String(group.identity)">
                <h2 class="truncate text-base font-semibold text-highlighted">
                  {{ group.identity }}
                </h2>
              </UTooltip>
            </div>

            <div class="flex shrink-0 items-center gap-2">
              <UBadge color="neutral" variant="soft" size="sm">{{ group.members.length }}</UBadge>

              <UButton
                color="neutral"
                variant="outline"
                size="sm"
                icon="i-lucide-trash-2"
                aria-label="Delete group"
                @click="deleteGroup(index)"
                label="Delete"
              />
            </div>
          </div>
        </template>

        <draggable
          v-model="group.members"
          :group="{ name: 'shared', pull: true, put: true }"
          animation="150"
          :move="checkBackend"
          item-key="id"
          class="flex min-h-20 flex-wrap gap-2 rounded-md border border-dashed border-default bg-elevated/20 p-2"
        >
          <template #item="{ element }">
            <div class="ws-identity-chip" :class="setClass(element)">
              <button
                v-if="element?.protected"
                type="button"
                class="inline-flex items-center text-toned hover:text-primary"
                @click="setMemberPin(element)"
              >
                <UTooltip text="Click to set/view user PIN">
                  <UIcon
                    :name="element?.options?.PLEX_USER_PIN ? 'i-lucide-lock' : 'i-lucide-lock-open'"
                    class="size-4"
                  />
                </UTooltip>
              </button>

              <span class="min-w-0">
                <span class="font-medium text-highlighted"
                  >{{ element.backend }}@{{ element.username }}</span
                >
                <span v-if="!isSameName(element.real_name, element.username)">
                  (<span class="underline">{{ element.real_name }}</span
                  >)
                </span>
              </span>
            </div>
          </template>

          <template #footer>
            <div
              v-if="group.members.length < 1"
              class="ws-identity-chip ws-identity-chip-placeholder"
            >
              <span class="font-medium text-toned">Drop members here.</span>
            </div>
          </template>
        </draggable>
      </UCard>
    </div>

    <UCard
      v-if="!isLoading"
      class="border shadow-sm"
      :class="allowSingleBackendIdentities ? 'border-info/50' : 'border-error/50'"
      :ui="groupCardUi"
    >
      <template #header>
        <div class="flex items-center justify-between gap-3">
          <div
            class="flex min-w-0 items-center gap-2 text-base font-semibold"
            :class="allowSingleBackendIdentities ? 'text-highlighted' : 'text-error'"
          >
            <UIcon
              :name="allowSingleBackendIdentities ? 'i-lucide-info' : 'i-lucide-triangle-alert'"
              class="size-4 shrink-0"
            />
            <span class="truncate">{{
              allowSingleBackendIdentities ? 'Single Backend Mode Enabled' : 'Unmatched Members'
            }}</span>
          </div>

          <UBadge color="neutral" variant="soft" size="sm">{{ unmatched.length }}</UBadge>
        </div>
      </template>

      <template #default>
        <draggable
          v-if="unmatched?.length > 0"
          v-model="unmatched"
          :group="{ name: 'shared', pull: true, put: true }"
          animation="150"
          :move="checkBackend"
          item-key="id"
          class="flex min-h-20 flex-wrap gap-2 rounded-md border border-dashed border-default bg-elevated/20 p-2"
        >
          <template #item="{ element }">
            <div class="ws-identity-chip" :class="setClass(element)">
              <button
                v-if="element?.protected && allowSingleBackendIdentities"
                type="button"
                class="inline-flex items-center text-toned hover:text-primary"
                @click="setMemberPin(element)"
              >
                <UTooltip text="Click to set/view user PIN">
                  <UIcon
                    :name="element?.options?.PLEX_USER_PIN ? 'i-lucide-lock' : 'i-lucide-lock-open'"
                    class="size-4"
                  />
                </UTooltip>
              </button>

              <UIcon
                v-else-if="element?.protected && !allowSingleBackendIdentities"
                :name="element?.options?.PLEX_USER_PIN ? 'i-lucide-lock' : 'i-lucide-lock-open'"
                class="size-4 text-toned"
              />

              <span class="min-w-0">
                <span class="font-medium text-highlighted"
                  >{{ element.backend }}@{{ element.username }}</span
                >
                <span v-if="!isSameName(element.real_name, element.username)">
                  (<span class="underline">{{ element.real_name }}</span
                  >)
                </span>
              </span>
            </div>
          </template>
        </draggable>

        <UAlert
          v-if="unmatched?.length < 1"
          color="success"
          variant="soft"
          icon="i-lucide-circle-check"
          description="All members are associated."
          class="mt-4"
        />
      </template>
    </UCard>

    <UCard v-if="!isLoading" :ui="formCardUi">
      <template #header>
        <div class="text-base font-semibold text-highlighted">Execution options</div>
      </template>

      <div class="space-y-4">
        <div class="space-y-3">
          <div
            v-if="hasIdentities"
            class="rounded-md border border-default bg-elevated/20 px-3 py-3"
          >
            <div class="flex items-center justify-between gap-3">
              <div class="min-w-0">
                <div class="text-sm font-medium text-highlighted">Re-create local identities</div>
                <p class="mt-1 text-sm text-toned">
                  Delete current local identity data before creating the new set.
                </p>
              </div>

              <USwitch v-model="recreate" color="neutral" />
            </div>
          </div>

          <div class="rounded-md border border-default bg-elevated/20 px-3 py-3">
            <div class="flex items-center justify-between gap-3">
              <div class="min-w-0">
                <div class="text-sm font-medium text-highlighted">Generate remote backups</div>
                <p class="mt-1 text-sm text-toned">
                  Create an initial backup for each identity remote backend dataset.
                </p>
              </div>

              <USwitch v-model="backup" color="neutral" />
            </div>
          </div>

          <div class="rounded-md border border-default bg-elevated/20 px-3 py-3">
            <div class="flex items-center justify-between gap-3">
              <div class="min-w-0">
                <div class="text-sm font-medium text-highlighted">Skip mapper save</div>
                <p class="mt-1 text-sm text-toned">
                  Do not save the current mapping before running.
                </p>
              </div>

              <USwitch v-model="noSave" color="neutral" />
            </div>
          </div>

          <div class="rounded-md border border-default bg-elevated/20 px-3 py-3">
            <div class="flex items-center justify-between gap-3">
              <div class="min-w-0">
                <div class="text-sm font-medium text-highlighted">Dry run</div>
                <p class="mt-1 text-sm text-toned">Preview the operation without making changes.</p>
              </div>

              <USwitch v-model="dryRun" color="neutral" />
            </div>
          </div>

          <div
            v-if="1 === backendCount"
            class="rounded-md border border-default bg-elevated/20 px-3 py-3"
          >
            <div class="flex items-center justify-between gap-3">
              <div class="min-w-0">
                <div class="text-sm font-medium text-highlighted">
                  Allow single backend identities
                </div>
                <p class="mt-1 text-sm text-toned">
                  Create identities from the single configured backend without requiring member
                  mapping.
                </p>
              </div>

              <USwitch v-model="allowSingleBackendIdentities" color="neutral" />
            </div>
          </div>
        </div>

        <UAlert
          v-if="allowSingleBackendIdentities && 1 === backendCount"
          color="success"
          variant="soft"
          icon="i-lucide-info"
          title="Single Backend Mode"
        >
          <template #description>
            <p class="text-sm text-default">
              You are in <strong>single backend mode</strong>. The system will create individual
              identities from your single configured backend without requiring member mapping. Each
              identity will be set up independently.
            </p>
          </template>
        </UAlert>
      </div>

      <template #footer>
        <div class="flex gap-2 flex-row justify-end">
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-save"
            :disabled="membersWithNoPin.length > 0"
            @click="
              () => {
                void saveMap();
              }
            "
            label="Save mapping"
          />

          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-users"
            :disabled="membersWithNoPin.length > 0"
            @click="provisionIdentities"
          >
            <span v-if="!dryRun">
              <span v-if="recreate || !hasIdentities"
                >{{ recreate ? 'Re-create' : 'Create' }} identities</span
              >
              <span v-else>Update identities</span>
            </span>
            <span v-else
              >Test create identities<span v-if="hasIdentities"> (Safe operation)</span></span
            >
          </UButton>
        </div>
      </template>
    </UCard>

    <UCard class="shadow-sm" :ui="tipsCardUi">
      <template #header>
        <button
          type="button"
          class="flex w-full items-center justify-between gap-3 text-left"
          @click="show_page_tips = !show_page_tips"
        >
          <span class="inline-flex items-center gap-2 text-sm font-semibold text-highlighted">
            <UIcon name="i-lucide-info" class="size-4 text-toned" />
            <span>Information</span>
          </span>

          <span class="inline-flex items-center gap-1 text-xs font-medium text-toned">
            <UIcon
              :name="show_page_tips ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
              class="size-4"
            />
            <span>{{ show_page_tips ? 'Hide' : 'Show' }}</span>
          </span>
        </button>
      </template>

      <ul v-if="show_page_tips" class="list-disc space-y-2 pl-5 text-sm leading-6 text-default">
        <li>
          This page lets you guide the system in matching identities across different backends.
        </li>
        <li>
          When you click <code>Create identities</code>, your mapping will be uploaded unless you’ve
          selected <code>Do not save mapper</code>. Based on your choice, the system will either
          delete and recreate the local identities, or try to update the existing ones.
        </li>
        <li class="font-semibold text-error">
          Warning: If you choose not to delete the existing local identities and the matching
          changes for any reason, you may end up with duplicate identities. We strongly recommend
          deleting the current local identities.
        </li>
        <li>
          Clicking <code>Save mapping</code> will only save your current mapping to the system. It
          will <strong>not</strong> create any identities.
        </li>
        <li>
          Clicking the
          <UIcon name="i-lucide-file-output" class="inline size-4 align-text-bottom" /> icon will
          download the current mapping as a YAML file. You can review and manually upload it to the
          system later if needed.
        </li>
        <li>
          Members in the <b>Not matched</b> group aren’t currently linked to any others and likely
          won’t be matched automatically.
        </li>
        <li>Each identity group must have at least two members to be considered a valid group.</li>
        <li>
          You can drag and drop members from the <b>Not matched</b> group into any other group to
          manually associate them.
        </li>
        <li>
          An identity group can only include <b>one</b> member from <b>each</b> backend. If you try
          to add a second member from the same backend, an error will be shown.
        </li>
        <li>
          The display name format is: <code>backend_name@normalized_name (real_username)</code>. The
          <code>(real_username)</code> part only appears if it’s different from the
          <code>normalized_name</code>.
        </li>
        <li>
          There is a 5-minute cache when retrieving members from the API, so the data you see might
          be slightly out of date.
        </li>
        <li>
          Backend members with red border and icon of
          <UIcon name="i-lucide-lock-open" class="inline size-4 align-text-bottom" /> are protected
          by PIN, and you need to click on the icon to set the PIN. Otherwise, you will not be able
          to proceed.
        </li>
      </ul>
    </UCard>
  </main>
</template>

<script setup lang="ts">
import { computed, nextTick, onMounted, ref, toRaw } from 'vue';
import { useStorage } from '@vueuse/core';
import { navigateTo, useRoute } from '#app';
import { NuxtLink } from '#components';
import moment from 'moment';
import draggable from 'vuedraggable';
import PageHeader from '~/components/PageHeader.vue';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import { notification, parse_api_response, request } from '~/utils';
import { useDialog } from '~/composables/useDialog';
import type { GenericResponse } from '~/types';

const pageShell = requireTopLevelPageShell('identities');

type IdentityOptions = {
  PLEX_USER_PIN?: string;
};

type IdentityMember = {
  id: string;
  backend: string;
  username: string;
  real_name: string;
  protected?: boolean;
  options?: IdentityOptions;
};

type IdentityMappingData = {
  version: string;
  identities: Array<IdentityGroup>;
};

type IdentityGroup = {
  identity: string;
  members: Array<IdentityMember>;
};

const matched = ref<Array<IdentityGroup>>([]);
const unmatched = ref<Array<IdentityMember>>([]);
const isLoading = ref<boolean>(false);
const toastIsVisible = ref<boolean>(false);
const recreate = ref<boolean>(false);
const backup = ref<boolean>(false);
const noSave = ref<boolean>(false);
const dryRun = ref<boolean>(false);
const hasIdentities = ref<boolean>(false);
const allowSingleBackendIdentities = ref<boolean>(false);
const backendCount = ref<number>(0);
const expires = ref<string | undefined>();
const headerDescription = computed(() => {
  let desc = 'Drag and drop backend members into groups to build identity associations.';
  if (expires.value) {
    desc += ` Cached results expire ${moment(expires.value).fromNow()}.`;
  }
  return desc;
});
const api_user = useStorage('api_user', 'main');
const show_page_tips = useStorage('show_page_tips', true);

type FilePickerOptions = {
  suggestedName?: string;
};

type FilePickerHandle = {
  createWritable: () => Promise<WritableStream>;
};

const groupCardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
};

const formCardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
  footer: 'border-t border-default px-4 py-4',
};

const tipsCardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
};

const addNewIdentity = (): void => {
  const newIdentityName = `Identity group #${matched.value.length + 1}`;
  matched.value.push({ identity: newIdentityName, members: [] });
};

const loadContent = async (force?: boolean): Promise<void> => {
  if (matched.value.length > 0) {
    const { status } = await useDialog().confirmDialog({
      title: 'Reload data',
      message: 'Reloading will remove all modifications. Are you sure?',
      confirmColor: 'error',
    });

    if (true !== status) {
      return;
    }
  }

  matched.value = [];
  unmatched.value = [];
  isLoading.value = true;

  try {
    const response = await request(`/identities/provision${force ? '?force=1' : ''}`, {
      method: 'GET',
      headers: { Accept: 'application/json' },
    });
    const json = await parse_api_response<{
      matched: Array<IdentityGroup>;
      unmatched: Array<IdentityMember>;
      has_identities: boolean;
      expires?: string;
      backends?: Array<string>;
    }>(response);

    if ('identities-provision' !== useRoute().name) {
      return;
    }

    if ('error' in json) {
      notification('error', 'Error', json.error.message || 'Unknown error');
      return;
    }

    matched.value = json.matched;
    unmatched.value = json.unmatched;
    recreate.value = json.has_identities;
    backup.value = !json.has_identities;
    hasIdentities.value = json.has_identities;
    backendCount.value = json.backends?.length || 0;
    expires.value = json?.expires;
  } catch (error: unknown) {
    const message = error instanceof Error ? error.message : 'Unexpected error';
    notification('error', 'Error', message);
  } finally {
    isLoading.value = false;
  }
};

const generateFile = async (): Promise<void> => {
  const filename = 'mapper.yaml';
  const data = formatData();

  if (!data.identities.length) {
    notification('error', 'Error', 'No data to export.');
    return;
  }

  const response = request(`/system/yaml/${filename}`, {
    method: 'POST',
    headers: { Accept: 'text/yaml' },
    body: JSON.stringify(data),
  });

  const pickerWindow = window as Window & {
    showSaveFilePicker?: (options: FilePickerOptions) => Promise<FilePickerHandle>;
  };
  const showSaveFilePicker = pickerWindow.showSaveFilePicker;

  if (showSaveFilePicker) {
    response.then(async (res) => {
      if (!res.body) {
        notification('error', 'Error', 'No data returned from export request.');
        return;
      }

      const handle = await showSaveFilePicker({
        suggestedName: `${filename}`,
      });
      await res.body.pipeTo(await handle.createWritable());
    });
  }

  response
    .then((res) => res.blob())
    .then((blob) => {
      const fileURL = URL.createObjectURL(blob);
      const fileLink = document.createElement('a');
      fileLink.href = fileURL;
      fileLink.download = `${filename}`;
      fileLink.click();
    });
};

interface DragEvent {
  draggedContext: {
    list: Array<IdentityMember>;
    element: IdentityMember;
  };
  relatedContext: {
    list: Array<IdentityMember>;
  };
}

const checkBackend = (e: DragEvent): boolean => {
  if (e.draggedContext.list === e.relatedContext.list) {
    return true;
  }

  const isMatchedContainer = matched.value.some((group) => group.members === e.relatedContext.list);

  if (false === isMatchedContainer) {
    return true;
  }

  const draggedMember = e.draggedContext.element;
  const alreadyExists = e.relatedContext.list.some(
    (item) => item.backend === draggedMember.backend,
  );

  if (true === alreadyExists) {
    if (!toastIsVisible.value) {
      toastIsVisible.value = true;
      nextTick(() => {
        notification(
          'error',
          'error',
          `A member from '${draggedMember.backend}' backend is already mapped in this identity.`,
          3001,
          {
            onClose: () => (toastIsVisible.value = false),
          },
        );
      });
    }
    return false;
  }

  return true;
};

const deleteGroup = async (i: number) => {
  const group = matched.value[i];
  if (group && group.members && group.members.length) {
    const { status } = await useDialog().confirmDialog({
      title: 'Delete group',
      message: `Delete identity group #${i + 1}? Members will be moved to unmatched.`,
      confirmColor: 'error',
    });

    if (true !== status) {
      return;
    }

    unmatched.value.push(...group.members);
  }

  nextTick(() => matched.value.splice(i, 1));
};

const saveMap = async (no_toast: boolean = false): Promise<boolean> => {
  const data = formatData();

  if (!data.identities.length) {
    if (!no_toast) {
      notification('error', 'Error', 'No mapping data to save.');
    }
    return true;
  }

  try {
    const req = await request('/identities/provision/mapping', {
      method: 'PUT',
      body: JSON.stringify(data),
    });

    const response = await parse_api_response<GenericResponse>(req);
    if ('error' in response) {
      if (!no_toast) {
        notification('error', 'Error', `${req.status}: ${response.error.message}`);
      }
      return false;
    }

    if (200 <= req.status && 300 > req.status) {
      if (!no_toast) {
        notification('success', 'Success', response.info.message);
      }
      return true;
    }

    if (!no_toast) {
      notification('error', 'Error', `${req.status}: Request failed`);
    }

    return false;
  } catch (error: unknown) {
    const message = error instanceof Error ? error.message : 'Unexpected error';
    notification('error', 'Error', `Error: ${message}`);
  }

  return false;
};

const formatData = (): IdentityMappingData => {
  const data: IdentityMappingData = { version: '1.6', identities: [] };

  matched.value.forEach((group) => {
    const members = group.members.map((member) => ({
      ...member,
      options: member.options ? toRaw(member.options) : {},
    }));

    if (members.length < 2) {
      return;
    }

    data.identities.push({
      identity: group.identity,
      members,
    });
  });

  if (allowSingleBackendIdentities.value) {
    unmatched.value.forEach((u) =>
      data.identities.push({
        identity: `${u.backend}_${u.username}`,
        members: [
          {
            ...u,
            options: u.options ? toRaw(u.options) : {},
          },
        ],
      }),
    );
  }

  return toRaw(data);
};

const provisionIdentities = async (): Promise<void> => {
  const data = formatData();

  if (!allowSingleBackendIdentities.value && 0 === data.identities.length) {
    notification('error', 'Error', 'No identity mapping data to provision.');
    return;
  }

  try {
    const req = await request('/identities/provision', {
      method: 'POST',
      body: JSON.stringify({
        mode:
          recreate.value || !hasIdentities.value
            ? recreate.value
              ? 'recreate'
              : 'create'
            : 'update',
        dry_run: dryRun.value,
        generate_backup: backup.value,
        allow_single_backend_identities: allowSingleBackendIdentities.value,
        save_mapping: !noSave.value,
        mapping: data,
      }),
    });

    const response = await parse_api_response<GenericResponse>(req);

    if ('error' in response) {
      notification('error', 'Error', `${req.status}: ${response.error.message}`);
      return;
    }

    notification('success', 'Success', response.info.message);

    if (false === dryRun.value) {
      await navigateTo('/identities');
    }
  } catch (error: unknown) {
    const message = error instanceof Error ? error.message : 'Unexpected error';
    notification('error', 'Error', `Error: ${message}`);
  }
};

const isSameName = (name1: string, name2: string): boolean =>
  name1.toLowerCase() === name2.toLowerCase();

const setMemberPin = async (user: IdentityMember): Promise<void> => {
  const { status, value } = await useDialog().promptDialog({
    title: 'Set PIN',
    message: `Enter user PIN for '${user.backend}@${user.username}':`,
    initial: user?.options?.PLEX_USER_PIN || '',
  });

  if (true !== status) {
    return;
  }

  const pin = value;

  if ('' === pin) {
    if (user?.options?.PLEX_USER_PIN) {
      delete user.options.PLEX_USER_PIN;
    }
    return;
  }

  if (pin === user?.options?.PLEX_USER_PIN) {
    console.log('PIN is the same, no changes made.');
    return;
  }

  if (4 !== pin.length) {
    notification('error', 'Error', 'PIN must be at least 4 characters.');
    return;
  }

  if (!user?.options) {
    user.options = {};
  }

  user.options.PLEX_USER_PIN = pin;
};

const setClass = (user: IdentityMember): string | undefined => {
  if (!user?.protected) {
    return;
  }

  return user?.options?.PLEX_USER_PIN ? 'is-success' : 'is-danger';
};

const membersWithNoPin = computed<Array<string>>(() => {
  const no_pin: Array<string> = [];

  matched.value.forEach((group) =>
    group.members.forEach((user) => {
      if (!user?.protected) {
        return;
      }

      if (!user?.options?.PLEX_USER_PIN) {
        no_pin.push(`${user.backend}@${user.username}`);
      }
    }),
  );

  if (!allowSingleBackendIdentities.value) {
    return no_pin;
  }

  unmatched.value.forEach((user) => {
    if (!user?.protected) {
      return;
    }

    if (!user?.options?.PLEX_USER_PIN) {
      no_pin.push(`${user.backend}@${user.username}`);
    }
  });

  return no_pin;
});

onMounted(async (): Promise<void> => {
  if ('main' !== api_user.value) {
    notification(
      'error',
      'Error',
      'The identity provision page is only available for the main identity.',
    );
    await navigateTo({ name: 'backends' });
    return;
  }
  await loadContent();
});
</script>

<style scoped>
.ws-identity-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.375rem 0.625rem;
  max-width: 100%;
  background: color-mix(in srgb, var(--ui-bg-elevated) 88%, transparent);
  cursor: move;
  border: 1px solid var(--ui-border);
  border-radius: 0.375rem;
  color: var(--ui-text-highlighted);
  overflow-wrap: anywhere;
}

.ws-identity-chip.is-danger {
  border-color: color-mix(in srgb, var(--ui-color-error-500) 55%, transparent);
}

.ws-identity-chip.is-success {
  border-color: color-mix(in srgb, var(--ui-color-success-500) 55%, transparent);
}

.ws-identity-chip-placeholder {
  cursor: default;
  border-style: dashed;
  background: transparent;
}
</style>
