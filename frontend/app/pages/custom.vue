<template>
  <main class="w-full min-w-0 max-w-full space-y-6">
    <PageHeader v-bind="pageShell" description="Support additional metadata providers.">
      <template #actions>
        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-refresh-cw"
          :loading="isLoading"
          :disabled="busy"
          @click="loadContent"
          label="Reload"
        />
      </template>
    </PageHeader>

    <UAlert
      v-if="isLoading"
      color="info"
      variant="soft"
      icon="i-lucide-loader-circle"
      title="Loading GUID definitions and client links"
      :ui="{ icon: 'animate-spin' }"
    />

    <template v-else>
      <UAlert
        v-if="loadError"
        color="error"
        variant="soft"
        icon="i-lucide-triangle-alert"
        title="Unable to load custom GUIDs and links"
        :description="loadError"
      />

      <div
        v-if="0 < guids.length || 0 < links.length"
        class="flex flex-wrap items-end justify-between gap-3"
      >
        <UFormField label="Filter GUIDs and links" name="rule-filter" class="w-full sm:max-w-sm">
          <UInput
            v-model="query"
            type="search"
            icon="i-lucide-search"
            placeholder="GUID, client, or mapping"
            class="w-full"
          />
        </UFormField>
        <p class="text-sm text-toned" aria-live="polite">
          {{ filteredGuids.length }}{{ query.trim() ? ` of ${guids.length}` : '' }}
          {{ 1 === guids.length ? 'definition' : 'definitions' }}, {{ filteredLinks.length
          }}{{ query.trim() ? ` of ${links.length}` : '' }}
          {{ 1 === links.length ? 'link' : 'links' }}
        </p>
      </div>

      <section aria-labelledby="guid-definitions-heading" class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <h2 id="guid-definitions-heading" class="text-lg font-semibold text-highlighted">
            GUID definitions
          </h2>
          <UButton
            color="primary"
            variant="solid"
            size="sm"
            icon="i-lucide-plus"
            :disabled="busy"
            @click="addGuidModalOpen = true"
            label="Add GUID"
          />
        </div>

        <ul v-if="0 < filteredGuids.length" class="ws-card divide-y divide-default">
          <li
            v-for="guid in filteredGuids"
            :key="guid.id"
            class="flex flex-wrap items-start justify-between gap-3 p-4"
          >
            <div class="min-w-0 flex-1 space-y-1">
              <h3 class="wrap-anywhere font-medium text-highlighted">{{ guid.name }}</h3>
              <p v-if="guid.description" class="wrap-anywhere text-sm text-toned">
                {{ guid.description }}
              </p>
            </div>
            <UButton
              color="neutral"
              variant="outline"
              size="sm"
              icon="i-lucide-trash-2"
              :disabled="busy || !!loadError"
              :aria-label="`Delete GUID ${guid.name}`"
              @click="deleteGUID(guid)"
              label="Delete"
            />
          </li>
        </ul>
        <UEmpty
          v-else-if="!loadError"
          size="sm"
          variant="naked"
          icon="i-lucide-fingerprint"
          :title="0 < guids.length ? 'No matching GUID definitions' : 'No custom GUIDs'"
        />
      </section>

      <section aria-labelledby="client-links-heading" class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <h2 id="client-links-heading" class="text-lg font-semibold text-highlighted">
            Client links
          </h2>
          <UButton
            color="primary"
            variant="solid"
            size="sm"
            icon="i-lucide-plus"
            :disabled="busy"
            @click="addLinkModalOpen = true"
            label="Add link"
          />
        </div>

        <ul v-if="0 < filteredLinks.length" class="ws-card divide-y divide-default">
          <li v-for="link in filteredLinks" :key="`${link.type}-${link.id}`" class="space-y-3 p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div class="min-w-0 flex-1 space-y-1">
                <h3 class="font-medium text-highlighted">{{ ucFirst(link.type) }}</h3>
                <p class="wrap-anywhere text-sm">
                  <span class="text-toned">Client </span>
                  <code>{{ link.map.from }}</code>
                  <span aria-hidden="true"> → </span>
                  <span class="sr-only">maps to</span>
                  <span class="text-toned">WatchState </span>
                  <code>{{ link.map.to }}</code>
                </p>
              </div>
              <UButton
                color="neutral"
                variant="outline"
                size="sm"
                icon="i-lucide-trash-2"
                :disabled="busy || !!loadError"
                :aria-label="`Delete ${ucFirst(link.type)} link ${link.map.from} to ${link.map.to}`"
                @click="deleteLink(link)"
                label="Delete"
              />
            </div>

            <details
              v-if="
                'plex' === link.type && (undefined !== link.options?.legacy || link.replace?.from)
              "
              class="text-sm"
            >
              <summary class="w-fit cursor-pointer font-medium text-toned">Plex options</summary>
              <dl class="mt-3 space-y-2">
                <div v-if="undefined !== link.options?.legacy" class="flex flex-wrap gap-x-3">
                  <dt class="text-toned">Legacy agent</dt>
                  <dd>{{ link.options.legacy ? 'Yes' : 'No' }}</dd>
                </div>
                <template v-if="link.replace?.from">
                  <div class="space-y-1">
                    <dt class="text-toned">Search for</dt>
                    <dd class="wrap-anywhere font-mono">{{ link.replace.from }}</dd>
                  </div>
                  <div class="space-y-1">
                    <dt class="text-toned">Replace with</dt>
                    <dd class="wrap-anywhere font-mono">{{ link.replace.to }}</dd>
                  </div>
                </template>
              </dl>
            </details>
          </li>
        </ul>
        <UEmpty
          v-else-if="!loadError"
          size="sm"
          variant="naked"
          icon="i-lucide-arrow-right-left"
          :title="0 < links.length ? 'No matching client links' : 'No client links'"
        />
      </section>

      <details class="text-sm">
        <summary class="w-fit cursor-pointer font-medium text-toned">GUID setup notes</summary>
        <ul class="mt-3 list-disc space-y-2 pl-5 text-toned">
          <li>A custom definition needs a client link before WatchState can parse its GUIDs.</li>
          <li>GUID names must be unique. Each client GUID can link to only one WatchState GUID.</li>
          <li>
            After adding or removing a definition, run
            <NuxtLink
              :to="makeConsoleCommand('db:index --force-reindex')"
              class="text-primary hover:underline"
            >
              <code>db:index --force-reindex</code>
            </NuxtLink>
            in Console.
          </li>
          <li>
            <NuxtLink
              to="/help/faq#advanced-how-to-extend-the-guid-parser-to-support-more-guids-or-custom-ones"
              class="inline-flex items-center gap-1 text-primary hover:underline"
            >
              Custom GUID setup guide
              <UIcon name="i-lucide-book-open" class="size-3.5" />
            </NuxtLink>
          </li>
        </ul>
      </details>
    </template>

    <UModal
      :open="addGuidModalOpen"
      title="Add GUID"
      :ui="formModalUi"
      :dismissible="!addGuidSaving"
      :close="!addGuidSaving"
      @update:open="handleAddGuidOpenChange"
    >
      <template #body>
        <CustomGuidForm
          v-if="addGuidModalOpen"
          :actions-target="`#${addGuidFooterId}`"
          @saved="handleGuidSaved"
          @dirty-change="(dirty) => (addGuidDirty = dirty)"
          @saving-change="(saving) => (addGuidSaving = saving)"
        />
      </template>
      <template #footer>
        <div :id="addGuidFooterId" class="w-full" />
      </template>
    </UModal>

    <UModal
      :open="addLinkModalOpen"
      title="Add client link"
      :ui="formModalUi"
      :dismissible="!addLinkSaving"
      :close="!addLinkSaving"
      @update:open="handleAddLinkOpenChange"
    >
      <template #body>
        <CustomLinkForm
          v-if="addLinkModalOpen"
          :actions-target="`#${addLinkFooterId}`"
          @saved="handleLinkSaved"
          @dirty-change="(dirty) => (addLinkDirty = dirty)"
          @saving-change="(saving) => (addLinkSaving = saving)"
        />
      </template>
      <template #footer>
        <div :id="addLinkFooterId" class="w-full" />
      </template>
    </UModal>
  </main>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount, useId } from 'vue';
import { useHead } from '#app';
import { NuxtLink, UAlert, UButton, UEmpty, UFormField, UIcon, UInput, UModal } from '#components';
import CustomGuidForm from '~/components/CustomGuidForm.vue';
import CustomLinkForm from '~/components/CustomLinkForm.vue';
import PageHeader from '~/components/PageHeader.vue';
import { useDirtyCloseGuard } from '~/composables/useDirtyCloseGuard';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import { request, makeConsoleCommand, notification, parse_api_response, ucFirst } from '~/utils';
import { useDialog } from '~/composables/useDialog';
import type { CustomGUID, CustomLink, GenericResponse } from '~/types';

useHead({ title: 'Custom GUIDs' });

const pageShell = requireTopLevelPageShell('custom');
const addGuidFooterId = useId();
const addLinkFooterId = useId();
const guids = ref<Array<CustomGUID>>([]);
const links = ref<Array<CustomLink>>([]);
const query = ref('');
const isLoading = ref(false);
const isDeleting = ref(false);
const loadError = ref('');
const addGuidModalOpen = ref(false);
const addLinkModalOpen = ref(false);
const addGuidDirty = ref(false);
const addLinkDirty = ref(false);
const addGuidSaving = ref(false);
const addLinkSaving = ref(false);
const dialog = useDialog();
let disposed = false;

const busy = computed(
  () =>
    isLoading.value ||
    isDeleting.value ||
    addGuidModalOpen.value ||
    addLinkModalOpen.value ||
    addGuidSaving.value ||
    addLinkSaving.value,
);

const filteredGuids = computed(() => {
  const filter = query.value.trim().toLowerCase();
  return guids.value.filter((guid) =>
    [guid.name, guid.description].some((value) => value.toLowerCase().includes(filter)),
  );
});

const filteredLinks = computed(() => {
  const filter = query.value.trim().toLowerCase();
  return links.value.filter((link) =>
    [link.type, link.map.from, link.map.to, link.replace?.from ?? '', link.replace?.to ?? ''].some(
      (value) => value.toLowerCase().includes(filter),
    ),
  );
});

const formModalUi = {
  content: 'sm:max-w-3xl',
  body: 'p-4 sm:p-5',
};

const { handleOpenChange: handleAddGuidOpenChange } = useDirtyCloseGuard(addGuidModalOpen, {
  dirty: addGuidDirty,
  onDiscard: () => {
    addGuidDirty.value = false;
  },
});

const { handleOpenChange: handleAddLinkOpenChange } = useDirtyCloseGuard(addLinkModalOpen, {
  dirty: addLinkDirty,
  onDiscard: () => {
    addLinkDirty.value = false;
  },
});

const loadContent = async (): Promise<void> => {
  if (disposed || busy.value) {
    return;
  }

  isLoading.value = true;
  loadError.value = '';

  try {
    const response = await request('/system/guids/custom');
    const data = await parse_api_response<{
      guids: Record<string, CustomGUID>;
      links: Record<string, CustomLink>;
    }>(response);

    if (disposed) {
      return;
    }

    if ('error' in data) {
      loadError.value = data.error.message;
      notification('error', 'Unable to load custom GUIDs and links', data.error.message);
      return;
    }

    guids.value = Object.values(data.guids);
    links.value = Object.values(data.links);
  } catch (error: unknown) {
    if (disposed) {
      return;
    }

    const message = error instanceof Error ? error.message : 'Unexpected error';
    loadError.value = message;
    notification('error', 'Unable to load custom GUIDs and links', message);
  } finally {
    isLoading.value = false;
  }
};

onMounted(loadContent);
onBeforeUnmount(() => {
  disposed = true;
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

const deleteGUID = async (guid: CustomGUID): Promise<void> => {
  if (disposed || busy.value || loadError.value || !guid.id) {
    return;
  }

  const { id, name } = guid;
  const currentGuids = guids.value;
  const currentLinks = links.value;
  isDeleting.value = true;

  try {
    const { status } = await dialog.confirmDialog({
      title: 'Delete GUID',
      message: `Delete '${name}'? All client links to this GUID will also be deleted.`,
      confirmText: 'Delete GUID',
      confirmColor: 'error',
    });

    if (
      true !== status ||
      disposed ||
      currentGuids !== guids.value ||
      currentLinks !== links.value
    ) {
      return;
    }

    const response = await request(`/system/guids/custom/${id}`, { method: 'DELETE' });
    if (disposed) {
      return;
    }

    if (!response.ok) {
      const result = await parse_api_response<GenericResponse>(response);
      notification(
        'error',
        'GUID not deleted',
        'error' in result ? result.error.message : 'Failed to delete GUID.',
      );
      return;
    }

    guids.value = guids.value.filter((item) => id !== item.id);
    links.value = links.value.filter((link) => name !== link.map.to);
    notification('success', 'GUID deleted', `'${name}' and its client links were removed.`);
  } catch (error: unknown) {
    if (!disposed) {
      const message = error instanceof Error ? error.message : 'Unexpected error';
      notification('error', 'GUID not deleted', message);
    }
  } finally {
    isDeleting.value = false;
  }
};

const deleteLink = async (link: CustomLink): Promise<void> => {
  if (disposed || busy.value || loadError.value || !link.id) {
    return;
  }

  const { id, type } = link;
  const { from, to } = link.map;
  const currentLinks = links.value;
  isDeleting.value = true;

  try {
    const { status } = await dialog.confirmDialog({
      title: 'Delete client link',
      message: `Delete the ${ucFirst(type)} link from '${from}' to '${to}'? This removes the mapping for all ${ucFirst(type)} backends.`,
      confirmText: 'Delete link',
      confirmColor: 'error',
    });

    if (true !== status || disposed || currentLinks !== links.value) {
      return;
    }

    const response = await request(`/system/guids/custom/${type}/${id}`, { method: 'DELETE' });
    if (disposed) {
      return;
    }

    if (!response.ok) {
      const result = await parse_api_response<GenericResponse>(response);
      notification(
        'error',
        'Link not deleted',
        'error' in result ? result.error.message : 'Failed to delete link.',
      );
      return;
    }

    links.value = links.value.filter((item) => id !== item.id || type !== item.type);
    notification(
      'success',
      'Link deleted',
      `The ${ucFirst(type)} link from '${from}' to '${to}' has been deleted.`,
    );
  } catch (error: unknown) {
    if (!disposed) {
      const message = error instanceof Error ? error.message : 'Unexpected error';
      notification('error', 'Link not deleted', message);
    }
  } finally {
    isDeleting.value = false;
  }
};
</script>
