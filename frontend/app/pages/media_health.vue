<template>
  <div
    class="space-y-6"
    @click.capture="openMainLink"
    @auxclick.capture="openMainLink"
    @contextmenu.capture="openMainLink"
  >
    <div class="space-y-4">
      <PageHeader v-bind="pageShell">
        <template #actions>
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-refresh-cw"
            :loading="isLoading"
            :disabled="isLoading || isQueueing || massActionInProgress"
            @click="loadContent(page)"
          >
            Reload
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
              Export
            </UButton>
          </UDropdownMenu>

          <UButton
            color="primary"
            variant="solid"
            size="sm"
            icon="i-lucide-play"
            :loading="isQueueing"
            :disabled="isLoading || isQueueing || massActionInProgress || reportState?.queued"
            @click="queueReport"
          >
            {{ reportState?.queued ? 'Queued' : report ? 'Queue refresh' : 'Generate audit' }}
          </UButton>
        </template>
      </PageHeader>

      <dl class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm text-toned">
        <div class="flex min-w-0 items-center gap-2">
          <dt>Database:</dt>
          <dd class="font-medium text-default">Main</dd>
        </div>
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
          <dt>Last completed:</dt>
          <dd>
            <UTooltip
              v-if="report && 'completed' === report.status && report.completed_at"
              :text="relativeDate(report.completed_at)"
            >
              <time :datetime="moment.unix(report.completed_at).toISOString()" class="cursor-help">
                {{ formatDate(report.completed_at) }}
              </time>
            </UTooltip>
            <span v-else>{{ report ? 'Unknown' : 'No report loaded' }}</span>
          </dd>
        </div>
      </dl>
    </div>

    <UAlert
      v-if="reportState?.queued"
      color="info"
      variant="soft"
      icon="i-lucide-clock-3"
      title="Report queued"
      description="Reload to check for a completed report."
    />

    <UEmpty
      v-if="!isLoading && !loadError && reportState && !reportState.queued && !report"
      icon="i-lucide-file-search"
      title="No audit found"
      description="Use Generate audit to check records in the main database."
    />

    <UAlert
      v-if="loadError"
      color="error"
      variant="soft"
      icon="i-lucide-triangle-alert"
      title="Couldn't load media health"
      :description="loadError"
    />

    <div v-if="report" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
      <StatCard
        label="Actionable"
        :value="formatNumber(report.summary.actionable_count)"
        icon="i-lucide-triangle-alert"
        color="warning"
      />
      <StatCard
        label="Healthy"
        :value="formatNumber(statusCount('healthy'))"
        icon="i-lucide-check-circle"
        color="success"
      />
      <StatCard
        label="Records"
        :value="formatNumber(report.state_count)"
        icon="i-lucide-library"
        color="neutral"
      />
      <StatCard
        label="Backends"
        :value="formatNumber(report.backend_count)"
        icon="i-lucide-server"
        color="neutral"
      />
    </div>

    <section v-if="report" class="ws-panel-padded space-y-3">
      <div class="flex flex-wrap items-end gap-3">
        <UFormField label="Status" name="status">
          <USelect
            v-model="status"
            :items="statusOptions"
            value-key="value"
            label-key="label"
            class="w-48"
            :disabled="isLoading || isQueueing || massActionInProgress"
          />
        </UFormField>
        <UFormField label="Type" name="type">
          <USelect
            v-model="type"
            :items="typeOptions"
            value-key="value"
            label-key="label"
            class="w-36"
            :disabled="isLoading || isQueueing || massActionInProgress"
          />
        </UFormField>
        <UFormField label="Filter this page" name="filter" class="min-w-0 flex-1">
          <UInput
            id="filter"
            v-model.lazy="filter"
            type="search"
            icon="i-lucide-search"
            placeholder="Title, backend, or file path"
            class="w-full"
            :disabled="isLoading || isQueueing || massActionInProgress"
          />
        </UFormField>
        <UButton
          v-if="UNHEALTHY_FILTER_VALUE !== status || ALL_FILTER_VALUE !== type || filter"
          color="neutral"
          variant="outline"
          icon="i-lucide-filter-x"
          :disabled="isLoading || isQueueing || massActionInProgress"
          @click="clearFilters"
        >
          Clear filters
        </UButton>
      </div>

      <p class="text-xs text-toned">
        {{ formatNumber(filteredItems.length) }} shown on this page;
        {{ formatNumber(total) }} records match Status and Type.
      </p>

      <div class="flex flex-wrap items-center justify-between gap-3 border-t border-default pt-3">
        <div class="flex flex-wrap items-center gap-2">
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-square-check"
            :disabled="
              isLoading ||
              isQueueing ||
              massActionInProgress ||
              !!loadError ||
              1 > filteredItems.length
            "
            @click="selected_ids = filteredItems.map((item) => item.state_id)"
          >
            Select page
          </UButton>
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-square"
            :disabled="isLoading || isQueueing || massActionInProgress || 1 > selected_ids.length"
            @click="selected_ids = []"
          >
            Unselect page
          </UButton>
          <span class="text-sm text-toned">{{ selected_ids.length }} selected</span>
        </div>
        <UButton
          v-if="0 < selected_ids.length"
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-trash-2"
          :loading="massActionInProgress"
          :disabled="isLoading || isQueueing || massActionInProgress || !!loadError"
          @click="massDelete"
        >
          Delete selected
        </UButton>
      </div>
    </section>

    <Pager
      v-if="total && last_page > 1"
      :page="page"
      :last_page="last_page"
      :is-loading="isLoading || isQueueing || massActionInProgress"
      @navigate="loadContent"
    />

    <UAlert
      v-if="isLoading"
      color="info"
      variant="soft"
      icon="i-lucide-loader-circle"
      title="Loading media health"
      :ui="{ icon: 'animate-spin' }"
    />

    <UEmpty
      v-if="!isLoading && !loadError && filteredItems.length < 1 && filter && items.length > 0"
      icon="i-lucide-search"
      title="No items found"
    >
      <template #description>
        The filter <code>{{ filter }}</code> did not match any records on this page.
      </template>
    </UEmpty>

    <div v-else-if="filteredItems.length > 0" class="space-y-3">
      <Lazy
        v-for="item in filteredItems"
        :key="item.id"
        :unrender="true"
        :min-height="200"
        class="block"
      >
        <UCard
          class="h-full shadow-sm"
          :class="item.severity >= 80 ? 'ring-1 ring-error/20' : ''"
          :ui="itemCardUi"
        >
          <template #header>
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div class="min-w-0 flex flex-1 items-start gap-2">
                <UIcon :name="mediaIcon(item.type)" class="mt-0.5 size-4 shrink-0 text-toned" />

                <div class="min-w-0 flex-1 text-base font-semibold text-highlighted">
                  <FloatingImage
                    :image="`/history/${item.state_id}/images/poster?user=main`"
                    v-if="poster_enable && 'main' === apiUser"
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

              <div class="flex flex-wrap items-center gap-2">
                <UBadge :color="severityColor(item.severity)" variant="outline">
                  {{ statusLabel(item.status) }}
                </UBadge>
                <UTooltip :text="`Severity ${item.severity}`">
                  <UBadge :color="severityColor(item.severity)" variant="soft">
                    {{ item.severity }}
                  </UBadge>
                </UTooltip>
                <UTooltip
                  :text="selected_ids.includes(item.state_id) ? 'Unselect item' : 'Select item'"
                >
                  <UCheckbox
                    color="primary"
                    :aria-label="`Select ${item.title}`"
                    :disabled="isLoading || isQueueing || massActionInProgress || !!loadError"
                    :model-value="selected_ids.includes(item.state_id)"
                    @update:model-value="toggleSelected(item.state_id, $event)"
                  />
                </UTooltip>
              </div>
            </div>
          </template>

          <div class="space-y-3">
            <div class="flex flex-wrap items-center gap-2 text-xs text-toned">
              <span>{{ item.backend_count }}/{{ item.expected_backend_count }} backends</span>
              <BackendItemLink
                v-for="backend in backendNames(item)"
                :key="`${item.id}-backend-${backend}`"
                :backend="backend"
                :url="backendItemUrl(item, backend)"
                color="neutral"
              />
              <span v-if="0 < missingBackendNames(item).length">
                Missing: {{ missingBackendNames(item).join(', ') }}
              </span>
            </div>

            <div>
              <div class="mb-1 flex items-center gap-2 font-medium text-highlighted">
                <UIcon :name="statusIcon(item.status)" class="size-4 text-toned" />
                <span>{{ statusActionTitle(item.status) }}</span>
              </div>
              <p class="text-sm text-toned">{{ statusActionText(item.status) }}</p>
            </div>
          </div>

          <details
            :open="item.show_details"
            class="mt-3 space-y-3 border-t border-default pt-3"
            @toggle="item.show_details = ($event.target as HTMLDetailsElement).open"
          >
            <summary class="cursor-pointer text-sm font-medium text-highlighted">Details</summary>
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
                  <UTooltip text="Toggle duplicate path wrapping">
                    <button
                      type="button"
                      class="mt-0.5 shrink-0 text-toned hover:text-primary"
                      aria-label="Toggle duplicate path wrapping"
                      @click="item.expand_duplicate_path = !item.expand_duplicate_path"
                    >
                      <UIcon name="i-lucide-file-text" class="size-4" />
                    </button>
                  </UTooltip>
                  <NuxtLink
                    :to="makeSearchLink('path', item.signals.duplicate_reference.path)"
                    class="min-w-0 flex-1 hover:text-primary"
                    :class="item.expand_duplicate_path ? 'wrap-break-word' : 'truncate'"
                  >
                    {{ item.signals.duplicate_reference.path }}
                  </NuxtLink>
                  <UTooltip text="Copy duplicate path">
                    <UButton
                      color="neutral"
                      variant="ghost"
                      size="xs"
                      square
                      icon="i-lucide-copy"
                      aria-label="Copy duplicate path"
                      @click="copyText(item.signals.duplicate_reference?.path ?? '', false)"
                    />
                  </UTooltip>
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
                  <UTooltip text="Toggle missing file path wrapping">
                    <button
                      type="button"
                      class="mt-0.5 shrink-0 text-toned hover:text-primary"
                      aria-label="Toggle missing file path wrapping"
                      @click="item.expand_file_checks = !item.expand_file_checks"
                    >
                      <UIcon name="i-lucide-file-x-2" class="size-4" />
                    </button>
                  </UTooltip>
                  <NuxtLink
                    v-if="check.path"
                    :to="makeSearchLink('path', check.path)"
                    class="min-w-0 flex-1 hover:text-primary"
                    :class="item.expand_file_checks ? 'wrap-break-word' : 'truncate'"
                  >
                    {{ check.path }}
                  </NuxtLink>
                  <span v-else class="min-w-0 flex-1 truncate text-toned">No path</span>
                  <UTooltip v-if="check.path" text="Copy missing file path">
                    <UButton
                      color="neutral"
                      variant="ghost"
                      size="xs"
                      square
                      icon="i-lucide-copy"
                      aria-label="Copy missing file path"
                      @click="copyText(check.path, false)"
                    />
                  </UTooltip>
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
              v-if="0 < pathRows(item).length"
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
                  <UTooltip text="Toggle backend path wrapping">
                    <button
                      type="button"
                      class="mt-0.5 shrink-0 text-toned hover:text-primary"
                      aria-label="Toggle backend path wrapping"
                      @click="item.expand_paths = !item.expand_paths"
                    >
                      <UIcon name="i-lucide-file-text" class="size-4" />
                    </button>
                  </UTooltip>
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
                  <UTooltip v-if="group.path" text="Copy backend path">
                    <UButton
                      color="neutral"
                      variant="ghost"
                      size="xs"
                      square
                      icon="i-lucide-copy"
                      aria-label="Copy backend path"
                      @click="copyText(group.path, false)"
                    />
                  </UTooltip>
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

            <ul
              v-if="0 < item.reasons.length"
              class="list-disc space-y-1 pl-5 text-sm text-default"
            >
              <li v-for="reason in item.reasons" :key="reason">{{ reason }}</li>
            </ul>
          </details>
        </UCard>
      </Lazy>
    </div>

    <UEmpty
      v-else-if="!isLoading && report && !loadError && 0 === report.summary.total"
      icon="i-lucide-library"
      title="No records audited"
    />

    <UEmpty
      v-else-if="
        report &&
        !isLoading &&
        !loadError &&
        UNHEALTHY_FILTER_VALUE === status &&
        ALL_FILTER_VALUE === type &&
        !filter &&
        report.summary.total === statusCount('healthy')
      "
      icon="i-lucide-check-circle"
      title="No unhealthy records"
    />

    <UEmpty
      v-else-if="!isLoading && report && !loadError"
      icon="i-lucide-search"
      title="No records match these filters"
    />

    <Pager
      v-if="total && last_page > 1"
      :page="page"
      :last_page="last_page"
      :is-loading="isLoading || isQueueing || massActionInProgress"
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
          After correcting backend metadata, force a metadata import from the affected backends,
          then queue a new audit.
        </li>
      </ul>
    </UCard>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { navigateTo, useHead, useRoute, useRouter } from '#app';
import { useStorage } from '@vueuse/core';
import moment from 'moment';
import {
  NuxtLink,
  UAlert,
  UBadge,
  UButton,
  UCard,
  UCheckbox,
  UDropdownMenu,
  UEmpty,
  UFormField,
  UIcon,
  UInput,
  USelect,
  UTooltip,
} from '#components';
import FloatingImage from '~/components/FloatingImage.vue';
import BackendItemLink from '~/components/BackendItemLink.vue';
import HistoryRecordLink from '~/components/HistoryRecordLink.vue';
import Lazy from '~/components/Lazy.vue';
import PageHeader from '~/components/PageHeader.vue';
import Pager from '~/components/Pager.vue';
import StatCard from '~/components/StatCard.vue';
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
const apiUser = useStorage<string>('api_user', 'main', undefined, { flush: 'sync' });
let pendingMainPath: string | null = null;

useHead({ title: 'Media Health' });

type SelectItem = {
  label: string;
  value: string;
};

type MediaHealthItemWithUI = MediaHealthItem & {
  expand_duplicate_path?: boolean;
  expand_file_checks?: boolean;
  expand_paths?: boolean;
  show_details?: boolean;
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
const report = ref<MediaHealthReport | null>(null);
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
const isLoading = ref<boolean>(false);
const loadError = ref('');
const isQueueing = ref<boolean>(false);
const selected_ids = ref<Array<number>>([]);
const massActionInProgress = ref<boolean>(false);
const exporting = ref<'markdown' | 'json' | 'csv' | null>(null);
let loadRequest = 0;

watch(
  () => router.currentRoute.value.path,
  (path) => {
    if (pendingMainPath && pendingMainPath === path) {
      apiUser.value = 'main';
      pendingMainPath = null;
    }
  },
  { flush: 'sync' },
);

const openMainLink = async (event: MouseEvent): Promise<void> => {
  const anchor = event.target instanceof Element ? event.target.closest('a[href]') : null;
  if (!(anchor instanceof HTMLAnchorElement)) {
    return;
  }

  const target = new URL(anchor.href, window.location.href);
  if (
    window.location.origin !== target.origin ||
    !/^\/(?:history(?:\/|$)|backend\/)/.test(target.pathname) ||
    'main' === apiUser.value
  ) {
    return;
  }

  event.preventDefault();
  event.stopPropagation();
  if ('auxclick' === event.type || 'contextmenu' === event.type) {
    notification(
      'info',
      'Main database link',
      'Select the main identity before opening this link in another tab.',
    );
    return;
  }
  if (pendingMainPath) {
    return;
  }

  pendingMainPath = target.pathname;
  try {
    await navigateTo(`${target.pathname}${target.search}${target.hash}`);
  } catch (error: unknown) {
    const message = error instanceof Error ? error.message : 'Unexpected navigation error';
    notification('error', 'Could not open main database link', message);
  } finally {
    pendingMainPath = null;
  }
};

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

const toggleSelected = (id: number, value: boolean | 'indeterminate'): void => {
  if (true === value) {
    if (!selected_ids.value.includes(id)) {
      selected_ids.value.push(id);
    }
    return;
  }

  selected_ids.value = selected_ids.value.filter((itemId) => itemId !== id);
};

const loadSummary = async (): Promise<MediaHealthResponse> => {
  const response = await request('/state/media-health');
  const json = await parse_api_response<MediaHealthResponse>(response);
  if ('error' in json) {
    throw new Error(`${json.error.code}: ${json.error.message}`);
  }

  return json;
};

const loadContent = async (pageNumber: number): Promise<void> => {
  pageNumber = Number(pageNumber);
  if (Number.isNaN(pageNumber) || 1 > pageNumber) {
    pageNumber = 1;
  }

  const requestId = ++loadRequest;
  isLoading.value = true;
  loadError.value = '';
  selected_ids.value = [];

  try {
    const summary = await loadSummary();
    if (requestId !== loadRequest) {
      return;
    }

    reportState.value = summary;
    if (!summary.report) {
      report.value = null;
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

    const response = await request(`/state/media-health/items?${search.toString()}`);
    const json = await parse_api_response<ItemsResponse>(response);
    if (requestId !== loadRequest) {
      return;
    }
    if ('error' in json) {
      loadError.value = `${json.error.code}: ${json.error.message}`;
      notification('error', 'Audit not loaded', `${json.error.code}: ${json.error.message}`);
      return;
    }

    report.value = json.report;
    reportState.value = { ...summary, report: json.report };
    items.value = json.items;
    page.value = json.paging.current_page;
    perpage.value = json.paging.perpage;
    total.value = json.paging.total;

    await router.replace({
      path: '/media_health',
      query: {
        ...Object.fromEntries(search),
        page: String(page.value),
        status: status.value,
        type: type.value,
        filter: filter.value.trim() || undefined,
      },
    });
  } catch (e: unknown) {
    if (requestId !== loadRequest) {
      return;
    }
    loadError.value = e instanceof Error ? e.message : 'Unknown error occurred';
    notification('error', 'Audit not loaded', loadError.value);
  } finally {
    if (requestId === loadRequest) {
      isLoading.value = false;
    }
  }
};

const massDelete = async (): Promise<void> => {
  if (
    !report.value ||
    'completed' !== report.value.status ||
    isLoading.value ||
    isQueueing.value ||
    massActionInProgress.value ||
    loadError.value ||
    1 > selected_ids.value.length
  ) {
    return;
  }

  const ids = [...selected_ids.value];
  const reportId = report.value.id;
  const requestId = loadRequest;
  const { status: confirmStatus } = await useDialog().confirmDialog({
    title: 'Delete selected records?',
    confirmText: 'Delete',
    message: `Delete ${ids.length} selected records from main database history? Backend items and media files are not deleted.`,
    confirmColor: 'error',
  });

  if (
    true !== confirmStatus ||
    reportId !== report.value?.id ||
    requestId !== loadRequest ||
    isLoading.value ||
    isQueueing.value ||
    massActionInProgress.value ||
    loadError.value ||
    ids.length !== selected_ids.value.length ||
    !ids.every((id) => selected_ids.value.includes(id)) ||
    !ids.every((id) =>
      filteredItems.value.some((item) => id === item.state_id && reportId === item.report_id),
    )
  ) {
    return;
  }

  massActionInProgress.value = true;

  try {
    notification('success', 'Deleting records', `Deleting ${ids.length} selected records.`);

    const requests = await Promise.all(
      ids.map((id) =>
        request(`/history/${id}`, {
          method: 'DELETE',
          headers: { 'X-User': 'main' },
        }),
      ),
    );

    if (!requests.every((response) => 200 === response.status)) {
      notification('error', 'Some records were not deleted', 'Reload before trying again.');
      return;
    }

    items.value = items.value.filter((item) => !ids.includes(item.state_id));
    total.value = Math.max(0, total.value - ids.length);
    notification(
      'success',
      'Records deleted',
      `Deleted ${ids.length} records from the main database.`,
    );
  } catch (e: unknown) {
    const error = e as Error;
    notification('error', 'Records not deleted', error.message);
  } finally {
    massActionInProgress.value = false;
    selected_ids.value = [];
  }
};

const queueReport = async (): Promise<void> => {
  if (
    isLoading.value ||
    isQueueing.value ||
    massActionInProgress.value ||
    reportState.value?.queued
  ) {
    return;
  }

  isQueueing.value = true;
  try {
    const response = await request('/state/media-health/run', { method: 'POST' });
    const json = await parse_api_response<MediaHealthRunResponse>(response);
    if ('error' in json) {
      notification('error', 'Audit not queued', `${json.error.code}: ${json.error.message}`);
      return;
    }

    notification(json.queued ? 'success' : 'info', 'Media Health', json.message);
    reportState.value = {
      report: report.value,
      stale: reportState.value?.stale ?? false,
      queued: true,
      queued_event: json.event_id,
    };
    reportState.value = await loadSummary();
  } catch (e: unknown) {
    const error = e as Error;
    notification('error', 'Audit not queued', error.message);
  } finally {
    isQueueing.value = false;
  }
};

const exportReport = async (format: 'markdown' | 'json' | 'csv'): Promise<void> => {
  if (null !== exporting.value) {
    return;
  }

  exporting.value = format;
  try {
    const response = await request(`/state/media-health/export/${format}`);
    if (!response.ok) {
      const json = await parse_api_response<MediaHealthResponse>(response);
      if ('error' in json) {
        notification('error', 'Audit not exported', `${json.error.code}: ${json.error.message}`);
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
    notification('error', 'Audit not exported', error.message);
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

const clearFilters = (): void => {
  status.value = UNHEALTHY_FILTER_VALUE;
  type.value = ALL_FILTER_VALUE;
  filter.value = '';
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
    return 'Compare external IDs on the linked backend items and correct any mismatch.';
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
watch(filter, async () => {
  selected_ids.value = [];
  await router.replace({
    path: '/media_health',
    query: {
      ...route.query,
      filter: filter.value.trim() || undefined,
    },
  });
});

onMounted(async () => await loadContent(page.value));

onBeforeUnmount(() => {
  pendingMainPath = null;
  loadRequest++;
});
</script>
