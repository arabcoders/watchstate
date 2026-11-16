<template>
  <div class="w-full min-w-0 max-w-full space-y-6">
    <section aria-label="Backend users" :aria-busy="isLoading" class="space-y-4">
      <PageHeader v-bind="pageShell" page-label="Users" description="">
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
          <UTooltip text="Refresh users">
            <UButton
              color="neutral"
              variant="outline"
              icon="i-lucide-refresh-cw"
              :loading="isLoading"
              :disabled="isLoading"
              aria-label="Refresh users"
              @click="loadContent"
            >
              Refresh
            </UButton>
          </UTooltip>
        </template>
      </PageHeader>

      <UAlert
        v-if="(!items || 1 > items.length) && isLoading"
        color="info"
        variant="soft"
        icon="i-lucide-loader-circle"
        title="Loading backend users"
        :ui="{ icon: 'animate-spin' }"
      />

      <UAlert
        v-else-if="loadError"
        color="error"
        variant="soft"
        icon="i-lucide-triangle-alert"
        title="Couldn't load users"
        :description="loadError"
      />

      <UEmpty
        v-else-if="items && 0 === items.length"
        icon="i-lucide-users"
        title="No users found"
        description="Plex tokens with limited access may not list users."
      />

      <div v-else-if="items && 0 < items.length" class="ws-card divide-y divide-default">
        <Lazy v-for="item in items" :key="`users-${item.id}`" :unrender="true" :min-height="112">
          <article
            :aria-labelledby="`user-${item.id}`"
            class="grid gap-4 p-4 sm:p-5 xl:grid-cols-[minmax(0,1fr)_minmax(0,2fr)_minmax(0,0.8fr)]"
          >
            <div class="min-w-0 space-y-1">
              <h2
                :id="`user-${item.id}`"
                class="flex items-start gap-2 text-base font-semibold text-highlighted"
              >
                <UIcon name="i-lucide-user" class="mt-0.5 size-4 shrink-0 text-toned" />
                <span class="min-w-0 wrap-anywhere">{{ item.name }}</span>
              </h2>
              <p class="wrap-anywhere text-xs text-muted">ID {{ item.id }}</p>
            </div>

            <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-3 xl:grid-cols-5">
              <div class="space-y-1">
                <dt class="text-xs text-muted">Admin</dt>
                <dd>{{ item.admin ? 'Yes' : 'No' }}</dd>
              </div>
              <div v-if="undefined !== item.guest" class="space-y-1">
                <dt class="text-xs text-muted">Guest</dt>
                <dd>{{ item.guest ? 'Yes' : 'No' }}</dd>
              </div>
              <div v-if="undefined !== item.hidden" class="space-y-1">
                <dt class="text-xs text-muted">Hidden</dt>
                <dd>{{ item.hidden ? 'Yes' : 'No' }}</dd>
              </div>
              <div v-if="undefined !== item.restricted" class="space-y-1">
                <dt class="text-xs text-muted">Restricted</dt>
                <dd>{{ item.restricted ? 'Yes' : 'No' }}</dd>
              </div>
              <div v-if="undefined !== item.disabled" class="space-y-1">
                <dt class="text-xs text-muted">Disabled</dt>
                <dd>{{ item.disabled ? 'Yes' : 'No' }}</dd>
              </div>
            </dl>

            <dl v-if="item.updatedAt" class="min-w-0 space-y-1 text-sm">
              <dt class="text-xs text-muted">Updated</dt>
              <dd class="wrap-anywhere">
                <span v-if="'external_user' === item.updatedAt || 'never' === item.updatedAt">
                  {{ item.updatedAt }}
                </span>
                <UTooltip v-else :text="moment(item.updatedAt).format(TOOLTIP_DATE_FORMAT)">
                  <span class="cursor-help">{{ moment(item.updatedAt).fromNow() }}</span>
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
import { useHead, useRoute } from '#app';
import { NuxtLink, UAlert, UButton, UEmpty, UIcon, UTooltip } from '#components';
import moment from 'moment';
import Lazy from '~/components/Lazy.vue';
import PageHeader from '~/components/PageHeader.vue';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import { notification, parse_api_response, request, TOOLTIP_DATE_FORMAT } from '~/utils';
import type { BackendUserItem } from '~/types';

const route = useRoute();
const backend = route.params.backend as string;
const pageShell = requireTopLevelPageShell('backends');
const items = ref<Array<BackendUserItem> | null>(null);
const isLoading = ref<boolean>(false);
const loadError = ref('');
useHead({ title: `${backend} - Users` });

const loadContent = async (): Promise<void> => {
  items.value = null;
  loadError.value = '';

  try {
    isLoading.value = true;

    const response = await request(`/backend/${backend}/users`);
    const data = await parse_api_response<Array<BackendUserItem>>(response);

    if ('error' in data) {
      loadError.value = `${data.error.code}: ${data.error.message}`;
      notification(
        'error',
        'Backend users unavailable',
        `${data.error.code}: ${data.error.message}`,
      );
      return;
    }

    items.value = data;
  } catch (e) {
    loadError.value = e instanceof Error ? e.message : 'Unknown error occurred';
    notification('error', 'Backend users unavailable', loadError.value);
  } finally {
    isLoading.value = false;
  }
};

onMounted(async () => await loadContent());
</script>
