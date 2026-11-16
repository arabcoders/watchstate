<template>
  <div class="w-full min-w-0 max-w-full space-y-6">
    <section aria-label="Backend play sessions" :aria-busy="isLoading" class="space-y-4">
      <PageHeader v-bind="pageShell" page-label="Active sessions" description="">
        <template #kicker>
          <NuxtLink to="/backends" class="hover:text-primary">{{ pageShell.pageLabel }}</NuxtLink>
          <span>/</span>
          <NuxtLink
            :to="`/backend/${backend}`"
            class="hover:text-primary normal-case tracking-normal"
            >{{ backend }}</NuxtLink
          >
        </template>

        <template #actions>
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-refresh-cw"
            :loading="isLoading"
            :disabled="isLoading"
            aria-label="Refresh sessions"
            @click="loadContent"
          >
            Refresh
          </UButton>
        </template>
      </PageHeader>

      <UAlert
        v-if="1 > items.length && isLoading"
        color="info"
        variant="soft"
        icon="i-lucide-loader-circle"
        title="Loading active play sessions"
        :ui="{ icon: 'animate-spin' }"
      />

      <UAlert
        v-else-if="loadError"
        color="error"
        variant="soft"
        icon="i-lucide-triangle-alert"
        title="Couldn't load sessions"
        :description="loadError"
      />

      <UEmpty
        v-else-if="hasLoaded && 0 === items.length"
        icon="i-lucide-play-circle"
        title="No active play sessions"
      />

      <div v-else-if="0 < items.length" class="ws-card divide-y divide-default">
        <Lazy v-for="item in items" :key="item.id" :unrender="true" :min-height="112">
          <article
            :aria-labelledby="`session-${item.id}`"
            class="grid grid-cols-2 gap-4 p-4 sm:p-5 xl:grid-cols-[minmax(0,1.5fr)_minmax(0,0.8fr)_7rem_7rem_minmax(0,0.9fr)]"
          >
            <h2
              :id="`session-${item.id}`"
              class="col-span-2 min-w-0 text-base font-semibold text-highlighted xl:col-span-1"
            >
              <NuxtLink :to="makeItemLink(item)" class="wrap-anywhere hover:text-primary">
                {{ item.item_title }}
              </NuxtLink>
            </h2>

            <dl class="min-w-0 space-y-1 text-sm">
              <dt class="text-xs text-muted">User</dt>
              <dd class="wrap-anywhere">{{ item.user_name }}</dd>
            </dl>

            <dl class="min-w-0 space-y-1 text-sm">
              <dt class="text-xs text-muted">Playback</dt>
              <dd>
                <UBadge :color="sessionStateColor(item.session_state)" variant="soft">
                  <span class="inline-flex items-center gap-1">
                    <UIcon :name="sessionStateIcon(item.session_state)" class="size-3.5" />
                    <span>{{ sessionStateLabel(item.session_state) }}</span>
                  </span>
                </UBadge>
              </dd>
            </dl>

            <dl class="min-w-0 space-y-1 text-sm">
              <dt class="text-xs text-muted">Progress at</dt>
              <dd class="tabular-nums">{{ formatDuration(item.item_offset_at) }}</dd>
            </dl>

            <dl v-if="item.updated_at" class="min-w-0 space-y-1 text-sm">
              <dt class="text-xs text-muted">Updated</dt>
              <dd class="wrap-anywhere">
                <UTooltip :text="item.updated_at">
                  <span class="cursor-help">{{ item.updated_at }}</span>
                </UTooltip>
              </dd>
            </dl>
          </article>
        </Lazy>
      </div>
    </section>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRoute } from '#app';
import { NuxtLink, UAlert, UBadge, UButton, UEmpty, UIcon, UTooltip } from '#components';
import Lazy from '~/components/Lazy.vue';
import PageHeader from '~/components/PageHeader.vue';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import { formatDuration, notification, parse_api_response, request } from '~/utils';
import type { SessionItem } from '~/types';

const backend = useRoute().params.backend as string;
const pageShell = requireTopLevelPageShell('backends');
const items = ref<Array<SessionItem>>([]);
const isLoading = ref<boolean>(false);
const loadError = ref('');
const hasLoaded = ref(false);

const sessionStateLabel = (state: string): string => {
  const normalizedState = state.toLowerCase();

  if ('playing' === normalizedState) {
    return 'Playing';
  }

  if ('paused' === normalizedState) {
    return 'Paused';
  }

  if ('buffering' === normalizedState) {
    return 'Buffering';
  }

  return state;
};

const sessionStateColor = (state: string): 'neutral' | 'success' | 'warning' => {
  const normalizedState = state.toLowerCase();

  if ('playing' === normalizedState) {
    return 'success';
  }

  if ('paused' === normalizedState || 'buffering' === normalizedState) {
    return 'warning';
  }

  return 'neutral';
};

const sessionStateIcon = (state: string): string => {
  const normalizedState = state.toLowerCase();

  if ('playing' === normalizedState) {
    return 'i-lucide-play';
  }

  if ('paused' === normalizedState) {
    return 'i-lucide-pause';
  }

  if ('buffering' === normalizedState) {
    return 'i-lucide-loader-circle';
  }

  return 'i-lucide-play-circle';
};

const loadContent = async (): Promise<void> => {
  try {
    isLoading.value = true;
    loadError.value = '';
    hasLoaded.value = false;
    items.value = [];

    const response = await request(`/backend/${backend}/sessions`);
    const data = await parse_api_response<Array<SessionItem>>(response);

    if ('error' in data) {
      loadError.value = `${data.error.code}: ${data.error.message}`;
      notification(
        'error',
        'Backend sessions unavailable',
        `${data.error.code}: ${data.error.message}`,
      );
      return;
    }

    items.value = data;
    hasLoaded.value = true;
  } catch (e) {
    loadError.value = e instanceof Error ? e.message : 'Unknown error occurred';
    return notification(
      'error',
      'Backend sessions unavailable',
      e instanceof Error ? e.message : 'Unknown error occurred',
    );
  } finally {
    isLoading.value = false;
  }
};

const makeItemLink = (item: SessionItem): string => {
  const params = new URLSearchParams();
  params.append('perpage', '50');
  params.append('page', '1');
  params.append('q', `${backend}.id://${item.item_id}`);
  params.append('key', 'metadata');

  return `/history?${params.toString()}`;
};

onMounted(async () => await loadContent());
</script>
