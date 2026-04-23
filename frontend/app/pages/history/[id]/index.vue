<template>
  <main class="w-full min-w-0 max-w-full space-y-4">
    <div class="flex flex-col gap-3 xl:flex-row xl:items-start xl:justify-between">
      <div class="min-w-0 space-y-2">
        <div
          class="flex flex-wrap items-center gap-2 text-xs font-medium uppercase tracking-[0.2em] text-toned"
        >
          <UIcon :name="pageShell.icon" class="size-4" />
          <span>{{ pageShell.sectionLabel }}</span>
          <span>/</span>
          <NuxtLink to="/history" class="hover:text-primary">{{ pageShell.pageLabel }}</NuxtLink>
          <span>/</span>
          <span class="truncate text-highlighted normal-case tracking-normal">{{
            headerTitle
          }}</span>
        </div>

        <div v-if="data?.via && (data?.content_title || data?.content_overview)" class="space-y-2">
          <div
            v-if="data.content_title"
            class="flex items-center gap-2 text-lg font-semibold text-highlighted"
          >
            <UIcon
              :name="'episode' === data.type ? 'i-lucide-tv' : 'i-lucide-film'"
              class="size-4 text-toned"
            />
            <span class="truncate">{{ data.content_title }}</span>
          </div>

          <p
            v-if="data.content_overview"
            class="max-w-4xl cursor-pointer text-sm text-toned"
            :class="{ 'overflow-hidden text-ellipsis whitespace-nowrap': !expandOverview }"
            @click="expandOverview = !expandOverview"
          >
            {{ data.content_overview }}
          </p>

          <div
            v-if="data.content_genres && data.content_genres.length > 0"
            class="flex flex-wrap gap-2"
          >
            <UBadge
              v-for="(genre, genreIndex) in data.content_genres"
              :key="`head-genre-${genreIndex}`"
              color="info"
              variant="soft"
            >
              <span class="inline-flex items-center gap-1">
                <UIcon name="i-lucide-tag" class="size-3.5" />
                <span class="capitalize">{{ genre }}</span>
              </span>
            </UBadge>
          </div>
        </div>
      </div>

      <div v-if="data?.via" class="flex flex-wrap items-center justify-end gap-2">
        <UTooltip
          v-if="data?.files?.length > 0"
          :text="`${data.content_exists ? 'Play media' : 'Media is inaccessible'}`"
        >
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-play"
            :disabled="!data.content_exists"
            @click="navigateTo(`/play/${data.id}`)"
          >
            Play
          </UButton>
        </UTooltip>

        <UTooltip text="Toggle watch state">
          <UButton
            color="neutral"
            :variant="data.watched ? 'soft' : 'outline'"
            size="sm"
            :icon="data.watched ? 'i-lucide-eye-off' : 'i-lucide-eye'"
            @click="toggleWatched"
          >
            {{ data.watched ? 'Unwatched' : 'Watched' }}
          </UButton>
        </UTooltip>

        <UTooltip text="View raw record payload">
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-bug"
            @click="showRawData = true"
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
            :disabled="isDeleting || isLoading"
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
          @click="() => void loadContent(id)"
        >
          Reload
        </UButton>
      </div>
    </div>

    <TextModal v-model:open="showRawData" :title="historyTitle" :text="rawData" />

    <div class="space-y-4">
      <UAlert
        v-if="!data?.via && isLoading"
        color="info"
        variant="soft"
        icon="i-lucide-loader-circle"
        title="Loading"
        description="Loading data. Please wait..."
        :ui="{ icon: 'animate-spin' }"
      />

      <UAlert
        v-if="(data?.duplicate_reference_ids?.length ?? 0) > 0"
        color="info"
        variant="soft"
        icon="i-lucide-info"
        title="Duplicate file references"
      >
        <template #description>
          <span>
            This record shares the same file path with other records.
            <Popover
              placement="bottom"
              trigger="click"
              :show-delay="0"
              :hide-delay="200"
              :offset="8"
              content-class="p-0"
            >
              <template #trigger>
                <button
                  type="button"
                  class="font-semibold text-primary underline underline-offset-2"
                >
                  Click here
                </button>
              </template>
              <template #content>
                <DuplicateRecordList :ids="data.duplicate_reference_ids ?? []" />
              </template>
            </Popover>
            to see the other records.
          </span>
        </template>
      </UAlert>

      <UAlert
        v-if="data?.not_reported_by && data.not_reported_by.length > 0"
        color="warning"
        variant="soft"
        icon="i-lucide-triangle-alert"
        :title="`Missing metadata from ${ucFirst(data.type)}`"
      >
        <template #description>
          <div class="space-y-2">
            <p>
              There are no metadata regarding this <strong>{{ data.type }}</strong> from:
            </p>
            <div class="flex flex-wrap gap-2">
              <UBadge
                v-for="backend in data.not_reported_by"
                :key="`nr-${backend}`"
                color="warning"
                variant="soft"
              >
                <NuxtLink :to="`/backend/${backend}`">{{ backend }}</NuxtLink>
              </UBadge>
            </div>
          </div>
        </template>
      </UAlert>

      <div class="space-y-4">
        <div v-if="data?.via" class="space-y-4">
          <UCard
            class="border border-default/70 shadow-sm"
            :class="[data.watched ? 'ring-1 ring-success/30' : '', 'bg-default/90']"
            :ui="detailCardUi"
          >
            <template #header>
              <div class="flex items-start gap-3">
                <div
                  class="flex min-w-0 flex-1 items-center gap-2 text-sm font-semibold text-highlighted"
                >
                  <button
                    type="button"
                    class="inline-flex shrink-0 items-center"
                    @click="data._toggle = !data._toggle"
                  >
                    <UIcon
                      :name="data?._toggle ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
                      class="size-4 text-toned"
                    />
                  </button>

                  <span>Latest local metadata via</span>

                  <NuxtLink
                    :to="`/backend/${data.via}`"
                    class="inline-flex min-w-0 items-center gap-1 text-highlighted hover:text-primary"
                  >
                    <UIcon name="i-lucide-server" class="size-4 shrink-0 text-toned" />
                    <span class="truncate">{{ data.via }}</span>
                  </NuxtLink>
                </div>
              </div>
            </template>

            <div v-if="data?._toggle" class="space-y-5 text-sm leading-6 text-default">
              <div class="grid grid-cols-2 gap-3">
                <div
                  class="rounded-md border border-default bg-elevated/40 px-3 py-2.5 text-sm text-default"
                >
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

                <div
                  class="rounded-md border border-default bg-elevated/40 px-3 py-2.5 text-sm text-default"
                >
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
                      data.watched ? 'Played' : 'Unplayed'
                    }}</span>
                  </div>
                </div>

                <div
                  class="rounded-md border border-default bg-elevated/40 px-3 py-2.5 text-sm text-default"
                >
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

                <div
                  class="rounded-md border border-default bg-elevated/40 px-3 py-2.5 text-sm text-default"
                >
                  <div
                    class="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3"
                  >
                    <span
                      class="inline-flex min-w-0 items-center gap-2 text-xs font-medium uppercase tracking-[0.16em] text-toned"
                    >
                      <UIcon name="i-lucide-clock-3" class="size-4 shrink-0" />
                      <span>Progress</span>
                    </span>
                    <span class="min-w-0 font-medium text-highlighted sm:ml-auto sm:text-right">
                      {{
                        Number(data.progress) > 0
                          ? formatDuration(Number(data.progress ?? 0))
                          : 'None'
                      }}
                    </span>
                  </div>
                </div>

                <div
                  class="rounded-md border border-default bg-elevated/40 px-3 py-2.5 text-sm text-default"
                >
                  <div
                    class="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3"
                  >
                    <span
                      class="inline-flex min-w-0 items-center gap-2 text-xs font-medium uppercase tracking-[0.16em] text-toned"
                    >
                      <UIcon
                        :name="'episode' === data.type ? 'i-lucide-tv' : 'i-lucide-film'"
                        class="size-4 shrink-0"
                      />
                      <span>Type</span>
                    </span>
                    <NuxtLink
                      :to="makeSearchLink('type', data.type)"
                      class="block min-w-0 font-medium text-highlighted hover:text-primary sm:ml-auto sm:text-right"
                      >{{ ucFirst(data.type) }}</NuxtLink
                    >
                  </div>
                </div>

                <div
                  class="rounded-md border border-default bg-elevated/40 px-3 py-2.5 text-sm text-default"
                >
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

                <div
                  v-if="'episode' === data.type"
                  class="rounded-md border border-default bg-elevated/40 px-3 py-2.5 text-sm text-default"
                >
                  <div
                    class="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3"
                  >
                    <span
                      class="inline-flex min-w-0 items-center gap-2 text-xs font-medium uppercase tracking-[0.16em] text-toned"
                    >
                      <UIcon name="i-lucide-tv" class="size-4 shrink-0" />
                      <span>Season</span>
                    </span>
                    <NuxtLink
                      :to="makeSearchLink('season', String(data.season ?? ''))"
                      class="block min-w-0 font-medium text-highlighted hover:text-primary sm:ml-auto sm:text-right"
                    >
                      {{ data.season }}
                    </NuxtLink>
                  </div>
                </div>

                <div
                  v-if="'episode' === data.type"
                  class="rounded-md border border-default bg-elevated/40 px-3 py-2.5 text-sm text-default"
                >
                  <div
                    class="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3"
                  >
                    <span
                      class="inline-flex min-w-0 items-center gap-2 text-xs font-medium uppercase tracking-[0.16em] text-toned"
                    >
                      <UIcon name="i-lucide-tv" class="size-4 shrink-0" />
                      <span>Episode</span>
                    </span>
                    <NuxtLink
                      :to="makeSearchLink('episode', String(data.episode ?? ''))"
                      class="block min-w-0 font-medium text-highlighted hover:text-primary sm:ml-auto sm:text-right"
                    >
                      {{ data.episode }}
                    </NuxtLink>
                  </div>
                </div>

                <div
                  v-if="data.created_at"
                  class="rounded-md border border-default bg-elevated/40 px-3 py-2.5 text-sm text-default"
                >
                  <div
                    class="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3"
                  >
                    <span
                      class="inline-flex min-w-0 items-center gap-2 text-xs font-medium uppercase tracking-[0.16em] text-toned"
                    >
                      <UIcon name="i-lucide-database" class="size-4 shrink-0" />
                      <span>Created</span>
                    </span>

                    <div class="min-w-0 sm:ml-auto sm:text-right">
                      <UTooltip
                        :text="`DB record created at: ${moment.unix(data.created_at).format(TOOLTIP_DATE_FORMAT)}`"
                      >
                        <span class="cursor-help font-medium text-highlighted">{{
                          moment.unix(data.created_at).fromNow()
                        }}</span>
                      </UTooltip>
                    </div>
                  </div>
                </div>

                <div
                  v-if="data.updated_at"
                  class="rounded-md border border-default bg-elevated/40 px-3 py-2.5 text-sm text-default"
                >
                  <div
                    class="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3"
                  >
                    <span
                      class="inline-flex min-w-0 items-center gap-2 text-xs font-medium uppercase tracking-[0.16em] text-toned"
                    >
                      <UIcon name="i-lucide-database" class="size-4 shrink-0" />
                      <span>Updated</span>
                    </span>

                    <div class="min-w-0 sm:ml-auto sm:text-right">
                      <UTooltip
                        :text="`DB record updated at: ${moment.unix(data.updated_at).format(TOOLTIP_DATE_FORMAT)}`"
                      >
                        <span class="cursor-help font-medium text-highlighted">{{
                          moment.unix(data.updated_at).fromNow()
                        }}</span>
                      </UTooltip>
                    </div>
                  </div>
                </div>
              </div>

              <div
                v-if="
                  data?.content_title ||
                  data?.content_path ||
                  (data?.content_genres && data.content_genres.length > 0) ||
                  data?.content_overview
                "
                class="space-y-3"
              >
                <div class="inline-flex items-center gap-2 text-sm font-semibold text-highlighted">
                  <UIcon name="i-lucide-clapperboard" class="size-4 text-toned" />
                  <span>Content</span>
                </div>

                <div class="grid gap-3 xl:grid-cols-2">
                  <div
                    v-if="data?.content_title"
                    class="rounded-md border border-default bg-elevated/40 px-3 py-3 xl:order-1"
                    :class="data?.content_overview ? '' : 'xl:col-span-2'"
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
                    v-if="data?.content_overview"
                    class="rounded-md border border-default bg-elevated/40 px-3 py-3 xl:order-2"
                    :class="data?.content_title ? '' : 'xl:col-span-2'"
                  >
                    <button
                      type="button"
                      class="mb-2 inline-flex items-center gap-2 text-left text-xs font-medium text-toned"
                      @click="expandOverview = !expandOverview"
                    >
                      <UIcon name="i-lucide-message-square" class="size-4" />
                      <span>Content Summary</span>
                    </button>
                    <div
                      class="cursor-pointer text-default"
                      :class="expandableBlockClass(expandOverview)"
                      @click="expandOverview = !expandOverview"
                    >
                      {{ data.content_overview }}
                    </div>
                  </div>

                  <div
                    v-if="data?.content_path"
                    class="rounded-md border border-default bg-elevated/40 px-3 py-3 xl:order-3 xl:col-span-2"
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

                  <div
                    v-if="data?.content_genres && data.content_genres.length > 0"
                    class="rounded-md border border-default bg-elevated/40 px-3 py-3 xl:order-4 xl:col-span-2"
                  >
                    <div class="mb-2 inline-flex items-center gap-2 text-xs font-medium text-toned">
                      <UIcon name="i-lucide-tag" class="size-4" />
                      <span>Genres</span>
                    </div>
                    <div class="flex flex-wrap gap-2">
                      <UBadge
                        v-for="genre in data.content_genres"
                        :key="`latest-${genre}`"
                        color="info"
                        variant="soft"
                      >
                        <span class="capitalize">{{ genre }}</span>
                      </UBadge>
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
                  class="rounded-md border border-default bg-elevated/40 px-3 py-3"
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
                  class="rounded-md border border-default bg-elevated/40 px-3 py-3"
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
                  class="rounded-md border border-default bg-elevated/40 px-3 py-3"
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
          </UCard>

          <div v-if="Object.keys(data.metadata).length > 0" class="space-y-4 xl:hidden">
            <div class="space-y-1">
              <div class="inline-flex items-center gap-2 text-sm font-semibold text-highlighted">
                <UIcon name="i-lucide-panels-top-left" class="size-4 text-toned" />
                <span>Backend Reports</span>
              </div>
              <p class="text-sm text-toned">
                Compare metadata reported by each backend side by side.
              </p>
            </div>

            <div class="grid gap-4 xl:grid-cols-2">
              <UCard
                v-for="(item, key) in data.metadata"
                :key="key"
                class="h-full border border-default/70 shadow-sm"
                :class="[Number(item.watched) ? 'ring-1 ring-success/30' : '', 'bg-default/90']"
                :ui="detailCardUi"
              >
                <template #header>
                  <div class="flex items-start justify-between gap-3">
                    <div
                      class="flex min-w-0 flex-1 items-center gap-2 text-sm font-semibold text-highlighted"
                    >
                      <button
                        type="button"
                        class="inline-flex shrink-0 items-center"
                        @click="item._toggle = !item._toggle"
                      >
                        <UIcon
                          :name="item?._toggle ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
                          class="size-4 text-toned"
                        />
                      </button>
                      <UIcon
                        :name="
                          undefined === item?.validated
                            ? 'i-lucide-loader-circle'
                            : true === item?.validated
                              ? 'i-lucide-circle-check'
                              : 'i-lucide-x'
                        "
                        :class="[
                          'size-4 shrink-0',
                          undefined === item?.validated
                            ? 'animate-spin text-toned'
                            : true === item?.validated
                              ? 'text-success'
                              : 'text-error',
                        ]"
                      />
                      <span>Metadata via</span>
                      <NuxtLink
                        :to="`/backend/${key}`"
                        class="inline-flex min-w-0 items-center gap-1 text-highlighted hover:text-primary"
                      >
                        <span class="truncate">{{ key }}</span>
                      </NuxtLink>
                    </div>

                    <div class="flex shrink-0 items-center gap-3">
                      <UButton
                        color="neutral"
                        variant="outline"
                        size="sm"
                        icon="i-lucide-trash-2"
                        @click="
                          Object.keys(data.metadata).length > 1 ? deleteMetadata(key) : deleteItem()
                        "
                      >
                        <span class="hidden sm:inline">Delete</span>
                      </UButton>
                    </div>
                  </div>
                </template>

                <div v-if="item?._toggle" class="space-y-5 text-sm leading-6 text-default">
                  <p
                    v-if="false === item?.validated && item.validated_message"
                    class="rounded-md border border-error/30 bg-error/10 px-3 py-2 text-sm text-error"
                  >
                    {{ item.validated_message }}
                  </p>

                  <div class="grid grid-cols-2 gap-3">
                    <div
                      class="rounded-md border border-default bg-elevated/40 px-3 py-2.5 text-sm text-default"
                    >
                      <div
                        class="flex min-w-0 cursor-pointer flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3"
                        @click="item.expandId = !item.expandId"
                      >
                        <span
                          class="inline-flex min-w-0 items-center gap-2 text-xs font-medium uppercase tracking-[0.16em] text-toned"
                        >
                          <UIcon name="i-lucide-id-card" class="size-4 shrink-0" />
                          <span>Source ID</span>
                        </span>
                        <NuxtLink
                          v-if="item?.webUrl"
                          :to="item.webUrl"
                          target="_blank"
                          class="block min-w-0 font-medium text-highlighted hover:text-primary sm:ml-auto sm:text-right"
                          :class="expandableInlineClass(item.expandId, true)"
                        >
                          {{ item.id }}
                        </NuxtLink>
                        <span
                          v-else
                          class="block min-w-0 font-medium text-highlighted sm:ml-auto sm:text-right"
                          :class="expandableInlineClass(item.expandId, true)"
                          >{{ item.id }}</span
                        >
                      </div>
                    </div>

                    <div
                      class="rounded-md border border-default bg-elevated/40 px-3 py-2.5 text-sm text-default"
                    >
                      <div
                        class="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3"
                      >
                        <span
                          class="inline-flex min-w-0 items-center gap-2 text-xs font-medium uppercase tracking-[0.16em] text-toned"
                        >
                          <UIcon
                            :name="Number(item.watched) ? 'i-lucide-eye' : 'i-lucide-eye-off'"
                            class="size-4 shrink-0"
                          />
                          <span>Status</span>
                        </span>
                        <span
                          class="min-w-0 font-medium text-highlighted sm:ml-auto sm:text-right"
                          >{{ Number(item.watched) ? 'Played' : 'Unplayed' }}</span
                        >
                      </div>
                    </div>

                    <div
                      class="rounded-md border border-default bg-elevated/40 px-3 py-2.5 text-sm text-default"
                    >
                      <div
                        class="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3"
                      >
                        <span
                          class="inline-flex min-w-0 items-center gap-2 text-xs font-medium uppercase tracking-[0.16em] text-toned"
                        >
                          <UIcon name="i-lucide-mail" class="size-4 shrink-0" />
                          <span>Event</span>
                        </span>
                        <span
                          class="min-w-0 font-medium text-highlighted sm:ml-auto sm:text-right"
                          >{{ ag(data.extra, `${key}.event`, 'Unknown') }}</span
                        >
                      </div>
                    </div>

                    <div
                      class="rounded-md border border-default bg-elevated/40 px-3 py-2.5 text-sm text-default"
                    >
                      <div
                        class="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3"
                      >
                        <span
                          class="inline-flex min-w-0 items-center gap-2 text-xs font-medium uppercase tracking-[0.16em] text-toned"
                        >
                          <UIcon name="i-lucide-clock-3" class="size-4 shrink-0" />
                          <span>Progress</span>
                        </span>
                        <span class="min-w-0 font-medium text-highlighted sm:ml-auto sm:text-right">
                          {{
                            Number(item?.progress) > 0
                              ? formatDuration(Number(item?.progress ?? 0))
                              : 'None'
                          }}
                        </span>
                      </div>
                    </div>

                    <div
                      class="rounded-md border border-default bg-elevated/40 px-3 py-2.5 text-sm text-default"
                    >
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
                            :text="`Backend last activity: ${getMoment(ag(data.extra, `${key}.received_at`, data.updated)).format(TOOLTIP_DATE_FORMAT)}`"
                          >
                            <span class="cursor-help font-medium text-highlighted">{{
                              getMoment(
                                ag(data.extra, `${key}.received_at`, data.updated),
                              ).fromNow()
                            }}</span>
                          </UTooltip>
                        </div>
                      </div>
                    </div>

                    <div
                      class="rounded-md border border-default bg-elevated/40 px-3 py-2.5 text-sm text-default"
                    >
                      <div
                        class="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3"
                      >
                        <span
                          class="inline-flex min-w-0 items-center gap-2 text-xs font-medium uppercase tracking-[0.16em] text-toned"
                        >
                          <UIcon
                            :name="'episode' === item.type ? 'i-lucide-tv' : 'i-lucide-film'"
                            class="size-4 shrink-0"
                          />
                          <span>Type</span>
                        </span>
                        <NuxtLink
                          :to="makeSearchLink('type', item.type)"
                          class="block min-w-0 font-medium text-highlighted hover:text-primary sm:ml-auto sm:text-right"
                          >{{ ucFirst(item.type) }}</NuxtLink
                        >
                      </div>
                    </div>

                    <div
                      v-if="'episode' === item.type"
                      class="rounded-md border border-default bg-elevated/40 px-3 py-2.5 text-sm text-default"
                    >
                      <div
                        class="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3"
                      >
                        <span
                          class="inline-flex min-w-0 items-center gap-2 text-xs font-medium uppercase tracking-[0.16em] text-toned"
                        >
                          <UIcon name="i-lucide-tv" class="size-4 shrink-0" />
                          <span>Season</span>
                        </span>
                        <NuxtLink
                          :to="makeSearchLink('season', String(item.season ?? ''))"
                          class="block min-w-0 font-medium text-highlighted hover:text-primary sm:ml-auto sm:text-right"
                        >
                          {{ item.season }}
                        </NuxtLink>
                      </div>
                    </div>

                    <div
                      v-if="'episode' === item.type"
                      class="rounded-md border border-default bg-elevated/40 px-3 py-2.5 text-sm text-default"
                    >
                      <div
                        class="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3"
                      >
                        <span
                          class="inline-flex min-w-0 items-center gap-2 text-xs font-medium uppercase tracking-[0.16em] text-toned"
                        >
                          <UIcon name="i-lucide-tv" class="size-4 shrink-0" />
                          <span>Episode</span>
                        </span>
                        <NuxtLink
                          :to="makeSearchLink('episode', String(item.episode ?? ''))"
                          class="block min-w-0 font-medium text-highlighted hover:text-primary sm:ml-auto sm:text-right"
                        >
                          {{ item.episode }}
                        </NuxtLink>
                      </div>
                    </div>
                  </div>

                  <div
                    v-if="
                      item?.extra?.title ||
                      item?.path ||
                      (item?.extra?.genres && item.extra.genres.length > 0) ||
                      item?.extra?.overview
                    "
                    class="space-y-3"
                  >
                    <div
                      class="inline-flex items-center gap-2 text-sm font-semibold text-highlighted"
                    >
                      <UIcon name="i-lucide-clapperboard" class="size-4 text-toned" />
                      <span>Content</span>
                    </div>

                    <div class="grid gap-3 xl:grid-cols-2">
                      <div
                        v-if="item?.extra?.title"
                        class="rounded-md border border-default bg-elevated/40 px-3 py-3 xl:order-1"
                        :class="item?.extra?.overview ? '' : 'xl:col-span-2'"
                      >
                        <div class="mb-2 flex items-center justify-between gap-3">
                          <button
                            type="button"
                            class="inline-flex items-center gap-2 text-left text-xs font-medium text-toned"
                            @click="item.expandTitle = !item.expandTitle"
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
                              @click="() => void copyText(item.extra?.title ?? '', false)"
                            />
                          </UTooltip>
                        </div>

                        <div
                          class="min-w-0 cursor-pointer text-default"
                          :class="expandableBlockClass(item.expandTitle)"
                          @click="item.expandTitle = !item.expandTitle"
                        >
                          <NuxtLink
                            :to="makeSearchLink('subtitle', item.extra.title)"
                            class="block min-w-0 hover:text-primary"
                            @click.stop
                          >
                            {{ item.extra.title }}
                          </NuxtLink>
                        </div>
                      </div>

                      <div
                        v-if="item?.extra?.overview"
                        class="rounded-md border border-default bg-elevated/40 px-3 py-3 xl:order-2"
                        :class="item?.extra?.title ? '' : 'xl:col-span-2'"
                      >
                        <button
                          type="button"
                          class="mb-2 inline-flex items-center gap-2 text-left text-xs font-medium text-toned"
                          @click="item.expandOverview = !item.expandOverview"
                        >
                          <UIcon name="i-lucide-message-square" class="size-4" />
                          <span>Content Summary</span>
                        </button>
                        <div
                          class="cursor-pointer text-default"
                          :class="expandableBlockClass(item.expandOverview)"
                          @click="item.expandOverview = !item.expandOverview"
                        >
                          {{ item.extra.overview }}
                        </div>
                      </div>

                      <div
                        v-if="item?.path"
                        class="rounded-md border border-default bg-elevated/40 px-3 py-3 xl:order-3 xl:col-span-2"
                      >
                        <div class="mb-2 flex items-center justify-between gap-3">
                          <button
                            type="button"
                            class="inline-flex items-center gap-2 text-left text-xs font-medium text-toned"
                            @click="item.expandPath = !item.expandPath"
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
                              @click="() => void copyText(item.path ?? '', false)"
                            />
                          </UTooltip>
                        </div>

                        <div
                          class="min-w-0 cursor-pointer text-default"
                          :class="expandableBlockClass(item.expandPath, true)"
                          @click="item.expandPath = !item.expandPath"
                        >
                          <NuxtLink
                            :to="makeSearchLink('path', item.path)"
                            class="block min-w-0 hover:text-primary"
                            @click.stop
                          >
                            {{ item.path }}
                          </NuxtLink>
                        </div>
                      </div>

                      <div
                        v-if="item?.extra?.genres && item.extra.genres.length > 0"
                        class="rounded-md border border-default bg-elevated/40 px-3 py-3 xl:order-4 xl:col-span-2"
                      >
                        <div
                          class="mb-2 inline-flex items-center gap-2 text-xs font-medium text-toned"
                        >
                          <UIcon name="i-lucide-tag" class="size-4" />
                          <span>Genres</span>
                        </div>
                        <div class="flex flex-wrap gap-2">
                          <UBadge
                            v-for="genre in item.extra.genres"
                            :key="`${item.id}-${genre}`"
                            color="info"
                            variant="soft"
                          >
                            <span class="capitalize">{{ genre }}</span>
                          </UBadge>
                        </div>
                      </div>
                    </div>
                  </div>

                  <div
                    v-if="metadataGuidSectionCount(item) > 0"
                    :class="[
                      'grid gap-3',
                      metadataGuidSectionCount(item) > 1 ? 'xl:grid-cols-2' : '',
                    ]"
                  >
                    <div
                      v-if="item.guids && Object.keys(item.guids).length > 0"
                      class="rounded-md border border-default bg-elevated/40 px-3 py-3"
                      :class="1 === metadataGuidSectionCount(item) ? 'xl:col-span-2' : ''"
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
                          v-for="(guid, source) in item.guids"
                          :key="`guid-${item.id}-${source}-${guid}`"
                          color="neutral"
                          variant="soft"
                          class="max-w-full"
                        >
                          <NuxtLink
                            target="_blank"
                            class="break-all hover:text-primary"
                            :to="
                              makeGUIDLink(
                                item.type,
                                String(source).split('guid_')[1] ?? String(source),
                                guid,
                                item as unknown as JsonObject,
                              )
                            "
                          >
                            {{ String(source).split('guid_')[1] ?? String(source) }}://{{ guid }}
                          </NuxtLink>
                        </UBadge>
                      </div>
                    </div>

                    <div
                      v-if="item.parent && Object.keys(item.parent).length > 0"
                      class="rounded-md border border-default bg-elevated/40 px-3 py-3"
                      :class="1 === metadataGuidSectionCount(item) ? 'xl:col-span-2' : ''"
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
                          v-for="(guid, source) in item.parent"
                          :key="`parent-guid-${item.id}-${source}-${guid}`"
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
                                item as unknown as JsonObject,
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
              </UCard>
            </div>
          </div>

          <UCard
            v-if="comparisonBackends.length > 0"
            class="hidden border border-default/70 bg-default/90 shadow-sm xl:block"
            :ui="detailCardUi"
          >
            <template #header>
              <div class="space-y-2">
                <div class="flex items-start gap-3">
                  <div
                    class="flex min-w-0 flex-1 items-center gap-2 text-sm font-semibold text-highlighted"
                  >
                    <button
                      type="button"
                      class="inline-flex shrink-0 items-center"
                      @click="showComparison = !showComparison"
                    >
                      <UIcon
                        :name="showComparison ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
                        class="size-4 text-toned"
                      />
                    </button>

                    <UIcon name="i-lucide-panels-top-left" class="size-4 text-toned" />
                    <span>Comparison</span>
                  </div>
                </div>

                <p v-if="showComparison" class="text-sm text-toned">
                  Backend values are compared against the local snapshot above. Highlighted cells
                  differ from the local record.
                </p>
              </div>
            </template>

            <div v-if="showComparison" class="space-y-4">
              <section v-for="section in comparisonSections" :key="section.key" class="space-y-2">
                <div class="inline-flex items-center gap-2 text-sm font-semibold text-highlighted">
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
                </div>

                <div class="overflow-hidden rounded-md border border-default">
                  <div class="grid" :style="comparisonGridStyle">
                    <div
                      class="border border-default/60 bg-elevated/20 px-3 py-3 text-xs font-semibold uppercase tracking-[0.16em] text-toned"
                    >
                      Field
                    </div>

                    <div
                      v-for="backend in comparisonBackends"
                      :key="`${section.key}-${backend.key}`"
                      class="border border-default/60 bg-elevated/20 px-3 py-3"
                    >
                      <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                          <div
                            class="inline-flex min-w-0 items-center gap-1.5 text-sm text-highlighted"
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

                    <div
                      v-for="(row, rowIndex) in section.rows"
                      :key="row.key"
                      class="contents group/row"
                    >
                      <div
                        class="border border-default/50 px-3 py-3 text-sm text-default transition-colors"
                        :class="[
                          0 === rowIndex % 2 ? 'bg-default' : 'bg-elevated/20',
                          'group-hover/row:bg-elevated/50',
                          row.expandable ? 'cursor-pointer' : '',
                        ]"
                        @click="row.expandable ? toggleComparisonRow(row.key) : undefined"
                      >
                        <div
                          class="flex min-w-0 items-center justify-between gap-2 text-xs font-semibold uppercase tracking-[0.16em] text-toned"
                        >
                          <span class="inline-flex min-w-0 items-center gap-2">
                            <UIcon :name="row.icon" class="size-4 shrink-0" />
                            <span>{{ row.label }}</span>
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
                        </div>
                      </div>

                      <div
                        v-for="entry in row.cells"
                        :key="`${row.key}:${entry.backend}`"
                        class="border border-default/50 px-3 py-3 text-sm text-default transition-colors"
                        :class="[
                          false !== row.compareDiffs && entry.differs
                            ? 'bg-warning/10 ring-1 ring-inset ring-warning/20'
                            : 0 === rowIndex % 2
                              ? 'bg-default/90'
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
                  </div>
                </div>
              </section>
            </div>
          </UCard>
        </div>
      </div>

      <UCard class="border border-default/70 bg-default/90 shadow-sm" :ui="tipsCardUi">
        <template #header>
          <button
            type="button"
            class="flex items-center gap-2 text-left text-sm font-semibold text-highlighted"
            @click="show_page_tips = !show_page_tips"
          >
            <UIcon
              :name="show_page_tips ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
              class="size-4 text-toned"
            />
            <UIcon name="i-lucide-info" class="size-4 text-toned" />
            <span>Tips</span>
          </button>
        </template>

        <div v-if="show_page_tips" class="text-sm leading-6 text-default">
          <ul class="list-disc space-y-2 pl-5">
            <li>
              To see if your media backends are reporting different metadata for the same file,
              click on the file link which will filter your history based on that file.
            </li>
            <li>
              Clicking on the ID in <code>metadata via</code> boxes will take you directly to the
              item in the source backend. While clicking on the GUIDs will take you to that source
              link, similarly clicking on the series GUIDs will take you to the series link that was
              provided by the external source.
            </li>
            <li>
              <code>rGUIDSs</code> are relative globally unique identifiers for episodes based on
              <code>series GUID</code>.
            </li>
            <li v-if="data?.not_reported_by && data.not_reported_by.length > 0">
              The warning on top of the page usually is accurate, and it is recommended to check the
              backend metadata for the item.
              <template v-if="'episode' === data.type">
                For episodes, we use <code>rGUIDs</code> to identify the episode, and
                <strong>important part</strong> of that GUID is the <code>series GUID</code>. We
                need at least one reported series GUIDs to match between your backends. If none are
                matching, it will be treated as separate series.
              </template>
            </li>
          </ul>
        </div>
      </UCard>
    </div>
  </main>
</template>

<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { navigateTo, useHead, useRoute } from '#app';
import { NuxtLink } from '#components';
import { useBreakpoints, useStorage } from '@vueuse/core';
import moment from 'moment';
import DuplicateRecordList from '~/components/DuplicateRecordList.vue';
import Popover from '~/components/Popover.vue';
import TextModal from '~/components/TextModal.vue';
import { useDialog } from '~/composables/useDialog';
import { usePageBackground } from '~/composables/usePageBackground';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import type { GenericResponse, HistoryItem, JsonObject, MediaFile } from '~/types';
import {
  ag,
  copyText,
  formatDuration,
  makeGUIDLink,
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
  _toggle?: boolean;
  validated?: boolean;
  validated_message?: string;
  expandId?: boolean;
  expandTitle?: boolean;
  expandPath?: boolean;
  expandGenres?: boolean;
  expandOverview?: boolean;
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
  _toggle?: boolean;
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
const idParam = Array.isArray(route.params.id) ? route.params.id[0] : route.params.id;
const id = Number.parseInt(idParam ?? '0', 10);
const pageShell = requireTopLevelPageShell('history');

useHead({ title: `History : ${id}` });

const show_page_tips = useStorage('show_page_tips', true);
const breakpoints = useBreakpoints({ mobile: 0, desktop: 640 });
const dialog = useDialog();
const {
  pageBackgroundOverride,
  pageBackgroundReloadToken,
  setPageBackgroundOverride,
  clearPageBackgroundOverride,
} = usePageBackground();
const backgroundOverrideId = `history:${id}`;

const detailCardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
};

const tipsCardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
};

const isLoading = ref(true);
const showRawData = ref(false);
const isDeleting = ref(false);
const showComparison = ref(true);
const comparisonExpanded = ref<Record<string, boolean>>({});
const expandLocalId = ref(false);
const expandLocalTitle = ref(false);
const expandLocalPath = ref(false);
const loadedImages = ref<Record<'poster' | 'background', string | null>>({
  poster: null,
  background: null,
});
const expandOverview = ref(false);

const data = ref<HistoryViewItem>({
  id,
  type: 'movie',
  updated: 0,
  watched: false,
  via: '',
  title: `${id}`,
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

const metadataGuidSectionCount = (item: HistoryMetadataItem): number => {
  let count = 0;

  if (item.guids && Object.keys(item.guids).length > 0) {
    count++;
  }

  if (item.parent && Object.keys(item.parent).length > 0) {
    count++;
  }

  return count;
};

const comparisonBackends = computed<Array<ComparisonBackend>>(() =>
  Object.entries(data.value.metadata).map(([key, item]) => ({ key, item })),
);

const comparisonGridStyle = computed<Record<string, string>>(() => ({
  gridTemplateColumns: `minmax(9rem, 10rem) repeat(${comparisonBackends.value.length}, minmax(0, 1fr))`,
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
    cells,
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
      ({ item }) => makeTextCell(ucFirst(item.type), { emptyLabel: 'Unknown' }),
    ),
    makeComparisonRow(
      'season',
      'Season',
      'i-lucide-tv',
      makeTextCell(data.value.season, { emptyLabel: 'None' }),
      ({ item }) => makeTextCell(item.season, { emptyLabel: 'None' }),
    ),
    makeComparisonRow(
      'episode',
      'Episode',
      'i-lucide-tv',
      makeTextCell(data.value.episode, { emptyLabel: 'None' }),
      ({ item }) => makeTextCell(item.episode, { emptyLabel: 'None' }),
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
      makeTextCell(data.value.watched ? 'Played' : 'Unplayed'),
      ({ item }) => makeTextCell(Number(item.watched) ? 'Played' : 'Unplayed'),
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
      'Updated',
      'i-lucide-calendar',
      makeDateCell(data.value.updated),
      ({ key }) => makeDateCell(ag(data.value.extra, `${key}.received_at`, data.value.updated)),
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
    { key: 'identity', label: 'Identity', rows: identityRows },
    { key: 'playback', label: 'Playback', rows: playbackRows },
    { key: 'content', label: 'Content', rows: contentRows },
  ].filter((section) => section.rows.length > 0);
});

const historyTitle = computed<string>(() => {
  const baseTitle = data.value.title ?? id.toString();
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

const rawData = computed<string>(() => {
  const dataRecord = data.value as unknown as JsonObject;
  const cleaned = Object.keys(dataRecord)
    .filter((key) => !['files', 'hardware', 'content_exists', '_toggle'].includes(key))
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

const loadContent = async (historyId: number) => {
  isLoading.value = true;

  const response = await request(`/history/${historyId}?files=true`);
  const json = await parse_api_response<HistoryViewItem>(response);

  if (route.name !== 'history-id') {
    return;
  }

  if ('error' in json) {
    isLoading.value = false;
    notification('Error', 'Error loading data', `${json.error.code}: ${json.error.message}`);
    if (404 === response.status) {
      await navigateTo({ name: 'history' });
    }
    return;
  }

  isLoading.value = false;
  data.value = { ...json, _toggle: true };

  useHead({ title: `History : ${historyTitle.value}` });
  await loadImage();
  await nextTick();
  await validateItem();
  await checkDuplicates();
};

watch(breakpoints.active(), async () => await loadImage());

watch(pageBackgroundReloadToken, async () => {
  if (pageBackgroundOverride.value?.id !== backgroundOverrideId) {
    return;
  }

  await loadImage(true);
});

const loadImage = async (force = false, imageType: 'poster' | 'background' | null = null) => {
  try {
    const activeBreakpoint = breakpoints.active().value;
    const bgType =
      null === imageType ? ('mobile' === activeBreakpoint ? 'poster' : 'background') : imageType;

    if (false === force && loadedImages.value[bgType]) {
      setPageBackgroundOverride({
        id: backgroundOverrideId,
        src: loadedImages.value[bgType] ?? '',
      });
      return;
    }

    let url = `/history/${id}/images/${bgType}`;
    if (force) {
      url += `?t=${Date.now()}`;
    }

    const imgRequest = await request(url);
    if (!imgRequest.ok) {
      clearPageBackgroundOverride(backgroundOverrideId);
      return;
    }

    loadedImages.value[bgType] = URL.createObjectURL(await imgRequest.blob());
    setPageBackgroundOverride({
      id: backgroundOverrideId,
      src: loadedImages.value[bgType] ?? '',
    });
  } catch {
    clearPageBackgroundOverride(backgroundOverrideId);
  }
};

const deleteItem = async () => {
  if (isDeleting.value) {
    return;
  }

  const { status: confirmStatus } = await dialog.confirmDialog({
    message: `Delete '${historyTitle.value}' local record?`,
    confirmColor: 'error',
  });

  if (true !== confirmStatus) {
    return;
  }

  isDeleting.value = true;

  try {
    const response = await request(`/history/${id}`, { method: 'DELETE' });
    const json = await parse_api_response<GenericResponse>(response);

    if ('error' in json) {
      notification('error', 'Error', `${json.error.code}: ${json.error.message}`);
      return;
    }

    notification('success', 'Success!', `Deleted '${historyTitle.value}'.`);
    await navigateTo({ name: 'history' });
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Request error.';
    notification('error', 'Error', message);
  } finally {
    isDeleting.value = false;
  }
};

const toggleWatched = async () => {
  const { status: confirmStatus } = await dialog.confirmDialog({
    message: `Mark '${historyTitle.value}' as ${data.value.watched ? 'unplayed' : 'played'}?`,
  });

  if (true !== confirmStatus) {
    return;
  }

  try {
    const response = await request(`/history/${data.value.id}/watch`, {
      method: data.value.watched ? 'DELETE' : 'POST',
    });

    const json = await parse_api_response<HistoryViewItem>(response);

    if ('error' in json) {
      notification('error', 'Error', `${json.error.code}: ${json.error.message}`);
      return;
    }

    data.value = { ...json, _toggle: data.value._toggle };

    notification(
      'success',
      '',
      `Marked '${historyTitle.value}' as ${data.value.watched ? 'played' : 'unplayed'}`,
    );
    await validateItem();
  } catch (error) {
    notification('error', 'Error', `Request error. ${String(error)}`);
  }
};

const validateItem = async () => {
  try {
    const response = await request(`/history/${id}/validate`);

    if (!response.ok) {
      return;
    }

    const json = await parse_api_response<ValidationResponse>(response);
    if ('error' in json) {
      return;
    }

    for (const [backend, item] of Object.entries(json)) {
      if (data.value.metadata[backend] === undefined) {
        continue;
      }

      data.value.metadata[backend].validated = item.status;
      data.value.metadata[backend].validated_message = item.message;
    }
  } catch {}
};

const deleteMetadata = async (backend: string) => {
  const { status: confirmStatus } = await dialog.confirmDialog({
    message: `Remove '${backend}' metadata from '${historyTitle.value}' data?`,
    confirmColor: 'error',
  });

  if (true !== confirmStatus) {
    return;
  }

  try {
    const response = await request(`/history/${id}/metadata/${backend}`, { method: 'DELETE' });
    const json = await parse_api_response<GenericResponse>(response);

    if ('error' in json) {
      notification('error', 'Error', `${json.error.code}: ${json.error.message}`);
      return;
    }

    notification('success', 'Success!', `Deleted '${backend}' metadata.`);
    await loadContent(id);
  } catch (error) {
    notification('error', 'Error', `Request error. ${String(error)}`);
  }
};

const checkDuplicates = async () => {
  try {
    const response = await request(`/history/${id}/duplicates`);

    if (!response.ok) {
      return;
    }

    const json = await parse_api_response<DuplicateResponse>(response);
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
const headerTitle = computed<string>(() => (isLoading.value ? id.toString() : historyTitle.value));

onUnmounted(() => {
  clearPageBackgroundOverride(backgroundOverrideId);
});

onMounted(async () => {
  await loadContent(id);
});
</script>
