<template>
  <div class="w-full min-w-0 max-w-full space-y-8">
    <PageHeader
      v-bind="pageShell"
      :page-label="backend"
      :description="`Watch-history updates & tools for ${apiUser}.`"
    >
      <template #kicker>
        <NuxtLink to="/backends" class="hover:text-primary">Backends</NuxtLink>
        <span>/</span>
        <span>Overview</span>
      </template>
      <template #actions>
        <UButton
          color="neutral"
          variant="outline"
          icon="i-lucide-refresh-cw"
          :loading="infoLoading || historyLoading"
          @click="
            () => {
              void loadInfo();
              void loadRecentHistory();
            }
          "
        >
          Refresh
        </UButton>
        <UButton
          color="neutral"
          variant="outline"
          icon="i-lucide-pencil"
          @click="editBackendOpen = true"
        >
          Edit
        </UButton>
        <UButton
          :to="`/backend/${backend}/delete`"
          color="neutral"
          variant="outline"
          icon="i-lucide-trash-2"
        >
          Delete
        </UButton>
      </template>
    </PageHeader>

    <section aria-labelledby="recent-updates-heading" :aria-busy="historyLoading" class="space-y-4">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="space-y-1">
          <h2 id="recent-updates-heading" class="text-lg font-semibold text-highlighted">
            Recent updates
          </h2>
          <p class="text-sm text-toned">Local watch-history records related to {{ backend }}.</p>
        </div>
        <UButton
          :to="historyLink"
          color="neutral"
          variant="ghost"
          size="sm"
          trailing-icon="i-lucide-arrow-right"
        >
          View history
        </UButton>
      </div>

      <div
        v-if="historyError"
        role="alert"
        class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-error/30 bg-error/5 p-4"
      >
        <div class="min-w-0 space-y-1">
          <p class="text-sm font-medium text-highlighted">
            {{
              0 < bHistory.length
                ? 'Recent updates could not be refreshed'
                : 'Recent updates could not be loaded'
            }}
          </p>
          <p class="wrap-break-word text-sm text-toned">{{ historyError }}</p>
          <p v-if="0 < bHistory.length" class="text-xs text-toned">
            These results may be out of date.
          </p>
        </div>
        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-refresh-cw"
          :loading="historyLoading"
          @click="void loadRecentHistory()"
        >
          Retry
        </UButton>
      </div>

      <div
        v-if="historyLoading && 0 === bHistory.length"
        role="status"
        aria-label="Loading recent updates"
        class="flex gap-4 overflow-hidden pb-3"
      >
        <div v-for="index in 6" :key="index" class="w-44 shrink-0 space-y-3 sm:w-48">
          <USkeleton v-if="posterEnable" class="aspect-2/3 w-full rounded-lg" />
          <USkeleton class="h-4 w-4/5" />
          <USkeleton class="h-3 w-3/5" />
        </div>
      </div>
      <UEmpty
        v-else-if="0 === bHistory.length && !historyError"
        size="sm"
        variant="naked"
        icon="i-lucide-history"
        title="No recent updates"
        description="No local watch-history records match this backend."
      />
      <RecentHistoryCards
        v-if="0 < bHistory.length"
        :key="`${apiUser}:${backend}`"
        :items="recentCards"
        :user="apiUser"
        :poster-enable="posterEnable"
        timestamp-label="Updated"
        show-state
        aria-label="Recently updated records related to this backend"
      >
        <template #details="{ item }">
          <p
            v-if="recentDetails[item.history_id]?.via"
            class="line-clamp-2 text-[11px] leading-4 text-dimmed"
          >
            Via
            <NuxtLink
              :to="`/backend/${recentDetails[item.history_id]?.via}`"
              class="hover:text-primary"
            >
              {{ recentDetails[item.history_id]?.via }}
            </NuxtLink>
          </p>
          <p
            v-if="recentDetails[item.history_id]?.event"
            class="flex items-center gap-1.5 text-xs text-toned"
          >
            <UIcon name="i-lucide-mail" class="size-3.5 shrink-0" />
            <span class="sr-only">Event:</span>
            {{ recentDetails[item.history_id]?.event }}
          </p>
        </template>
      </RecentHistoryCards>
    </section>

    <section aria-labelledby="backend-tools-heading" class="space-y-4">
      <h2 id="backend-tools-heading" class="text-lg font-semibold text-highlighted">
        Backend tools
      </h2>
      <div class="grid gap-6 lg:grid-cols-[minmax(0,1.25fr)_minmax(0,1fr)]">
        <div class="overflow-hidden rounded-xl border border-default">
          <div class="border-b border-default bg-elevated/20 px-4 py-3">
            <h3 class="text-sm font-medium text-highlighted">Browse backend</h3>
          </div>
          <NuxtLink
            :to="`/backend/${backend}/search`"
            class="group flex items-center gap-3 border-b border-default px-4 py-4 transition-colors hover:bg-elevated/40"
          >
            <UIcon name="i-lucide-search" class="size-5 shrink-0 text-toned" />
            <div class="min-w-0 flex-1 space-y-1">
              <p class="text-sm font-semibold text-highlighted group-hover:text-primary">Search</p>
              <p class="text-sm text-toned">Find movies &amp; episodes on {{ backend }}.</p>
            </div>
            <UIcon name="i-lucide-arrow-right" class="size-4 shrink-0 text-toned" />
          </NuxtLink>
          <div class="flex flex-wrap gap-2 p-4">
            <UButton
              :to="`/backend/${backend}/libraries`"
              color="neutral"
              variant="ghost"
              icon="i-lucide-library-big"
            >
              Libraries
            </UButton>
            <UButton
              :to="`/backend/${backend}/users`"
              color="neutral"
              variant="ghost"
              icon="i-lucide-users"
            >
              Users
            </UButton>
            <UButton
              :to="`/backend/${backend}/sessions`"
              color="neutral"
              variant="ghost"
              icon="i-lucide-monitor-play"
            >
              Sessions
            </UButton>
          </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-default">
          <div class="border-b border-default bg-elevated/20 px-4 py-3">
            <h3 class="text-sm font-medium text-highlighted">Review matches</h3>
          </div>
          <NuxtLink
            :to="`/backend/${backend}/unmatched`"
            class="group flex items-center gap-3 border-b border-default px-4 py-4 transition-colors hover:bg-elevated/40"
          >
            <UIcon name="i-lucide-unlink" class="size-4 shrink-0 text-toned" />
            <div class="min-w-0 flex-1 space-y-1">
              <p class="text-sm font-semibold text-highlighted group-hover:text-primary">
                Unmatched
              </p>
              <p class="text-sm text-toned">Items without recognized metadata GUIDs.</p>
            </div>
            <UIcon name="i-lucide-chevron-right" class="size-4 shrink-0 text-toned" />
          </NuxtLink>
          <NuxtLink
            :to="`/backend/${backend}/mismatched`"
            class="group flex items-center gap-3 px-4 py-4 transition-colors hover:bg-elevated/40"
          >
            <UIcon name="i-lucide-scan-search" class="size-4 shrink-0 text-toned" />
            <div class="min-w-0 flex-1 space-y-1">
              <p class="text-sm font-semibold text-highlighted group-hover:text-primary">
                Misidentified
              </p>
              <p class="text-sm text-toned">Check titles against file &amp; folder names.</p>
            </div>
            <UIcon name="i-lucide-chevron-right" class="size-4 shrink-0 text-toned" />
          </NuxtLink>
        </div>
      </div>
    </section>

    <section
      aria-labelledby="connection-details-heading"
      :aria-busy="infoLoading"
      class="overflow-hidden rounded-xl border border-default"
    >
      <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-5">
        <h2 id="connection-details-heading" class="text-sm font-medium text-highlighted">
          Connection details
        </h2>
        <div v-if="info" class="flex items-center gap-2">
          <UButton
            color="neutral"
            :variant="showRawInfo ? 'soft' : 'outline'"
            size="xs"
            :icon="showRawInfo ? 'i-lucide-chevron-up' : 'i-lucide-code'"
            :aria-expanded="showRawInfo"
            aria-controls="raw-backend-info"
            @click="showRawInfo = !showRawInfo"
          >
            {{ showRawInfo ? 'Hide raw info' : 'Raw info' }}
          </UButton>
          <UButton
            color="neutral"
            variant="outline"
            size="xs"
            icon="i-lucide-copy"
            @click="copyText(JSON.stringify(info, null, 2))"
          >
            Copy raw
          </UButton>
        </div>
      </div>

      <div
        v-if="infoError"
        role="alert"
        class="flex flex-wrap items-center justify-between gap-3 border-t border-default px-4 py-3 sm:px-5"
      >
        <div class="min-w-0 space-y-1">
          <p class="text-sm font-medium text-highlighted">
            {{
              info ? 'Connection details could not be refreshed' : 'Connection details unavailable'
            }}
          </p>
          <p class="wrap-break-word text-sm text-toned">{{ infoError }}</p>
          <p v-if="info" class="text-xs text-toned">These details may be out of date.</p>
        </div>
        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-refresh-cw"
          :loading="infoLoading"
          @click="void loadInfo()"
        >
          Retry
        </UButton>
      </div>
      <p
        v-if="infoLoading && !info"
        role="status"
        class="border-t border-default px-4 py-4 text-sm text-toned sm:px-5"
      >
        Loading connection details…
      </p>
      <dl
        v-if="info"
        class="grid gap-x-6 gap-y-4 border-t border-default px-4 py-4 sm:grid-cols-2 sm:px-5 xl:grid-cols-4"
      >
        <div v-for="detail in backendInfoDetails" :key="detail.label" class="min-w-0 space-y-1">
          <dt class="text-xs text-toned">{{ detail.label }}</dt>
          <dd class="wrap-break-word text-sm font-medium text-highlighted">{{ detail.value }}</dd>
        </div>
      </dl>
      <div
        v-if="info && showRawInfo"
        id="raw-backend-info"
        class="border-t border-default bg-elevated/30"
      >
        <pre
          class="ws-terminal ws-terminal-panel whitespace-pre-wrap"
        ><code>{{ JSON.stringify(info, null, 2) }}</code></pre>
      </div>
    </section>

    <UModal
      :open="editBackendOpen"
      title="Edit backend"
      :ui="backendEditModalUi"
      @update:open="handleBackendEditOpenChange"
    >
      <template #body>
        <BackendEditForm
          v-if="editBackendOpen"
          :key="`${apiUser}:${backend}`"
          :backend-name="backend"
          :actions-target="`#${backendEditFooterId}`"
          @saved="void handleBackendEdited()"
          @dirty-change="(dirty) => (backendEditDirty = dirty)"
        />
      </template>
      <template #footer>
        <div :id="backendEditFooterId" class="w-full" />
      </template>
    </UModal>
  </div>
</template>

<script setup lang="ts">
import moment from 'moment';
import { computed, onBeforeUnmount, ref, shallowRef, useId, watch } from 'vue';
import { useHead, useRoute } from '#app';
import { useStorage } from '@vueuse/core';
import { NuxtLink, UButton, UEmpty, UIcon, UModal, USkeleton } from '#components';
import BackendEditForm from '~/components/BackendEditForm.vue';
import PageHeader from '~/components/PageHeader.vue';
import RecentHistoryCards from '~/components/RecentHistoryCards.vue';
import { useDirtyCloseGuard } from '~/composables/useDirtyCloseGuard';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import {
  copyText,
  notification,
  parse_api_response,
  request,
  TOOLTIP_DATE_FORMAT,
  ucFirst,
} from '~/utils';
import type { GenericError, HistoryItem, JsonObject, RecentHistoryCard } from '~/types';

const route = useRoute();
const backend = computed(() => String(route.params.backend || ''));
const apiUser = useStorage<string>('api_user', 'main');
const posterEnable = useStorage<boolean>('poster_enable', true);
const pageShell = requireTopLevelPageShell('backends');
const backendEditFooterId = useId();
const bHistory = shallowRef<Array<HistoryItem>>([]);
const info = shallowRef<JsonObject | null>(null);
const infoLoading = ref(true);
const historyLoading = ref(true);
const infoError = ref('');
const historyError = ref('');
const editBackendOpen = ref(false);
const backendEditDirty = ref(false);
const showRawInfo = ref(false);
let infoLoadToken = 0;
let historyLoadToken = 0;

useHead(() => ({ title: `Backends: ${backend.value}` }));

const backendEditModalUi = {
  content: 'max-w-6xl',
  body: 'p-4 sm:p-5',
};

const historyLink = computed(() => ({
  path: '/history/',
  query: {
    perpage: '50',
    page: '1',
    q: `${backend.value}.via://${backend.value}`,
    key: 'metadata',
  },
}));

const recentCards = computed<Array<RecentHistoryCard>>(() =>
  bHistory.value.map((item) => ({
    history_id: item.id,
    type: 'episode' === item.type ? 'episode' : 'movie',
    title: item.title || 'Untitled record',
    year: item.year ?? null,
    season: item.season ?? null,
    episode: item.episode ?? null,
    timestamp: item.updated || item.updated_at || null,
    timestampTooltip: `Record updated at: ${moment.unix(item.updated || item.updated_at).format(TOOLTIP_DATE_FORMAT.value)}`,
    progress: Number(item.progress ?? 0),
    watched: item.watched,
  })),
);

const recentDetails = computed<Record<number, Pick<HistoryItem, 'via' | 'event'>>>(() =>
  Object.fromEntries(bHistory.value.map((item) => [item.id, { via: item.via, event: item.event }])),
);

const backendInfoDetails = computed(() => {
  if (!info.value) {
    return [];
  }

  return [
    { label: 'Type', value: ucFirst(String(info.value.type || 'Unknown')) },
    { label: 'Server name', value: String(info.value.name || 'Unknown') },
    { label: 'Version', value: String(info.value.version || 'Unknown') },
    { label: 'Platform', value: String(info.value.platform || 'Unknown') },
    { label: 'Identifier', value: String(info.value.identifier || 'Unknown') },
  ];
});

const { handleOpenChange: handleBackendEditOpenChange } = useDirtyCloseGuard(editBackendOpen, {
  dirty: backendEditDirty,
  onDiscard: () => {
    backendEditDirty.value = false;
  },
});

const loadRecentHistory = async (): Promise<void> => {
  const loadToken = ++historyLoadToken;
  const selectedBackend = backend.value;
  const selectedUser = apiUser.value;
  const isCurrent = () =>
    loadToken === historyLoadToken &&
    selectedBackend === backend.value &&
    selectedUser === apiUser.value;
  historyLoading.value = true;
  historyError.value = '';

  const search = new URLSearchParams({
    perpage: '8',
    key: 'metadata',
    q: `${selectedBackend}.via://${selectedBackend}`,
    sort: 'updated_at:desc',
  });

  try {
    const response = await request(`/history/?${search.toString()}`, {
      headers: { 'X-User': selectedUser },
    });
    const json = await parse_api_response<{ history: Array<HistoryItem> }>(response);
    if (false === isCurrent()) {
      return;
    }

    if ('error' in json) {
      throw new Error(`${json.error.code}: ${json.error.message}`);
    }

    if (false === response.ok) {
      throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    }

    bHistory.value = json.history;
  } catch (cause: unknown) {
    if (isCurrent()) {
      historyError.value = cause instanceof Error ? cause.message : String(cause);
      notification('error', 'Recent updates', historyError.value);
    }
  } finally {
    if (isCurrent()) {
      historyLoading.value = false;
    }
  }
};

const loadInfo = async (): Promise<void> => {
  const loadToken = ++infoLoadToken;
  const selectedBackend = backend.value;
  const selectedUser = apiUser.value;
  const isCurrent = () =>
    loadToken === infoLoadToken &&
    selectedBackend === backend.value &&
    selectedUser === apiUser.value;
  infoLoading.value = true;
  infoError.value = '';

  try {
    const response = await request(`/backend/${selectedBackend}/info`, {
      headers: { 'X-User': selectedUser },
    });
    const json = await parse_api_response<JsonObject>(response);
    if (false === isCurrent()) {
      return;
    }

    if ('error' in json) {
      const error = json as GenericError;
      throw new Error(`${error.error.code}: ${error.error.message}`);
    }

    if (false === response.ok) {
      throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    }

    info.value = json;
  } catch (cause: unknown) {
    if (isCurrent()) {
      infoError.value = cause instanceof Error ? cause.message : String(cause);
      notification('error', 'Connection details', infoError.value);
    }
  } finally {
    if (isCurrent()) {
      infoLoading.value = false;
    }
  }
};

const handleBackendEdited = async (): Promise<void> => {
  backendEditDirty.value = false;
  editBackendOpen.value = false;
  await Promise.all([loadInfo(), loadRecentHistory()]);
};

watch(
  [backend, apiUser],
  async () => {
    info.value = null;
    bHistory.value = [];
    infoError.value = '';
    historyError.value = '';
    showRawInfo.value = false;
    editBackendOpen.value = false;
    backendEditDirty.value = false;
    await Promise.all([loadInfo(), loadRecentHistory()]);
  },
  { immediate: true, flush: 'sync' },
);

onBeforeUnmount(() => {
  ++infoLoadToken;
  ++historyLoadToken;
});
</script>
