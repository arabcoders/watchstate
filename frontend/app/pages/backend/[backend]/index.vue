<template>
  <div class="space-y-6">
    <UAlert
      v-if="isLoading"
      color="info"
      variant="soft"
      icon="i-lucide-loader-circle"
      title="Loading"
      description="Loading backend settings. Please wait..."
      :ui="{ icon: 'animate-spin' }"
    />

    <UAlert
      v-else-if="isError"
      color="warning"
      variant="soft"
      icon="i-lucide-triangle-alert"
      title="Warning"
    >
      <template #description>
        <div class="space-y-3 text-sm text-default">
          <p>There was error loading your backend data. Please try again later.</p>
          <pre
            v-if="error"
            class="overflow-x-auto rounded-md border border-default bg-elevated/60 p-3"
          ><code>{{ error }}</code></pre>
        </div>
      </template>
    </UAlert>

    <template v-else>
      <UModal
        :open="editBackendOpen"
        title="Edit Backend"
        :ui="backendEditModalUi"
        @update:open="handleBackendEditOpenChange"
      >
        <template #body>
          <BackendEditForm
            v-if="editBackendOpen"
            :backend-name="backend"
            @close="() => void requestCloseBackendEdit()"
            @saved="() => void handleBackendEdited()"
            @dirty-change="(dirty) => (backendEditDirty = dirty)"
          />
        </template>
      </UModal>

      <section class="space-y-4">
        <PageHeader v-bind="pageShell">
          <template #kicker>
            <span>{{ pageShell.sectionLabel }}</span>
            <span>/</span>
            <NuxtLink to="/backends" class="hover:text-primary">{{ pageShell.pageLabel }}</NuxtLink>
            <span>/</span>
            <span class="text-highlighted normal-case tracking-normal">{{ backend }}</span>
          </template>

          <template #actions>
            <UTooltip text="Delete Backend">
              <UButton
                :to="`/backend/${backend}/delete`"
                color="neutral"
                variant="outline"
                icon="i-lucide-trash-2"
                aria-label="Delete backend"
              >
                <span class="hidden sm:inline">Delete</span>
              </UButton>
            </UTooltip>

            <UTooltip text="Edit Backend">
              <UButton
                color="neutral"
                variant="outline"
                icon="i-lucide-pencil"
                aria-label="Edit backend"
                @click="editBackendOpen = true"
              >
                <span class="hidden sm:inline">Edit</span>
              </UButton>
            </UTooltip>
          </template>
        </PageHeader>
      </section>

      <section v-if="0 === bHistory.length">
        <UAlert
          color="warning"
          variant="soft"
          icon="i-lucide-triangle-alert"
          title="No items were found"
          description="There are probably no items in the local database yet or the backend data not imported yet."
        />
      </section>

      <section v-else class="space-y-4">
        <div class="space-y-1">
          <div
            class="flex items-center gap-2 text-xs font-medium uppercase tracking-[0.2em] text-toned"
          >
            <UIcon name="i-lucide-history" class="size-4" />
            <span>Recent Activity</span>
          </div>
        </div>

        <div class="grid gap-4 xl:grid-cols-2">
          <UCard
            v-for="item in bHistory"
            :key="item.id"
            class="h-full shadow-sm"
            :class="item.watched ? 'ring-1 ring-success/20' : ''"
            :ui="historyCardUi"
          >
            <template #header>
              <div class="flex items-start gap-3">
                <div class="min-w-0 flex-1">
                  <div
                    class="flex min-w-0 items-start gap-2 text-base font-semibold leading-6 text-highlighted"
                  >
                    <UIcon
                      :name="'episode' === item.type ? 'i-lucide-tv' : 'i-lucide-film'"
                      class="mt-0.5 size-4 shrink-0 text-toned"
                    />

                    <div class="min-w-0 flex-1">
                      <FloatingImage
                        v-if="poster_enable"
                        :image="`/history/${item.id}/images/poster`"
                      >
                        <UTooltip
                          :text="
                            String(item?.full_title || makeName(item as unknown as JsonObject))
                          "
                        >
                          <NuxtLink
                            :to="`/history/${item.id}`"
                            class="block truncate text-highlighted hover:text-primary"
                          >
                            {{ item?.full_title || makeName(item as unknown as JsonObject) }}
                          </NuxtLink>
                        </UTooltip>
                      </FloatingImage>

                      <UTooltip
                        v-else
                        :text="String(item?.full_title || makeName(item as unknown as JsonObject))"
                      >
                        <NuxtLink
                          :to="`/history/${item.id}`"
                          class="block truncate text-highlighted hover:text-primary"
                        >
                          {{ item?.full_title || makeName(item as unknown as JsonObject) }}
                        </NuxtLink>
                      </UTooltip>
                    </div>
                  </div>
                </div>
              </div>
            </template>

            <div class="grid grid-cols-2 gap-3 xl:grid-cols-3">
              <div
                class="flex items-center justify-center gap-2 rounded-md border border-default bg-elevated/40 px-3 py-2 text-center text-sm font-medium text-default"
              >
                <UIcon name="i-lucide-calendar" class="size-4 shrink-0 text-toned" />
                <UTooltip
                  :text="`Updated at: ${moment.unix(item.updated || item.updated_at).format(TOOLTIP_DATE_FORMAT)}`"
                >
                  <span class="cursor-help">{{
                    moment.unix(item.updated || item.updated_at).fromNow()
                  }}</span>
                </UTooltip>
              </div>

              <div
                class="flex items-center justify-center gap-2 rounded-md border border-default bg-elevated/40 px-3 py-2 text-center text-sm font-medium text-default"
              >
                <UIcon name="i-lucide-server" class="size-4 shrink-0 text-toned" />
                <NuxtLink :to="'/backend/' + item.via" class="hover:text-primary">{{
                  item.via
                }}</NuxtLink>
              </div>

              <div
                class="col-span-2 flex items-center justify-center gap-2 rounded-md border border-default bg-elevated/40 px-3 py-2 text-center text-sm font-medium text-default xl:col-span-1"
              >
                <UIcon name="i-lucide-mail" class="size-4 shrink-0 text-toned" />
                <span>{{ item.event }}</span>
              </div>
            </div>

            <template v-if="item.progress" #footer>
              <div class="flex items-center justify-center gap-6 border-t border-default pt-4">
                <UBadge :color="item.watched ? 'success' : 'error'" variant="soft">
                  {{ item.watched ? 'Played' : 'Unplayed' }}
                </UBadge>

                <span class="text-sm font-medium text-default">
                  {{
                    formatDuration(
                      typeof item.progress === 'number'
                        ? item.progress
                        : parseInt(String(item.progress), 10) || 0,
                    )
                  }}
                </span>
              </div>
            </template>
          </UCard>
        </div>

        <div>
          <NuxtLink
            :to="`/history/?perpage=50&page=1&q=${backend}.via://${backend}&key=metadata`"
            class="inline-flex items-center gap-2 text-sm font-medium text-primary"
          >
            <UIcon name="i-lucide-history" class="size-4" />
            <span>View all history related to this backend</span>
          </NuxtLink>
        </div>
      </section>

      <section v-if="info">
        <UCard class="shadow-sm" :ui="infoCardUi">
          <template #header>
            <div class="space-y-2">
              <div class="flex items-start justify-between gap-3">
                <div class="min-w-0 flex-1">
                  <div class="flex items-center gap-2">
                    <UIcon name="i-lucide-file-code-2" class="size-5 text-toned" />
                    <span class="font-semibold text-highlighted">Basic Info</span>
                  </div>
                </div>

                <div class="flex shrink-0 flex-wrap items-center gap-2">
                  <UButton
                    color="neutral"
                    :variant="showRawInfo ? 'soft' : 'outline'"
                    size="sm"
                    :icon="showRawInfo ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
                    @click="showRawInfo = !showRawInfo"
                  >
                    <span class="hidden sm:inline">{{
                      showRawInfo ? 'Hide Raw' : 'Show Raw'
                    }}</span>
                  </UButton>

                  <UTooltip text="Copy raw backend info">
                    <UButton
                      color="neutral"
                      variant="outline"
                      size="sm"
                      icon="i-lucide-copy"
                      @click="() => copyText(JSON.stringify(info, null, 2))"
                    >
                      <span class="hidden sm:inline">Copy Raw</span>
                    </UButton>
                  </UTooltip>
                </div>
              </div>

              <p class="text-sm text-toned">
                Connection identity and runtime details reported by the backend.
              </p>
            </div>
          </template>

          <div class="space-y-4">
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
              <div
                v-for="detail in backendInfoDetails"
                :key="detail.label"
                class="rounded-md border border-default bg-elevated/20 px-4 py-3"
              >
                <div
                  class="mb-1 inline-flex items-center gap-2 text-xs font-medium uppercase tracking-[0.16em] text-toned"
                >
                  <UIcon :name="detail.icon" class="size-4" />
                  <span>{{ detail.label }}</span>
                </div>
                <p class="break-all text-sm font-medium text-highlighted">{{ detail.value }}</p>
              </div>
            </div>

            <div
              v-if="showRawInfo"
              class="overflow-hidden rounded-md border border-default bg-elevated/60"
            >
              <code class="ws-terminal ws-terminal-panel whitespace-pre-wrap" v-text="info" />
            </div>
          </div>
        </UCard>
      </section>

      <section>
        <UCard class="shadow-sm" :ui="toolsCardUi">
          <template #header>
            <div class="space-y-1">
              <div class="flex items-center gap-2">
                <UIcon name="i-lucide-wrench" class="size-5 text-toned" />
                <span class="font-semibold text-highlighted">Useful Tools</span>
              </div>
              <p class="text-sm text-toned">
                Inspect content quality, browse backend data, and run targeted lookups.
              </p>
            </div>
          </template>

          <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <NuxtLink
              v-for="tool in backendTools"
              :key="tool.to"
              :to="tool.to"
              class="group rounded-md border border-default bg-elevated/20 p-4 transition hover:border-primary/40 hover:bg-elevated/40"
            >
              <div class="flex items-start gap-3">
                <span
                  class="inline-flex size-10 shrink-0 items-center justify-center rounded-md border border-default bg-elevated/40 text-toned transition group-hover:border-primary/30 group-hover:text-primary"
                >
                  <UIcon :name="tool.icon" class="size-4.5" />
                </span>

                <div class="min-w-0 space-y-1">
                  <div class="flex items-center gap-2">
                    <span class="text-sm font-semibold text-highlighted group-hover:text-primary">{{
                      tool.label
                    }}</span>
                  </div>
                  <p class="text-sm leading-5 text-toned">{{ tool.description }}</p>
                </div>
              </div>
            </NuxtLink>
          </div>
        </UCard>
      </section>
    </template>
  </div>
</template>

<script setup lang="ts">
import moment from 'moment';
import { onMounted, ref } from 'vue';
import { useHead, useRoute } from '#app';
import { useStorage } from '@vueuse/core';
import BackendEditForm from '~/components/BackendEditForm.vue';
import FloatingImage from '~/components/FloatingImage.vue';
import PageHeader from '~/components/PageHeader.vue';
import { useDirtyCloseGuard } from '~/composables/useDirtyCloseGuard';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import {
  copyText,
  formatDuration,
  makeName,
  parse_api_response,
  request,
  TOOLTIP_DATE_FORMAT,
  ucFirst,
} from '~/utils';
import type { GenericError, HistoryItem, JsonObject } from '~/types';

const poster_enable = useStorage('poster_enable', true);

const backend = ref<string>(useRoute().params.backend as string);
const pageShell = requireTopLevelPageShell('backends');
const bHistory = ref<Array<HistoryItem>>([]);
const info = ref<JsonObject | null>(null);
const isLoading = ref<boolean>(true);
const isError = ref<boolean>(false);
const error = ref<string | null>(null);
const editBackendOpen = ref<boolean>(false);
const backendEditDirty = ref<boolean>(false);
const showRawInfo = ref<boolean>(false);

const historyCardUi = {
  header: 'p-5',
  body: 'px-5 pb-5 pt-0',
  footer: 'px-5 pb-5 pt-0',
};

const infoCardUi = {
  header: 'p-5',
  body: 'p-5 pt-0',
};

const toolsCardUi = {
  header: 'p-5',
  body: 'px-5 pb-5 pt-0',
};

const backendEditModalUi = {
  content: 'max-w-6xl',
  body: 'p-4 sm:p-5',
};

const backendTools = computed<
  Array<{ to: string; label: string; description: string; icon: string }>
>(() => [
  {
    to: `/backend/${backend.value}/search`,
    label: 'Search Content',
    description: 'Find specific movies, episodes, and metadata directly on this backend.',
    icon: 'i-lucide-search',
  },
  {
    to: `/backend/${backend.value}/libraries`,
    label: 'Libraries',
    description: 'Browse the libraries exposed by this backend and inspect their identifiers.',
    icon: 'i-lucide-library-big',
  },
  {
    to: `/backend/${backend.value}/users`,
    label: 'Users',
    description: 'Inspect users known to this backend and their available account metadata.',
    icon: 'i-lucide-users',
  },
  {
    to: `/backend/${backend.value}/sessions`,
    label: 'Sessions',
    description: 'Review active playback sessions currently reported by the backend.',
    icon: 'i-lucide-monitor-play',
  },
  {
    to: `/backend/${backend.value}/unmatched`,
    label: 'Unmatched',
    description: 'Review backend items that still cannot be matched to local history records.',
    icon: 'i-lucide-unlink',
  },
  {
    to: `/backend/${backend.value}/mismatched`,
    label: 'Mismatched',
    description: 'Audit possible mis-identified items where local and backend metadata disagree.',
    icon: 'i-lucide-scan-search',
  },
]);

const backendInfoDetails = computed<Array<{ label: string; value: string; icon: string }>>(() => {
  if (!info.value) {
    return [];
  }

  return [
    {
      label: 'Type',
      value: ucFirst(String(info.value.type || 'Unknown')),
      icon: 'i-lucide-server',
    },
    {
      label: 'Server Name',
      value: String(info.value.name || 'Unknown'),
      icon: 'i-lucide-badge-info',
    },
    {
      label: 'Version',
      value: String(info.value.version || 'Unknown'),
      icon: 'i-lucide-box',
    },
    {
      label: 'Platform',
      value: String(info.value.platform || 'Unknown'),
      icon: 'i-lucide-monitor-smartphone',
    },
    {
      label: 'Identifier',
      value: String(info.value.identifier || 'Unknown'),
      icon: 'i-lucide-fingerprint',
    },
  ];
});

const { handleOpenChange: handleBackendEditOpenChange, requestClose: requestCloseBackendEdit } =
  useDirtyCloseGuard(editBackendOpen, {
    dirty: backendEditDirty,
    onDiscard: async () => {
      backendEditDirty.value = false;
    },
  });

const loadRecentHistory = async (): Promise<void> => {
  if (!backend.value) {
    return;
  }
  const search = new URLSearchParams();
  search.append('perpage', '6');
  search.append('key', 'metadata');
  search.append('q', `${backend.value}.via://${backend.value}`);
  search.append('sort', 'updated_at:desc');

  try {
    const response = await request(`/history/?${search.toString()}`);
    const json = await parse_api_response<{
      /** Array of history items */
      history: Array<HistoryItem>;
      /** Total number of items available */
      total?: number;
      /** Current page number */
      page?: number;
      /** Items per page */
      perpage?: number;
    }>(response);
    if ('backend-backend' !== useRoute().name) {
      return;
    }

    if (response.ok && 'error' in json) {
      return;
    }

    if (response.ok && 'history' in json) {
      bHistory.value = json.history;
    }
  } catch {
    // Silently handle errors for this non-critical operation
  }
};

const loadInfo = async (): Promise<void> => {
  try {
    isLoading.value = false;
    const response = await request(`/backend/${backend.value}/info`);
    const json = await parse_api_response<JsonObject>(response);

    if ('backend-backend' !== useRoute().name) {
      return;
    }

    if ('error' in json) {
      const errorJson = json as GenericError;
      isError.value = true;
      error.value = `${errorJson.error.code}: ${errorJson.error.message}`;
      backend.value = '';
      return;
    }

    info.value = json;

    if (200 !== response.status) {
      isError.value = true;
      error.value = 'Unknown error occurred';
      backend.value = '';
      return;
    }
    await loadRecentHistory();
    useHead({ title: `Backends: ${backend.value}` });
  } catch (e) {
    const message = e instanceof Error ? e.message : String(e);
    error.value = message;
    isError.value = true;
  } finally {
    isLoading.value = false;
  }
};

const handleBackendEdited = async (): Promise<void> => {
  backendEditDirty.value = false;
  editBackendOpen.value = false;
  await loadInfo();
};

onMounted(async () => await loadInfo());
</script>
