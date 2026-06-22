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
          <span class="text-highlighted normal-case tracking-normal">Libraries</span>
        </template>

        <template #actions>
          <UButton
            color="neutral"
            variant="outline"
            icon="i-lucide-refresh-cw"
            :loading="isLoading"
            :disabled="isLoading"
            aria-label="Reload libraries"
            @click="loadContent"
            label="Reload"
          />
        </template>
      </PageHeader>

      <UAlert
        v-if="0 === items.length && isLoading"
        color="info"
        variant="soft"
        icon="i-lucide-loader-circle"
        title="Loading"
        description="Loading libraries list. Please wait..."
        :ui="{ icon: 'animate-spin' }"
      />

      <UAlert
        v-else-if="0 === items.length"
        color="warning"
        variant="soft"
        icon="i-lucide-triangle-alert"
        title="Warning"
        description="WatchState was unable to get any libraries from the backend."
      />

      <div v-else class="grid gap-4 xl:grid-cols-2">
        <UCard
          v-for="item in items"
          :key="`library-${item.id}`"
          class="h-full shadow-sm"
          :ui="cardUi"
        >
          <template #header>
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0 flex-1">
                <div
                  class="flex min-w-0 items-start gap-2 text-base font-semibold leading-6 text-highlighted"
                >
                  <UIcon
                    :name="'Movie' === item.type ? 'i-lucide-film' : 'i-lucide-tv'"
                    class="mt-0.5 size-4 shrink-0 text-toned"
                  />

                  <div class="min-w-0 flex-1">
                    <UTooltip v-if="item?.webUrl" :text="String(item.title)">
                      <NuxtLink
                        target="_blank"
                        :to="item.webUrl"
                        class="block truncate text-highlighted hover:text-primary"
                      >
                        {{ item.title }}
                      </NuxtLink>
                    </UTooltip>
                    <UTooltip v-else :text="String(item.title)">
                      <span class="block truncate text-highlighted">
                        {{ item.title }}
                      </span>
                    </UTooltip>
                  </div>
                </div>
              </div>

              <div class="flex shrink-0 items-center justify-end">
                <USwitch
                  :model-value="item.ignored"
                  :color="item.ignored ? 'success' : 'neutral'"
                  :label="item.ignored ? 'Ignored' : 'Ignore'"
                  @update:model-value="() => void toggleIgnore(item)"
                />
              </div>
            </div>
          </template>

          <div class="grid grid-cols-2 gap-3">
            <div
              class="rounded-md border border-default bg-elevated/40 px-3 py-2.5 text-sm text-default"
            >
              <div
                class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3"
              >
                <div
                  class="inline-flex min-w-0 items-center gap-2 text-xs font-medium uppercase tracking-[0.16em] text-toned"
                >
                  <UIcon name="i-lucide-tag" class="size-3.5 shrink-0" />
                  <span>Type</span>
                </div>

                <div class="min-w-0 font-medium text-highlighted sm:ml-auto sm:text-right">
                  {{ item.type }}
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
                  <UIcon name="i-lucide-badge-check" class="size-3.5 shrink-0" />
                  <span>Supported</span>
                </div>

                <div class="min-w-0 sm:ml-auto sm:text-right">
                  <UBadge :color="item.supported ? 'success' : 'warning'" variant="soft">
                    {{ item.supported ? 'Yes' : 'No' }}
                  </UBadge>
                </div>
              </div>
            </div>

            <div
              v-if="item?.agent"
              class="rounded-md border border-default bg-elevated/40 px-3 py-2.5 text-sm text-default"
            >
              <div
                class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3"
              >
                <div
                  class="inline-flex min-w-0 items-center gap-2 text-xs font-medium uppercase tracking-[0.16em] text-toned"
                >
                  <UIcon name="i-lucide-clapperboard" class="size-3.5 shrink-0" />
                  <span>Agent</span>
                </div>
                <div
                  class="min-w-0 wrap-break-word font-medium text-highlighted sm:ml-auto sm:text-right"
                >
                  {{ item.agent }}
                </div>
              </div>
            </div>

            <div
              v-if="item?.scanner"
              class="rounded-md border border-default bg-elevated/40 px-3 py-2.5 text-sm text-default"
            >
              <div
                class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3"
              >
                <div
                  class="inline-flex min-w-0 items-center gap-2 text-xs font-medium uppercase tracking-[0.16em] text-toned"
                >
                  <UIcon name="i-lucide-cpu" class="size-3.5 shrink-0" />
                  <span>Scanner</span>
                </div>
                <div
                  class="min-w-0 wrap-break-word font-medium text-highlighted sm:ml-auto sm:text-right"
                >
                  {{ item.scanner }}
                </div>
              </div>
            </div>
          </div>

          <template v-if="item.supported && !item.ignored" #footer>
            <div class="flex flex-wrap items-center justify-end gap-2">
              <USelect
                v-model="selectedCommand"
                :items="commandItems"
                value-key="value"
                placeholder="Quick operations"
                icon="i-lucide-terminal"
                class="w-full"
                @update:model-value="() => void forwardCommand(item)"
              />
            </div>
          </template>
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

      <ul v-if="show_page_tips" class="list-disc space-y-2 pl-5 text-sm leading-6 text-default">
        <li>
          Ignoring library will prevent any content from being added to the local database from the
          library during import process, and webhook events handling.
        </li>
        <li>
          Libraries that show <code>Supported: No</code> will not be processed by
          <code>WatchState</code>.
        </li>
      </ul>
    </UCard>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { navigateTo, useHead, useRoute } from '#app';
import { useStorage } from '@vueuse/core';
import PageHeader from '~/components/PageHeader.vue';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import { makeConsoleCommand, notification, parse_api_response, r, request } from '~/utils';
import type { JsonObject, JsonValue, LibraryItem, UtilityCommand } from '~/types';

const route = useRoute();
const backend = route.params.backend as string;
const pageShell = requireTopLevelPageShell('backends');
const items = ref<Array<LibraryItem>>([]);
const isLoading = ref<boolean>(false);
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

const cardUi = {
  header: 'p-5',
  body: 'p-5',
  footer: 'p-5 border-t border-default',
};

const tipsCardUi = {
  header: 'p-4',
  body: 'p-5 pt-0',
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
    items.value = [];

    const response = await request(`/backend/${backend}/library`);
    const data = await parse_api_response<Array<LibraryItem>>(response);

    if ('error' in data) {
      notification('error', 'Error', `${data.error.code}: ${data.error.message}`);
      return;
    }

    items.value = data;
  } catch (e) {
    const errorMessage = e instanceof Error ? e.message : 'Unknown error occurred';
    return notification('error', 'Error', `Request error. ${errorMessage}`);
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
    const data = await parse_api_response<any>(response);

    if ('error' in data) {
      notification('error', 'Error', `${data.error.code}: ${data.error.message}`);
      return;
    }

    const libraryIndex = items.value.findIndex((b) => b.id === library.id);
    if (-1 !== libraryIndex && items.value[libraryIndex]) {
      items.value[libraryIndex].ignored = !library.ignored;
    }
  } catch (e) {
    const errorMessage = e instanceof Error ? e.message : 'Unknown error occurred';
    return notification('error', 'Error', `Request error. ${errorMessage}`);
  }
};

onMounted(() => loadContent());
</script>
