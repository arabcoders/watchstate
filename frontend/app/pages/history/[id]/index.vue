<template>
  <main class="w-full min-w-0 max-w-full space-y-6">
    <div class="space-y-3">
      <PageHeader v-bind="pageShell">
        <template #kicker>
          <span>{{ pageShell.sectionLabel }}</span>
          <span>/</span>
          <NuxtLink to="/history" class="hover:text-primary">{{ pageShell.pageLabel }}</NuxtLink>
          <span>/</span>
          <span class="truncate text-highlighted normal-case tracking-normal">{{
            headerTitle
          }}</span>
        </template>
        <template #actions>
          <template v-if="data?.via">
            <UTooltip
              v-if="data?.files?.length > 0"
              :text="`${data.content_exists ? 'Play media' : 'Media is inaccessible'}`"
            >
              <UButton
                color="neutral"
                variant="outline"
                size="sm"
                icon="i-lucide-play"
                :disabled="!data.content_exists || isLoading || isDeleting || isMutating"
                @click="openPlaybackModal"
              >
                Play
              </UButton>
            </UTooltip>

            <UTooltip text="Change this record's watch state">
              <UButton
                color="neutral"
                variant="outline"
                size="sm"
                :icon="data.watched ? 'i-lucide-eye-off' : 'i-lucide-eye'"
                :disabled="isLoading || isMutating || isDeleting"
                :loading="isMutating"
                @click="toggleWatched"
              >
                {{ data.watched ? 'Mark unwatched' : 'Mark watched' }}
              </UButton>
            </UTooltip>

            <UTooltip text="View raw data">
              <UButton
                color="neutral"
                variant="outline"
                size="sm"
                icon="i-lucide-bug"
                @click="
                  () => {
                    showRawData = true;
                  }
                "
              >
                Raw Data
              </UButton>
            </UTooltip>

            <UTooltip text="Delete the record">
              <UButton
                color="neutral"
                variant="outline"
                size="sm"
                icon="i-lucide-trash-2"
                :disabled="isDeleting || isLoading || isMutating"
                :loading="isDeleting"
                @click="deleteItem"
              >
                Delete
              </UButton>
            </UTooltip>

            <UButton
              color="neutral"
              variant="outline"
              size="sm"
              icon="i-lucide-refresh-cw"
              :loading="isLoading"
              :disabled="isMutating || isDeleting"
              @click="() => void loadContent()"
            >
              Reload
            </UButton>
          </template>
        </template>
      </PageHeader>

      <UAlert
        v-if="data?.via && data.not_reported_by.length > 0"
        color="warning"
        variant="soft"
        icon="i-lucide-triangle-alert"
        title=""
      >
        <template #description>
          <div class="space-y-2">
            <p>
              Not reported by:
              <UBadge
                v-for="backend in data.not_reported_by"
                :key="`nr-${backend}`"
                color="warning"
                variant="soft"
              >
                <NuxtLink :to="`/backend/${backend}`">{{ backend }}</NuxtLink>
              </UBadge>
            </p>
          </div>
        </template>
      </UAlert>

      <section
        v-if="data?.via"
        aria-labelledby="media-heading"
        class="flex min-w-0 gap-4 rounded-xl bg-elevated/50 p-4 sm:gap-6 sm:p-6"
      >
        <StatisticsPoster
          v-if="posterEnable"
          :key="`${apiUser}:${id}`"
          :history-id="data.id"
          :title="historyTitle"
          :user="apiUser"
          class="w-24 shrink-0 self-start sm:w-36"
        />
        <div class="min-w-0 flex-1 space-y-4">
          <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0 space-y-2">
              <p class="text-xs font-medium uppercase tracking-wider text-primary">Watch state</p>
              <h2
                id="media-heading"
                class="wrap-break-word text-2xl font-semibold leading-tight text-highlighted sm:text-3xl"
              >
                {{ data.title }}
              </h2>
              <p
                v-if="data.content_title && data.content_title !== data.title"
                class="wrap-break-word text-base text-default"
              >
                {{ data.content_title }}
              </p>
              <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
                <span
                  :class="data.watched ? 'text-success' : 'text-default'"
                  class="inline-flex items-center gap-2 font-medium"
                >
                  <UIcon
                    :name="data.watched ? 'i-lucide-eye' : 'i-lucide-eye-off'"
                    class="size-4"
                  />
                  {{ data.watched ? 'Watched' : 'Unwatched' }}
                </span>
                <span class="text-toned"
                  >Position:
                  {{
                    0 < Number(data.progress) ? formatDuration(Number(data.progress)) : 'None'
                  }}</span
                >
              </div>
              <p class="text-sm leading-6 text-toned">
                Via
                <NuxtLink
                  :to="`/backend/${data.via}`"
                  class="font-medium text-default hover:text-primary"
                >
                  {{ data.via }}
                </NuxtLink>
              </p>

              <p
                v-if="data.content_overview"
                class="text-sm leading-6 text-default"
                :class="expandableBlockClass(headerOverviewExpanded)"
              >
                {{ data.content_overview }}
              </p>
            </div>

            <UButton
              v-if="data.content_overview"
              color="neutral"
              :variant="headerOverviewExpanded ? 'soft' : 'outline'"
              size="sm"
              :icon="headerOverviewExpanded ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
              class="shrink-0 self-start"
              @click="
                () => {
                  headerOverviewExpanded = !headerOverviewExpanded;
                }
              "
            >
              {{ headerOverviewExpanded ? 'Show Less' : 'Show More' }}
            </UButton>
          </div>

          <div
            v-if="
              summaryBadges.length > 0 || (data.content_genres && data.content_genres.length > 0)
            "
            class="flex flex-wrap items-center gap-2"
          >
            <UBadge
              v-for="badge in summaryBadges"
              :key="`${badge.icon}-${badge.label}`"
              color="neutral"
              :variant="badge.variant ?? 'outline'"
              class="gap-1"
            >
              <UIcon :name="badge.icon" class="size-3" />
              <span>{{ badge.label }}</span>
            </UBadge>

            <UBadge
              v-for="(genre, genreIndex) in data.content_genres"
              :key="`head-genre-${genreIndex}`"
              color="neutral"
              variant="soft"
              class="gap-1"
            >
              <UIcon name="i-lucide-tag" class="size-3" />
              <span class="capitalize">{{ genre }}</span>
            </UBadge>
          </div>
        </div>
      </section>
    </div>

    <TextModal v-model:open="showRawData" :title="historyTitle" :text="rawData" />

    <UModal v-model:open="playbackModalOpen" :title="historyTitle" :ui="playbackModalUi">
      <template #body>
        <PlaybackSelection
          v-if="playbackModalOpen"
          :key="`${apiUser}:${data.id}`"
          :id="data.id"
          :actions-target="`#${playbackActionsId}`"
          @watched-change="handlePlaybackWatchedChange"
        />
      </template>
      <template #footer>
        <div :id="playbackActionsId" class="w-full" />
      </template>
    </UModal>

    <div class="space-y-4">
      <UAlert
        v-if="contentError"
        color="error"
        variant="soft"
        title="Could not load this record"
        :description="contentError"
      >
        <template #actions>
          <UButton
            color="neutral"
            variant="outline"
            icon="i-lucide-refresh-cw"
            label="Retry"
            @click="() => void loadContent()"
          />
        </template>
      </UAlert>

      <UAlert
        v-if="!data?.via && isLoading"
        color="info"
        variant="soft"
        icon="i-lucide-loader-circle"
        title="Loading record"
        :ui="{ icon: 'animate-spin' }"
      />

      <UAlert
        v-if="data?.via && (data.duplicate_reference_ids?.length ?? 0) > 0"
        color="info"
        variant="soft"
        icon="i-lucide-info"
        title="Duplicate file references"
      >
        <template #description>
          <span>This record shares the same file path with other records.</span>
        </template>
        <template #actions>
          <Popover
            placement="bottom"
            trigger="click"
            :show-delay="0"
            :hide-delay="200"
            :offset="8"
            content-class="p-0"
          >
            <template #trigger>
              <UButton
                type="button"
                color="neutral"
                variant="outline"
                size="sm"
                icon="i-lucide-files"
              >
                Related records
              </UButton>
            </template>
            <template #content>
              <DuplicateRecordList
                :key="`${apiUser}:${id}`"
                :ids="data.duplicate_reference_ids ?? []"
                :user="apiUser"
              />
            </template>
          </Popover>
        </template>
      </UAlert>

      <div class="space-y-4">
        <div v-if="data?.via" class="space-y-4">
          <section class="space-y-3">
            <div class="flex w-full items-center justify-between gap-3">
              <h2 class="min-w-0 flex-1">
                <button
                  type="button"
                  class="flex min-w-0 items-center gap-2 text-left text-sm font-semibold text-highlighted"
                  :aria-expanded="showLocalMetadata"
                  aria-controls="history-local-metadata"
                  @click="showLocalMetadata = !showLocalMetadata"
                >
                  <UIcon name="i-lucide-database" class="size-4 shrink-0 text-toned" />
                  <span>Details</span>
                  <UIcon
                    name="i-lucide-chevron-right"
                    :class="[
                      'size-4 text-toned transition-transform',
                      showLocalMetadata ? 'rotate-90' : '',
                    ]"
                  />
                </button>
              </h2>
              <NuxtLink
                :to="`/backend/${data.via}`"
                class="inline-flex min-w-0 items-center gap-1 text-sm font-medium text-toned hover:text-primary"
              >
                <UIcon name="i-lucide-server" class="size-4 shrink-0" />
                <span class="truncate">{{ data.via }}</span>
              </NuxtLink>
            </div>

            <div
              id="history-local-metadata"
              v-if="showLocalMetadata"
              class="space-y-5 text-sm leading-6 text-default"
            >
              <div class="grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2">
                <div class="py-2 text-sm text-default">
                  <div
                    class="flex min-w-0 cursor-pointer flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3"
                    @click="expandLocalId = !expandLocalId"
                  >
                    <span
                      class="inline-flex min-w-0 items-center gap-2 text-xs font-medium uppercase tracking-[0.16em] text-toned"
                    >
                      <UIcon name="i-lucide-id-card" class="size-4 shrink-0" />
                      <span>Record ID</span>
                    </span>
                    <NuxtLink
                      :to="`/history/${data.id}`"
                      class="block min-w-0 font-medium text-highlighted hover:text-primary sm:ml-auto sm:text-right"
                      :class="expandableInlineClass(expandLocalId, true)"
                      >{{ data.id }}</NuxtLink
                    >
                  </div>
                </div>

                <div class="py-2 text-sm text-default">
                  <div
                    class="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3"
                  >
                    <span
                      class="inline-flex min-w-0 items-center gap-2 text-xs font-medium uppercase tracking-[0.16em] text-toned"
                    >
                      <UIcon
                        :name="data.watched ? 'i-lucide-eye' : 'i-lucide-eye-off'"
                        class="size-4 shrink-0"
                      />
                      <span>Status</span>
                    </span>
                    <span class="min-w-0 font-medium text-highlighted sm:ml-auto sm:text-right">{{
                      data.watched ? 'Watched' : 'Unwatched'
                    }}</span>
                  </div>
                </div>

                <div class="py-2 text-sm text-default">
                  <div
                    class="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3"
                  >
                    <span
                      class="inline-flex min-w-0 items-center gap-2 text-xs font-medium uppercase tracking-[0.16em] text-toned"
                    >
                      <UIcon name="i-lucide-mail" class="size-4 shrink-0" />
                      <span>Event</span>
                    </span>
                    <span class="min-w-0 font-medium text-highlighted sm:ml-auto sm:text-right">{{
                      ag(data.extra, `${data.via}.event`, 'Unknown')
                    }}</span>
                  </div>
                </div>

                <div class="py-2 text-sm text-default">
                  <div
                    class="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3"
                  >
                    <span
                      class="inline-flex min-w-0 items-center gap-2 text-xs font-medium uppercase tracking-[0.16em] text-toned"
                    >
                      <UIcon name="i-lucide-calendar" class="size-4 shrink-0" />
                      <span>Source Updated</span>
                    </span>

                    <div class="min-w-0 sm:ml-auto sm:text-right">
                      <UTooltip
                        :text="`Backend updated this record at: ${moment.unix(Number(data.updated ?? 0)).format(TOOLTIP_DATE_FORMAT)}`"
                      >
                        <span class="cursor-help font-medium text-highlighted">{{
                          moment.unix(Number(data.updated ?? 0)).fromNow()
                        }}</span>
                      </UTooltip>
                    </div>
                  </div>
                </div>

                <div v-if="data.created_at" class="py-2 text-sm text-default">
                  <div
                    class="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3"
                  >
                    <span
                      class="inline-flex min-w-0 items-center gap-2 text-xs font-medium uppercase tracking-[0.16em] text-toned"
                    >
                      <UIcon name="i-lucide-database" class="size-4 shrink-0" />
                      <span>Added to WatchState</span>
                    </span>

                    <div class="min-w-0 sm:ml-auto sm:text-right">
                      <UTooltip
                        :text="`Added to WatchState at: ${moment.unix(data.created_at).format(TOOLTIP_DATE_FORMAT)}`"
                      >
                        <span class="cursor-help font-medium text-highlighted">{{
                          moment.unix(data.created_at).fromNow()
                        }}</span>
                      </UTooltip>
                    </div>
                  </div>
                </div>

                <div v-if="data.updated_at" class="py-2 text-sm text-default">
                  <div
                    class="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3"
                  >
                    <span
                      class="inline-flex min-w-0 items-center gap-2 text-xs font-medium uppercase tracking-[0.16em] text-toned"
                    >
                      <UIcon name="i-lucide-database" class="size-4 shrink-0" />
                      <span>Updated in WatchState</span>
                    </span>

                    <div class="min-w-0 sm:ml-auto sm:text-right">
                      <UTooltip
                        :text="`Updated in WatchState at: ${moment.unix(data.updated_at).format(TOOLTIP_DATE_FORMAT)}`"
                      >
                        <span class="cursor-help font-medium text-highlighted">{{
                          moment.unix(data.updated_at).fromNow()
                        }}</span>
                      </UTooltip>
                    </div>
                  </div>
                </div>
              </div>

              <div v-if="data?.content_title || data?.content_path" class="space-y-3">
                <div class="inline-flex items-center gap-2 text-sm font-semibold text-highlighted">
                  <UIcon name="i-lucide-clapperboard" class="size-4 text-toned" />
                  <span>Content</span>
                </div>

                <div class="grid gap-3 xl:grid-cols-2">
                  <div
                    v-if="data?.content_title"
                    class="ws-inset px-3 py-3"
                    :class="data?.content_path ? '' : 'xl:col-span-2'"
                  >
                    <div class="mb-2 flex items-center justify-between gap-3">
                      <button
                        type="button"
                        class="inline-flex items-center gap-2 text-left text-xs font-medium text-toned"
                        @click="expandLocalTitle = !expandLocalTitle"
                      >
                        <UIcon name="i-lucide-heading" class="size-4" />
                        <span>Subtitle</span>
                      </button>

                      <UTooltip text="Copy subtitle">
                        <UButton
                          color="neutral"
                          variant="outline"
                          size="sm"
                          square
                          icon="i-lucide-copy"
                          aria-label="Copy subtitle"
                          @click="() => void copyText(data.content_title ?? '', false)"
                        />
                      </UTooltip>
                    </div>

                    <div
                      class="min-w-0 cursor-pointer text-default"
                      :class="expandableBlockClass(expandLocalTitle)"
                      @click="expandLocalTitle = !expandLocalTitle"
                    >
                      <NuxtLink
                        :to="makeSearchLink('subtitle', data.content_title)"
                        class="block min-w-0 hover:text-primary"
                        @click.stop
                      >
                        {{ data.content_title }}
                      </NuxtLink>
                    </div>
                  </div>

                  <div
                    v-if="data?.content_path"
                    class="ws-inset px-3 py-3"
                    :class="data?.content_title ? '' : 'xl:col-span-2'"
                  >
                    <div class="mb-2 flex items-center justify-between gap-3">
                      <button
                        type="button"
                        class="inline-flex items-center gap-2 text-left text-xs font-medium text-toned"
                        @click="expandLocalPath = !expandLocalPath"
                      >
                        <UIcon name="i-lucide-file-text" class="size-4" />
                        <span>File Path</span>
                      </button>

                      <UTooltip text="Copy file path">
                        <UButton
                          color="neutral"
                          variant="outline"
                          size="sm"
                          square
                          icon="i-lucide-copy"
                          aria-label="Copy file path"
                          @click="() => void copyText(data.content_path ?? '', false)"
                        />
                      </UTooltip>
                    </div>

                    <div
                      class="min-w-0 cursor-pointer text-default"
                      :class="expandableBlockClass(expandLocalPath, true)"
                      @click="expandLocalPath = !expandLocalPath"
                    >
                      <NuxtLink
                        :to="makeSearchLink('path', data.content_path)"
                        class="block min-w-0 hover:text-primary"
                        @click.stop
                      >
                        {{ data.content_path }}
                      </NuxtLink>
                    </div>
                  </div>
                </div>
              </div>

              <div
                v-if="localGuidSectionCount > 0"
                :class="['grid gap-3', localGuidSectionCount > 1 ? 'xl:grid-cols-2' : '']"
              >
                <div
                  v-if="data.guids && Object.keys(data.guids).length > 0"
                  class="ws-inset px-3 py-3"
                  :class="1 === localGuidSectionCount ? 'xl:col-span-2' : ''"
                >
                  <UTooltip text="Globally unique identifier for this item">
                    <span
                      class="mb-2 inline-flex cursor-help items-center gap-2 font-medium text-highlighted"
                    >
                      <UIcon name="i-lucide-link" class="size-4 text-toned" />
                      <span>GUIDs</span>
                    </span>
                  </UTooltip>

                  <div class="flex flex-wrap gap-2">
                    <UBadge
                      v-for="(guid, source) in data.guids"
                      :key="`guid-${id}-${source}-${guid}`"
                      color="neutral"
                      variant="soft"
                      class="max-w-full"
                    >
                      <NuxtLink
                        target="_blank"
                        class="break-all hover:text-primary"
                        :to="
                          makeGUIDLink(
                            data.type,
                            String(source).split('guid_')[1] ?? String(source),
                            guid,
                            dataContext,
                          )
                        "
                      >
                        {{ String(source).split('guid_')[1] ?? String(source) }}://{{ guid }}
                      </NuxtLink>
                    </UBadge>
                  </div>
                </div>

                <div
                  v-if="data.rguids && Object.keys(data.rguids).length > 0"
                  class="ws-inset px-3 py-3"
                  :class="1 === localGuidSectionCount ? 'xl:col-span-2' : ''"
                >
                  <UTooltip text="Relative Globally unique identifier for this episode">
                    <span
                      class="mb-2 inline-flex cursor-help items-center gap-2 font-medium text-highlighted"
                    >
                      <UIcon name="i-lucide-link" class="size-4 text-toned" />
                      <span>rGUIDs</span>
                    </span>
                  </UTooltip>

                  <div class="flex flex-wrap gap-2">
                    <UBadge
                      v-for="(guid, source) in data.rguids"
                      :key="`rguid-${id}-${source}-${guid}`"
                      color="neutral"
                      variant="soft"
                      class="max-w-full"
                    >
                      <NuxtLink
                        class="break-all hover:text-primary"
                        :to="
                          makeSearchLink(
                            'rguid',
                            `${String(source).split('guid_')[1] ?? String(source)}://${guid}`,
                          )
                        "
                      >
                        {{ String(source).split('guid_')[1] ?? String(source) }}://{{ guid }}
                      </NuxtLink>
                    </UBadge>
                  </div>
                </div>

                <div
                  v-if="data.parent && Object.keys(data.parent).length > 0"
                  class="ws-inset px-3 py-3"
                  :class="
                    1 === localGuidSectionCount || 3 === localGuidSectionCount
                      ? 'xl:col-span-2'
                      : ''
                  "
                >
                  <UTooltip text="Globally unique identifier for the series">
                    <span
                      class="mb-2 inline-flex cursor-help items-center gap-2 font-medium text-highlighted"
                    >
                      <UIcon name="i-lucide-link" class="size-4 text-toned" />
                      <span>Series GUIDs</span>
                    </span>
                  </UTooltip>

                  <div class="flex flex-wrap gap-2">
                    <UBadge
                      v-for="(guid, source) in data.parent"
                      :key="`parent-guid-${id}-${source}-${guid}`"
                      color="neutral"
                      variant="soft"
                      class="max-w-full"
                    >
                      <NuxtLink
                        target="_blank"
                        class="break-all hover:text-primary"
                        :to="
                          makeGUIDLink(
                            'series',
                            String(source).split('guid_')[1] ?? String(source),
                            guid,
                            dataContext,
                          )
                        "
                      >
                        {{ String(source).split('guid_')[1] ?? String(source) }}://{{ guid }}
                      </NuxtLink>
                    </UBadge>
                  </div>
                </div>
              </div>
            </div>
          </section>

          <section v-if="comparisonBackends.length > 0" class="space-y-3">
            <h2 :id="comparisonHeadingId">
              <button
                type="button"
                class="flex w-full items-center justify-between gap-3 text-left"
                :aria-expanded="showComparison"
                :aria-controls="comparisonContentId"
                @click="showComparison = !showComparison"
              >
                <div class="flex items-center gap-2 text-sm font-semibold text-highlighted">
                  <UIcon name="i-lucide-panels-top-left" class="size-4 text-toned" />
                  <span>Backend comparison</span>
                </div>
                <UIcon
                  name="i-lucide-chevron-right"
                  :class="[
                    'size-4 text-toned transition-transform',
                    showComparison ? 'rotate-90' : '',
                  ]"
                />
              </button>
            </h2>

            <p v-if="showComparison" class="text-sm text-toned">
              Highlighted values differ from WatchState.
            </p>

            <div
              v-if="showComparison"
              :id="comparisonContentId"
              class="ws-table-surface min-w-0 max-w-full overflow-x-auto [--ws-comparison-label-width:6rem] sm:[--ws-comparison-label-width:9rem]"
              role="region"
              tabindex="0"
              :aria-labelledby="comparisonHeadingId"
            >
              <div class="grid" :style="comparisonGridStyle">
                <div
                  class="sticky left-0 z-10 border-b border-default bg-default/95 px-3 py-3 text-xs font-semibold uppercase tracking-[0.16em] text-toned backdrop-blur-sm"
                >
                  Field
                </div>
                <div class="border-b border-default bg-primary/5 px-3 py-3">
                  <p class="text-sm font-semibold text-primary">WatchState</p>
                </div>

                <div
                  v-for="backend in comparisonBackends"
                  :key="backend.key"
                  class="border-b border-default bg-elevated/40 px-3 py-3"
                >
                  <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                      <div
                        class="inline-flex min-w-0 items-center gap-1.5 text-sm text-highlighted"
                      >
                        <UTooltip
                          :open="true === availabilityTooltipOpen[backend.key]"
                          :disable-closing-trigger="true"
                          :ui="{
                            content: 'h-auto max-w-xs',
                            text: 'whitespace-normal wrap-anywhere',
                          }"
                          :text="backend.availabilityMessage"
                          @update:open="availabilityTooltipOpen[backend.key] = $event"
                        >
                          <button
                            type="button"
                            class="inline-flex shrink-0 cursor-help rounded p-1 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                            :aria-label="`${backend.key}: ${backend.availabilityMessage}`"
                            @click="
                              availabilityTooltipOpen[backend.key] =
                                !availabilityTooltipOpen[backend.key]
                            "
                          >
                            <UIcon
                              :name="
                                undefined === backend.item.validated
                                  ? 'i-lucide-loader-circle'
                                  : true === backend.item.validated
                                    ? 'i-lucide-circle-check'
                                    : 'i-lucide-triangle-alert'
                              "
                              :class="[
                                'size-4 shrink-0',
                                undefined === backend.item.validated
                                  ? 'animate-spin text-toned'
                                  : true === backend.item.validated
                                    ? 'text-success'
                                    : 'text-warning',
                              ]"
                            />
                          </button>
                        </UTooltip>
                        <NuxtLink
                          :to="`/backend/${backend.key}`"
                          class="truncate hover:text-primary"
                        >
                          {{ backend.key }}
                        </NuxtLink>
                      </div>
                    </div>

                    <UButton
                      color="neutral"
                      variant="outline"
                      size="xs"
                      icon="i-lucide-trash-2"
                      :disabled="isMutating || isLoading"
                      @click="
                        Object.keys(data.metadata).length > 1
                          ? deleteMetadata(backend.key)
                          : deleteItem()
                      "
                    >
                      Delete
                    </UButton>
                  </div>
                </div>

                <template v-for="section in comparisonSections" :key="section.key">
                  <div class="col-span-full border-b border-default bg-elevated/40 px-3 py-3">
                    <h3
                      class="sticky left-3 inline-flex w-fit items-center gap-2 text-sm font-semibold text-highlighted"
                    >
                      <UIcon
                        :name="
                          'identity' === section.key
                            ? 'i-lucide-badge-info'
                            : 'playback' === section.key
                              ? 'i-lucide-circle-play'
                              : 'content' === section.key
                                ? 'i-lucide-clapperboard'
                                : 'i-lucide-link'
                        "
                        class="size-4 shrink-0 text-toned"
                      />
                      <span>{{ section.label }}</span>
                    </h3>
                  </div>

                  <div
                    v-for="(row, rowIndex) in section.rows"
                    :key="row.key"
                    class="contents group/row"
                  >
                    <component
                      :is="row.expandable ? 'button' : 'div'"
                      :type="row.expandable ? 'button' : undefined"
                      :aria-expanded="row.expandable ? isComparisonRowExpanded(row.key) : undefined"
                      class="sticky left-0 z-10 border-b border-default/50 bg-default/95 px-3 py-3 text-left text-sm text-default backdrop-blur-sm transition-colors"
                      :class="row.expandable ? 'cursor-pointer' : ''"
                      @click="row.expandable ? toggleComparisonRow(row.key) : undefined"
                    >
                      <span
                        class="flex min-w-0 items-center justify-between gap-1 text-xs font-medium text-toned sm:font-semibold sm:uppercase sm:tracking-[0.16em]"
                      >
                        <span class="inline-flex min-w-0 items-center gap-1 sm:gap-2">
                          <UIcon :name="row.icon" class="hidden size-4 shrink-0 sm:block" />
                          <span class="min-w-0 wrap-break-word">{{ row.label }}</span>
                        </span>

                        <UIcon
                          v-if="row.expandable"
                          :name="
                            isComparisonRowExpanded(row.key)
                              ? 'i-lucide-chevron-up'
                              : 'i-lucide-chevron-down'
                          "
                          class="size-3.5 shrink-0"
                        />
                      </span>
                    </component>

                    <div
                      v-for="entry in row.cells"
                      :key="`${row.key}:${entry.backend}`"
                      class="border-b border-default/50 px-3 py-3 text-sm text-default transition-colors"
                      :class="[
                        false !== row.compareDiffs && entry.differs
                          ? 'bg-warning/10 ring-1 ring-inset ring-warning/20'
                          : 0 === rowIndex % 2
                            ? 'bg-elevated/40'
                            : 'bg-elevated/20',
                        false !== row.compareDiffs && entry.differs
                          ? 'group-hover/row:bg-warning/15'
                          : 'group-hover/row:bg-elevated/50',
                      ]"
                    >
                      <div class="flex min-w-0 items-start justify-between gap-2">
                        <div class="min-w-0 flex-1">
                          <template v-if="'tags' === entry.cell.kind">
                            <div
                              v-if="(entry.cell.tags?.length ?? 0) > 0"
                              class="flex flex-wrap gap-2"
                            >
                              <UBadge
                                v-for="tag in entry.cell.tags"
                                :key="`${row.key}:${entry.backend}:${tag.label}`"
                                color="neutral"
                                variant="soft"
                                class="max-w-full"
                              >
                                <NuxtLink
                                  v-if="tag.href"
                                  :to="tag.href"
                                  :target="tag.target"
                                  class="break-all hover:text-primary"
                                >
                                  {{ tag.label }}
                                </NuxtLink>
                                <span v-else class="break-all">{{ tag.label }}</span>
                              </UBadge>
                            </div>
                            <span v-else class="text-toned">None</span>
                          </template>

                          <template v-else-if="'date' === entry.cell.kind && entry.cell.tooltip">
                            <UTooltip :text="entry.cell.tooltip">
                              <span class="cursor-help font-medium text-highlighted">
                                {{ entry.cell.value }}
                              </span>
                            </UTooltip>
                          </template>

                          <template v-else>
                            <div
                              class="min-w-0 text-default"
                              :class="[
                                entry.cell.expandable
                                  ? expandableBlockClass(
                                      isComparisonRowExpanded(row.key),
                                      entry.cell.allowBreakAll,
                                    )
                                  : entry.cell.allowBreakAll
                                    ? 'break-all'
                                    : 'wrap-break-word',
                              ]"
                            >
                              <NuxtLink
                                v-if="entry.cell.href"
                                :to="entry.cell.href"
                                :target="entry.cell.target"
                                :external="'_blank' === entry.cell.target"
                                class="block min-w-0 font-medium text-highlighted hover:text-primary"
                                @click.stop
                              >
                                {{ entry.cell.value }}
                              </NuxtLink>
                              <span
                                v-else
                                :class="
                                  ['None', 'Unknown'].includes(entry.cell.value ?? '')
                                    ? 'text-toned'
                                    : 'font-medium text-highlighted'
                                "
                              >
                                {{ entry.cell.value }}
                              </span>
                            </div>
                          </template>
                        </div>

                        <UTooltip text="Copy value">
                          <UButton
                            color="neutral"
                            variant="outline"
                            size="xs"
                            square
                            icon="i-lucide-copy"
                            aria-label="Copy cell value"
                            @click.stop="
                              () => void copyText(comparisonCellCopyText(entry.cell), false)
                            "
                          />
                        </UTooltip>
                      </div>
                    </div>
                  </div>
                </template>
              </div>
            </div>
          </section>
        </div>
      </div>

      <Lazy v-if="data?.via && !isLoading" :min-height="120" @show.once="showRelated">
        <div class="space-y-6">
          <section class="space-y-3">
            <div class="flex w-full flex-wrap items-center justify-between gap-3 text-left">
              <h2 class="min-w-0 basis-full sm:basis-0 sm:flex-1">
                <button
                  type="button"
                  class="flex items-center gap-2 text-left text-sm font-semibold text-highlighted"
                  :aria-expanded="relatedLogsExpanded"
                  @click="toggleRelatedLogs"
                >
                  <UIcon name="i-lucide-logs" class="size-4 text-toned" />
                  <span>Related logs</span>
                </button>
              </h2>
              <div class="flex items-center gap-2">
                <template v-if="relatedLogsExpanded && relatedLoaded && 0 < related.logs.length">
                  <UButton
                    color="neutral"
                    :variant="wrapRelatedLogs ? 'soft' : 'outline'"
                    size="xs"
                    icon="i-lucide-wrap-text"
                    :aria-pressed="wrapRelatedLogs"
                    @click="wrapRelatedLogs = !wrapRelatedLogs"
                  >
                    Wrap
                  </UButton>
                  <UDropdownMenu
                    :items="relatedLogFiles"
                    :content="{ align: 'end' }"
                    :modal="false"
                  >
                    <UButton
                      color="neutral"
                      variant="outline"
                      size="xs"
                      icon="i-lucide-file-text"
                      trailing-icon="i-lucide-chevron-down"
                    >
                      Files
                    </UButton>
                  </UDropdownMenu>
                </template>
                <UDropdownMenu
                  v-if="relatedLogsExpanded && relatedLoaded && related.logs.length > 0"
                  :items="relatedLogCopyMenuItems(related.logs)"
                  :content="{ align: 'end' }"
                  :modal="false"
                >
                  <UButton
                    color="neutral"
                    variant="outline"
                    size="xs"
                    icon="i-lucide-copy"
                    trailing-icon="i-lucide-chevron-down"
                    @click.stop
                  >
                    Copy
                  </UButton>
                </UDropdownMenu>
                <UBadge color="neutral" variant="outline" size="sm">
                  {{ relatedLoaded ? related.logs.length : '?' }}
                </UBadge>
                <UTooltip
                  :text="relatedLogsExpanded ? 'Collapse related logs' : 'Expand related logs'"
                >
                  <button type="button" aria-label="Toggle related logs" @click="toggleRelatedLogs">
                    <UIcon
                      name="i-lucide-chevron-right"
                      :class="[
                        'size-4 text-toned transition-transform',
                        relatedLogsExpanded ? 'rotate-90' : '',
                      ]"
                    />
                  </button>
                </UTooltip>
              </div>
            </div>

            <div v-if="relatedLogsExpanded">
              <UAlert
                v-if="relatedLoading"
                color="info"
                variant="soft"
                icon="i-lucide-loader-circle"
                title="Loading"
                description="Finding related log lines..."
                :ui="{ icon: 'animate-spin' }"
              />
              <UAlert v-else-if="relatedError" color="error" variant="soft" title="Error">
                {{ relatedError }}
              </UAlert>
              <UEmpty
                v-else-if="relatedLoaded && related.logs.length < 1"
                size="sm"
                variant="naked"
                icon="i-lucide-logs"
                title="No related logs found"
              />
              <div
                v-else-if="relatedLoaded"
                ref="relatedLogsContainer"
                class="ws-card max-h-96 overflow-y-auto overflow-x-hidden"
                tabindex="0"
                aria-label="Related logs"
              >
                <article
                  v-for="(log, index) in related.logs"
                  :key="relatedLogKey(log, index)"
                  class="ws-log-row"
                >
                  <div class="ws-log-line">
                    <StructuredLogLine
                      :log="log.entry"
                      :show-details="true"
                      :wrapped="wrapRelatedLogs"
                      :expanded="isExpandedRelatedLog(relatedLogKey(log, index))"
                      toggleable
                      @details="openLogDetails"
                      @open-event="openEventFromLog"
                      @toggle-expand="toggleExpandedRelatedLog(relatedLogKey(log, index))"
                    />
                  </div>
                </article>
              </div>
            </div>
          </section>

          <section class="space-y-3">
            <h2>
              <button
                type="button"
                class="flex w-full items-center justify-between gap-3 text-left"
                :aria-expanded="relatedEventsExpanded"
                @click="toggleRelatedEvents"
              >
                <div class="flex items-center gap-2 text-sm font-semibold text-highlighted">
                  <UIcon name="i-lucide-activity" class="size-4 text-toned" />
                  <span>Related events</span>
                </div>
                <div class="flex items-center gap-2">
                  <UBadge color="neutral" variant="outline" size="sm">
                    {{ relatedLoaded ? related.events.length : '?' }}
                  </UBadge>
                  <UIcon
                    name="i-lucide-chevron-right"
                    :class="[
                      'size-4 text-toned transition-transform',
                      relatedEventsExpanded ? 'rotate-90' : '',
                    ]"
                  />
                </div>
              </button>
            </h2>

            <div v-if="relatedEventsExpanded">
              <UAlert
                v-if="relatedLoading"
                color="info"
                variant="soft"
                icon="i-lucide-loader-circle"
                title="Loading"
                description="Finding related events..."
                :ui="{ icon: 'animate-spin' }"
              />
              <UAlert v-else-if="relatedError" color="error" variant="soft" title="Error">
                {{ relatedError }}
              </UAlert>
              <UEmpty
                v-else-if="relatedLoaded && related.events.length < 1"
                size="sm"
                variant="naked"
                icon="i-lucide-activity"
                title="No related events"
              />
              <div
                v-else-if="relatedLoaded"
                class="max-h-96 min-w-0 overflow-y-auto overflow-x-hidden rounded-lg border border-default/70 bg-elevated/30 shadow-sm"
                tabindex="0"
                aria-label="Related events"
              >
                <article
                  v-for="event in related.events"
                  :key="event.id"
                  class="flex items-center justify-between gap-2 border-b border-default/50 px-3 py-2 last:border-b-0 hover:bg-elevated/60"
                >
                  <div class="flex min-w-0 flex-1 items-center gap-2">
                    <span
                      class="inline-flex size-7 shrink-0 items-center justify-center rounded-md border border-default bg-elevated/70 text-primary"
                    >
                      <UIcon name="i-lucide-activity" class="size-3.5" />
                    </span>

                    <button
                      type="button"
                      class="min-w-0 truncate text-left text-sm font-semibold text-highlighted hover:text-primary"
                      :title="event.name"
                      @click="openEvent(event.id)"
                    >
                      {{ event.name }}
                    </button>
                  </div>

                  <div class="flex shrink-0 items-center gap-2">
                    <UBadge :color="getEventStatusColor(event.status)" variant="soft" size="sm">
                      <span class="inline-flex items-center gap-1">
                        <UIcon
                          :name="getEventStatusIcon(event.status)"
                          :class="getEventStatusIconClass(event.status)"
                        />
                        <span>{{ event.status_name }}</span>
                      </span>
                    </UBadge>

                    <UTooltip
                      :text="`Created at: ${moment(event.timestamp).format(TOOLTIP_DATE_FORMAT)}`"
                    >
                      <span class="cursor-help whitespace-nowrap text-xs text-toned">
                        {{ moment(event.timestamp).fromNow() }}
                      </span>
                    </UTooltip>
                  </div>
                </article>
              </div>
            </div>
          </section>
        </div>
      </Lazy>

      <section class="space-y-3">
        <button
          type="button"
          class="flex w-full items-center justify-between gap-3 text-left"
          @click="show_page_tips = !show_page_tips"
        >
          <div class="flex items-center gap-2 text-sm font-semibold text-highlighted">
            <UIcon name="i-lucide-info" class="size-4 text-toned" />
            <span>Tips</span>
          </div>
          <UIcon
            name="i-lucide-chevron-right"
            :class="['size-4 text-toned transition-transform', show_page_tips ? 'rotate-90' : '']"
          />
        </button>

        <div v-if="show_page_tips" class="ws-card p-4 text-sm leading-6 text-default shadow-sm">
          <ul class="list-disc space-y-2 pl-5">
            <li>Follow the file path link to compare records that reference the same file.</li>
            <li>
              Source IDs open items on their backend. GUIDs &amp; series GUIDs open their external
              provider pages.
            </li>
            <li><code>rGUIDs</code> identify episodes relative to a <code>series GUID</code>.</li>
            <li v-if="data?.not_reported_by && data.not_reported_by.length > 0">
              Check the metadata for any backend listed as not reporting this item.
              <template v-if="'episode' === data.type">
                Matching episodes by <code>rGUID</code> needs at least one shared
                <code>series GUID</code> between backends. Otherwise, their relative episode
                identifiers won't match.
              </template>
            </li>
          </ul>
        </div>
      </section>
    </div>

    <UModal v-model:open="eventViewOpen" :title="eventViewTitle" :ui="eventViewModalUi">
      <template #body>
        <EventView
          v-if="selectedEventId"
          :key="selectedEventId"
          :id="selectedEventId"
          :actions-target="`#${eventActionsId}`"
          @open-event="(eventId) => (selectedEventId = eventId)"
        />
      </template>
      <template #footer>
        <div :id="eventActionsId" class="w-full" />
      </template>
    </UModal>

    <LogDetailsModal
      v-model="detailsOpen"
      :log="selectedLog"
      @open-event="
        (eventId) => {
          detailsOpen = false;
          selectedEventId = eventId;
        }
      "
    />
  </main>
</template>

<script setup lang="ts">
import { computed, onUnmounted, ref, useId, watch } from 'vue';
import { navigateTo, useHead, useRoute } from '#app';
import {
  NuxtLink,
  UAlert,
  UBadge,
  UButton,
  UDropdownMenu,
  UEmpty,
  UIcon,
  UModal,
  UTooltip,
} from '#components';
import { useBreakpoints, useStorage } from '@vueuse/core';
import moment from 'moment';
import DuplicateRecordList from '~/components/DuplicateRecordList.vue';
import EventView from '~/components/EventView.vue';
import Lazy from '~/components/Lazy.vue';
import LogDetailsModal from '~/components/LogDetailsModal.vue';
import PageHeader from '~/components/PageHeader.vue';
import PlaybackSelection from '~/components/PlaybackSelection.vue';
import Popover from '~/components/Popover.vue';
import StructuredLogLine from '~/components/StructuredLogLine.vue';
import StatisticsPoster from '~/components/StatisticsPoster.vue';
import TextModal from '~/components/TextModal.vue';
import { useDialog } from '~/composables/useDialog';
import { usePageBackground } from '~/composables/usePageBackground';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import type {
  GenericResponse,
  HistoryItem,
  JsonObject,
  MediaFile,
  RelatedHistoryLog,
  RelatedHistoryResponse,
  ServerJsonLogEntry,
} from '~/types';
import {
  ag,
  copyText,
  formatDuration,
  getEventStatusColor,
  getEventStatusIcon,
  makeGUIDLink,
  makeEventName,
  makeSearchLink,
  notification,
  parse_api_response,
  request,
  TOOLTIP_DATE_FORMAT,
  ucFirst,
} from '~/utils';

type HistoryMetadataItem = {
  id: string;
  type: string;
  watched: string | number | boolean;
  via: string;
  title: string;
  guids: Record<string, string>;
  progress?: string | number;
  season?: number;
  episode?: number;
  year?: number;
  webUrl?: string;
  parent?: Record<string, string>;
  path?: string;
  extra?: {
    title?: string;
    genres?: Array<string>;
    overview?: string;
  };
  validated?: boolean;
  validated_message?: string;
};

type HistoryViewItem = {
  id: number;
  type: string;
  watched: boolean;
  via: string;
  title: string;
  year?: number;
  season?: number;
  episode?: number;
  parent?: Record<string, string>;
  rguids?: Record<string, string>;
  guids: Record<string, string>;
  metadata: Record<string, HistoryMetadataItem>;
  extra: Record<string, Record<string, string | number | boolean | null>>;
  created_at?: number;
  updated_at?: number;
  updated?: number;
  content_title?: string;
  content_overview?: string;
  content_genres?: Array<string>;
  content_path?: string;
  content_exists?: boolean;
  reported_by: Array<string>;
  not_reported_by: Array<string>;
  progress?: number | string;
  files: Array<MediaFile>;
  duplicate_reference_ids?: Array<number>;
};

type ValidationResponse = Record<string, { status: boolean; message: string }>;

type DuplicateResponse = {
  duplicate_reference_ids: Array<number>;
  duplicates?: Array<HistoryItem>;
};

type ComparisonTag = {
  label: string;
  href?: string;
  target?: '_blank';
};

type SummaryBadge = {
  icon: string;
  label: string;
  variant?: 'outline' | 'soft';
};

type ComparisonCell = {
  kind: 'text' | 'link' | 'date' | 'tags';
  value?: string;
  href?: string;
  target?: '_blank';
  tooltip?: string;
  compareValue?: string;
  allowBreakAll?: boolean;
  expandable?: boolean;
  tags?: Array<ComparisonTag>;
};

type ComparisonBackend = {
  key: string;
  item: HistoryMetadataItem;
  availabilityMessage: string;
};

type ComparisonRowCell = {
  backend: string;
  cell: ComparisonCell;
  differs: boolean;
};

type ComparisonRow = {
  key: string;
  label: string;
  icon: string;
  expandable: boolean;
  compareDiffs?: boolean;
  cells: Array<ComparisonRowCell>;
};

type ComparisonSection = {
  key: string;
  label: string;
  rows: Array<ComparisonRow>;
};

const route = useRoute();
const id = computed<number>(() => {
  const routeId = Array.isArray(route.params.id) ? route.params.id[0] : route.params.id;
  return Number.parseInt(routeId ?? '0', 10);
});
const pageShell = requireTopLevelPageShell('history');

const show_page_tips = useStorage('show_page_tips', true);
const showLocalMetadata = useStorage<boolean>('history_local_metadata_open', false);
const showComparison = useStorage<boolean>('history_comparison_open', true);
const breakpoints = useBreakpoints({ mobile: 0, desktop: 640 });
const dialog = useDialog();
const {
  pageBackgroundOverride,
  pageBackgroundReloadToken,
  setPageBackgroundOverride,
  clearPageBackgroundOverride,
} = usePageBackground();
const backgroundOverrideId = computed(() => `history:${apiUser.value}:${id.value}`);

const comparisonHeadingId = useId();
const comparisonContentId = useId();
const eventActionsId = useId();
const eventViewModalUi = {
  content: 'max-w-5xl',
};

const playbackActionsId = useId();
const playbackModalUi = {
  content: 'max-w-6xl overflow-visible',
  header: 'border-b border-default px-4 py-4 sm:px-5 pe-14 sm:pe-16',
  title: 'pr-2 wrap-break-word',
  body: 'p-0 sm:p-0',
  footer: 'sm:px-5 [&:not(:has(button))]:hidden',
} as const;

const isLoading = ref(true);
const contentError = ref('');
const showRawData = ref(false);
const playbackModalOpen = ref(false);
const isDeleting = ref(false);
const isMutating = ref(false);
const availabilityTooltipOpen = ref<Record<string, boolean>>({});
const comparisonExpanded = ref<Record<string, boolean>>({});
const expandLocalId = ref(false);
const expandLocalTitle = ref(false);
const expandLocalPath = ref(false);
const loadedImages = ref<Record<'poster' | 'background', string | null>>({
  poster: null,
  background: null,
});
const headerOverviewExpanded = ref(false);
const apiUser = useStorage<string>('api_user', 'main');
const posterEnable = useStorage<boolean>('poster_enable', true);
const relatedLogsExpanded = useStorage<boolean>('history_related_logs_open', true);
const relatedEventsExpanded = useStorage<boolean>('history_related_events_open', true);
const relatedLoading = ref(false);
const relatedLoaded = ref(false);
const relatedVisible = ref(false);
const relatedLoadedAt = ref<number | null>(null);
const relatedError = ref('');
const related = ref<RelatedHistoryResponse>({ logs: [], events: [] });
const relatedLogFiles = computed(() =>
  [...new Set(related.value.logs.map((log) => log.filename))].map((filename) => ({
    label: filename,
    icon: 'i-lucide-file-text',
    to: `/logs/${encodeURIComponent(filename)}`,
  })),
);
const selectedEventId = ref<string | null>(null);
const selectedLog = ref<ServerJsonLogEntry | null>(null);
const detailsOpen = ref(false);
const expandedRelatedLogs = ref<Set<string>>(new Set());
const wrapRelatedLogs = useStorage<boolean>('history_related_logs_wrap', false);
const relatedLogsContainer = ref<HTMLElement | null>(null);
const eventViewOpen = computed({
  get: (): boolean => null !== selectedEventId.value,
  set: (open: boolean): void => {
    if (false === open) {
      selectedEventId.value = null;
    }
  },
});
const eventViewTitle = computed(() =>
  null === selectedEventId.value ? 'Event' : `#${makeEventName(selectedEventId.value)}`,
);
let relatedLoadToken = 0;
let contentLoadToken = 0;
let imageLoadToken = 0;

const data = ref<HistoryViewItem>({
  id: id.value,
  type: 'movie',
  updated: 0,
  watched: false,
  via: '',
  title: `${id.value}`,
  guids: {},
  parent: {},
  rguids: {},
  metadata: {},
  extra: {},
  created_at: 0,
  updated_at: 0,
  reported_by: [],
  not_reported_by: [],
  files: [],
  duplicate_reference_ids: [],
});

const dataContext = computed<JsonObject>(() => data.value as unknown as JsonObject);

const localGuidSectionCount = computed<number>(() => {
  let count = 0;

  if (data.value.guids && Object.keys(data.value.guids).length > 0) {
    count++;
  }

  if (data.value.rguids && Object.keys(data.value.rguids).length > 0) {
    count++;
  }

  if (data.value.parent && Object.keys(data.value.parent).length > 0) {
    count++;
  }

  return count;
});

const comparisonBackends = computed<Array<ComparisonBackend>>(() =>
  Object.entries(data.value.metadata).map(([key, item]) => ({
    key,
    item,
    availabilityMessage:
      item.validated_message ||
      (undefined === item.validated
        ? 'Checking availability'
        : item.validated
          ? 'Available'
          : 'Availability not confirmed'),
  })),
);

const comparisonGridStyle = computed<Record<string, string>>(() => ({
  gridTemplateColumns: `var(--ws-comparison-label-width) repeat(${comparisonBackends.value.length + 1}, minmax(12rem, 1fr))`,
  minWidth: `calc(var(--ws-comparison-label-width) + ${(comparisonBackends.value.length + 1) * 12}rem)`,
}));

const isComparisonRowExpanded = (rowKey: string): boolean =>
  true === comparisonExpanded.value[rowKey];

const toggleComparisonRow = (rowKey: string): void => {
  comparisonExpanded.value[rowKey] = !comparisonExpanded.value[rowKey];
};

const makeTextCell = (
  value: string | number | boolean | null | undefined,
  options: { allowBreakAll?: boolean; emptyLabel?: string; expandable?: boolean } = {},
): ComparisonCell => ({
  kind: 'text',
  value:
    value === undefined || value === null || '' === String(value)
      ? (options.emptyLabel ?? 'None')
      : String(value),
  compareValue:
    value === undefined || value === null || '' === String(value)
      ? ''
      : String(value).trim().toLowerCase(),
  allowBreakAll: options.allowBreakAll,
  expandable: options.expandable,
});

const makeLinkCell = (
  value: string | number | null | undefined,
  href: string | null | undefined,
  options: {
    allowBreakAll?: boolean;
    emptyLabel?: string;
    expandable?: boolean;
    target?: '_blank';
  } = {},
): ComparisonCell => {
  if (value === undefined || value === null || '' === String(value)) {
    return makeTextCell('', { emptyLabel: options.emptyLabel ?? 'None' });
  }

  return {
    kind: 'link',
    value: String(value),
    href: href ?? undefined,
    target: options.target,
    compareValue: String(value).trim().toLowerCase(),
    allowBreakAll: options.allowBreakAll,
    expandable: options.expandable,
  };
};

const makeDateCell = (
  time: number | string | null | undefined,
  emptyLabel = 'Unknown',
): ComparisonCell => {
  if (!time) {
    return makeTextCell('', { emptyLabel });
  }

  const momentValue = getMoment(time);

  return {
    kind: 'date',
    value: momentValue.fromNow(),
    tooltip: momentValue.format(TOOLTIP_DATE_FORMAT.value),
    compareValue: String(momentValue.valueOf()),
  };
};

const makeTagsCell = (items: Array<ComparisonTag>): ComparisonCell => ({
  kind: 'tags',
  tags: items,
  compareValue: items
    .map((item) => item.label.toLowerCase())
    .sort()
    .join('|'),
});

const makeGenreCell = (genres?: Array<string>): ComparisonCell => {
  if (!genres || genres.length < 1) {
    return makeTextCell('', { emptyLabel: 'None' });
  }

  return makeTagsCell(
    genres.map((genre) => ({
      label: genre,
    })),
  );
};

const makeGuidCell = (
  values: Record<string, string> | undefined,
  type: string,
  context: JsonObject,
): ComparisonCell => {
  if (!values || Object.keys(values).length < 1) {
    return makeTextCell('', { emptyLabel: 'None' });
  }

  return makeTagsCell(
    Object.entries(values).map(([source, guid]) => {
      const provider = String(source).split('guid_')[1] ?? String(source);

      return {
        label: `${provider}://${guid}`,
        href: makeGUIDLink(type, provider, guid, context),
        target: '_blank',
      };
    }),
  );
};

const comparisonCellValue = (cell: ComparisonCell): string => {
  if (cell.compareValue !== undefined) {
    return cell.compareValue;
  }

  if ('tags' === cell.kind) {
    return (cell.tags ?? [])
      .map((item) => item.label.toLowerCase())
      .sort()
      .join('|');
  }

  return (cell.value ?? '').trim().toLowerCase();
};

const comparisonCellCopyText = (cell: ComparisonCell): string => {
  if ('tags' === cell.kind) {
    return cell.tags && cell.tags.length > 0
      ? cell.tags.map((item) => item.label).join('\n')
      : 'None';
  }

  if ('date' === cell.kind) {
    return cell.tooltip ?? cell.value ?? 'Unknown';
  }

  return cell.value ?? '';
};

const differsFromBaseline = (baseline: ComparisonCell, cell: ComparisonCell): boolean =>
  comparisonCellValue(baseline) !== comparisonCellValue(cell);

const makeComparisonRow = (
  key: string,
  label: string,
  icon: string,
  baseline: ComparisonCell,
  backendCellFactory: (backend: ComparisonBackend) => ComparisonCell,
  options: { compareDiffs?: boolean } = {},
): ComparisonRow | null => {
  const cells = comparisonBackends.value.map((backend) => {
    const cell = backendCellFactory(backend);

    return {
      backend: backend.key,
      cell,
      differs: differsFromBaseline(baseline, cell),
    };
  });

  const hasValue =
    '' !== comparisonCellValue(baseline) ||
    cells.some((entry) => '' !== comparisonCellValue(entry.cell));

  if (!hasValue) {
    return null;
  }

  return {
    key,
    label,
    icon,
    expandable:
      Boolean(baseline.expandable) || cells.some((entry) => Boolean(entry.cell.expandable)),
    compareDiffs: options.compareDiffs,
    cells: [{ backend: 'LOCAL', cell: baseline, differs: false }, ...cells],
  };
};

const comparisonSections = computed<Array<ComparisonSection>>(() => {
  const identityRows = [
    makeComparisonRow(
      'source-id',
      'Source ID',
      'i-lucide-id-card',
      makeTextCell(data.value.id, { emptyLabel: 'None', allowBreakAll: true, expandable: true }),
      ({ item }) =>
        makeLinkCell(item.id, item.webUrl, {
          emptyLabel: 'None',
          allowBreakAll: true,
          expandable: true,
          target: '_blank',
        }),
      { compareDiffs: false },
    ),
    makeComparisonRow(
      'type',
      'Type',
      'i-lucide-clapperboard',
      makeTextCell(ucFirst(data.value.type), { emptyLabel: 'Unknown' }),
      ({ item }) =>
        makeLinkCell(ucFirst(item.type), makeSearchLink('type', item.type), {
          emptyLabel: 'Unknown',
        }),
    ),
    makeComparisonRow(
      'season',
      'Season',
      'i-lucide-tv',
      makeTextCell(data.value.season, { emptyLabel: 'None' }),
      ({ item }) =>
        makeLinkCell(item.season, makeSearchLink('season', String(item.season ?? '')), {
          emptyLabel: 'None',
        }),
    ),
    makeComparisonRow(
      'episode',
      'Episode',
      'i-lucide-tv',
      makeTextCell(data.value.episode, { emptyLabel: 'None' }),
      ({ item }) =>
        makeLinkCell(item.episode, makeSearchLink('episode', String(item.episode ?? '')), {
          emptyLabel: 'None',
        }),
    ),
    makeComparisonRow(
      'guids',
      'GUIDs',
      'i-lucide-link',
      makeGuidCell(data.value.guids, data.value.type, dataContext.value),
      ({ item }) => makeGuidCell(item.guids, item.type, item as unknown as JsonObject),
    ),
    makeComparisonRow(
      'parent-guids',
      'Series GUIDs',
      'i-lucide-link',
      makeGuidCell(data.value.parent, 'series', dataContext.value),
      ({ item }) => makeGuidCell(item.parent, 'series', item as unknown as JsonObject),
    ),
  ].filter((row): row is ComparisonRow => row !== null);

  const playbackRows = [
    makeComparisonRow(
      'status',
      'Status',
      'i-lucide-eye',
      makeTextCell(data.value.watched ? 'Watched' : 'Unwatched'),
      ({ item }) => makeTextCell(Number(item.watched) ? 'Watched' : 'Unwatched'),
    ),
    makeComparisonRow(
      'progress',
      'Progress',
      'i-lucide-clock-3',
      makeTextCell(
        Number(data.value.progress) > 0 ? formatDuration(Number(data.value.progress ?? 0)) : 'None',
      ),
      ({ item }) =>
        makeTextCell(
          Number(item.progress) > 0 ? formatDuration(Number(item.progress ?? 0)) : 'None',
        ),
    ),
    makeComparisonRow(
      'event',
      'Event',
      'i-lucide-mail',
      makeTextCell(ag(data.value.extra, `${data.value.via}.event`, 'Unknown')),
      ({ key }) => makeTextCell(ag(data.value.extra, `${key}.event`, 'Unknown')),
      { compareDiffs: false },
    ),
    makeComparisonRow(
      'updated',
      'Backend updated',
      'i-lucide-calendar',
      makeDateCell(data.value.updated),
      () => makeTextCell('', { emptyLabel: 'N/A' }),
      { compareDiffs: false },
    ),
    makeComparisonRow(
      'received',
      'Last report',
      'i-lucide-inbox',
      makeTextCell('', { emptyLabel: 'N/A' }),
      ({ key }) => makeDateCell(ag(data.value.extra, `${key}.received_at`, null)),
      { compareDiffs: false },
    ),
    makeComparisonRow(
      'db-updated',
      'Updated in WatchState',
      'i-lucide-database',
      makeDateCell(data.value.updated_at),
      () => makeTextCell('', { emptyLabel: 'N/A' }),
      { compareDiffs: false },
    ),
  ].filter((row): row is ComparisonRow => row !== null);

  const contentRows = [
    makeComparisonRow(
      'subtitle',
      'Subtitle',
      'i-lucide-heading',
      makeLinkCell(
        data.value.content_title ?? data.value.title,
        makeSearchLink('subtitle', data.value.content_title ?? data.value.title),
        { expandable: true },
      ),
      ({ item }) =>
        makeLinkCell(
          item.extra?.title ?? item.title,
          makeSearchLink('subtitle', item.extra?.title ?? item.title),
          { expandable: true },
        ),
    ),
    makeComparisonRow(
      'path',
      'File Path',
      'i-lucide-file-text',
      makeLinkCell(
        data.value.content_path,
        data.value.content_path ? makeSearchLink('path', data.value.content_path) : '',
        { allowBreakAll: true, expandable: true },
      ),
      ({ item }) =>
        makeLinkCell(item.path, item.path ? makeSearchLink('path', item.path) : '', {
          allowBreakAll: true,
          expandable: true,
        }),
    ),
    makeComparisonRow(
      'summary',
      'Summary',
      'i-lucide-message-square',
      makeTextCell(data.value.content_overview, { expandable: true }),
      ({ item }) => makeTextCell(item.extra?.overview, { expandable: true }),
    ),
    makeComparisonRow(
      'genres',
      'Genres',
      'i-lucide-tag',
      makeGenreCell(data.value.content_genres),
      ({ item }) => makeGenreCell(item.extra?.genres),
    ),
  ].filter((row): row is ComparisonRow => row !== null);

  return [
    { key: 'playback', label: 'Playback', rows: playbackRows },
    { key: 'content', label: 'Content', rows: contentRows },
    { key: 'identity', label: 'Identifiers', rows: identityRows },
  ].filter((section) => section.rows.length > 0);
});

const historyTitle = computed<string>(() => {
  const baseTitle = data.value.title ?? id.value.toString();
  if (
    'episode' === data.value.type &&
    data.value.season !== undefined &&
    data.value.episode !== undefined
  ) {
    const season = String(data.value.season).padStart(2, '0');
    const episode = String(data.value.episode).padStart(3, '0');
    const year = data.value.year ?? '0000';
    return `${baseTitle} (${year}) - ${season}x${episode}`;
  }
  if (data.value.year !== undefined) {
    return `${baseTitle} (${data.value.year})`;
  }
  return baseTitle;
});

const contentDateLabel = computed<string | null>(() => {
  const contentDate = ag(data.value.metadata, `${data.value.via}.extra.date`, '');
  if ('string' !== typeof contentDate || '' === contentDate.trim()) {
    return null;
  }

  return contentDate.trim();
});

const summaryBadges = computed<Array<SummaryBadge>>(() => {
  const badges: Array<SummaryBadge> = [];

  if (contentDateLabel.value) {
    badges.push({
      icon: 'i-lucide-calendar',
      label: contentDateLabel.value,
    });
  } else if (data.value.year !== undefined) {
    badges.push({
      icon: 'i-lucide-calendar',
      label: String(data.value.year),
    });
  }

  if ('episode' === data.value.type && data.value.season !== undefined) {
    badges.push({
      icon: 'i-lucide-tv',
      label: `Season ${data.value.season}`,
    });
  }

  if ('episode' === data.value.type && data.value.episode !== undefined) {
    badges.push({
      icon: 'i-lucide-clapperboard',
      label: `Episode ${data.value.episode}`,
    });
  }

  return badges;
});

const rawData = computed<string>(() => {
  const dataRecord = data.value as unknown as JsonObject;
  const cleaned = Object.keys(dataRecord)
    .filter((key) => !['files', 'hardware', 'content_exists'].includes(key))
    .reduce((obj: JsonObject, key: string) => {
      obj[key] = dataRecord[key] ?? null;
      return obj;
    }, {} as JsonObject);
  return JSON.stringify(cleaned, null, 2);
});

const expandableInlineClass = (expanded?: boolean, allowBreakAll = false): string => {
  if (true === expanded) {
    return allowBreakAll ? 'break-all' : 'break-words';
  }

  return allowBreakAll ? 'ws-expandable-inline-breakall' : 'ws-expandable-inline';
};

const expandableBlockClass = (expanded?: boolean, allowBreakAll = false): string => {
  if (true === expanded) {
    return allowBreakAll ? 'break-all' : 'break-words';
  }

  return 'ws-expandable-block';
};

const isExpandedRelatedLog = (key: string): boolean => expandedRelatedLogs.value.has(key);

const relatedLogKey = (log: RelatedHistoryLog, index: number): string =>
  `${log.filename}:${log.entry.id}:${index}`;

const relatedLogCopyMenuItems = (logs: Array<RelatedHistoryLog>) => [
  [
    {
      label: 'Copy text',
      icon: 'i-lucide-message-square-text',
      onSelect: () => {
        copyText(
          logs
            .map(
              ({ entry }) =>
                `[${entry.datetime}] ${entry.level.toUpperCase()} [${entry.logger}] ${entry.message}`,
            )
            .join('\n'),
        );
      },
    },
    {
      label: 'Copy raw',
      icon: 'i-lucide-braces',
      onSelect: () => {
        copyText(logs.map(({ entry }) => JSON.stringify(entry)).join('\n'));
      },
    },
  ],
];

const toggleExpandedRelatedLog = (key: string): void => {
  const next = new Set(expandedRelatedLogs.value);

  if (true === next.has(key)) {
    next.delete(key);
  } else {
    next.add(key);
  }

  expandedRelatedLogs.value = next;
};

const openLogDetails = (entry: ServerJsonLogEntry): void => {
  selectedLog.value = entry;
  detailsOpen.value = true;
};

const openEventFromLog = (eventId: string): void => {
  selectedEventId.value = eventId;
};

const openEvent = (eventId: string): void => {
  if ('' !== eventId) {
    selectedEventId.value = eventId;
  }
};

const getEventStatusIconClass = (status: number): string =>
  1 === status ? 'size-3.5 animate-spin' : 'size-3.5';

const loadContent = async (): Promise<void> => {
  const token = ++contentLoadToken;
  const historyId = id.value;
  const user = apiUser.value;
  isLoading.value = true;
  contentError.value = '';

  try {
    const response = await request(`/history/${historyId}?files=true`, {
      headers: { 'X-User': user },
    });
    const json = await parse_api_response<HistoryViewItem>(response);

    if (token !== contentLoadToken || historyId !== id.value || user !== apiUser.value) {
      return;
    }

    if ('error' in json) {
      contentError.value = `${json.error.code}: ${json.error.message}`;
      notification('error', 'Error loading data', `${json.error.code}: ${json.error.message}`);
      if (404 === response.status) {
        await navigateTo({ name: 'history' });
      }
      return;
    }

    data.value = { ...json };
    isLoading.value = false;
    await Promise.all([
      loadImage(),
      validateItem(historyId, user, token),
      checkDuplicates(historyId, user, token),
    ]);
  } catch (error: unknown) {
    if (token === contentLoadToken) {
      contentError.value = error instanceof Error ? error.message : 'Request error.';
      notification('error', 'Error loading data', contentError.value);
    }
  } finally {
    if (token === contentLoadToken) {
      isLoading.value = false;
    }
  }
};

const resetRelated = (): void => {
  relatedLoadToken++;
  relatedLoading.value = false;
  relatedLoaded.value = false;
  relatedLoadedAt.value = null;
  relatedError.value = '';
  related.value = { logs: [], events: [] };
  selectedEventId.value = null;
  selectedLog.value = null;
  detailsOpen.value = false;
  expandedRelatedLogs.value = new Set();
};

const loadRelated = async (): Promise<void> => {
  const now = Date.now();
  if (true === relatedLoaded.value) {
    if (null !== relatedLoadedAt.value && now - relatedLoadedAt.value < 300_000) {
      return;
    }
  }

  if (true === relatedLoading.value) {
    return;
  }

  const token = ++relatedLoadToken;
  const historyId = id.value;
  const user = apiUser.value;
  relatedLoading.value = true;
  relatedLoaded.value = false;
  relatedLoadedAt.value = null;
  relatedError.value = '';
  related.value = { logs: [], events: [] };

  try {
    const response = await request(`/history/${historyId}/related`, {
      headers: { 'X-User': user },
    });
    const json = await parse_api_response<RelatedHistoryResponse>(response);

    const requestIsCurrent =
      token === relatedLoadToken && historyId === id.value && user === apiUser.value;
    if (false === requestIsCurrent) {
      return;
    }

    if ('error' in json) {
      relatedError.value = `${json.error.code}: ${json.error.message}`;
      return;
    }

    related.value = json;
    relatedLoaded.value = true;
    relatedLoadedAt.value = Date.now();
  } catch (error) {
    if (token === relatedLoadToken) {
      relatedError.value = error instanceof Error ? error.message : 'Request error.';
    }
  } finally {
    if (token === relatedLoadToken) {
      relatedLoading.value = false;
    }
  }
};

const toggleRelatedLogs = (): void => {
  relatedLogsExpanded.value = !relatedLogsExpanded.value;
  if (relatedLogsExpanded.value) {
    void loadRelated();
  }
};

const toggleRelatedEvents = (): void => {
  relatedEventsExpanded.value = !relatedEventsExpanded.value;
  if (relatedEventsExpanded.value) {
    void loadRelated();
  }
};

const showRelated = (): void => {
  relatedVisible.value = true;
  if (relatedLogsExpanded.value || relatedEventsExpanded.value) {
    void loadRelated();
  }
};

watch(
  relatedLogsContainer,
  (container) => {
    if (container) {
      container.scrollTop = container.scrollHeight;
    }
  },
  { flush: 'post' },
);

watch(breakpoints.active(), async () => await loadImage());

watch(pageBackgroundReloadToken, async () => {
  if (pageBackgroundOverride.value?.id !== backgroundOverrideId.value) {
    return;
  }

  await loadImage(true);
});

const loadImage = async (force = false, imageType: 'poster' | 'background' | null = null) => {
  const token = ++imageLoadToken;
  const historyId = id.value;
  const user = apiUser.value;
  const overrideId = backgroundOverrideId.value;
  try {
    const activeBreakpoint = breakpoints.active().value;
    const bgType =
      null === imageType ? ('mobile' === activeBreakpoint ? 'poster' : 'background') : imageType;

    if (false === force && loadedImages.value[bgType]) {
      setPageBackgroundOverride({
        id: overrideId,
        src: loadedImages.value[bgType] ?? '',
      });
      return;
    }

    let url = `/history/${historyId}/images/${bgType}`;
    if (force) {
      url += `?t=${Date.now()}`;
    }

    const imgRequest = await request(url, { headers: { 'X-User': user } });
    if (token !== imageLoadToken || historyId !== id.value || user !== apiUser.value) {
      return;
    }
    if (!imgRequest.ok) {
      clearPageBackgroundOverride(overrideId);
      return;
    }

    const blob = await imgRequest.blob();
    if (token !== imageLoadToken || historyId !== id.value || user !== apiUser.value) {
      return;
    }
    const previousUrl = loadedImages.value[bgType];
    if (previousUrl) {
      URL.revokeObjectURL(previousUrl);
    }
    loadedImages.value[bgType] = URL.createObjectURL(blob);
    setPageBackgroundOverride({
      id: overrideId,
      src: loadedImages.value[bgType] ?? '',
    });
  } catch {
    if (token === imageLoadToken) {
      clearPageBackgroundOverride(overrideId);
    }
  }
};

const deleteItem = async () => {
  if (isDeleting.value || isLoading.value || isMutating.value) {
    return;
  }
  const historyId = id.value;
  const user = apiUser.value;
  const token = contentLoadToken;
  const title = historyTitle.value;

  const { status: confirmStatus } = await dialog.confirmDialog({
    message: `Delete the local record for '${historyTitle.value}'?`,
    confirmText: 'Delete',
    confirmColor: 'error',
  });

  if (
    true !== confirmStatus ||
    token !== contentLoadToken ||
    historyId !== id.value ||
    user !== apiUser.value
  ) {
    return;
  }

  isDeleting.value = true;

  try {
    const response = await request(`/history/${historyId}`, {
      method: 'DELETE',
      headers: { 'X-User': user },
    });
    const json = await parse_api_response<GenericResponse>(response);
    if (token !== contentLoadToken || historyId !== id.value || user !== apiUser.value) {
      return;
    }

    if ('error' in json) {
      notification('error', 'Error', `${json.error.code}: ${json.error.message}`);
      return;
    }

    notification('success', 'Record deleted', `Deleted '${title}'.`);
    await navigateTo({ name: 'history' });
  } catch (error) {
    if (token === contentLoadToken) {
      const message = error instanceof Error ? error.message : 'Request error.';
      notification('error', 'Error', message);
    }
  } finally {
    if (token === contentLoadToken) {
      isDeleting.value = false;
    }
  }
};

const openPlaybackModal = (): void => {
  if (isLoading.value || isMutating.value || isDeleting.value) {
    return;
  }
  playbackModalOpen.value = true;
};

const handlePlaybackWatchedChange = async (watched: boolean): Promise<void> => {
  if (isLoading.value || false === playbackModalOpen.value) {
    return;
  }
  data.value.watched = watched;
  await loadContent();
};

const toggleWatched = async () => {
  if (isLoading.value || isMutating.value || isDeleting.value) {
    return;
  }
  const historyId = id.value;
  const user = apiUser.value;
  const token = contentLoadToken;
  const watched = data.value.watched;
  const { status: confirmStatus } = await dialog.confirmDialog({
    message: `Mark '${historyTitle.value}' ${data.value.watched ? 'unwatched' : 'watched'} in WatchState?`,
  });

  if (
    true !== confirmStatus ||
    token !== contentLoadToken ||
    historyId !== id.value ||
    user !== apiUser.value
  ) {
    return;
  }

  isMutating.value = true;
  try {
    const response = await request(`/history/${historyId}/watch`, {
      method: watched ? 'DELETE' : 'POST',
      headers: { 'X-User': user },
    });

    const json = await parse_api_response<HistoryViewItem>(response);
    if (token !== contentLoadToken || historyId !== id.value || user !== apiUser.value) {
      return;
    }

    if ('error' in json) {
      notification('error', 'Error', `${json.error.code}: ${json.error.message}`);
      return;
    }

    data.value = { ...json };

    notification(
      'success',
      'Local state updated',
      `Marked '${historyTitle.value}' ${data.value.watched ? 'watched' : 'unwatched'}.`,
    );
    await Promise.all([
      validateItem(historyId, user, token),
      checkDuplicates(historyId, user, token),
    ]);
  } catch (error) {
    if (token === contentLoadToken) {
      notification('error', 'Error', `Request error. ${String(error)}`);
    }
  } finally {
    if (token === contentLoadToken) {
      isMutating.value = false;
    }
  }
};

const validateItem = async (historyId: number, user: string, token: number) => {
  try {
    const response = await request(`/history/${historyId}/validate`, {
      headers: { 'X-User': user },
    });
    const json = await parse_api_response<ValidationResponse>(response);
    if (token !== contentLoadToken || historyId !== id.value || user !== apiUser.value) {
      return;
    }
    if ('error' in json) {
      throw new Error(json.error.message);
    }

    for (const item of Object.values(data.value.metadata)) {
      item.validated = false;
      item.validated_message = 'Availability could not be checked.';
    }

    for (const [backend, item] of Object.entries(json)) {
      if (data.value.metadata[backend] === undefined) {
        continue;
      }

      data.value.metadata[backend].validated = item.status;
      data.value.metadata[backend].validated_message = item.message;
    }
  } catch (error: unknown) {
    if (token !== contentLoadToken || historyId !== id.value || user !== apiUser.value) {
      return;
    }
    for (const item of Object.values(data.value.metadata)) {
      item.validated = false;
      item.validated_message = `Could not check availability: ${error instanceof Error ? error.message : 'Request error.'}`;
    }
  }
};

const deleteMetadata = async (backend: string) => {
  if (isLoading.value || isMutating.value || isDeleting.value) {
    return;
  }
  const historyId = id.value;
  const user = apiUser.value;
  const token = contentLoadToken;
  const { status: confirmStatus } = await dialog.confirmDialog({
    message: `Remove '${backend}' metadata from '${historyTitle.value}'?`,
    confirmText: 'Remove',
    confirmColor: 'error',
  });

  if (
    true !== confirmStatus ||
    token !== contentLoadToken ||
    historyId !== id.value ||
    user !== apiUser.value
  ) {
    return;
  }

  isMutating.value = true;
  try {
    const response = await request(`/history/${historyId}/metadata/${backend}`, {
      method: 'DELETE',
      headers: { 'X-User': user },
    });
    const json = await parse_api_response<GenericResponse>(response);
    if (token !== contentLoadToken || historyId !== id.value || user !== apiUser.value) {
      return;
    }

    if ('error' in json) {
      notification('error', 'Error', `${json.error.code}: ${json.error.message}`);
      return;
    }

    notification('success', 'Metadata deleted', `Deleted '${backend}' metadata.`);
    isMutating.value = false;
    await loadContent();
  } catch (error) {
    if (token === contentLoadToken) {
      notification('error', 'Error', `Request error. ${String(error)}`);
    }
  } finally {
    if (token === contentLoadToken) {
      isMutating.value = false;
    }
  }
};

const checkDuplicates = async (historyId: number, user: string, token: number) => {
  try {
    const response = await request(`/history/${historyId}/duplicates`, {
      headers: { 'X-User': user },
    });

    if (!response.ok) {
      return;
    }

    const json = await parse_api_response<DuplicateResponse>(response);
    if (token !== contentLoadToken || historyId !== id.value || user !== apiUser.value) {
      return;
    }
    if ('error' in json) {
      return;
    }

    data.value.duplicate_reference_ids = json.duplicate_reference_ids;

    if (json.duplicates && json.duplicates.length > 0) {
      notification(
        'info',
        'Info',
        `There are ${json.duplicates.length} duplicate items for this record.`,
        10000,
      );
    }
  } catch {}
};

const getMoment = (time: number | string) =>
  time.toString().length < 13 ? moment.unix(Number(time)) : moment(time);
const headerTitle = computed<string>(() =>
  isLoading.value ? id.value.toString() : historyTitle.value,
);

useHead(() => ({ title: `History : ${headerTitle.value}` }));

watch(
  [apiUser, id],
  (_current, previous) => {
    imageLoadToken++;
    if (previous) {
      clearPageBackgroundOverride(`history:${previous[0]}:${previous[1]}`);
    }
    for (const url of Object.values(loadedImages.value)) {
      if (url) {
        URL.revokeObjectURL(url);
      }
    }
    loadedImages.value = { poster: null, background: null };
    data.value = {
      id: id.value,
      type: 'movie',
      watched: false,
      via: '',
      title: String(id.value),
      guids: {},
      metadata: {},
      extra: {},
      reported_by: [],
      not_reported_by: [],
      files: [],
      duplicate_reference_ids: [],
    };
    comparisonExpanded.value = {};
    expandLocalId.value = false;
    expandLocalTitle.value = false;
    expandLocalPath.value = false;
    headerOverviewExpanded.value = false;
    showRawData.value = false;
    playbackModalOpen.value = false;
    isDeleting.value = false;
    isMutating.value = false;
    resetRelated();
    void loadContent();
    if (relatedVisible.value && (relatedLogsExpanded.value || relatedEventsExpanded.value)) {
      void loadRelated();
    }
  },
  { immediate: true, flush: 'sync' },
);

onUnmounted(() => {
  contentLoadToken++;
  relatedLoadToken++;
  imageLoadToken++;
  clearPageBackgroundOverride(backgroundOverrideId.value);
  for (const url of Object.values(loadedImages.value)) {
    if (url) {
      URL.revokeObjectURL(url);
    }
  }
});
</script>
