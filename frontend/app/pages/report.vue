<template>
  <main :aria-busy="isLoading" class="w-full min-w-0 max-w-full space-y-6">
    <PageHeader v-bind="pageShell">
      <template #actions>
        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-refresh-cw"
          :loading="isLoading"
          :disabled="isLoading"
          @click="void load(true)"
        >
          Refresh
        </UButton>

        <UButton
          color="primary"
          variant="solid"
          size="sm"
          icon="i-lucide-copy"
          :disabled="null === report || isLoading"
          @click="copyReport"
        >
          Copy report
        </UButton>
      </template>
    </PageHeader>

    <UAlert
      v-if="isLoading"
      color="info"
      variant="soft"
      icon="i-lucide-loader-circle"
      title="Loading system report"
      :ui="{ icon: 'animate-spin' }"
    />

    <UAlert
      v-if="null !== error"
      color="error"
      variant="soft"
      icon="i-lucide-triangle-alert"
      title="Failed to load report"
      :description="error"
    />

    <template v-if="null !== report">
      <p class="text-xs text-toned">
        Generated <time :datetime="report.generated_at">{{ report.generated_at }}</time>
      </p>
      <section class="space-y-3">
        <button
          type="button"
          class="flex w-full items-center justify-between gap-3 text-left"
          :aria-expanded="isSectionOpen('system')"
          aria-controls="report-system"
          @click="toggleSection('system')"
        >
          <div class="flex items-center gap-2 text-sm font-semibold text-highlighted">
            <UIcon name="i-lucide-gauge" class="size-4 text-toned" />
            <span>System status</span>
          </div>
          <UIcon
            name="i-lucide-chevron-right"
            :class="[
              'size-4 text-toned transition-transform',
              isSectionOpen('system') ? 'rotate-90' : '',
            ]"
          />
        </button>

        <div
          id="report-system"
          v-if="isSectionOpen('system')"
          class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
        >
          <StatCard
            v-for="item in statusCards"
            :key="item.label"
            :label="item.label"
            :value="item.value"
            :hint="item.hint"
            :icon="item.icon"
            :color="item.color"
          />
        </div>
      </section>

      <section class="space-y-3">
        <button
          type="button"
          class="flex w-full items-center justify-between gap-3 text-left"
          :aria-expanded="isSectionOpen('runtime')"
          aria-controls="report-runtime"
          @click="toggleSection('runtime')"
        >
          <div class="flex items-center gap-2 text-sm font-semibold text-highlighted">
            <UIcon name="i-lucide-server" class="size-4 text-toned" />
            <span>Runtime</span>
          </div>
          <UIcon
            name="i-lucide-chevron-right"
            :class="[
              'size-4 text-toned transition-transform',
              isSectionOpen('runtime') ? 'rotate-90' : '',
            ]"
          />
        </button>

        <dl
          id="report-runtime"
          v-if="isSectionOpen('runtime')"
          class="ws-card grid divide-y divide-default md:grid-cols-2"
        >
          <div
            v-for="item in runtimeCards"
            :key="item.label"
            class="grid min-w-0 gap-1 px-4 py-3 sm:grid-cols-[7rem_minmax(0,1fr)]"
          >
            <dt class="text-xs text-muted">{{ item.label }}</dt>
            <dd class="min-w-0 wrap-anywhere text-sm text-default">
              <NuxtLink
                v-if="item.valueLink"
                :to="item.valueLink"
                target="_blank"
                rel="noopener noreferrer"
                class="hover:text-primary"
              >
                {{ item.value }}
              </NuxtLink>
              <span v-else>{{ item.value }}</span>
            </dd>
          </div>
        </dl>
      </section>

      <section class="space-y-4">
        <button
          type="button"
          class="flex w-full items-center justify-between gap-3 text-left"
          :aria-expanded="isSectionOpen('backends')"
          aria-controls="report-backends"
          @click="toggleSection('backends')"
        >
          <div class="flex items-center gap-2 text-sm font-semibold text-highlighted">
            <UIcon name="i-lucide-database" class="size-4 text-toned" />
            <span>Backends ({{ report.backends.length }})</span>
          </div>
          <UIcon
            name="i-lucide-chevron-right"
            :class="[
              'size-4 text-toned transition-transform',
              isSectionOpen('backends') ? 'rotate-90' : '',
            ]"
          />
        </button>

        <div id="report-backends" v-if="isSectionOpen('backends')" class="space-y-4">
          <UEmpty
            v-if="0 === backendsByUser.length"
            size="sm"
            variant="naked"
            icon="i-lucide-server-off"
            title="No backends configured"
          />
          <div v-for="group in backendsByUser" :key="group.user" class="space-y-3">
            <button
              type="button"
              class="flex w-full items-center justify-between gap-3 text-left"
              :aria-expanded="isUserOpen(group.user)"
              @click="toggleUser(group.user)"
            >
              <div class="flex items-center gap-2">
                <UIcon name="i-lucide-user" class="size-4 text-toned" />
                <span class="text-sm font-medium text-highlighted">{{ group.user }}</span>
                <UBadge color="neutral" variant="outline" size="sm">
                  {{ group.backends.length }}
                </UBadge>
              </div>
              <UIcon
                name="i-lucide-chevron-right"
                :class="[
                  'size-4 text-toned transition-transform',
                  isUserOpen(group.user) ? 'rotate-90' : '',
                ]"
              />
            </button>

            <div v-if="isUserOpen(group.user)" class="space-y-3">
              <UEmpty
                v-if="0 === group.backends.length"
                size="sm"
                variant="naked"
                icon="i-lucide-server-off"
                title="No backends configured"
              />

              <div v-else class="ws-list-card">
                <article
                  v-for="backend in group.backends"
                  :key="`${backend.user}@${backend.name}`"
                  class="min-w-0 p-4"
                >
                  <div class="space-y-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                      <div class="flex items-center gap-2">
                        <span
                          class="flex size-8 items-center justify-center rounded-md bg-elevated ring-1 ring-inset ring-default"
                        >
                          <UIcon name="i-lucide-server" class="size-4 text-toned" />
                        </span>
                        <div class="min-w-0">
                          <p class="wrap-anywhere text-sm font-semibold text-default">
                            {{ backend.name }}
                          </p>
                          <p class="text-xs text-toned">{{ ucFirst(backend.type) }}</p>
                        </div>
                      </div>

                      <UBadge
                        :color="null !== backend.version ? 'success' : 'error'"
                        variant="soft"
                        size="sm"
                      >
                        <UIcon
                          :name="
                            null !== backend.version
                              ? 'i-lucide-badge-check'
                              : 'i-lucide-circle-alert'
                          "
                          class="size-3"
                        />
                        {{ null !== backend.version ? backend.version : 'Unreachable' }}
                      </UBadge>
                    </div>

                    <div class="flex flex-wrap gap-1.5">
                      <UBadge
                        v-for="chip in backendChips(backend)"
                        :key="chip.label"
                        color="neutral"
                        variant="outline"
                        size="sm"
                      >
                        <UIcon :name="chip.icon" class="size-3" />
                        {{ chip.label }}
                      </UBadge>
                    </div>

                    <div
                      v-if="hasSyncDates(backend)"
                      class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-toned"
                    >
                      <span v-if="backend.export.enabled">
                        Export:
                        {{
                          backend.export.last_sync ? formatDate(backend.export.last_sync) : 'Never'
                        }}
                      </span>
                      <span v-if="backend.import.metadata_refresh">
                        Import:
                        {{
                          backend.import.last_sync ? formatDate(backend.import.last_sync) : 'Never'
                        }}
                      </span>
                    </div>

                    <div v-if="Object.keys(backend.options).length > 0">
                      <button
                        type="button"
                        class="flex items-center gap-1.5 text-xs font-medium text-toned hover:text-default"
                        :aria-expanded="isDetailOpen(`opts-${backend.user}@${backend.name}`)"
                        @click="toggleDetail(`opts-${backend.user}@${backend.name}`)"
                      >
                        <UIcon
                          name="i-lucide-chevron-right"
                          :class="[
                            'size-3.5 transition-transform',
                            isDetailOpen(`opts-${backend.user}@${backend.name}`) ? 'rotate-90' : '',
                          ]"
                        />
                        Custom options ({{ Object.keys(backend.options).length }})
                      </button>
                      <pre
                        v-if="isDetailOpen(`opts-${backend.user}@${backend.name}`)"
                        class="ws-inset mt-2 overflow-x-auto p-2 text-xs text-default"
                        >{{ JSON.stringify(backend.options, null, 2) }}</pre>
                    </div>
                  </div>
                </article>
              </div>
            </div>
          </div>
        </div>
      </section>

      <section class="space-y-3">
        <button
          type="button"
          class="flex w-full items-center justify-between gap-3 text-left"
          :aria-expanded="isSectionOpen('tasks')"
          aria-controls="report-tasks"
          @click="toggleSection('tasks')"
        >
          <div class="flex items-center gap-2 text-sm font-semibold text-highlighted">
            <UIcon name="i-lucide-calendar-clock" class="size-4 text-toned" />
            <span>Tasks ({{ report.tasks.length }})</span>
          </div>
          <UIcon
            name="i-lucide-chevron-right"
            :class="[
              'size-4 text-toned transition-transform',
              isSectionOpen('tasks') ? 'rotate-90' : '',
            ]"
          />
        </button>

        <div id="report-tasks" v-if="isSectionOpen('tasks')" class="ws-list-card">
          <UEmpty
            v-if="0 === report.tasks.length"
            size="sm"
            variant="naked"
            icon="i-lucide-list-checks"
            title="No scheduled tasks"
          />
          <article v-for="task in report.tasks" :key="task.name" class="p-4">
            <div class="space-y-3">
              <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm font-semibold text-default">
                  {{ task.name.split('_').map(ucFirst).join(' ') }}
                </p>
                <UBadge :color="task.enabled ? 'success' : 'neutral'" variant="soft" size="sm">
                  <UIcon
                    :name="task.enabled ? 'i-lucide-check' : 'i-lucide-minus'"
                    class="size-3"
                  />
                  {{ task.enabled ? 'Enabled' : 'Disabled' }}
                </UBadge>
              </div>

              <div v-if="task.enabled" class="flex flex-wrap gap-x-6 gap-y-2 text-xs text-toned">
                <div v-if="task.args" class="flex items-center gap-2">
                  <UIcon name="i-lucide-terminal" class="size-3.5 shrink-0" />
                  <code class="wrap-anywhere text-default">{{ task.args }}</code>
                </div>
                <div v-if="task.timer" class="flex items-center gap-2">
                  <UIcon name="i-lucide-clock" class="size-3.5 shrink-0" />
                  <code class="text-default">{{ task.timer }}</code>
                </div>
                <div v-if="task.next_run" class="flex items-center gap-2">
                  <UIcon name="i-lucide-arrow-right" class="size-3.5 shrink-0" />
                  <span>{{ task.next_run }}</span>
                </div>
              </div>

              <UAlert
                v-if="task.error"
                color="error"
                variant="soft"
                icon="i-lucide-circle-alert"
                :description="task.error"
              />
            </div>
          </article>
        </div>
      </section>

      <section class="space-y-3">
        <button
          type="button"
          class="flex w-full items-center justify-between gap-3 text-left"
          :aria-expanded="isSectionOpen('suppression')"
          aria-controls="report-suppression"
          @click="toggleSection('suppression')"
        >
          <div class="flex items-center gap-2 text-sm font-semibold text-highlighted">
            <UIcon name="i-lucide-filter-off" class="size-4 text-toned" />
            <span>Log suppression</span>
          </div>
          <UIcon
            name="i-lucide-chevron-right"
            :class="[
              'size-4 text-toned transition-transform',
              isSectionOpen('suppression') ? 'rotate-90' : '',
            ]"
          />
        </button>

        <div
          id="report-suppression"
          v-if="isSectionOpen('suppression')"
          class="ws-card space-y-3 p-4"
        >
          <div class="flex items-center justify-between gap-4">
            <div class="flex items-center gap-2">
              <span
                class="inline-flex size-2.5 rounded-full"
                :class="report.suppression.file_exists ? 'bg-success' : 'bg-muted'"
              />
              <p class="text-sm text-default">
                suppress.yaml
                {{ report.suppression.file_exists ? 'exists' : 'does not exist' }}
              </p>
            </div>
            <UBadge
              v-if="report.suppression.rules && Object.keys(report.suppression.rules).length > 0"
              color="neutral"
              variant="outline"
              size="sm"
            >
              {{ Object.keys(report.suppression.rules).length }}
              {{ 1 === Object.keys(report.suppression.rules).length ? 'rule' : 'rules' }}
            </UBadge>
          </div>

          <UAlert
            v-if="report.suppression.error"
            color="error"
            variant="soft"
            icon="i-lucide-circle-alert"
            :description="report.suppression.error"
          />

          <div
            v-if="report.suppression.rules && Object.keys(report.suppression.rules).length > 0"
            class="space-y-2"
          >
            <button
              type="button"
              class="flex items-center gap-1.5 text-xs font-medium text-toned hover:text-default"
              :aria-expanded="isDetailOpen('suppression-rules')"
              @click="toggleDetail('suppression-rules')"
            >
              <UIcon
                name="i-lucide-chevron-right"
                :class="[
                  'size-3.5 transition-transform',
                  isDetailOpen('suppression-rules') ? 'rotate-90' : '',
                ]"
              />
              Rules
            </button>
            <pre
              v-if="isDetailOpen('suppression-rules')"
              class="ws-inset mt-2 overflow-x-auto p-2 text-xs text-default"
              >{{ JSON.stringify(report.suppression.rules, null, 2) }}</pre>
          </div>
        </div>
      </section>

      <section class="space-y-3">
        <div class="flex w-full items-center justify-between gap-3 text-left">
          <div class="flex items-center gap-2 text-sm font-semibold text-highlighted">
            <UIcon name="i-lucide-scroll-text" class="size-4 text-toned" />
            <span>Recent logs</span>
          </div>
          <div class="flex items-center gap-2">
            <UButton
              color="neutral"
              variant="outline"
              size="xs"
              icon="i-lucide-copy"
              :disabled="0 === totalLogEntries"
              @click="copyRecentLogs"
            >
              Copy
            </UButton>
            <UTooltip :text="isSectionOpen('logs') ? 'Collapse recent logs' : 'Expand recent logs'">
              <UButton
                color="neutral"
                variant="ghost"
                size="xs"
                :aria-label="isSectionOpen('logs') ? 'Collapse recent logs' : 'Expand recent logs'"
                :aria-expanded="isSectionOpen('logs')"
                aria-controls="report-logs"
                @click="toggleSection('logs')"
              >
                <UIcon
                  name="i-lucide-chevron-right"
                  :class="[
                    'size-4 text-toned transition-transform',
                    isSectionOpen('logs') ? 'rotate-90' : '',
                  ]"
                />
              </UButton>
            </UTooltip>
          </div>
        </div>

        <div
          id="report-logs"
          v-if="isSectionOpen('logs') && 0 < totalLogEntries"
          class="grid min-w-0 gap-4 xl:grid-cols-2"
        >
          <section
            v-for="group in logGroups"
            :key="group.type"
            class="ws-card min-w-0 overflow-hidden"
          >
            <h3 class="border-b border-default px-4 py-3 text-sm font-semibold text-highlighted">
              {{ ucFirst(group.type) }} logs
            </h3>
            <div
              class="max-h-96 overflow-x-hidden overflow-y-auto"
              tabindex="0"
              :aria-label="`${ucFirst(group.type)} log entries`"
            >
              <template v-for="(entry, i) in group.entries" :key="i">
                <div
                  v-if="entry.separator"
                  class="border-b border-default/50 px-4 py-2 text-xs text-dimmed"
                >
                  .....
                </div>
                <article v-else-if="entry.log" class="ws-log-row">
                  <div class="ws-log-line">
                    <StructuredLogLine
                      :log="entry.log"
                      :expanded="true === expandedLogRows[`${group.type}:${i}`]"
                      toggleable
                      @toggle-expand="
                        expandedLogRows[`${group.type}:${i}`] =
                          !expandedLogRows[`${group.type}:${i}`]
                      "
                    />
                  </div>
                </article>
              </template>
            </div>
          </section>
        </div>
        <UEmpty
          v-else-if="isSectionOpen('logs')"
          size="sm"
          variant="naked"
          icon="i-lucide-scroll-text"
          title="No log entries found"
        />
      </section>
    </template>
  </main>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useHead } from '#app';
import { NuxtLink, UAlert, UBadge, UButton, UEmpty, UIcon, UTooltip } from '#components';
import PageHeader from '~/components/PageHeader.vue';
import StatCard from '~/components/StatCard.vue';
import StructuredLogLine from '~/components/StructuredLogLine.vue';
import { useDialog } from '~/composables/useDialog';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import type { SystemReport, SystemReportBackend } from '~/types';
import { copyText, parse_api_response, request, ucFirst } from '~/utils';

useHead({ title: 'System Report' });

const pageShell = requireTopLevelPageShell('report');
const dialog = useDialog();

const report = ref<SystemReport | null>(null);
const isLoading = ref(false);
const error = ref<string | null>(null);

const collapsedUsers = ref<Record<string, boolean>>({});
const collapsedSections = ref<Record<string, boolean>>({});
const expandedDetails = ref<Record<string, boolean>>({});
const expandedLogRows = ref<Record<string, boolean>>({});

const isSectionOpen = (id: string): boolean => !collapsedSections.value[id];
const toggleSection = (id: string): void => {
  collapsedSections.value[id] = !collapsedSections.value[id];
};

const isUserOpen = (user: string): boolean => !collapsedUsers.value[user];
const toggleUser = (user: string): void => {
  collapsedUsers.value[user] = !collapsedUsers.value[user];
};

const isDetailOpen = (id: string): boolean => true === expandedDetails.value[id];
const toggleDetail = (id: string): void => {
  expandedDetails.value[id] = !expandedDetails.value[id];
};

const load = async (force: boolean = false): Promise<void> => {
  if (true === isLoading.value) {
    return;
  }

  if (null !== report.value && !force) {
    return;
  }

  isLoading.value = true;
  error.value = null;

  try {
    const response = await request('/system/report');
    const json = await parse_api_response<SystemReport>(response);

    if ('error' in json) {
      error.value = json.error.message;
      return;
    }

    report.value = json;
    expandedLogRows.value = {};
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : 'Failed to load report.';
  } finally {
    isLoading.value = false;
  }
};

type StatusCard = {
  label: string;
  value: string;
  hint: string;
  icon: string;
  color: 'success' | 'error' | 'warning' | 'neutral';
};

type ChipInfo = {
  label: string;
  icon: string;
};

const statusCards = computed<Array<StatusCard>>(() => {
  if (null === report.value) {
    return [];
  }

  const sys = report.value.system;

  return [
    {
      label: 'Scheduler',
      value: sys.scheduler_running ? 'Running' : 'Stopped',
      hint: sys.scheduler_message,
      icon: sys.scheduler_running ? 'i-lucide-circle-check' : 'i-lucide-circle-x',
      color: sys.scheduler_running ? 'success' : 'error',
    },
    {
      label: 'Database',
      value: sys.database_migrated ? 'Migrated' : 'Pending',
      hint: sys.database_migrated ? 'Schema up to date.' : 'Migrations pending.',
      icon: 'i-lucide-database',
      color: sys.database_migrated ? 'success' : 'error',
    },
    {
      label: 'Config',
      value: sys.env_file_exists ? '.env found' : 'No .env',
      hint: '',
      icon: 'i-lucide-file-cog',
      color: sys.env_file_exists ? 'success' : 'warning',
    },
    {
      label: 'Container',
      value: sys.in_container ? 'Container' : 'Native',
      hint: '',
      icon: sys.in_container ? 'i-lucide-box' : 'i-lucide-monitor',
      color: 'neutral',
    },
  ];
});

const runtimeCards = computed(() => {
  if (null === report.value) {
    return [];
  }

  const sys = report.value.system;

  return [
    {
      label: 'Version',
      value: sys.version,
    },
    {
      label: 'Commit',
      value: sys.sha,
      valueLink: `https://github.com/arabcoders/watchstate/commit/${sys.sha}`,
    },
    {
      label: 'Build date',
      value: sys.build,
    },
    {
      label: 'Branch',
      value: sys.branch,
    },
    {
      label: 'PHP',
      value: `${sys.sapi}/${sys.php_version}`,
    },
    {
      label: 'Timezone',
      value: sys.timezone,
    },
    {
      label: 'Data path',
      value: sys.data_path,
    },
    {
      label: 'Temp path',
      value: sys.temp_path,
    },
  ];
});

const backendsByUser = computed(() => {
  if (null === report.value) {
    return [];
  }

  return report.value.users.map((user) => ({
    user,
    backends: report.value!.backends.filter((b) => b.user === user),
  }));
});

const logGroups = computed(() => {
  if (null === report.value) {
    return [];
  }

  return report.value.logs
    .filter((group) => 0 < group.entries.length)
    .map((group) => ({
      ...group,
      entries: group.entries.toReversed().map((entry, index) => ({
        ...entry,
        log: entry.separator ? null : { ...entry, id: `${group.type}:${index}` },
      })),
    }));
});

const totalLogEntries = computed(() => {
  if (null === report.value) {
    return 0;
  }

  return report.value.logs.reduce((total, group) => total + group.entries.length, 0);
});

const hasSyncDates = (backend: SystemReportBackend): boolean => {
  return (
    (true === backend.export.enabled &&
      (null !== backend.export.last_sync || null !== backend.export.playlist_last_sync)) ||
    (true === backend.import.metadata_refresh &&
      (null !== backend.import.last_sync || null !== backend.import.playlist_last_sync))
  );
};

const formatDate = (timestamp: number): string => {
  return new Date(timestamp * 1000).toISOString();
};

const backendChips = (backend: SystemReportBackend): Array<ChipInfo> => {
  const chips: Array<ChipInfo> = [];

  chips.push({
    label: backend.https ? 'HTTPS' : 'HTTP',
    icon: backend.https ? 'i-lucide-lock' : 'i-lucide-unlock',
  });

  chips.push({
    label: backend.has_uuid ? 'UUID' : 'No UUID',
    icon: 'i-lucide-fingerprint',
  });

  chips.push({
    label: backend.has_user ? 'User' : 'No User',
    icon: backend.has_user ? 'i-lucide-user-check' : 'i-lucide-user-x',
  });

  chips.push({
    label: backend.export.enabled ? 'Export' : 'No Export',
    icon: 'i-lucide-upload',
  });

  chips.push({
    label: backend.import.enabled ? 'Import' : 'No Import',
    icon: 'i-lucide-download',
  });

  return chips;
};

const shareText = computed(() => {
  if (null === report.value) {
    return '';
  }

  const r = report.value;
  const lines: Array<string> = [
    'WatchState System Report',
    `Generated: ${r.generated_at}`,
    '',
    'System Status',
    `- Version: ${r.system.version}`,
    `- Branch: ${r.system.branch}`,
    `- Build: ${r.system.build}`,
    `- SHA: ${r.system.sha}`,
    `- PHP: ${r.system.sapi}/${r.system.php_version}`,
    `- Timezone: ${r.system.timezone}`,
    `- Data path: ${r.system.data_path}`,
    `- Temp path: ${r.system.temp_path}`,
    `- Database migrated: ${r.system.database_migrated ? 'Yes' : 'No'}`,
    `- .env exists: ${r.system.env_file_exists ? 'Yes' : 'No'}`,
    `- Scheduler: ${r.system.scheduler_running ? 'Yes' : 'No'} - ${r.system.scheduler_message}`,
    `- Container: ${r.system.in_container ? 'Yes' : 'No'}`,
  ];

  if (r.backends.length > 0) {
    lines.push('', 'Backends');
    for (const b of r.backends) {
      lines.push(`[ ${ucFirst(b.type)} (${b.version ?? 'Unknown'}) ==> ${b.user}@${b.name} ]`);
      lines.push(`- HTTPS: ${b.https ? 'Yes' : 'No'}`);
      lines.push(`- UUID: ${b.has_uuid ? 'Yes' : 'No'}`);
      lines.push(`- User: ${b.has_user ? 'Yes' : 'No'}`);
      lines.push(
        `- Export: ${b.export.enabled ? 'Enabled' : 'Disabled'}${
          b.export.enabled && b.export.last_sync ? ` (last: ${formatDate(b.export.last_sync)})` : ''
        }`,
      );
      lines.push(
        `- Import: ${b.import.enabled ? 'Enabled' : 'Disabled'}${
          b.import.metadata_refresh && b.import.last_sync
            ? ` (last: ${formatDate(b.import.last_sync)})`
            : ''
        }`,
      );
      const opts = Object.keys(b.options);
      if (opts.length > 0) {
        lines.push(`- Options: ${JSON.stringify(b.options)}`);
      }
    }
  }

  if (r.tasks.length > 0) {
    lines.push('', 'Tasks');
    for (const t of r.tasks) {
      lines.push(`[ ${ucFirst(t.name)} ]`);
      lines.push(`- Enabled: ${t.enabled ? 'Yes' : 'No'}`);
      if (t.enabled) {
        if (t.args) {
          lines.push(`- Args: ${t.args}`);
        }
        if (t.timer) {
          lines.push(`- Timer: ${t.timer}`);
        }
        if (t.next_run) {
          lines.push(`- Next run: ${t.next_run}`);
        }
        if (t.error) {
          lines.push(`- Error: ${t.error}`);
        }
      }
    }
  }

  lines.push('', 'Log Suppression');
  lines.push(`- File exists: ${r.suppression.file_exists ? 'Yes' : 'No'}`);
  if (r.suppression.error) {
    lines.push(`- Error: ${r.suppression.error}`);
  } else if (r.suppression.rules) {
    lines.push(`- Rules: ${JSON.stringify(r.suppression.rules)}`);
  }

  if (totalLogEntries.value > 0) {
    lines.push('', 'Logs');
    for (const group of logGroups.value) {
      lines.push(`---  ${group.type} logs ---`);
      for (const entry of group.entries) {
        lines.push(
          entry.separator
            ? '.....'
            : `${entry.datetime} ${entry.level} [${entry.logger}] ${entry.message}`,
        );
      }
    }
  }

  return lines.join('\n');
});

const copyReport = async (): Promise<void> => {
  if (!shareText.value) {
    return;
  }

  const { status } = await dialog.confirmDialog({
    title: 'Copy report?',
    confirmText: 'Copy report',
    confirmColor: 'primary',
    message: 'The report can include sensitive details. Review it before sharing.',
  });

  if (true !== status) {
    return;
  }

  copyText(shareText.value);
};

const copyRecentLogs = (): void => {
  const lines: Array<string> = [];

  for (const group of logGroups.value) {
    lines.push(`---  ${group.type} logs ---`);
    for (const entry of group.entries) {
      lines.push(
        entry.separator
          ? '.....'
          : `${entry.datetime} ${entry.level} [${entry.logger}] ${entry.message}`,
      );
    }
  }

  copyText(lines.join('\n'));
};

onMounted(() => {
  void load(true);
});
</script>
