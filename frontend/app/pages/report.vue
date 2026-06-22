<template>
  <main class="w-full min-w-0 max-w-full space-y-6">
    <PageHeader v-bind="pageShell">
      <template #actions>
        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-copy"
          :disabled="null === report || isLoading"
          @click="copyReport"
        >
          Copy
        </UButton>

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
      </template>
    </PageHeader>

    <div
      v-if="null === report && isLoading"
      class="ws-card flex min-h-72 items-center justify-center"
    >
      <div class="flex flex-col items-center gap-3 text-center text-toned">
        <UIcon name="i-lucide-loader-circle" class="size-10 animate-spin text-info" />
        <div class="space-y-1">
          <p class="text-sm font-medium text-default">Loading</p>
          <p class="text-xs">Collecting system, backend, and task diagnostics.</p>
        </div>
      </div>
    </div>

    <UAlert
      v-else-if="null === report && null !== error"
      color="error"
      variant="soft"
      icon="i-lucide-triangle-alert"
      title="Failed to load report"
      :description="error"
    />

    <template v-else-if="null !== report">
      <section class="space-y-3">
        <button
          type="button"
          class="flex w-full items-center justify-between gap-3 text-left"
          @click="toggleSection('system')"
        >
          <div class="flex items-center gap-2 text-sm font-semibold text-highlighted">
            <UIcon name="i-lucide-gauge" class="size-4 text-toned" />
            <span>System Status</span>
          </div>
          <UIcon
            name="i-lucide-chevron-right"
            :class="[
              'size-4 text-toned transition-transform',
              isSectionOpen('system') ? 'rotate-90' : '',
            ]"
          />
        </button>

        <div v-if="isSectionOpen('system')" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
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

        <div v-if="isSectionOpen('runtime')" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          <StatCard
            v-for="item in runtimeCards"
            :key="item.label"
            :label="item.label"
            :value="item.value"
            :hint="item.hint"
            :icon="item.icon"
            color="neutral"
            value-wrap
          />
        </div>
      </section>

      <section v-if="report.backends.length > 0" class="space-y-4">
        <button
          type="button"
          class="flex w-full items-center justify-between gap-3 text-left"
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

        <div v-if="isSectionOpen('backends')" class="space-y-4">
          <div v-for="group in backendsByUser" :key="group.user" class="space-y-3">
            <button
              type="button"
              class="flex w-full items-center justify-between gap-3 text-left"
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
              <UAlert
                v-if="group.backends.length === 0"
                color="warning"
                variant="soft"
                icon="i-lucide-server-off"
                title="No backends configured"
                description=""
              />

              <div v-else class="grid gap-4 lg:grid-cols-2 2xl:grid-cols-3">
                <article
                  v-for="backend in group.backends"
                  :key="`${backend.user}@${backend.name}`"
                  class="ws-card p-4 shadow-sm"
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
                          <p class="text-sm font-semibold text-default">{{ backend.name }}</p>
                          <p class="text-xs text-toned">{{ ucFirst(backend.type) }}</p>
                        </div>
                      </div>

                      <span
                        class="inline-flex items-center gap-1 rounded-md border px-2 py-1 text-xs font-medium"
                        :class="
                          null !== backend.version
                            ? 'border-success/30 bg-success/10 text-success'
                            : 'border-error/30 bg-error/10 text-error'
                        "
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
                      </span>
                    </div>

                    <div class="flex flex-wrap gap-1.5">
                      <span
                        v-for="chip in backendChips(backend)"
                        :key="chip.label"
                        class="inline-flex items-center gap-1 rounded-md border px-2 py-1 text-xs"
                        :class="chip.class"
                      >
                        <UIcon :name="chip.icon" class="size-3" />
                        {{ chip.label }}
                      </span>
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
                        class="mt-2 overflow-x-auto rounded-md border border-default bg-elevated/40 p-2 text-xs text-default"
                        >{{ JSON.stringify(backend.options, null, 2) }}</pre
                      >
                    </div>
                  </div>
                </article>
              </div>
            </div>
          </div>
        </div>
      </section>

      <section v-if="report.tasks.length > 0" class="space-y-3">
        <button
          type="button"
          class="flex w-full items-center justify-between gap-3 text-left"
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

        <div v-if="isSectionOpen('tasks')" class="grid gap-4 lg:grid-cols-2 2xl:grid-cols-3">
          <article v-for="task in report.tasks" :key="task.name" class="ws-card p-4 shadow-sm">
            <div class="space-y-3">
              <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm font-semibold text-default">{{ ucFirst(task.name) }}</p>
                <span
                  class="inline-flex items-center gap-1 rounded-md border px-2 py-1 text-xs font-medium"
                  :class="
                    task.enabled
                      ? 'border-success/30 bg-success/10 text-success'
                      : 'border-default bg-elevated/40 text-toned'
                  "
                >
                  <UIcon
                    :name="task.enabled ? 'i-lucide-check' : 'i-lucide-minus'"
                    class="size-3"
                  />
                  {{ task.enabled ? 'Enabled' : 'Disabled' }}
                </span>
              </div>

              <div v-if="task.enabled" class="space-y-1.5 text-xs text-toned">
                <div v-if="task.args" class="flex items-center gap-2">
                  <UIcon name="i-lucide-terminal" class="size-3.5 shrink-0" />
                  <code class="text-default">{{ task.args }}</code>
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

              <div
                v-if="task.error"
                class="flex items-center gap-2 rounded-md border border-error/30 bg-error/10 px-3 py-2 text-xs text-error"
              >
                <UIcon name="i-lucide-circle-alert" class="size-3.5 shrink-0" />
                <span>{{ task.error }}</span>
              </div>
            </div>
          </article>
        </div>
      </section>

      <section class="space-y-3">
        <button
          type="button"
          class="flex w-full items-center justify-between gap-3 text-left"
          @click="toggleSection('suppression')"
        >
          <div class="flex items-center gap-2 text-sm font-semibold text-highlighted">
            <UIcon name="i-lucide-filter-off" class="size-4 text-toned" />
            <span>Log Suppression</span>
          </div>
          <UIcon
            name="i-lucide-chevron-right"
            :class="[
              'size-4 text-toned transition-transform',
              isSectionOpen('suppression') ? 'rotate-90' : '',
            ]"
          />
        </button>

        <div v-if="isSectionOpen('suppression')" class="ws-card p-4 shadow-sm">
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
            <span
              v-if="report.suppression.rules && Object.keys(report.suppression.rules).length > 0"
              class="inline-flex items-center gap-1 rounded-md border border-default bg-elevated/40 px-2 py-1 text-xs text-toned"
            >
              {{ Object.keys(report.suppression.rules).length }} rule(s)
            </span>
          </div>

          <div
            v-if="report.suppression.error"
            class="mt-3 flex items-center gap-2 rounded-md border border-error/30 bg-error/10 px-3 py-2 text-xs text-error"
          >
            <UIcon name="i-lucide-circle-alert" class="size-3.5 shrink-0" />
            <span>{{ report.suppression.error }}</span>
          </div>

          <div
            v-if="report.suppression.rules && Object.keys(report.suppression.rules).length > 0"
            class="mt-3"
          >
            <button
              type="button"
              class="flex items-center gap-1.5 text-xs font-medium text-toned hover:text-default"
              @click="toggleDetail('suppression-rules')"
            >
              <UIcon
                name="i-lucide-chevron-right"
                :class="[
                  'size-3.5 transition-transform',
                  isDetailOpen('suppression-rules') ? 'rotate-90' : '',
                ]"
              />
              View rules
            </button>
            <pre
              v-if="isDetailOpen('suppression-rules')"
              class="mt-2 overflow-x-auto rounded-md border border-default bg-elevated/40 p-2 text-xs text-default"
              >{{ JSON.stringify(report.suppression.rules, null, 2) }}</pre
            >
          </div>
        </div>
      </section>

      <section class="space-y-3">
        <button
          type="button"
          class="flex w-full items-center justify-between gap-3 text-left"
          @click="toggleSection('logs')"
        >
          <div class="flex items-center gap-2 text-sm font-semibold text-highlighted">
            <UIcon name="i-lucide-scroll-text" class="size-4 text-toned" />
            <span>Recent Logs</span>
          </div>
          <UIcon
            name="i-lucide-chevron-right"
            :class="[
              'size-4 text-toned transition-transform',
              isSectionOpen('logs') ? 'rotate-90' : '',
            ]"
          />
        </button>

        <div
          v-if="isSectionOpen('logs')"
          class="ws-card ws-terminal-panel ws-terminal-panel-lg bg-elevated"
        >
          <template v-for="group in logGroups" :key="group.type">
            <div class="border-b border-default/50 px-4 py-1.5 text-xs font-medium text-toned">
              --- {{ group.type }} logs ---
            </div>
            <pre
              class="whitespace-pre-wrap wrap-break-word px-4 py-2 text-xs leading-5 text-default"
            ><code><span
  v-for="(entry, i) in group.entries"
  :key="i"
  class="block"
  :class="{ 'text-toned': entry.separator }"
>{{ entry.separator ? '.....' : `${entry.datetime} ${entry.level} [${entry.logger}] ${entry.message}` }}</span></code></pre>
          </template>

          <div v-if="totalLogEntries === 0" class="px-4 py-8 text-center text-sm text-toned">
            No log entries found.
          </div>
        </div>
      </section>
    </template>
  </main>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useHead } from '#app';
import PageHeader from '~/components/PageHeader.vue';
import StatCard from '~/components/StatCard.vue';
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
  class: string;
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
      icon: sys.database_migrated ? 'i-lucide-database' : 'i-lucide-database',
      color: sys.database_migrated ? 'success' : 'error',
    },
    {
      label: 'Config',
      value: sys.env_file_exists ? '.env found' : 'No .env',
      hint: sys.env_file_exists ? 'Environment file loaded.' : 'Using default configuration.',
      icon: 'i-lucide-file-cog',
      color: sys.env_file_exists ? 'success' : 'warning',
    },
    {
      label: 'Container',
      value: sys.in_container ? 'Yes' : 'No',
      hint: sys.in_container ? 'Running in container.' : 'Running natively.',
      icon: sys.in_container ? 'i-lucide-box' : 'i-lucide-monitor',
      color: 'neutral',
    },
  ];
});

const runtimeCards = computed<Array<StatusCard>>(() => {
  if (null === report.value) {
    return [];
  }

  const sys = report.value.system;

  return [
    {
      label: 'Version',
      value: sys.version,
      hint: 'WatchState build.',
      icon: 'i-lucide-tag',
      color: 'neutral',
    },
    {
      label: 'PHP',
      value: `${sys.sapi}/${sys.php_version}`,
      hint: 'Runtime.',
      icon: 'i-lucide-code',
      color: 'neutral',
    },
    {
      label: 'Timezone',
      value: sys.timezone,
      hint: 'Server timezone.',
      icon: 'i-lucide-globe',
      color: 'neutral',
    },
    {
      label: 'Data path',
      value: sys.data_path,
      hint: 'Config and database.',
      icon: 'i-lucide-folder',
      color: 'neutral',
    },
    {
      label: 'Temp path',
      value: sys.temp_path,
      hint: 'Logs and cache.',
      icon: 'i-lucide-folder-open',
      color: 'neutral',
    },
    {
      label: 'Generated at',
      value: report.value.generated_at,
      hint: 'Report timestamp.',
      icon: 'i-lucide-clock',
      color: 'neutral',
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

  return report.value.logs.filter((group) => group.entries.length > 0);
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

  const onClass = 'border-success/30 bg-success/10 text-success';
  const offClass = 'border-default bg-elevated/40 text-toned';

  chips.push({
    label: backend.https ? 'HTTPS' : 'HTTP',
    icon: backend.https ? 'i-lucide-lock' : 'i-lucide-unlock',
    class: backend.https ? onClass : offClass,
  });

  chips.push({
    label: backend.has_uuid ? 'UUID' : 'No UUID',
    icon: backend.has_uuid ? 'i-lucide-fingerprint' : 'i-lucide-fingerprint',
    class: backend.has_uuid ? onClass : offClass,
  });

  chips.push({
    label: backend.has_user ? 'User' : 'No User',
    icon: backend.has_user ? 'i-lucide-user-check' : 'i-lucide-user-x',
    class: backend.has_user ? onClass : offClass,
  });

  chips.push({
    label: backend.export.enabled ? 'Export' : 'No Export',
    icon: backend.export.enabled ? 'i-lucide-upload' : 'i-lucide-upload',
    class: backend.export.enabled ? onClass : offClass,
  });

  chips.push({
    label: backend.import.enabled ? 'Import' : 'No Import',
    icon: backend.import.enabled ? 'i-lucide-download' : 'i-lucide-download',
    class: backend.import.enabled ? onClass : offClass,
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
        if (t.args) lines.push(`- Args: ${t.args}`);
        if (t.timer) lines.push(`- Timer: ${t.timer}`);
        if (t.next_run) lines.push(`- Next run: ${t.next_run}`);
        if (t.error) lines.push(`- Error: ${t.error}`);
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
    title: 'Copy Report',
    confirmText: 'Copy Report',
    confirmColor: 'warning',
    message:
      'While we try to make sure no sensitive information is leaked via the report, it is possible that something might be missed. Please review the report before posting it.',
  });

  if (true !== status) {
    return;
  }

  copyText(shareText.value);
};

onMounted(() => {
  void load(true);
});
</script>
