<template>
  <div class="space-y-6">
    <section class="space-y-4">
      <PageHeader v-bind="pageShell">
        <template #kicker>
          <span>{{ pageShell.sectionLabel }}</span>
          <span>/</span>
          <NuxtLink to="/backends" class="hover:text-primary">{{ pageShell.pageLabel }}</NuxtLink>
          <span>/</span>
          <NuxtLink
            :to="`/backend/${backend}`"
            class="hover:text-primary normal-case tracking-normal"
            >{{ backend }}</NuxtLink
          >
          <span>/</span>
          <span class="text-highlighted normal-case tracking-normal">Sessions</span>
        </template>

        <template #actions>
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-refresh-cw"
            :loading="isLoading"
            :disabled="isLoading"
            aria-label="Reload sessions"
            @click="loadContent"
          >
            <span class="hidden sm:inline">Reload</span>
          </UButton>
        </template>
      </PageHeader>

      <UAlert
        v-if="1 > items.length && isLoading"
        color="info"
        variant="soft"
        icon="i-lucide-loader-circle"
        title="Loading"
        description="Requesting active play sessions. Please wait..."
        :ui="{ icon: 'animate-spin' }"
      />

      <UAlert
        v-else-if="1 > items.length"
        color="success"
        variant="soft"
        icon="i-lucide-info"
        title="Information"
        description="There are no active play sessions currently running."
      />

      <div v-else class="space-y-4">
        <div class="space-y-1">
          <div
            class="flex items-center gap-2 text-xs font-medium uppercase tracking-[0.2em] text-toned"
          >
            <UIcon name="i-lucide-play-circle" class="size-4" />
            <span>Active Sessions</span>
          </div>
        </div>

        <div class="grid gap-4 xl:grid-cols-2">
          <UCard v-for="item in items" :key="item.id" class="h-full shadow-sm" :ui="cardUi">
            <template #header>
              <div class="flex items-start justify-between gap-3">
                <div class="min-w-0 flex-1">
                  <UTooltip :text="String(item.item_title)">
                    <NuxtLink
                      :to="makeItemLink(item)"
                      class="block truncate text-base font-semibold text-highlighted hover:text-primary"
                    >
                      {{ item.item_title }}
                    </NuxtLink>
                  </UTooltip>
                </div>

                <UBadge :color="sessionStateColor(item.session_state)" variant="soft">
                  <span class="inline-flex items-center gap-1">
                    <UIcon :name="sessionStateIcon(item.session_state)" class="size-3.5" />
                    <span>{{ sessionStateLabel(item.session_state) }}</span>
                  </span>
                </UBadge>
              </div>
            </template>

            <div
              :class="[
                'grid gap-3 sm:grid-cols-2',
                item.updated_at ? 'xl:grid-cols-3' : 'xl:grid-cols-2',
              ]"
            >
              <div
                class="rounded-md border border-default bg-elevated/40 px-3 py-2.5 text-sm text-default"
              >
                <div
                  class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3"
                >
                  <div
                    class="inline-flex min-w-0 items-center gap-2 text-xs font-medium uppercase tracking-[0.16em] text-toned"
                  >
                    <UIcon name="i-lucide-user" class="size-3.5 shrink-0" />
                    <span>User</span>
                  </div>

                  <div class="min-w-0 font-medium text-highlighted sm:ml-auto sm:text-right">
                    {{ item.user_name }}
                  </div>
                </div>
              </div>

              <div
                class="rounded-md border border-default bg-elevated/40 px-3 py-2.5 text-sm text-default"
              >
                <div
                  class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3"
                >
                  <div
                    class="inline-flex min-w-0 items-center gap-2 text-xs font-medium uppercase tracking-[0.16em] text-toned"
                  >
                    <UIcon name="i-lucide-gauge" class="size-3.5 shrink-0" />
                    <span>Progress At</span>
                  </div>

                  <div class="min-w-0 font-medium text-highlighted sm:ml-auto sm:text-right">
                    {{ formatDuration(item.item_offset_at) }}
                  </div>
                </div>
              </div>

              <div
                v-if="item.updated_at"
                class="rounded-md border border-default bg-elevated/40 px-3 py-2.5 text-sm text-default sm:col-span-2 xl:col-span-1"
              >
                <div
                  class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3"
                >
                  <div
                    class="inline-flex min-w-0 items-center gap-2 text-xs font-medium uppercase tracking-[0.16em] text-toned"
                  >
                    <UIcon name="i-lucide-calendar" class="size-3.5 shrink-0" />
                    <span>Updated</span>
                  </div>

                  <div class="min-w-0 sm:ml-auto sm:text-right">
                    <UTooltip :text="item.updated_at">
                      <span class="cursor-help font-medium text-highlighted">{{
                        item.updated_at
                      }}</span>
                    </UTooltip>
                  </div>
                </div>
              </div>
            </div>
          </UCard>
        </div>
      </div>
    </section>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRoute } from '#app';
import PageHeader from '~/components/PageHeader.vue';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import { formatDuration, notification, parse_api_response, request } from '~/utils';
import type { SessionItem } from '~/types';

const backend = useRoute().params.backend as string;
const pageShell = requireTopLevelPageShell('backends');
const items = ref<Array<SessionItem>>([]);
const isLoading = ref<boolean>(false);

const cardUi = {
  header: 'p-4 sm:p-5',
  body: 'px-4 pb-4 pt-0 sm:px-5 sm:pb-5',
};

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
    items.value = [];

    const response = await request(`/backend/${backend}/sessions`);
    const data = await parse_api_response<Array<SessionItem>>(response);

    if ('error' in data) {
      notification('error', 'Error', `${data.error.code}: ${data.error.message}`);
      return;
    }

    items.value = data;
  } catch (e) {
    return notification(
      'error',
      'Error',
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
