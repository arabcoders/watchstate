<template>
  <main class="w-full min-w-0 max-w-full space-y-4">
    <div class="flex flex-col gap-3 xl:flex-row xl:items-start xl:justify-between">
      <div class="min-w-0 space-y-1">
        <div
          class="flex items-center gap-2 text-xs font-medium uppercase tracking-[0.2em] text-toned"
        >
          <UIcon :name="pageShell.icon" class="size-4" />
          <span>{{ pageShell.sectionLabel }}</span>
          <span>/</span>
          <span>{{ pageShell.pageLabel }}</span>
        </div>

        <div>
          <p class="mt-1 text-sm text-toned">User defined custom GUIDs and client mapping links.</p>
        </div>
      </div>

      <div class="flex flex-wrap items-center justify-end gap-2">
        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-plus"
          @click="addGuidModalOpen = true"
          label="Add GUID"
        />

        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-plus"
          @click="addLinkModalOpen = true"
          label="Add Link"
        />

        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-refresh-cw"
          :loading="isLoading"
          :disabled="isLoading"
          @click="loadContent"
          label="Reload"
        />
      </div>
    </div>

    <UAlert
      v-if="isLoading"
      color="info"
      variant="soft"
      icon="i-lucide-loader-circle"
      title="Loading"
      description="Loading data. Please wait..."
      :ui="{ icon: 'animate-spin' }"
    />

    <template v-else>
      <section class="space-y-4">
        <div v-if="guids.length > 0" class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
          <UCard
            v-for="(guid, index) in guids"
            :key="guid.name"
            class="h-full border border-default/70 shadow-sm"
            :ui="itemCardUi"
          >
            <template #header>
              <div class="flex items-start justify-between gap-3">
                <div class="min-w-0 flex-1 text-base font-semibold text-highlighted">
                  <UTooltip :text="String(guid.name)">
                    <span class="block truncate">{{ guid.name }}</span>
                  </UTooltip>
                </div>
                <UButton
                  color="neutral"
                  variant="outline"
                  size="sm"
                  icon="i-lucide-trash-2"
                  aria-label="Delete GUID"
                  @click="deleteGUID(index, guid)"
                  label="Delete"
                />
              </div>
            </template>

            <p class="text-sm leading-6 text-default">
              {{ guid.description || 'No description provided.' }}
            </p>
          </UCard>
        </div>

        <UAlert
          v-else
          color="warning"
          variant="soft"
          icon="i-lucide-triangle-alert"
          title="No custom GUIDs"
          description="There are no custom GUIDs configured yet."
        />
      </section>

      <section class="space-y-4">
        <div class="space-y-1">
          <div
            class="flex items-center gap-2 text-xs font-medium uppercase tracking-[0.2em] text-toned"
          >
            <UIcon name="i-lucide-arrow-right-left" class="size-4" />
            <span>Client GUID Links</span>
          </div>
        </div>

        <div v-if="links.length > 0" class="grid gap-4 xl:grid-cols-2">
          <UCard
            v-for="(link, index) in links"
            :key="link.id"
            class="h-full border border-default/70 shadow-sm"
            :ui="itemCardUi"
          >
            <template #header>
              <div class="flex items-start justify-between gap-3">
                <button
                  v-if="link.replace?.from"
                  type="button"
                  class="flex min-w-0 flex-1 items-center gap-2 text-left text-base font-semibold text-highlighted"
                  @click="link.show = !link.show"
                >
                  <UIcon
                    :name="
                      true === (link.show ?? false)
                        ? 'i-lucide-chevron-up'
                        : 'i-lucide-chevron-down'
                    "
                    class="size-4 shrink-0 text-toned"
                  />
                  <UTooltip :text="`${ucFirst(link.type)} client link`">
                    <span class="truncate">{{ ucFirst(link.type) }} client link</span>
                  </UTooltip>
                </button>

                <div v-else class="min-w-0 flex-1 text-base font-semibold text-highlighted">
                  <UTooltip :text="`${ucFirst(link.type)} client link`">
                    <span class="block truncate">{{ ucFirst(link.type) }} client link</span>
                  </UTooltip>
                </div>

                <UButton
                  color="neutral"
                  variant="outline"
                  size="sm"
                  icon="i-lucide-trash-2"
                  aria-label="Delete link"
                  @click="deleteLink(index, link)"
                  label="Delete"
                />
              </div>
            </template>

            <div class="grid gap-3 text-sm md:grid-cols-2">
              <div class="rounded-md border border-default bg-elevated/40 px-3 py-2.5">
                <div
                  class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3"
                >
                  <span class="inline-flex items-center gap-2 text-toned">
                    <UIcon name="i-lucide-chevron-right" class="size-4" />
                    <span>From Client GUID</span>
                  </span>
                  <span class="min-w-0 font-medium text-highlighted sm:ml-auto sm:text-right">{{
                    link.map.from
                  }}</span>
                </div>
              </div>

              <div class="rounded-md border border-default bg-elevated/40 px-3 py-2.5">
                <div
                  class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3"
                >
                  <span class="inline-flex items-center gap-2 text-toned">
                    <UIcon name="i-lucide-chevron-left" class="size-4" />
                    <span>To WatchState GUID</span>
                  </span>
                  <span class="min-w-0 font-medium text-highlighted sm:ml-auto sm:text-right">{{
                    link.map.to
                  }}</span>
                </div>
              </div>

              <template v-if="link.replace?.from && 'show' in link && link.show">
                <div class="rounded-md border border-default bg-elevated/40 px-3 py-2.5">
                  <div
                    class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3"
                  >
                    <span class="inline-flex items-center gap-2 text-toned">
                      <UIcon name="i-lucide-x" class="size-4" />
                      <span>Replace</span>
                    </span>
                    <span class="min-w-0 font-medium text-highlighted sm:ml-auto sm:text-right">{{
                      link.replace.from
                    }}</span>
                  </div>
                </div>

                <div class="rounded-md border border-default bg-elevated/40 px-3 py-2.5">
                  <div
                    class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3"
                  >
                    <span class="inline-flex items-center gap-2 text-toned">
                      <UIcon name="i-lucide-circle-check" class="size-4" />
                      <span>With</span>
                    </span>
                    <span class="min-w-0 font-medium text-highlighted sm:ml-auto sm:text-right">{{
                      link.replace.to
                    }}</span>
                  </div>
                </div>
              </template>
            </div>
          </UCard>
        </div>

        <UAlert
          v-else
          color="warning"
          variant="soft"
          icon="i-lucide-triangle-alert"
          title="No client GUID links"
          description="There are no client GUID links configured yet."
        />
      </section>

      <UCard class="border border-default/70 shadow-sm" :ui="tipsCardUi">
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
            Using this feature allows you to extend <code>WatchState</code> to support more less
            known or regional specific metadata databases. We cannot add support directly to all
            databases, so this feature instead allows you to do it manually.
          </li>
          <li>
            Adding a custom GUID without a client link is useless because the parsing engine will
            not know what to do with it, so make sure to add a client GUID link referencing the
            custom GUID.
          </li>
          <li>The GUID names are unique, so you cannot reuse an existing one.</li>
          <li>
            You cannot add a link from the same client GUID twice. For example, you cannot add
            <code>jellyfin:foobar -> WatchState:guid_foobar</code> and another for
            <code>jellyfin:foobar -> guid_imdb</code>.
          </li>
          <li>
            Editing the <code>guid.yaml</code> file directly is unsupported and might lead to
            unexpected behavior. Use the Web UI to manage GUIDs so the built-in safeguards stay in
            place.
          </li>
          <li>
            If you added or removed a custom GUID, you should run
            <NuxtLink
              :to="makeConsoleCommand('db:index --force-reindex')"
              class="text-primary hover:underline"
            >
              <span class="inline-flex items-center gap-1">
                <UIcon name="i-lucide-terminal" class="size-4" />
                <span>db:index --force-reindex</span>
              </span>
            </NuxtLink>
            to rebuild the database indexes.
          </li>
          <li>
            The links are global for each client, not for a single backend. If you add a new
            Jellyfin GUID link, it applies to all Jellyfin backends.
          </li>
          <li>
            For more information, read <code>FAQ.md</code> or open
            <NuxtLink
              target="_blank"
              to="https://github.com/arabcoders/watchstate/blob/master/FAQ.md#advanced-how-to-extend-the-guid-parser-to-support-more-guids-or-custom-ones"
              class="text-primary hover:underline"
            >
              <span class="inline-flex items-center gap-1">
                <UIcon name="i-lucide-external-link" class="size-4" />
                <span>this link</span>
              </span>
            </NuxtLink>
            directly.
          </li>
        </ul>
      </UCard>

      <UModal
        :open="addGuidModalOpen"
        title="Add Custom GUID"
        :ui="formModalUi"
        @update:open="handleAddGuidOpenChange"
      >
        <template #body>
          <CustomGuidForm
            v-if="addGuidModalOpen"
            @cancel="() => void requestCloseAddGuid()"
            @saved="handleGuidSaved"
            @dirty-change="(dirty) => (addGuidDirty = dirty)"
          />
        </template>
      </UModal>

      <UModal
        :open="addLinkModalOpen"
        title="Add Client GUID Link"
        :ui="formModalUi"
        @update:open="handleAddLinkOpenChange"
      >
        <template #body>
          <CustomLinkForm
            v-if="addLinkModalOpen"
            @cancel="() => void requestCloseAddLink()"
            @saved="handleLinkSaved"
            @dirty-change="(dirty) => (addLinkDirty = dirty)"
          />
        </template>
      </UModal>
    </template>
  </main>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { useHead } from '#app';
import { useStorage } from '@vueuse/core';
import CustomGuidForm from '~/components/CustomGuidForm.vue';
import CustomLinkForm from '~/components/CustomLinkForm.vue';
import { useDirtyCloseGuard } from '~/composables/useDirtyCloseGuard';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import { request, makeConsoleCommand, notification, parse_api_response, ucFirst } from '~/utils';
import { useDialog } from '~/composables/useDialog';
import type { CustomGUID, CustomLink, GenericError, GenericResponse } from '~/types';

type CustomLinkWithUI = CustomLink & { show?: boolean };
useHead({ title: 'Custom Guids' });

const pageShell = requireTopLevelPageShell('custom');

const guids = ref<Array<CustomGUID>>([]);
const links = ref<Array<CustomLinkWithUI>>([]);
const show_page_tips = useStorage('show_page_tips', true);
const isLoading = ref<boolean>(false);
const addGuidModalOpen = ref<boolean>(false);
const addLinkModalOpen = ref<boolean>(false);
const addGuidDirty = ref<boolean>(false);
const addLinkDirty = ref<boolean>(false);
const dialog = useDialog();

const itemCardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
};

const tipsCardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
};

const formModalUi = {
  content: 'sm:max-w-4xl',
  body: 'p-4 sm:p-5',
};

const { handleOpenChange: handleAddGuidOpenChange, requestClose: requestCloseAddGuid } =
  useDirtyCloseGuard(addGuidModalOpen, {
    dirty: addGuidDirty,
    onDiscard: async () => {
      addGuidDirty.value = false;
    },
  });

const { handleOpenChange: handleAddLinkOpenChange, requestClose: requestCloseAddLink } =
  useDirtyCloseGuard(addLinkModalOpen, {
    dirty: addLinkDirty,
    onDiscard: async () => {
      addLinkDirty.value = false;
    },
  });

const loadContent = async (): Promise<void> => {
  isLoading.value = true;
  try {
    const response = await request('/system/guids/custom');
    const data = await parse_api_response<{
      guids: Record<string, CustomGUID>;
      links: Record<string, CustomLink>;
    }>(response);

    if ('error' in data) {
      notification('error', 'Error', data.error.message);
      return;
    }

    // Clear existing data
    guids.value = [];
    links.value = [];

    // Convert object values to arrays
    guids.value = Object.values(data.guids);
    links.value = Object.values(data.links).map((link) => ({
      ...link,
      show: false,
    }));
  } catch (error: unknown) {
    const message = error instanceof Error ? error.message : 'Unexpected error';
    notification('error', 'Error', message);
  } finally {
    isLoading.value = false;
  }
};

onMounted(async (): Promise<void> => {
  await loadContent();
});

const handleGuidSaved = async (): Promise<void> => {
  addGuidDirty.value = false;
  addGuidModalOpen.value = false;
  await loadContent();
};

const handleLinkSaved = async (): Promise<void> => {
  addLinkDirty.value = false;
  addLinkModalOpen.value = false;
  await loadContent();
};

const deleteGUID = async (index: number, guid: CustomGUID): Promise<void> => {
  const { status } = await dialog.confirmDialog({
    title: 'Delete GUID',
    message: `Delete '${guid.name}'? links using this GUID will be deleted as well.`,
    confirmColor: 'error',
  });

  if (true !== status) {
    return;
  }

  try {
    const response = await request(`/system/guids/custom/${guid.id}`, { method: 'DELETE' });
    if (!response.ok) {
      const result = await parse_api_response<GenericResponse>(response);
      if ('error' in result) {
        const errorJson = result as GenericError;
        notification('error', 'Error', errorJson.error.message);
      } else {
        notification('error', 'Error', 'Failed to delete GUID.');
      }
      return;
    }

    guids.value.splice(index, 1);
    links.value = links.value.filter((link) => link.map.to !== guid.name);

    notification('success', 'Success', `The GUID '${guid.name}' has been deleted.`);
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Unexpected error';
    notification('error', 'Error', message);
  }
};

const deleteLink = async (index: number, link: CustomLinkWithUI): Promise<void> => {
  const { status } = await dialog.confirmDialog({
    title: 'Delete Link',
    message: `Are you sure you want to delete the '${link.type}' - '${link.id}'?`,
    confirmColor: 'error',
  });

  if (true !== status) {
    return;
  }

  try {
    const response = await request(`/system/guids/custom/${link.type}/${link.id}`, {
      method: 'DELETE',
    });
    if (!response.ok) {
      const result = await parse_api_response<GenericResponse>(response);
      if ('error' in result) {
        const errorJson = result as GenericError;
        notification('error', 'Error', errorJson.error.message);
      } else {
        notification('error', 'Error', 'Failed to delete link.');
      }
      return;
    }

    links.value.splice(index, 1);
    notification('success', 'Success', `The link '${link.type}' - '${link.id}' has been deleted.`);
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Unexpected error';
    notification('error', 'Error', message);
  }
};
</script>
