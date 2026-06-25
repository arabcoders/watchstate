<template>
  <div class="space-y-6">
    <PageHeader
      v-bind="pageShell"
      description="This page will show records with files that no longer exist on the system."
    >
      <template #actions>
        <template v-if="isLoaded">
          <UInput
            v-if="showFilter"
            id="filter"
            v-model.lazy="filter"
            type="search"
            icon="i-lucide-filter"
            placeholder="Filter displayed results."
            size="sm"
            class="w-full sm:w-72"
          />

          <UButton
            color="neutral"
            :variant="showFilter ? 'soft' : 'outline'"
            size="sm"
            icon="i-lucide-filter"
            @click="toggleFilter"
          >
            <span class="hidden sm:inline">Filter</span>
          </UButton>

          <UButton
            color="neutral"
            :variant="selectAll ? 'soft' : 'outline'"
            size="sm"
            :icon="!selectAll ? 'i-lucide-square-check' : 'i-lucide-square'"
            @click="selectAll = !selectAll"
          >
            <span class="hidden sm:inline">{{ !selectAll ? 'Select' : 'Unselect' }}</span>
          </UButton>

          <UTooltip v-if="isCached" text="Empty cache.">
            <UButton
              color="neutral"
              variant="outline"
              size="sm"
              icon="i-lucide-archive"
              :disabled="isDeleting || isLoading"
              @click="emptyCache"
            >
              <span class="hidden sm:inline">Empty Cache</span>
            </UButton>
          </UTooltip>

          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-refresh-cw"
            :loading="isLoading"
            :disabled="isLoading"
            @click.prevent="loadContent"
          >
            <span class="hidden sm:inline">Reload</span>
          </UButton>
        </template>
      </template>
    </PageHeader>

    <div
      v-if="selected_ids.length > 0"
      class="flex flex-wrap items-center justify-between gap-3 rounded-md border border-default bg-elevated/30 px-3 py-3"
    >
      <div class="flex flex-wrap items-center gap-2">
        <UBadge color="neutral" variant="soft" size="sm">{{ selected_ids.length }}</UBadge>

        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-trash-2"
          :loading="isDeleting"
          :disabled="isDeleting"
          @click="massDelete"
        >
          Delete
        </UButton>
      </div>

      <div class="text-xs text-toned">{{ filteredRows(items).length }} displayed</div>
    </div>

    <template v-if="!isLoaded">
      <UCard :ui="startCardUi">
        <template #header>
          <div class="text-center text-base font-semibold text-highlighted">
            Request File integrity check.
          </div>
        </template>

        <div class="space-y-4 text-sm leading-6 text-default">
          <ul class="list-disc space-y-2 pl-5">
            <li>
              Please be aware, this process will take time. You will see the spinner while
              <code>WatchState</code> is analyzing the entire history records. Do not reload the
              page.
            </li>
            <li>
              This check <strong><code>REQUIRES</code></strong> that the file contents be accessible
              to <code>WatchState</code>. You should mount your library in <code>compose.yml</code>
              file as readonly.
              <strong>If you do not mount your library. every record will fail the check.</strong>
            </li>
            <li>There are no path replacement support.</li>
            <li>
              This process will do two checks, One will do dir stat on the file directory, and file
              stat on the file itself if the directory exists.
              <span class="text-error">
                If you are using cloud storage, we do not recommend to use this feature as it can
                cause a lot of requests to the storage provider which can lead to additional costs.
              </span>
            </li>
            <li>
              The process caches the file and dir stat, as such we only run stat once per file or
              directory no matter how many backends reports the same path or file.
            </li>
            <li>The results are cached server side for one hour from the request.</li>
          </ul>
        </div>

        <template #footer>
          <div class="flex justify-end">
            <UButton
              color="primary"
              icon="i-lucide-circle-check"
              :disabled="isLoading"
              @click="loadContent"
            >
              Initiate The process
            </UButton>
          </div>
        </template>
      </UCard>
    </template>

    <template v-else>
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
        v-else-if="filter && filteredRows(items).length < 1"
        color="warning"
        variant="soft"
        icon="i-lucide-circle-check"
        title="Information"
      >
        <template #description>
          <p class="text-sm text-default">
            The filter <code>{{ filter }}</code> did not match any records.
          </p>
        </template>
      </UAlert>

      <UAlert
        v-else-if="items.length < 1"
        color="success"
        variant="soft"
        icon="i-lucide-circle-check"
        title="Success"
        description="WatchState did not find any file references that are no longer on the system."
      />

      <div v-else class="grid gap-4 xl:grid-cols-2">
        <Lazy
          v-for="item in items"
          v-show="filterItem(item)"
          :key="item.id"
          :unrender="true"
          :min-height="343"
          class="block"
        >
          <UCard
            class="h-full shadow-sm"
            :class="item.watched ? 'ring-1 ring-success/20' : ''"
            :ui="itemCardUi"
          >
            <template #header>
              <div class="flex items-start justify-between gap-3">
                <div class="min-w-0 flex flex-1 items-start gap-2">
                  <UIcon
                    :name="'episode' === item.type.toLowerCase() ? 'i-lucide-tv' : 'i-lucide-film'"
                    class="mt-0.5 size-4 shrink-0 text-toned"
                  />

                  <div class="min-w-0 flex-1 text-base font-semibold text-highlighted">
                    <FloatingImage
                      :image="`/history/${item.id}/images/poster`"
                      v-if="poster_enable"
                    >
                      <UTooltip :text="String(makeName(item))">
                        <NuxtLink
                          :to="`/history/${item.id}`"
                          class="block truncate text-highlighted hover:text-primary"
                          >{{ makeName(item) }}</NuxtLink
                        >
                      </UTooltip>
                    </FloatingImage>
                    <UTooltip v-else :text="String(makeName(item))">
                      <NuxtLink
                        :to="`/history/${item.id}`"
                        class="block truncate text-highlighted hover:text-primary"
                        >{{ makeName(item) }}</NuxtLink
                      >
                    </UTooltip>
                  </div>
                </div>

                <UTooltip :text="selected_ids.includes(item.id) ? 'Unselect item' : 'Select item'">
                  <UCheckbox
                    color="primary"
                    :model-value="selected_ids.includes(item.id)"
                    @update:model-value="toggleSelected(item.id, $event)"
                  />
                </UTooltip>
              </div>
            </template>

            <div class="space-y-3">
              <div
                class="flex items-start gap-2 rounded-md border border-default bg-elevated/20 px-3 py-2 text-sm text-default"
              >
                <button
                  type="button"
                  class="mt-0.5 shrink-0 text-toned hover:text-primary"
                  @click="item.expand_title = !item?.expand_title"
                >
                  <UIcon name="i-lucide-heading" class="size-4" />
                </button>
                <div
                  class="min-w-0 flex-1"
                  :class="item?.expand_title ? 'wrap-break-word' : 'truncate'"
                >
                  <NuxtLink
                    :to="makeSearchLink('subtitle', item?.content_title || item.title)"
                    class="hover:text-primary"
                  >
                    {{ item?.content_title || item.title }}
                  </NuxtLink>
                </div>
                <UButton
                  color="neutral"
                  variant="ghost"
                  size="xs"
                  square
                  icon="i-lucide-copy"
                  @click="copyText(item?.content_title ?? item.title, false)"
                />
              </div>

              <div
                class="flex items-start gap-2 rounded-md border border-default bg-elevated/20 px-3 py-2 text-sm text-default"
              >
                <button
                  type="button"
                  class="mt-0.5 shrink-0 text-toned hover:text-primary"
                  @click="item.expand_path = !item?.expand_path"
                >
                  <UIcon name="i-lucide-file-text" class="size-4" />
                </button>
                <div
                  class="min-w-0 flex-1"
                  :class="item?.expand_path ? 'wrap-break-word' : 'truncate'"
                >
                  <NuxtLink
                    v-if="item?.content_path"
                    :to="makeSearchLink('path', item.content_path)"
                    class="hover:text-primary"
                  >
                    {{ item.content_path }}
                  </NuxtLink>
                  <span v-else>No path found.</span>
                </div>
                <UButton
                  color="neutral"
                  variant="ghost"
                  size="xs"
                  square
                  icon="i-lucide-copy"
                  @click="copyText(item?.content_path ? item.content_path : '', false)"
                />
              </div>

              <div
                class="rounded-md border border-default bg-elevated/20 px-3 py-2 text-sm text-default"
              >
                <div class="mb-2 flex items-center gap-2 font-medium text-highlighted">
                  <UIcon name="i-lucide-server" class="size-4 text-toned" />
                  <span>Has metadata from</span>
                </div>
                <div class="flex flex-wrap gap-2">
                  <NuxtLink
                    v-for="reportedBackend in item.reported_by"
                    :key="`${item.id}-rb-${reportedBackend}`"
                    :to="'/backend/' + reportedBackend"
                    class="inline-flex items-center gap-1.5 rounded-md border border-default bg-elevated/40 px-2.5 py-1 text-xs font-medium text-default"
                  >
                    <UIcon name="i-lucide-server" class="size-3.5" />
                    {{ reportedBackend }}
                  </NuxtLink>
                  <NuxtLink
                    v-for="missingBackend in item.not_reported_by"
                    :key="`${item.id}-rb-${missingBackend}`"
                    :to="'/backend/' + missingBackend"
                    class="inline-flex items-center gap-1.5 rounded-md border border-error/30 bg-error/10 px-2.5 py-1 text-xs font-medium text-error"
                  >
                    <UIcon name="i-lucide-circle-alert" class="size-3.5" />
                    {{ missingBackend }}
                  </NuxtLink>
                </div>
              </div>

              <div
                v-if="item?.integrity"
                class="rounded-md border border-default bg-elevated/20 px-3 py-2 text-sm text-default"
              >
                <div class="mb-2 flex items-center gap-2 font-medium text-highlighted">
                  <UIcon name="i-lucide-file-text" class="size-4 text-toned" />
                  <span>File reference exists</span>
                </div>
                <div class="flex flex-wrap gap-2">
                  <UTooltip
                    v-for="record in item.integrity"
                    :key="`${item.id}-int-${record.backend}`"
                    :text="!record.status ? `${record.backend}: ${record.message}` : ''"
                  >
                    <NuxtLink
                      :to="'/backend/' + record.backend"
                      class="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-medium"
                      :class="
                        record.status
                          ? 'border border-default bg-elevated/40 text-default'
                          : 'border border-error/30 bg-error/10 text-error'
                      "
                    >
                      <UIcon
                        :name="record.status ? 'i-lucide-file-text' : 'i-lucide-circle-alert'"
                        class="size-3.5"
                      />
                      <span>{{ record.backend }}</span>
                    </NuxtLink>
                  </UTooltip>
                </div>
              </div>
            </div>

            <template #footer>
              <div class="grid grid-cols-2 gap-2">
                <div
                  class="flex items-center justify-center gap-2 rounded-md border border-default bg-elevated/40 px-3 py-2 text-sm text-default"
                >
                  <UIcon
                    :name="item.watched ? 'i-lucide-eye' : 'i-lucide-eye-off'"
                    class="size-4 text-toned"
                  />
                  <span :class="item.watched ? 'text-success' : 'text-error'">{{
                    item.watched ? 'Played' : 'Unplayed'
                  }}</span>
                </div>
                <div
                  class="flex items-center justify-center gap-2 rounded-md border border-default bg-elevated/40 px-3 py-2 text-sm text-default"
                >
                  <UIcon name="i-lucide-calendar" class="size-4 text-toned" />
                  <UTooltip
                    :text="`Record updated at: ${moment.unix(item.updated_at).format(TOOLTIP_DATE_FORMAT)}`"
                  >
                    <span class="cursor-help">{{ moment.unix(item.updated_at).fromNow() }}</span>
                  </UTooltip>
                </div>
              </div>
            </template>
          </UCard>
        </Lazy>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import { ref, watch, onMounted } from 'vue';
import { useHead, useRoute } from '#app';
import { useStorage } from '@vueuse/core';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import {
  request,
  awaitElement,
  copyText,
  makeName,
  makeSearchLink,
  notification,
  parse_api_response,
  TOOLTIP_DATE_FORMAT,
} from '~/utils';
import moment from 'moment';
import Lazy from '~/components/Lazy.vue';
import PageHeader from '~/components/PageHeader.vue';
import { useSessionCache } from '~/utils/cache';
import type { IntegrityItem } from '~/types';
import { useDialog } from '~/composables/useDialog';
import { NuxtLink } from '#components';
import FloatingImage from '~/components/FloatingImage.vue';

const pageShell = requireTopLevelPageShell('integrity');

type IntegrityItemWithUI = IntegrityItem & {
  expand_title?: boolean;
  expand_path?: boolean;
};

useHead({ title: 'File Integrity' });

const api_user = useStorage('api_user', 'main');
const poster_enable = useStorage('poster_enable', true);
const cache = useSessionCache(api_user.value);

const items = ref<Array<IntegrityItemWithUI>>([]);
const isLoading = ref<boolean>(false);
const isLoaded = ref<boolean>(false);
const selected_ids = ref<Array<string | number>>([]);
const isDeleting = ref<boolean>(false);
const filter = ref<string>('');
const showFilter = ref<boolean>(false);
const isCached = ref<boolean>(false);
const selectAll = ref<boolean>(false);
const startCardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
  footer: 'border-t border-default px-4 py-4',
};

const itemCardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
  footer: 'border-t border-default px-4 py-4',
};

watch(selectAll, (v) => {
  selected_ids.value = v ? filteredRows(items.value).map((i) => i.id) : [];
});

const toggleSelected = (id: string | number, value: boolean | 'indeterminate'): void => {
  if (true === value) {
    if (!selected_ids.value.includes(id)) {
      selected_ids.value.push(id);
    }
    return;
  }

  selected_ids.value = selected_ids.value.filter((itemId) => itemId !== id);
};

const toggleFilter = (): void => {
  showFilter.value = !showFilter.value;
  if (!showFilter.value) {
    filter.value = '';
    return;
  }
  awaitElement('#filter', (_, elm) => (elm as HTMLInputElement).focus());
};

const loadContent = async (): Promise<void> => {
  isLoaded.value = true;
  isLoading.value = true;
  items.value = [];
  selectAll.value = false;
  selected_ids.value = [];

  try {
    const response = await request(`/system/integrity`);
    const json = await parse_api_response<{
      items: Array<IntegrityItemWithUI>;
      fromCache?: boolean;
    }>(response);

    if ('integrity' !== useRoute().name) {
      return;
    }

    if (200 !== response.status) {
      if ('error' in json) {
        notification('error', 'Error', `API Error. ${json.error?.code}: ${json.error?.message}`);
      }

      isLoading.value = false;
      return;
    }

    if (!('items' in json)) {
      notification('error', 'Error', `API Error. Malformed response.`);
      isLoading.value = false;
      return;
    }

    if (json.items) {
      items.value = json.items;
    }

    isLoading.value = false;
    isCached.value = Boolean(json?.fromCache ?? false);

    cache.set('integrity', { items: items.value, fromCache: isCached.value });
  } catch (e: unknown) {
    if (e && typeof e === 'object' && 'message' in e) {
      notification('error', 'Error', `Request error. ${(e as Error).message}`);
    } else {
      notification('error', 'Error', 'Unknown error');
    }
  }
};

const massDelete = async (): Promise<void> => {
  if (0 === selected_ids.value.length) {
    return;
  }

  const { status: confirmStatus } = await useDialog().confirmDialog({
    title: 'Confirm Deletion',
    message: `Are you sure you want to delete '${selected_ids.value.length}' item/s?`,
    confirmColor: 'error',
  });

  if (true !== confirmStatus) {
    return;
  }

  try {
    isDeleting.value = true;

    const urls = selected_ids.value.map((id) => `/history/${id}`);

    notification(
      'success',
      'Action in progress',
      `Deleting '${urls.length}' item/s. Please wait...`,
    );

    const requests = await Promise.all(urls.map((url) => request(url, { method: 'DELETE' })));

    if (!requests.every((response) => 200 === response.status)) {
      notification(
        'error',
        'Error',
        `Some requests failed. Please check the console for more details.`,
      );
    } else {
      items.value = items.value.filter((i) => !selected_ids.value.includes(i.id));
    }

    notification('success', 'Success', `Deleting '${urls.length}' item/s completed.`);
  } catch (e: unknown) {
    if (e && typeof e === 'object' && 'message' in e) {
      notification('error', 'Error', `Request error. ${(e as Error).message}`);
    } else {
      notification('error', 'Error', 'Unknown error');
    }
  } finally {
    selected_ids.value = [];
    selectAll.value = false;
    isDeleting.value = false;
  }
};

const emptyCache = async (): Promise<void> => {
  const { status: confirmStatus } = await useDialog().confirmDialog({
    title: 'Confirm Cache Purge',
    message: `Are you sure you want to purge the file stats cache?`,
    confirmColor: 'error',
  });

  if (true !== confirmStatus) {
    return;
  }

  try {
    const response = await request(`/system/integrity`, { method: 'DELETE' });
    if (200 !== response.status) {
      const json = await response.json();
      return notification('error', 'Error', `API Error. ${json.error.code}: ${json.error.message}`);
    }

    items.value = [];
    isLoaded.value = false;
    isLoading.value = false;
    isCached.value = false;
    selectAll.value = false;
    selected_ids.value = [];
    if (cache.has('integrity')) {
      cache.remove('integrity');
    }

    notification('success', 'Success', `Cache purged.`);
  } catch (e: unknown) {
    if (e && typeof e === 'object' && 'message' in e) {
      notification('error', 'Error', `Request error. ${(e as Error).message}`);
    } else {
      notification('error', 'Error', 'Unknown error');
    }
  } finally {
    selected_ids.value = [];
    selectAll.value = false;
  }
};

const filteredRows = (items: Array<IntegrityItemWithUI>): Array<IntegrityItemWithUI> => {
  if (!filter.value) {
    return items;
  }
  return items.filter((i) =>
    Object.values(i).some((v) =>
      'string' === typeof v ? v.toLowerCase().includes(filter.value.toLowerCase()) : false,
    ),
  );
};

const filterItem = (item: IntegrityItemWithUI): boolean => {
  if (!filter.value || !item) {
    return true;
  }
  return Object.values(item).some((v) =>
    'string' === typeof v ? v.toLowerCase().includes(filter.value.toLowerCase()) : false,
  );
};

onMounted(() => {
  cache.setNameSpace(api_user.value);
  if (items.value.length < 1 && cache.has('integrity')) {
    const cachedData = cache.get('integrity') as {
      items: Array<IntegrityItemWithUI>;
      fromCache: boolean;
    };
    items.value = cachedData.items;
    isCached.value = cachedData.fromCache;
    isLoaded.value = true;
  }
});
</script>
