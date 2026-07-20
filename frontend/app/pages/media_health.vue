<template>
  <div class="space-y-6">
    <PageHeader v-bind="pageShell" description="Review media health across configured backends.">
      <template #actions>
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

        <USelect
          v-if="report"
          v-model="status"
          :items="statusOptions"
          value-key="value"
          label-key="label"
          size="sm"
          class="w-42"
          :disabled="isLoading"
        />

        <USelect
          v-if="report"
          v-model="type"
          :items="typeOptions"
          value-key="value"
          label-key="label"
          size="sm"
          class="w-32"
          :disabled="isLoading"
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
          v-if="report && (UNHEALTHY_FILTER_VALUE !== status || ALL_FILTER_VALUE !== type)"
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-filter-x"
          :disabled="isLoading"
          @click="clearFilters"
        >
          <span class="hidden sm:inline">Clear</span>
        </UButton>

        <UButton
          v-if="report"
          color="neutral"
          :variant="selectAll ? 'soft' : 'outline'"
          size="sm"
          :icon="!selectAll ? 'i-lucide-square-check' : 'i-lucide-square'"
          :disabled="isLoading || massActionInProgress || filteredItems.length < 1"
          @click="selectAll = !selectAll"
        >
          <span class="hidden sm:inline">{{ !selectAll ? 'Select' : 'Unselect' }}</span>
        </UButton>

        <UButton
          v-if="report"
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-trash-2"
          :loading="massActionInProgress"
          :disabled="isLoading || massActionInProgress || selected_ids.length < 1"
          @click="massDelete"
        >
          <span class="hidden sm:inline">Delete</span>
        </UButton>

        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-refresh-cw"
          :loading="isLoading"
          :disabled="isLoading"
          @click="loadContent(page)"
        >
          <span class="hidden sm:inline">Reload</span>
        </UButton>

        <UDropdownMenu
          v-if="report"
          :items="exportMenuItems"
          :content="{ align: 'end' }"
          :modal="false"
        >
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-download"
            trailing-icon="i-lucide-chevron-down"
            :loading="null !== exporting"
            :disabled="null !== exporting"
          >
            <span class="hidden sm:inline">Export</span>
          </UButton>
        </UDropdownMenu>

        <UButton
          color="primary"
          variant="solid"
          size="sm"
          icon="i-lucide-play"
          :loading="isQueueing"
          :disabled="isQueueing || reportState?.queued"
          @click="queueReport"
        >
          <span class="hidden sm:inline">{{ reportState?.queued ? 'Queued' : 'Queue Audit' }}</span>
        </UButton>
      </template>
    </PageHeader>

    <UAlert
      v-if="reportState?.queued"
      color="info"
      variant="soft"
      icon="i-lucide-clock-3"
      title="Audit queued"
      description="Media health audit is queued or running in the background. Reload this page after the task completes."
    />

    <UAlert
      v-if="reportState?.stale"
      color="warning"
      variant="soft"
      icon="i-lucide-triangle-alert"
      title="Audit may be stale"
      description="State data changed after this report was generated. Queue a new report for fresh results."
    />

    <UAlert
      v-if="!isLoading && !report"
      color="warning"
      variant="soft"
      icon="i-lucide-file-warning"
      title="No audit found"
      description="Queue a media health audit to inspect records health."
    />

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
          :loading="massActionInProgress"
          :disabled="massActionInProgress"
          @click="massDelete"
        >
          Delete
        </UButton>
      </div>

      <div class="text-xs text-toned">{{ filteredItems.length }} displayed</div>
    </div>

    <div v-if="report" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
      <div class="rounded-md border border-default bg-elevated/40 px-3 py-2">
        <div class="text-xs font-medium uppercase tracking-[0.16em] text-toned">Actionable</div>
        <div class="text-xl font-semibold text-warning">
          {{ formatNumber(report.summary.actionable_count) }}
        </div>
      </div>

      <div class="rounded-md border border-default bg-elevated/40 px-3 py-2">
        <div class="text-xs font-medium uppercase tracking-[0.16em] text-toned">Healthy</div>
        <div class="text-xl font-semibold text-success">
          {{ formatNumber(statusCount('healthy')) }}
        </div>
      </div>

      <div class="rounded-md border border-default bg-elevated/40 px-3 py-2">
        <div class="text-xs font-medium uppercase tracking-[0.16em] text-toned">Records</div>
        <div class="text-xl font-semibold text-highlighted">
          {{ formatNumber(report.state_count) }}
        </div>
      </div>

      <div class="rounded-md border border-default bg-elevated/40 px-3 py-2">
        <div class="text-xs font-medium uppercase tracking-[0.16em] text-toned">Generated</div>
        <UTooltip :text="formatDate(report.completed_at)">
          <div class="cursor-help text-sm font-semibold text-highlighted">
            {{ relativeDate(report.completed_at) }}
          </div>
        </UTooltip>
      </div>
    </div>

    <Pager
      v-if="total && last_page > 1"
      :page="page"
      :last_page="last_page"
      :is-loading="isLoading"
      @navigate="loadContent"
    />

    <UAlert
      v-if="isLoading"
      color="info"
      variant="soft"
      icon="i-lucide-loader-circle"
      title="Loading"
      description="Loading report data. Please wait..."
      :ui="{ icon: 'animate-spin' }"
    />

    <UAlert
      v-else-if="filteredItems.length < 1 && filter && items.length > 0"
      color="warning"
      variant="soft"
      icon="i-lucide-circle-check"
      title="Information"
    >
      <template #description>
        <p class="text-sm text-default">
          The filter <code>{{ filter }}</code> did not match any records on this page.
        </p>
      </template>
    </UAlert>

    <div v-else-if="filteredItems.length > 0" class="grid gap-4 xl:grid-cols-2">
      <Lazy
        v-for="item in filteredItems"
        :key="item.id"
        :unrender="true"
        :min-height="380"
        class="block"
      >
        <UCard
          class="h-full shadow-sm"
          :class="item.severity >= 80 ? 'ring-1 ring-error/20' : ''"
          :ui="itemCardUi"
        >
          <template #header>
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0 flex flex-1 items-start gap-2">
                <UIcon :name="mediaIcon(item.type)" class="mt-0.5 size-4 shrink-0 text-toned" />

                <div class="min-w-0 flex-1 text-base font-semibold text-highlighted">
                  <FloatingImage
                    :image="`/history/${item.state_id}/images/poster`"
                    v-if="poster_enable"
                  >
                    <UTooltip :text="String(makeName(item as unknown as JsonObject))">
                      <NuxtLink
                        :to="`/history/${item.state_id}`"
                        class="block truncate text-highlighted hover:text-primary"
                      >
                        {{ makeName(item as unknown as JsonObject) }}
                      </NuxtLink>
                    </UTooltip>
                  </FloatingImage>

                  <UTooltip v-else :text="String(makeName(item as unknown as JsonObject))">
                    <NuxtLink
                      :to="`/history/${item.state_id}`"
                      class="block truncate text-highlighted hover:text-primary"
                    >
                      {{ makeName(item as unknown as JsonObject) }}
                    </NuxtLink>
                  </UTooltip>
                </div>
              </div>

              <div class="flex shrink-0 items-center gap-2">
                <UBadge :color="severityColor(item.severity)" variant="outline">
                  {{ item.severity }}
                </UBadge>
                <UBadge
                  :color="
                    item.backend_count === item.expected_backend_count ? 'success' : 'warning'
                  "
                  variant="soft"
                >
                  {{ item.backend_count }}/{{ item.expected_backend_count }}
                </UBadge>
                <UTooltip
                  :text="selected_ids.includes(item.state_id) ? 'Unselect item' : 'Select item'"
                >
                  <UCheckbox
                    color="primary"
                    :model-value="selected_ids.includes(item.state_id)"
                    @update:model-value="toggleSelected(item.state_id, $event)"
                  />
                </UTooltip>
              </div>
            </div>
          </template>

          <div class="space-y-3">
            <div class="rounded-md border border-default bg-elevated/40 px-3 py-2.5">
              <div class="mb-1 flex items-center gap-2 font-medium text-highlighted">
                <UIcon :name="statusIcon(item.status)" class="size-4 text-toned" />
                <span>{{ statusActionTitle(item.status) }}</span>
              </div>
              <p class="text-sm leading-6 text-default">{{ statusActionText(item.status) }}</p>
            </div>

            <div
              v-if="guidConflictEntries(item).length > 0"
              class="rounded-md border border-error/30 bg-error/5 px-3 py-2.5"
            >
              <div class="mb-2 flex items-center gap-2 font-medium text-error">
                <UIcon name="i-lucide-git-compare-arrows" class="size-4" />
                <span>GUID conflicts</span>
              </div>

              <div class="space-y-2">
                <div
                  v-for="[key, values] in guidConflictEntries(item)"
                  :key="`${item.id}-${key}`"
                  class="space-y-2 rounded-md border border-default bg-elevated/40 p-2"
                >
                  <div class="text-xs font-semibold uppercase tracking-[0.14em] text-toned">
                    {{ guidSource(key) }}
                  </div>

                  <div
                    v-for="[value, backends] in objectEntries(values)"
                    :key="`${item.id}-${key}-${value}`"
                    class="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-start sm:justify-between"
                  >
                    <NuxtLink
                      :to="guidLink(item, key, value)"
                      target="_blank"
                      class="block min-w-0 truncate font-mono text-xs text-primary hover:underline"
                    >
                      {{ guidSource(key) }}://{{ value }}
                    </NuxtLink>

                    <div class="flex flex-wrap gap-1.5">
                      <BackendItemLink
                        v-for="backend in backends"
                        :key="`${item.id}-${key}-${value}-${backend}`"
                        :backend="backend"
                        :url="backendItemUrl(item, backend)"
                        color="error"
                      />
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div
              v-if="item.signals.duplicate_guid"
              class="rounded-md border border-error/30 bg-error/5 px-3 py-2.5"
            >
              <div class="mb-2 flex items-center gap-2 font-medium text-error">
                <UIcon name="i-lucide-fingerprint" class="size-4" />
                <span>Duplicate GUID</span>
              </div>

              <div class="space-y-2 text-sm text-default">
                <NuxtLink
                  :to="
                    guidLink(
                      item,
                      item.signals.duplicate_guid.key,
                      String(item.signals.duplicate_guid.value),
                    )
                  "
                  target="_blank"
                  class="block min-w-0 truncate font-mono text-xs text-primary hover:underline"
                >
                  {{ guidSource(item.signals.duplicate_guid.key) }}://{{
                    item.signals.duplicate_guid.value
                  }}
                </NuxtLink>

                <div class="flex flex-wrap gap-1.5">
                  <HistoryRecordLink
                    v-for="stateId in item.signals.duplicate_guid.state_ids"
                    :key="`${item.id}-dg-${stateId}`"
                    :state-id="stateId"
                    :current-state-id="item.state_id"
                  />
                </div>
              </div>
            </div>

            <div
              v-if="item.signals.duplicate_reference"
              class="rounded-md border border-warning/30 bg-warning/5 px-3 py-2.5"
            >
              <div class="mb-2 flex items-center gap-2 font-medium text-warning">
                <UIcon name="i-lucide-layers-3" class="size-4" />
                <span>Duplicate file reference</span>
              </div>

              <div class="space-y-2">
                <div
                  class="grid min-w-0 grid-cols-[auto_minmax(0,1fr)_auto] items-start gap-2 text-sm text-default"
                >
                  <button
                    type="button"
                    class="mt-0.5 shrink-0 text-toned hover:text-primary"
                    @click="item.expand_duplicate_path = !item.expand_duplicate_path"
                  >
                    <UIcon name="i-lucide-file-text" class="size-4" />
                  </button>
                  <NuxtLink
                    :to="makeSearchLink('path', item.signals.duplicate_reference.path)"
                    class="min-w-0 flex-1 hover:text-primary"
                    :class="item.expand_duplicate_path ? 'wrap-break-word' : 'truncate'"
                  >
                    {{ item.signals.duplicate_reference.path }}
                  </NuxtLink>
                  <UButton
                    color="neutral"
                    variant="ghost"
                    size="xs"
                    square
                    icon="i-lucide-copy"
                    aria-label="Copy duplicate path"
                    @click="copyText(item.signals.duplicate_reference?.path ?? '', false)"
                  />
                </div>

                <div class="flex flex-wrap gap-1.5">
                  <HistoryRecordLink
                    v-for="stateId in duplicateReferenceStateIds(item)"
                    :key="`${item.id}-dr-${stateId}`"
                    :state-id="stateId"
                    :current-state-id="item.state_id"
                  />
                </div>
              </div>
            </div>

            <div
              v-if="metadataConflictEntries(item).length > 0"
              class="rounded-md border border-warning/30 bg-warning/5 px-3 py-2.5"
            >
              <div class="mb-2 flex items-center gap-2 font-medium text-warning">
                <UIcon name="i-lucide-list-x" class="size-4" />
                <span>Metadata disagreement</span>
              </div>

              <div class="space-y-2">
                <div
                  v-for="[field, values] in metadataConflictEntries(item)"
                  :key="`${item.id}-metadata-${field}`"
                  class="space-y-2 rounded-md border border-default bg-elevated/40 p-2"
                >
                  <div class="text-xs font-semibold uppercase tracking-[0.14em] text-toned">
                    {{ statusLabel(field) }}
                  </div>

                  <div
                    v-for="[value, backends] in objectEntries(values)"
                    :key="`${item.id}-metadata-${field}-${value}`"
                    class="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-start sm:justify-between"
                  >
                    <span class="min-w-0 wrap-break-word text-sm font-medium text-default">
                      {{ value }}
                    </span>

                    <div class="flex flex-wrap gap-1.5">
                      <BackendItemLink
                        v-for="backend in backends"
                        :key="`${item.id}-metadata-${field}-${value}-${backend}`"
                        :backend="backend"
                        :url="backendItemUrl(item, backend)"
                        color="warning"
                      />
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div
              v-if="missingFileChecks(item).length > 0"
              class="rounded-md border border-error/30 bg-error/5 px-3 py-2.5"
            >
              <div class="mb-2 flex items-center gap-2 font-medium text-error">
                <UIcon name="i-lucide-file-x-2" class="size-4" />
                <span>Missing files</span>
              </div>

              <div class="space-y-2">
                <div
                  v-for="check in missingFileChecks(item)"
                  :key="`${item.id}-file-${check.backend}-${check.path}`"
                  class="grid min-w-0 grid-cols-[8rem_auto_minmax(0,1fr)_auto] items-start gap-2 text-sm text-default"
                >
                  <BackendItemLink
                    :backend="check.backend"
                    :url="backendItemUrl(item, check.backend)"
                    color="error"
                  />
                  <button
                    type="button"
                    class="mt-0.5 shrink-0 text-toned hover:text-primary"
                    @click="item.expand_file_checks = !item.expand_file_checks"
                  >
                    <UIcon name="i-lucide-file-x-2" class="size-4" />
                  </button>
                  <NuxtLink
                    v-if="check.path"
                    :to="makeSearchLink('path', check.path)"
                    class="min-w-0 flex-1 hover:text-primary"
                    :class="item.expand_file_checks ? 'wrap-break-word' : 'truncate'"
                  >
                    {{ check.path }}
                  </NuxtLink>
                  <span v-else class="min-w-0 flex-1 truncate text-toned">No path</span>
                  <UButton
                    v-if="check.path"
                    color="neutral"
                    variant="ghost"
                    size="xs"
                    square
                    icon="i-lucide-copy"
                    aria-label="Copy missing file path"
                    @click="copyText(check.path, false)"
                  />
                </div>
              </div>
            </div>

            <div
              v-if="missingBackendNames(item).length > 0"
              class="rounded-md border border-warning/30 bg-warning/5 px-3 py-2.5"
            >
              <div class="mb-2 flex items-center gap-2 font-medium text-warning">
                <UIcon name="i-lucide-server-off" class="size-4" />
                <span>Missing backend metadata</span>
              </div>

              <div class="flex flex-wrap gap-1.5">
                <BackendItemLink
                  v-for="backend in missingBackendNames(item)"
                  :key="`${item.id}-missing-${backend}`"
                  :backend="backend"
                  color="warning"
                  icon="i-lucide-circle-alert"
                />
              </div>
            </div>

            <div
              v-if="pathRows(item).length > 1"
              class="rounded-md border border-default bg-elevated/40 px-3 py-2.5"
            >
              <div class="mb-2 flex items-center gap-2 font-medium text-highlighted">
                <UIcon name="i-lucide-file-text" class="size-4 text-toned" />
                <span>Backend paths</span>
              </div>

              <div class="space-y-2">
                <div
                  v-for="group in pathRows(item)"
                  :key="`${item.id}-path-${group.path}`"
                  class="grid min-w-0 grid-cols-[8rem_auto_minmax(0,1fr)_auto] items-start gap-2 text-sm text-default"
                >
                  <div class="flex min-w-0 flex-wrap gap-1.5">
                    <BackendItemLink
                      v-for="backend in group.backends"
                      :key="`${item.id}-path-${group.path}-${backend}`"
                      :backend="backend"
                      :url="backendItemUrl(item, backend)"
                      color="neutral"
                    />
                  </div>
                  <button
                    type="button"
                    class="mt-0.5 shrink-0 text-toned hover:text-primary"
                    @click="item.expand_paths = !item.expand_paths"
                  >
                    <UIcon name="i-lucide-file-text" class="size-4" />
                  </button>
                  <UTooltip v-if="group.path" :text="group.path">
                    <NuxtLink
                      v-if="group.path"
                      :to="makeSearchLink('path', group.path)"
                      class="min-w-0 flex-1 hover:text-primary"
                      :class="item.expand_paths ? 'wrap-break-word' : 'truncate'"
                    >
                      {{ group.path }}
                    </NuxtLink>
                  </UTooltip>
                  <span v-else class="min-w-0 flex-1 truncate text-toned">No metadata</span>
                  <UButton
                    v-if="group.path"
                    color="neutral"
                    variant="ghost"
                    size="xs"
                    square
                    icon="i-lucide-copy"
                    aria-label="Copy backend path"
                    @click="copyText(group.path, false)"
                  />
                </div>
              </div>
            </div>

            <div
              v-if="objectEntries(item.signals.guids).length > 0"
              class="rounded-md border border-default bg-elevated/40 px-3 py-2.5"
            >
              <div class="mb-2 flex items-center gap-2 font-medium text-highlighted">
                <UIcon name="i-lucide-link" class="size-4 text-toned" />
                <span>Stored GUIDs</span>
              </div>

              <div class="flex flex-wrap gap-2">
                <UBadge
                  v-for="[key, value] in objectEntries(item.signals.guids)"
                  :key="`${item.id}-guid-${key}-${value}`"
                  color="neutral"
                  variant="soft"
                  class="max-w-full"
                >
                  <NuxtLink
                    :to="guidLink(item, key, String(value))"
                    target="_blank"
                    class="block max-w-64 truncate hover:text-primary"
                  >
                    {{ guidSource(key) }}://{{ value }}
                  </NuxtLink>
                </UBadge>
              </div>
            </div>

            <div v-if="showReasonDetails(item)">
              <button
                type="button"
                class="flex items-center gap-1.5 text-xs font-medium text-toned hover:text-default"
                @click="item.show_reasons = !item.show_reasons"
              >
                <UIcon
                  name="i-lucide-chevron-right"
                  :class="['size-3.5 transition-transform', item.show_reasons ? 'rotate-90' : '']"
                />
                <span>Details ({{ item.reasons.length }})</span>
              </button>

              <ul
                v-if="item.show_reasons"
                class="mt-2 list-disc space-y-1 rounded-md border border-default bg-elevated/40 px-6 py-2 text-sm leading-6 text-default"
              >
                <li v-for="reason in item.reasons" :key="reason">{{ reason }}</li>
              </ul>
            </div>
          </div>
        </UCard>
      </Lazy>
    </div>

    <UAlert
      v-else-if="report"
      color="success"
      variant="soft"
      icon="i-lucide-check-circle"
      title="No items found"
      description="No report items match the selected filters."
    />

    <Pager
      v-if="total && last_page > 1"
      :page="page"
      :last_page="last_page"
      :is-loading="isLoading"
      @navigate="loadContent"
    />

    <UCard v-if="report" class="shadow-sm" :ui="tipsCardUi">
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
        <li>Backend names open the exact remote item when the backend supplied a source ID.</li>
        <li>Use record links to compare duplicate GUID or file reference records in history.</li>
        <li>
          After changing metadata in a backends, force import metadata from the effected backends,
          and then queue a new audit report to see the updated results. The report is a snapshot of
          the state at the time it was generated.
        </li>
      </ul>
    </UCard>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useHead, useRoute, useRouter } from '#app';
import { useStorage } from '@vueuse/core';
import moment from 'moment';
import { NuxtLink, UIcon } from '#components';
import FloatingImage from '~/components/FloatingImage.vue';
import BackendItemLink from '~/components/BackendItemLink.vue';
import HistoryRecordLink from '~/components/HistoryRecordLink.vue';
import Lazy from '~/components/Lazy.vue';
import PageHeader from '~/components/PageHeader.vue';
import Pager from '~/components/Pager.vue';
import { useDialog } from '~/composables/useDialog';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import {
  copyText,
  makeGUIDLink,
  makeName,
  makeSearchLink,
  notification,
  parse_api_response,
  request,
  TOOLTIP_DATE_FORMAT,
} from '~/utils';
import type {
  JsonObject,
  MediaHealthItem,
  MediaHealthReport,
  MediaHealthResponse,
  MediaHealthRunResponse,
  MediaHealthStatus,
  PaginatedResponse,
} from '~/types';

const pageShell = requireTopLevelPageShell('media-health');
const route = useRoute();
const router = useRouter();

useHead({ title: 'Media Health' });

type SelectItem = {
  label: string;
  value: string;
};

type MediaHealthItemWithUI = MediaHealthItem & {
  expand_duplicate_path?: boolean;
  expand_file_checks?: boolean;
  expand_paths?: boolean;
  show_reasons?: boolean;
};

type MediaHealthFileCheck = {
  backend: string;
  path: string;
  status: boolean;
  message: string;
};

type ItemsResponse = PaginatedResponse<MediaHealthItemWithUI> & {
  report: MediaHealthReport;
};

const ALL_FILTER_VALUE = 'all';
const UNHEALTHY_FILTER_VALUE = 'unhealthy';

const show_page_tips = useStorage('show_page_tips', true);
const poster_enable = useStorage('poster_enable', true);
const reportState = ref<MediaHealthResponse | null>(null);
const report = computed<MediaHealthReport | null>(() => reportState.value?.report ?? null);
const items = ref<Array<MediaHealthItemWithUI>>([]);
const page = ref<number>(Number(route.query.page ?? 1));
const perpage = ref<number>(Number(route.query.perpage ?? 50));
const total = ref<number>(0);
const last_page = computed<number>(() => Math.max(1, Math.ceil(total.value / perpage.value)));
const routeStatus = Array.isArray(route.query.status) ? route.query.status[0] : route.query.status;
const routeType = Array.isArray(route.query.type) ? route.query.type[0] : route.query.type;
const status = ref<string>(routeStatus ? String(routeStatus) : UNHEALTHY_FILTER_VALUE);
const type = ref<string>(routeType ? String(routeType) : ALL_FILTER_VALUE);
const filter = ref<string>(String(route.query.filter ?? ''));
const showFilter = ref<boolean>(!!filter.value);
const isLoading = ref<boolean>(false);
const isQueueing = ref<boolean>(false);
const selectAll = ref<boolean>(false);
const selected_ids = ref<Array<number>>([]);
const massActionInProgress = ref<boolean>(false);
const exporting = ref<'markdown' | 'json' | 'csv' | null>(null);

const itemCardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
};
const tipsCardUi = { header: 'p-4', body: 'px-4 pb-4 pt-0' };

const statusOptions = computed<Array<SelectItem>>(() => [
  { label: 'Unhealthy', value: UNHEALTHY_FILTER_VALUE },
  { label: 'All statuses', value: ALL_FILTER_VALUE },
  ...Object.keys(report.value?.summary.statuses ?? {}).map((value) => ({
    label: statusLabel(value as MediaHealthStatus),
    value,
  })),
]);

const typeOptions: Array<SelectItem> = [
  { label: 'All types', value: ALL_FILTER_VALUE },
  { label: 'Movie', value: 'movie' },
  { label: 'Episode', value: 'episode' },
];

const filteredItems = computed<Array<MediaHealthItemWithUI>>(() => {
  const term = filter.value.trim().toLowerCase();
  if ('' === term) {
    return items.value;
  }

  return items.value.filter((item) => {
    const haystack = [
      item.title,
      item.type,
      item.status,
      String(item.state_id),
      ...backendNames(item),
      ...missingBackendNames(item),
      ...Object.values(item.signals.paths ?? {}),
      ...missingFileChecks(item).map((check) => `${check.backend} ${check.path} ${check.message}`),
      ...metadataConflictEntries(item).flatMap(([field, values]) => [
        field,
        ...Object.keys(values),
      ]),
      ...Object.values(item.signals.guids ?? {}).map(String),
      ...item.reasons,
    ]
      .join(' ')
      .toLowerCase();

    return haystack.includes(term);
  });
});

watch(selectAll, (value) => {
  selected_ids.value = value ? filteredItems.value.map((item) => item.state_id) : [];
});

const toggleSelected = (id: number, value: boolean | 'indeterminate'): void => {
  if (true === value) {
    if (!selected_ids.value.includes(id)) {
      selected_ids.value.push(id);
    }
    return;
  }

  selected_ids.value = selected_ids.value.filter((itemId) => itemId !== id);
};

const loadSummary = async (): Promise<void> => {
  const response = await request('/state/media-health');
  const json = await parse_api_response<MediaHealthResponse>(response);
  if ('error' in json) {
    notification('error', 'Error', `API Error. ${json.error.code}: ${json.error.message}`);
    return;
  }

  reportState.value = json;
};

const loadContent = async (pageNumber: number): Promise<void> => {
  pageNumber = Number(pageNumber);
  if (Number.isNaN(pageNumber) || pageNumber < 1) {
    pageNumber = 1;
  }

  isLoading.value = true;
  page.value = pageNumber;

  try {
    await loadSummary();
    if (!report.value) {
      items.value = [];
      total.value = 0;
      return;
    }

    const search = new URLSearchParams();
    search.set('page', String(pageNumber));
    search.set('perpage', String(perpage.value));
    if (UNHEALTHY_FILTER_VALUE === status.value) {
      search.set('unhealthy', '1');
    } else if (ALL_FILTER_VALUE !== status.value) {
      search.set('status', status.value);
    }
    if (ALL_FILTER_VALUE !== type.value) {
      search.set('type', type.value);
    }
    if ('' !== filter.value.trim()) {
      search.set('filter', filter.value.trim());
    }

    const response = await request(`/state/media-health/items?${search.toString()}`);
    const json = await parse_api_response<ItemsResponse>(response);
    if ('error' in json) {
      notification('error', 'Error', `API Error. ${json.error.code}: ${json.error.message}`);
      return;
    }

    items.value = json.items;
    page.value = json.paging.current_page;
    perpage.value = json.paging.perpage;
    total.value = json.paging.total;

    await router.replace({ path: '/media_health', query: Object.fromEntries(search) });
  } catch (e: unknown) {
    const error = e as Error;
    notification('error', 'Error', `Request error. ${error.message}`);
  } finally {
    isLoading.value = false;
    selectAll.value = false;
    selected_ids.value = [];
  }
};

const massDelete = async (): Promise<void> => {
  if (selected_ids.value.length < 1) {
    return;
  }

  const { status: confirmStatus } = await useDialog().confirmDialog({
    title: 'Confirm Deletion',
    message: `Delete '${selected_ids.value.length}' item/s?`,
    confirmColor: 'error',
  });

  if (true !== confirmStatus) {
    return;
  }

  massActionInProgress.value = true;

  try {
    const ids = [...selected_ids.value];
    notification(
      'success',
      'Action in progress',
      `Deleting '${ids.length}' item/s. Please wait...`,
    );

    const requests = await Promise.all(
      ids.map((id) => request(`/history/${id}`, { method: 'DELETE' })),
    );

    if (!requests.every((response) => 200 === response.status)) {
      notification(
        'error',
        'Error',
        'Some delete requests failed. Please check the console for more details.',
      );
      return;
    }

    items.value = items.value.filter((item) => !ids.includes(item.state_id));
    total.value = Math.max(0, total.value - ids.length);
    notification('success', 'Success', `Deleting '${ids.length}' item/s completed.`);
  } catch (e: unknown) {
    const error = e as Error;
    notification('error', 'Error', `Request error. ${error.message}`);
  } finally {
    massActionInProgress.value = false;
    selected_ids.value = [];
    selectAll.value = false;
  }
};

const queueReport = async (): Promise<void> => {
  isQueueing.value = true;
  try {
    const response = await request('/state/media-health/run', { method: 'POST' });
    const json = await parse_api_response<MediaHealthRunResponse>(response);
    if ('error' in json) {
      notification('error', 'Error', `API Error. ${json.error.code}: ${json.error.message}`);
      return;
    }

    notification(json.queued ? 'success' : 'info', 'Media Health', json.message);
    await loadSummary();
  } catch (e: unknown) {
    const error = e as Error;
    notification('error', 'Error', `Request error. ${error.message}`);
  } finally {
    isQueueing.value = false;
  }
};

const exportReport = async (format: 'markdown' | 'json' | 'csv'): Promise<void> => {
  exporting.value = format;
  try {
    const response = await request(`/state/media-health/export/${format}`);
    if (!response.ok) {
      const json = await parse_api_response<MediaHealthResponse>(response);
      if ('error' in json) {
        notification('error', 'Error', `API Error. ${json.error.code}: ${json.error.message}`);
      }
      return;
    }

    const blob = await response.blob();
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `media-health-${format}.zip`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  } catch (e: unknown) {
    const error = e as Error;
    notification('error', 'Error', `Request error. ${error.message}`);
  } finally {
    exporting.value = null;
  }
};

const exportMenuItems = computed(() => [
  [
    {
      label: 'Markdown',
      icon: 'i-lucide-file-text',
      disabled: null !== exporting.value,
      onSelect: () => {
        void exportReport('markdown');
      },
    },
    {
      label: 'JSON',
      icon: 'i-lucide-file-json',
      disabled: null !== exporting.value,
      onSelect: () => {
        void exportReport('json');
      },
    },
    {
      label: 'CSV',
      icon: 'i-lucide-table-2',
      disabled: null !== exporting.value,
      onSelect: () => {
        void exportReport('csv');
      },
    },
  ],
]);

const clearFilters = async (): Promise<void> => {
  if (UNHEALTHY_FILTER_VALUE === status.value && ALL_FILTER_VALUE === type.value) {
    await loadContent(1);
    return;
  }

  status.value = UNHEALTHY_FILTER_VALUE;
  type.value = ALL_FILTER_VALUE;
};

const toggleFilter = (): void => {
  showFilter.value = !showFilter.value;
  if (!showFilter.value && '' !== filter.value) {
    filter.value = '';
  }
};

const statusCount = (key: string): number => report.value?.summary.statuses[key] ?? 0;

const formatNumber = (value: number): string => new Intl.NumberFormat().format(value);

const formatDate = (value: number | null): string => {
  if (!value) {
    return 'Unknown';
  }

  return moment.unix(value).format(TOOLTIP_DATE_FORMAT.value);
};

const relativeDate = (value: number | null): string => {
  if (!value) {
    return 'Unknown';
  }

  return moment.unix(value).fromNow();
};

const statusLabel = (value: MediaHealthStatus | string): string =>
  value
    .split('_')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join(' ');

const statusIcon = (value: MediaHealthStatus): string => {
  if ('file_missing' === value) {
    return 'i-lucide-file-x-2';
  }
  if ('guid_conflict' === value || 'duplicate_guid' === value) {
    return 'i-lucide-fingerprint';
  }
  if ('duplicate_reference' === value) {
    return 'i-lucide-layers-3';
  }
  if ('metadata_disagreement' === value) {
    return 'i-lucide-list-x';
  }
  if ('partial' === value) {
    return 'i-lucide-server-off';
  }
  if ('path_disagreement' === value) {
    return 'i-lucide-file-warning';
  }
  if ('weak_match' === value) {
    return 'i-lucide-link-2-off';
  }

  return 'i-lucide-check-circle';
};

const statusActionTitle = (value: MediaHealthStatus): string => {
  if ('file_missing' === value) {
    return 'A backend points at a file WatchState cannot find';
  }
  if ('guid_conflict' === value) {
    return 'Backends point at different external records';
  }
  if ('duplicate_guid' === value) {
    return 'The same external record is attached to multiple local records';
  }
  if ('duplicate_reference' === value) {
    return 'Multiple local records use the same file path';
  }
  if ('metadata_disagreement' === value) {
    return 'Backends disagree on structural metadata';
  }
  if ('partial' === value) {
    return 'Some backends did not report this record';
  }
  if ('path_disagreement' === value) {
    return 'Backends disagree on the media path';
  }
  if ('weak_match' === value) {
    return 'No strong external identifier was stored';
  }

  return 'No action needed';
};

const statusActionText = (value: MediaHealthStatus): string => {
  if ('file_missing' === value) {
    return 'Check the listed backend path, restore the media file, or point it to the correct one.';
  }
  if ('guid_conflict' === value) {
    return 'Click the backend names below and make the affected backends use the same GUID ID. A small number of mismatched GUIDs is usually harmless; however, if no matching record is found, a duplicate record may have been created.';
  }
  if ('duplicate_guid' === value) {
    return 'Compare the linked history records and remove or refresh the record that is mapped to the wrong external ID.';
  }
  if ('duplicate_reference' === value) {
    return 'Compare the linked history records and fix duplicate media-file reporting in the affected backend.';
  }
  if ('metadata_disagreement' === value) {
    return 'Open the affected backend items and correct the type, year, season, or episode number that differs.';
  }
  if ('partial' === value) {
    return 'Open the missing backend names and refresh or import metadata so every expected backend reports the item.';
  }
  if ('path_disagreement' === value) {
    return 'Compare backend paths below and fix the backend reporting a different file or folder.';
  }
  if ('weak_match' === value) {
    return 'Refresh metadata in the backend so WatchState stores a strong external GUID for future matching.';
  }

  return 'This record matched across the available backend metadata.';
};

const mediaIcon = (itemType: string): string =>
  'episode' === itemType.toLowerCase() ? 'i-lucide-tv' : 'i-lucide-film';

const severityColor = (severity: number): 'error' | 'warning' | 'success' => {
  if (severity >= 80) {
    return 'error';
  }
  if (severity >= 50) {
    return 'warning';
  }

  return 'success';
};

const backendNames = (item: MediaHealthItem): Array<string> => item.signals.backends ?? [];

const missingBackendNames = (item: MediaHealthItem): Array<string> =>
  item.signals.missing_backends ?? [];

const missingFileChecks = (item: MediaHealthItem): Array<MediaHealthFileCheck> =>
  item.signals.missing_files ?? [];

const duplicateReferenceStateIds = (item: MediaHealthItem): Array<number> =>
  (item.signals.duplicate_reference?.state_ids ?? []).map(Number).filter(Number.isFinite);

const backendItemUrl = (item: MediaHealthItem, backend: string): string | null =>
  item.signals.backend_items?.[backend]?.webUrl ?? null;

const guidConflictEntries = (
  item: MediaHealthItem,
): Array<[string, Record<string, Array<string>>]> => objectEntries(item.signals.guid_conflicts);

const metadataConflictEntries = (
  item: MediaHealthItem,
): Array<[string, Record<string, Array<string>>]> => objectEntries(item.signals.metadata_conflicts);

const showReasonDetails = (item: MediaHealthItem): boolean => {
  if (item.reasons.length < 1) {
    return false;
  }

  return (
    guidConflictEntries(item).length < 1 &&
    metadataConflictEntries(item).length < 1 &&
    !item.signals.duplicate_guid &&
    !item.signals.duplicate_reference &&
    missingFileChecks(item).length < 1 &&
    missingBackendNames(item).length < 1 &&
    pathRows(item).length < 2 &&
    objectEntries(item.signals.guids).length < 1
  );
};

const pathGroups = (item: MediaHealthItem): Array<{ path: string; backends: Array<string> }> => {
  const groups = new Map<string, Array<string>>();

  for (const [backend, path] of objectEntries(item.signals.paths)) {
    const normalizedPath = String(path).trim();
    if ('' === normalizedPath) {
      continue;
    }

    groups.set(normalizedPath, [...(groups.get(normalizedPath) ?? []), backend]);
  }

  return Array.from(groups.entries()).map(([path, backends]) => ({ path, backends }));
};

const pathRows = (item: MediaHealthItem): Array<{ path: string; backends: Array<string> }> => {
  const rows = pathGroups(item);
  const backendsWithoutPath = backendNames(item).filter((backend) => {
    const path = String(item.signals.paths?.[backend] ?? '').trim();
    return '' === path;
  });

  if (backendsWithoutPath.length > 0 && rows.length > 0) {
    rows.push({ path: '', backends: backendsWithoutPath });
  }

  return rows;
};

function objectEntries<T>(value: Record<string, T> | undefined): Array<[string, T]> {
  return Object.entries(value ?? {});
}

const guidSource = (key: string): string => key.replace(/^guid_/, '');

const guidLink = (item: MediaHealthItem, key: string, value: string): string => {
  const source = guidSource(key);
  const externalLink = makeGUIDLink(item.type, source, value);
  if ('' !== externalLink) {
    return externalLink;
  }

  return makeSearchLink('guid', `${source}://${value}`);
};

watch([status, type], async () => await loadContent(1));
watch(filter, async () => await loadContent(1));

onMounted(async () => await loadContent(page.value));
</script>
