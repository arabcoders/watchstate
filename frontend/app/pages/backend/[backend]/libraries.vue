<template>
  <div class="w-full min-w-0 max-w-full space-y-6">
    <section aria-label="Backend libraries" :aria-busy="isLoading" class="space-y-4">
      <PageHeader v-bind="pageShell" page-label="Libraries" description="">
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
            icon="i-lucide-refresh-cw"
            :loading="isLoading"
            :disabled="isLoading"
            aria-label="Refresh libraries"
            @click="loadContent"
            label="Refresh"
          />
        </template>
      </PageHeader>

      <UAlert
        v-if="0 === items.length && isLoading"
        color="info"
        variant="soft"
        icon="i-lucide-loader-circle"
        title="Loading backend libraries"
        :ui="{ icon: 'animate-spin' }"
      />

      <UAlert
        v-else-if="loadError"
        color="error"
        variant="soft"
        icon="i-lucide-triangle-alert"
        title="Couldn't load libraries"
        :description="loadError"
      />

      <UEmpty
        v-else-if="hasLoaded && 0 === items.length"
        icon="i-lucide-library"
        title="No libraries found"
      />

      <div v-else-if="0 < items.length" class="ws-list-card">
        <Lazy v-for="item in items" :key="`library-${item.id}`" :unrender="true" :min-height="148">
          <article
            :aria-labelledby="`library-${item.id}`"
            class="grid gap-4 p-4 sm:p-5 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)_18rem]"
          >
            <div class="min-w-0 space-y-2">
              <h2
                :id="`library-${item.id}`"
                class="flex min-w-0 items-start gap-2 text-base font-semibold text-highlighted"
              >
                <UIcon
                  :name="'Movie' === item.type ? 'i-lucide-film' : 'i-lucide-tv'"
                  class="mt-0.5 size-4 shrink-0 text-toned"
                />
                <UTooltip v-if="item.webUrl" :text="String(item.title)">
                  <NuxtLink
                    target="_blank"
                    rel="noopener noreferrer"
                    :to="item.webUrl"
                    class="min-w-0 wrap-anywhere hover:text-primary"
                  >
                    {{ item.title }}
                  </NuxtLink>
                </UTooltip>
                <span v-else class="min-w-0 wrap-anywhere">{{ item.title }}</span>
              </h2>
              <dl class="flex flex-wrap gap-x-4 gap-y-1 text-sm">
                <div class="flex items-baseline gap-2">
                  <dt class="text-xs text-muted">Type</dt>
                  <dd>{{ item.type }}</dd>
                </div>
                <div class="flex items-baseline gap-2">
                  <dt class="text-xs text-muted">Supported</dt>
                  <dd>{{ item.supported ? 'Yes' : 'No' }}</dd>
                </div>
              </dl>
            </div>

            <dl v-if="item.agent || item.scanner" class="min-w-0 space-y-2 text-sm">
              <div v-if="item.agent" class="space-y-1">
                <dt class="text-xs text-muted">Agent</dt>
                <dd class="wrap-anywhere">{{ item.agent }}</dd>
              </div>
              <div v-if="item.scanner" class="space-y-1">
                <dt class="text-xs text-muted">Scanner</dt>
                <dd class="wrap-anywhere">{{ item.scanner }}</dd>
              </div>
            </dl>

            <div class="min-w-0 space-y-3 xl:col-start-3">
              <USwitch
                :model-value="item.ignored"
                color="neutral"
                :label="item.ignored ? 'Ignored' : 'Ignore'"
                :aria-label="`Ignore ${item.title} during imports and webhook processing`"
                @update:model-value="() => void toggleIgnore(item)"
              />
              <USelect
                v-if="item.supported && !item.ignored"
                v-model="selectedCommand"
                :items="commandItems"
                value-key="value"
                placeholder="Quick operations"
                icon="i-lucide-terminal"
                :aria-label="`Quick operations for ${item.title}`"
                class="w-full"
                @update:model-value="() => void forwardCommand(item)"
              />
            </div>
          </article>
        </Lazy>
      </div>
    </section>

    <details
      :open="show_page_tips"
      class="space-y-3 border-t border-default pt-4"
      @toggle="show_page_tips = ($event.target as HTMLDetailsElement).open"
    >
      <summary class="cursor-pointer text-sm font-medium text-highlighted">
        Library filtering
      </summary>
      <ul v-if="show_page_tips" class="list-disc space-y-2 pl-5 text-sm leading-6 text-default">
        <li>
          Ignoring a library prevents its content from being added to the local database through
          imports or webhook events.
        </li>
        <li>
          Libraries that show <code>Supported: No</code> will not be processed by
          <code>WatchState</code>.
        </li>
      </ul>
    </details>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { navigateTo, useHead, useRoute } from '#app';
import { NuxtLink, UAlert, UButton, UEmpty, UIcon, USelect, USwitch, UTooltip } from '#components';
import { useStorage } from '@vueuse/core';
import Lazy from '~/components/Lazy.vue';
import PageHeader from '~/components/PageHeader.vue';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import { makeConsoleCommand, notification, parse_api_response, r, request } from '~/utils';
import type { Backend, JsonObject, JsonValue, LibraryItem, UtilityCommand } from '~/types';

const route = useRoute();
const backend = route.params.backend as string;
const pageShell = requireTopLevelPageShell('backends');
const items = ref<Array<LibraryItem>>([]);
const isLoading = ref<boolean>(false);
const loadError = ref('');
const hasLoaded = ref(false);
const show_page_tips = useStorage('show_page_tips', true);
const api_user = useStorage('api_user', 'main');
const selectedCommand = ref<string>('');

type UsefulCommand = UtilityCommand;

type UsefulCommands = Record<string, UsefulCommand>;

type CommandUtility = {
  user: string;
  backend: string;
  library_id: string;
  [key: string]: JsonValue | undefined;
};

type UiCommand = {
  id: number;
  title: string;
  path: string;
};

type SelectItem = {
  label: string;
  value: string;
};

const uiCommands = computed<Record<string, UiCommand>>(() => ({
  stale_library: {
    id: 1,
    title: 'Check this library for stale items.',
    path: `/backend/${backend}/stale/{library_id}`,
  },
}));

const usefulCommands: UsefulCommands = {
  import_library: {
    id: 2,
    title: 'Import data from this library.',
    command: 'state:import -v -u {user} -s {backend} -S {library_id}',
  },
  force_import_library: {
    id: 3,
    title: 'Force import from this library.',
    command: 'state:import -f -v -u {user} -s {backend} -S {library_id}',
  },
};

const commandItems = computed<Array<SelectItem>>(() => {
  const uiItems = Object.entries(uiCommands.value).map(([key, command]) => ({
    label: `${command.id}. ${command.title}`,
    value: key,
  }));

  const utilityItems = Object.entries(usefulCommands).map(([key, command]) => ({
    label: `${command.id}. ${command.title}`,
    value: key,
  }));

  return [...uiItems, ...utilityItems];
});

useHead({ title: `Backends: ${backend} - Libraries` });

const loadContent = async (): Promise<void> => {
  try {
    isLoading.value = true;
    loadError.value = '';
    hasLoaded.value = false;
    items.value = [];

    const response = await request(`/backend/${backend}/library`);
    const data = await parse_api_response<Array<LibraryItem>>(response);

    if ('error' in data) {
      loadError.value = `${data.error.code}: ${data.error.message}`;
      notification(
        'error',
        'Backend libraries unavailable',
        `${data.error.code}: ${data.error.message}`,
      );
      return;
    }

    items.value = data;
    hasLoaded.value = true;
  } catch (e) {
    const errorMessage = e instanceof Error ? e.message : 'Unknown error occurred';
    loadError.value = errorMessage;
    return notification('error', 'Backend libraries unavailable', errorMessage);
  } finally {
    isLoading.value = false;
  }
};

const forwardCommand = async (library: LibraryItem): Promise<void> => {
  if ('' === selectedCommand.value) {
    return;
  }

  const index = selectedCommand.value as keyof UsefulCommands;
  selectedCommand.value = '';

  const util: CommandUtility = {
    user: api_user.value,
    backend,
    library_id: String(library.id),
  };

  const uiCommand = uiCommands.value[index];
  if (uiCommand) {
    await navigateTo(r(uiCommand.path, util as unknown as JsonObject));
    return;
  }

  const command = usefulCommands[index];
  if (!command) {
    return;
  }

  await navigateTo(makeConsoleCommand(r(command.command, util as unknown as JsonObject)));
};

const getIgnoreIds = (targetLibrary: LibraryItem, nextIgnoredState: boolean): Array<string> => {
  const targetId = String(targetLibrary.id);
  const ignoreIds = items.value
    .filter((item) => (String(item.id) === targetId ? nextIgnoredState : item.ignored))
    .map((item) => String(item.id));

  return Array.from(new Set(ignoreIds));
};

const toggleIgnore = async (library: LibraryItem): Promise<void> => {
  try {
    const newState = !library.ignored;
    const ignoreIds = getIgnoreIds(library, newState);

    const response = await request(`/backend/${backend}`, {
      method: 'PATCH',
      body: JSON.stringify([
        {
          key: 'options.ignore',
          value: ignoreIds.join(','),
        },
      ]),
    });
    const data = await parse_api_response<Backend>(response);

    if ('error' in data) {
      notification(
        'error',
        'Library ignore setting not saved',
        `${data.error.code}: ${data.error.message}`,
      );
      return;
    }

    const libraryIndex = items.value.findIndex((b) => b.id === library.id);
    if (-1 !== libraryIndex && items.value[libraryIndex]) {
      items.value[libraryIndex].ignored = !library.ignored;
    }
  } catch (e) {
    const errorMessage = e instanceof Error ? e.message : 'Unknown error occurred';
    return notification('error', 'Library ignore setting not saved', errorMessage);
  }
};

onMounted(() => loadContent());
</script>
