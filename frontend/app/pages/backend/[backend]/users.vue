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
          <span class="text-highlighted normal-case tracking-normal">Users</span>
        </template>

        <template #actions>
          <UTooltip text="Reload users">
            <UButton
              color="neutral"
              variant="outline"
              icon="i-lucide-refresh-cw"
              :loading="isLoading"
              :disabled="isLoading"
              aria-label="Reload users"
              @click="loadContent"
            >
              <span class="hidden sm:inline">Reload</span>
            </UButton>
          </UTooltip>
        </template>
      </PageHeader>

      <UAlert
        v-if="(!items || items.length < 1) && isLoading"
        color="info"
        variant="soft"
        icon="i-lucide-loader-circle"
        title="Loading"
        description="Loading users list. Please wait..."
        :ui="{ icon: 'animate-spin' }"
      />

      <UAlert
        v-else-if="!items || items.length < 1"
        color="warning"
        variant="soft"
        icon="i-lucide-triangle-alert"
        title="Warning"
        description="WatchState was unable to get any users from the backend. This is expected if the backend is plex and the token is limited."
      />

      <div v-else class="grid gap-4 xl:grid-cols-2">
        <UCard
          v-for="item in items"
          :key="`users-${item.id}`"
          class="h-full shadow-sm"
          :ui="cardUi"
        >
          <template #header>
            <div class="flex items-start gap-3">
              <div class="min-w-0 flex-1">
                <div
                  class="flex min-w-0 items-start gap-2 text-base font-semibold leading-6 text-highlighted"
                >
                  <UIcon name="i-lucide-user" class="mt-0.5 size-4 shrink-0 text-toned" />
                  <UTooltip :text="String(item.name)">
                    <span class="block truncate">{{ item.name }}</span>
                  </UTooltip>
                </div>
              </div>
            </div>
          </template>

          <div class="grid grid-cols-2 gap-3">
            <div
              class="rounded-md border border-default bg-elevated/40 px-3 py-2.5 text-sm text-default"
            >
              <div
                class="inline-flex items-center gap-2 text-xs font-medium uppercase tracking-[0.16em] text-toned"
              >
                <UIcon name="i-lucide-shield-check" class="size-3.5" />
                <span>Admin</span>
              </div>
              <div class="mt-1 font-medium text-highlighted">{{ item.admin ? 'Yes' : 'No' }}</div>
            </div>

            <div
              v-if="undefined !== item?.guest"
              class="rounded-md border border-default bg-elevated/40 px-3 py-2.5 text-sm text-default"
            >
              <div
                class="inline-flex items-center gap-2 text-xs font-medium uppercase tracking-[0.16em] text-toned"
              >
                <UIcon name="i-lucide-user-round-plus" class="size-3.5" />
                <span>Guest</span>
              </div>
              <div class="mt-1 font-medium text-highlighted">{{ item.guest ? 'Yes' : 'No' }}</div>
            </div>

            <div
              v-if="undefined !== item?.hidden"
              class="rounded-md border border-default bg-elevated/40 px-3 py-2.5 text-sm text-default"
            >
              <div
                class="inline-flex items-center gap-2 text-xs font-medium uppercase tracking-[0.16em] text-toned"
              >
                <UIcon name="i-lucide-eye-off" class="size-3.5" />
                <span>Hidden</span>
              </div>
              <div class="mt-1 font-medium text-highlighted">{{ item.hidden ? 'Yes' : 'No' }}</div>
            </div>

            <div
              v-if="item?.updatedAt"
              class="rounded-md border border-default bg-elevated/40 px-3 py-2.5 text-sm text-default"
            >
              <div
                class="inline-flex items-center gap-2 text-xs font-medium uppercase tracking-[0.16em] text-toned"
              >
                <UIcon name="i-lucide-calendar" class="size-3.5" />
                <span>Updated</span>
              </div>
              <div class="mt-1">
                <span v-if="item.updatedAt === 'external_user' || item.updatedAt === 'never'">
                  <span class="font-medium text-highlighted">{{ item.updatedAt }}</span>
                </span>
                <UTooltip v-else :text="moment(item.updatedAt).format(TOOLTIP_DATE_FORMAT)">
                  <span class="cursor-help font-medium text-highlighted">{{
                    moment(item.updatedAt).fromNow()
                  }}</span>
                </UTooltip>
              </div>
            </div>

            <div
              v-if="undefined !== item?.restricted"
              class="rounded-md border border-default bg-elevated/40 px-3 py-2.5 text-sm text-default"
            >
              <div
                class="inline-flex items-center gap-2 text-xs font-medium uppercase tracking-[0.16em] text-toned"
              >
                <UIcon name="i-lucide-lock" class="size-3.5" />
                <span>Restricted</span>
              </div>
              <div class="mt-1 font-medium text-highlighted">
                {{ item.restricted ? 'Yes' : 'No' }}
              </div>
            </div>

            <div
              v-if="undefined !== item?.disabled"
              class="rounded-md border border-default bg-elevated/40 px-3 py-2.5 text-sm text-default"
            >
              <div
                class="inline-flex items-center gap-2 text-xs font-medium uppercase tracking-[0.16em] text-toned"
              >
                <UIcon name="i-lucide-ban" class="size-3.5" />
                <span>Disabled</span>
              </div>
              <div class="mt-1 font-medium text-highlighted">
                {{ item.disabled ? 'Yes' : 'No' }}
              </div>
            </div>
          </div>
        </UCard>
      </div>
    </section>

    <UCard class="shadow-sm" :ui="tipsCardUi">
      <template #header>
        <button
          type="button"
          class="flex w-full items-center justify-between gap-3 text-left"
          @click="show_page_tips = !show_page_tips"
        >
          <span class="inline-flex items-center gap-2 text-sm font-semibold text-highlighted">
            <UIcon name="i-lucide-info" class="size-4 text-toned" />
            <span>Tips</span>
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

      <div v-if="show_page_tips" class="text-sm leading-6 text-default">
        <ul class="list-disc space-y-2 pl-5">
          <li>
            For <code>Plex</code> backends, if the <code>X-Plex-Token</code> is limited one, the
            users will not show up. This is a limitation of the Plex API.
          </li>
        </ul>
      </div>
    </UCard>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useHead, useRoute } from '#app';
import { useStorage } from '@vueuse/core';
import moment from 'moment';
import PageHeader from '~/components/PageHeader.vue';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import { notification, parse_api_response, request, TOOLTIP_DATE_FORMAT } from '~/utils';
import type { BackendUserItem } from '~/types';

const route = useRoute();
const backend = route.params.backend as string;
const pageShell = requireTopLevelPageShell('backends');
const items = ref<Array<BackendUserItem> | null>(null);
const isLoading = ref<boolean>(false);
const show_page_tips = useStorage('show_page_tips', true);

const cardUi = {
  header: 'p-5',
  body: 'p-5',
};

const tipsCardUi = {
  header: 'p-4',
  body: 'p-5 pt-0',
};

useHead({ title: `${backend} - Users` });

const loadContent = async (): Promise<void> => {
  items.value = [];

  try {
    isLoading.value = true;

    const response = await request(`/backend/${backend}/users`);
    const data = await parse_api_response<Array<BackendUserItem>>(response);

    if ('error' in data) {
      notification('error', 'Error', `${data.error.code}: ${data.error.message}`);
      return;
    }

    items.value = data;
  } catch (e) {
    const error = e as Error;
    notification('error', 'Error', error.message);
  } finally {
    isLoading.value = false;
  }
};

onMounted(async () => await loadContent());
</script>
