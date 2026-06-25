<template>
  <main class="w-full min-w-0 max-w-full space-y-6">
    <PageHeader v-bind="pageShell">
      <template #actions>
        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-eraser"
          aria-label="Clear terminal output"
          @click="clearOutput"
        >
          Clear output
        </UButton>
      </template>
    </PageHeader>

    <div class="ws-card overflow-hidden bg-elevated shadow-sm">
      <div ref="outputConsole" class="min-h-[55vh] max-h-[55vh] overflow-hidden" />
    </div>

    <div class="ws-card bg-elevated/20 shadow-sm">
      <div
        class="flex flex-col gap-3 border-b border-default bg-muted/10 px-4 py-3 lg:flex-row lg:items-start lg:justify-between"
      >
        <div class="min-w-0 flex-1 space-y-1">
          <div class="flex items-center justify-between gap-3">
            <div class="flex min-w-0 items-center gap-2 text-sm font-semibold text-highlighted">
              <UIcon name="i-lucide-send" class="size-4 shrink-0 text-toned" />
              <span>Command</span>
            </div>

            <UButton
              color="neutral"
              variant="outline"
              size="sm"
              icon="i-lucide-circle-help"
              class="shrink-0"
              :disabled="isLoading"
              @click="showHelp"
            >
              Help
            </UButton>
          </div>

          <div class="flex flex-wrap items-center gap-2 text-xs text-toned">
            <p>
              <template v-if="allEnabled">
                Shell commands are available when prefixed with <code>$</code>.
              </template>
              <template v-else>
                Shell commands stay disabled unless <code>WS_CONSOLE_ENABLE_ALL</code> is enabled.
              </template>
            </p>
            <UBadge :color="streamStatusColor" variant="soft" size="sm">
              <span v-if="streamStatusSpinning" class="inline-flex items-center gap-1.5">
                <UIcon :name="streamStatusIcon" class="size-3.5 animate-spin" />
                <span>{{ streamStatusLabel }}</span>
              </span>
              <span v-else class="inline-flex items-center gap-1.5">
                <UIcon :name="streamStatusIcon" class="size-3.5" />
                <span>{{ streamStatusLabel }}</span>
              </span>
            </UBadge>
          </div>
        </div>
      </div>

      <div class="space-y-3 px-4 py-4">
        <UAlert
          v-if="streamState.error"
          color="error"
          variant="soft"
          icon="i-lucide-triangle-alert"
          title="Command stream failed"
          :description="streamState.error"
        />

        <UAlert
          v-if="hasPrefix"
          color="warning"
          variant="soft"
          icon="i-lucide-triangle-alert"
          title="Remove the prefix"
          description="Use the command directly, for example `db:list --output yaml`."
        />

        <UAlert
          v-if="hasPlaceholder"
          color="warning"
          variant="soft"
          icon="i-lucide-triangle-alert"
          title="Placeholder values found"
        >
          <template #description>
            <p class="text-sm text-default">
              Replace <code>[...]</code> with the intended value if applicable before running the
              command.
            </p>
          </template>
        </UAlert>

        <div class="grid gap-3 xl:grid-cols-[minmax(0,1fr)_auto] xl:items-end">
          <UInput
            ref="commandInput"
            v-model="command"
            type="text"
            size="lg"
            aria-label="Command"
            :placeholder="`system:tasks ${allEnabled ? 'or $ ls' : ''}`"
            autocomplete="off"
            :disabled="isLoading"
            :icon="isLoading ? 'i-lucide-loader-circle' : 'i-lucide-terminal'"
            :ui="isLoading ? { leadingIcon: 'animate-spin' } : undefined"
            class="ws-console-input w-full"
            @keydown.enter="RunCommand"
          />

          <div class="flex flex-wrap items-center justify-end gap-2 xl:self-end">
            <UPopover :content="{ side: 'top', align: 'end', sideOffset: 8 }">
              <UButton
                color="neutral"
                variant="outline"
                size="lg"
                icon="i-lucide-history"
                trailing-icon="i-lucide-chevron-up"
                class="flex-1 justify-center sm:flex-none sm:min-w-36"
              >
                History
              </UButton>

              <template #content>
                <UCard class="w-[min(92vw,42rem)] shadow-sm" :ui="historyCardUi">
                  <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-2 text-sm font-semibold text-highlighted">
                      <UIcon name="i-lucide-history" class="size-4 text-toned" />
                      <span>Recent runs</span>
                    </div>

                    <div class="flex flex-wrap items-center justify-end gap-2">
                      <UButton
                        color="neutral"
                        variant="outline"
                        size="sm"
                        icon="i-lucide-eye-off"
                        :disabled="recentRunEntries.length < 1"
                        @click="hideRecentRuns"
                      >
                        Hide recent runs
                      </UButton>
                    </div>
                  </div>

                  <UAlert
                    v-if="recentRunEntries.length < 1"
                    color="info"
                    variant="soft"
                    icon="i-lucide-clock-3"
                    title="No recent console sessions"
                  />

                  <div v-else class="space-y-4">
                    <div
                      v-if="recentRunEntries.length > 0"
                      class="max-h-80 overflow-auto rounded-lg border border-default bg-elevated/30"
                    >
                      <table class="w-full text-sm">
                        <tbody class="divide-y divide-default">
                          <tr
                            v-for="item in recentRunEntries"
                            :key="item.token"
                            class="hover:bg-muted/20"
                          >
                            <td class="px-3 py-3 align-middle">
                              <div class="space-y-2">
                                <button
                                  type="button"
                                  class="block w-full text-left font-mono text-xs text-default hover:text-highlighted"
                                  @click="replayHistoryItem(item)"
                                >
                                  {{ item.displayCommand }}
                                </button>

                                <div
                                  class="flex flex-wrap items-center gap-2 text-[11px] text-toned"
                                >
                                  <UBadge
                                    :color="recentRunStatusColor(item)"
                                    variant="soft"
                                    size="sm"
                                  >
                                    <span
                                      v-if="recentRunStatusSpinning(item)"
                                      class="inline-flex items-center gap-1.5"
                                    >
                                      <UIcon
                                        :name="recentRunStatusIcon(item)"
                                        class="size-3.5 animate-spin"
                                      />
                                      <span>{{ recentRunStatusLabel(item) }}</span>
                                    </span>
                                    <span v-else class="inline-flex items-center gap-1.5">
                                      <UIcon :name="recentRunStatusIcon(item)" class="size-3.5" />
                                      <span>{{ recentRunStatusLabel(item) }}</span>
                                    </span>
                                  </UBadge>
                                  <UTooltip
                                    v-if="item.finishedAt"
                                    :text="`Completed at: ${moment(item.finishedAt).format(TOOLTIP_DATE_FORMAT)}`"
                                  >
                                    <span class="cursor-help">{{
                                      moment(item.finishedAt).fromNow()
                                    }}</span>
                                  </UTooltip>
                                </div>
                              </div>
                            </td>

                            <td class="w-24 px-3 py-3 text-center align-middle whitespace-nowrap">
                              <div class="flex items-center justify-end gap-1">
                                <UButton
                                  color="neutral"
                                  variant="ghost"
                                  size="xs"
                                  icon="i-lucide-terminal"
                                  @click="loadCommand(item.command)"
                                >
                                  Fill
                                </UButton>
                                <UButton
                                  color="neutral"
                                  variant="ghost"
                                  size="xs"
                                  icon="i-lucide-x"
                                  square
                                  @click="removeRecentRun(item.token)"
                                />
                              </div>
                            </td>
                          </tr>
                        </tbody>
                      </table>
                    </div>
                  </div>
                </UCard>
              </template>
            </UPopover>

            <UButton
              v-if="isLoading"
              color="neutral"
              variant="outline"
              size="lg"
              icon="i-lucide-power"
              class="flex-1 justify-center sm:flex-none sm:min-w-36"
              @click="closeOutput"
            >
              Close output
            </UButton>

            <UButton
              v-else
              color="primary"
              variant="solid"
              size="lg"
              icon="i-lucide-send"
              :disabled="hasPrefix || !hasRunnableCommand"
              class="flex-1 justify-center sm:flex-none sm:min-w-36"
              @click="RunCommand"
            >
              Run command
            </UButton>
          </div>
        </div>
      </div>
    </div>
  </main>
</template>

<style scoped>
.ws-console-input :deep(input) {
  font-family: 'JetBrains Mono', monospace;
}

.xterm {
  padding: 0.5rem !important;
}
</style>

<script setup lang="ts">
import '@xterm/xterm/css/xterm.css';
import moment from 'moment';
import { ref, computed, onMounted, onUnmounted, nextTick, watch } from 'vue';
import { useHead, useRoute, useRouter } from '#app';
import { useColorMode } from '#imports';
import { Terminal } from '@xterm/xterm';
import type { ITheme } from '@xterm/xterm';
import { FitAddon } from '@xterm/addon-fit';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import PageHeader from '~/components/PageHeader.vue';
import {
  request,
  formatCommandEcho,
  disableOpacity,
  enableOpacity,
  notification,
  parse_api_response,
  TOOLTIP_DATE_FORMAT,
} from '~/utils';
import type { EnvVar } from '~/types';
import { useDialog } from '~/composables/useDialog';
import { useConsoleStream } from '~/composables/useConsoleStream';

useHead({ title: 'Console' });

const pageShell = requireTopLevelPageShell('console');

const route = useRoute();
const fromCommand: string =
  route.query.cmd && 'string' === typeof route.query.cmd ? atob(route.query.cmd) : '';

type ConsoleInputRef = {
  inputRef?: HTMLInputElement | null;
};

type RecentRunStatus = 'queued' | 'running' | 'completed';
type RecentRunState = {
  status: RecentRunStatus;
  exitCode: number | null;
};

let flushFrame: number | null = null;
let fitFrame: number | null = null;
let terminalResizeObserver: ResizeObserver | null = null;
let didInitialRender = false;
let renderedChunkCount = 0;

const colorMode = useColorMode();

const xtermDarkTheme: ITheme = {
  background: '#1f2229',
  foreground: '#e3c981',
  cursor: '#e3c981',
  cursorAccent: '#1f2229',
  selectionBackground: 'rgba(255, 255, 255, 0.18)',
  black: '#1f2229',
  red: '#ff6b6b',
  green: '#9ece6a',
  yellow: '#e0af68',
  blue: '#7aa2f7',
  magenta: '#bb9af7',
  cyan: '#7dcfff',
  white: '#c0caf5',
  brightBlack: '#414868',
  brightRed: '#ff7e93',
  brightGreen: '#9ece6a',
  brightYellow: '#e0af68',
  brightBlue: '#7aa2f7',
  brightMagenta: '#bb9af7',
  brightCyan: '#7dcfff',
  brightWhite: '#e3c981',
};

const xtermLightTheme: ITheme = {
  background: '#ffffff',
  foreground: '#3b3a1d',
  cursor: '#3b3a1d',
  cursorAccent: '#ffffff',
  selectionBackground: 'rgba(0, 0, 0, 0.12)',
  black: '#3b3a1d',
  red: '#c43c4a',
  green: '#4a7a3c',
  yellow: '#9a7b1f',
  blue: '#3a5fb3',
  magenta: '#8a4fb3',
  cyan: '#2f7a7a',
  white: '#6b6b6b',
  brightBlack: '#6b6b6b',
  brightRed: '#c43c4a',
  brightGreen: '#4a7a3c',
  brightYellow: '#9a7b1f',
  brightBlue: '#3a5fb3',
  brightMagenta: '#8a4fb3',
  brightCyan: '#2f7a7a',
  brightWhite: '#1f1f1f',
};

const xtermTheme = computed<ITheme>(() =>
  'dark' === colorMode.value ? xtermDarkTheme : xtermLightTheme,
);

const terminal = ref<Terminal | null>(null);
const terminalFit = ref<FitAddon | null>(null);
const command = ref<string>(fromCommand);
const outputConsole = ref<HTMLElement | null>(null);
const commandInput = ref<ConsoleInputRef | null>(null);
const {
  recentRuns,
  state: streamState,
  bufferedChunks,
  appendOutput,
  clearRecentRuns,
  clearOutput: clearStreamOutput,
  fetchRecentRuns,
  replayRun,
  removeRecentRun,
  restoreRun,
  startRun,
  stopCommand,
  stopStream,
} = useConsoleStream();

watch(
  () => colorMode.value,
  (mode) => {
    if (terminal.value) {
      terminal.value.options.theme = 'dark' === mode ? xtermDarkTheme : xtermLightTheme;
    }
  },
);

const isLoading = computed(() =>
  ['starting', 'streaming', 'reconnecting'].includes(streamState.value.status),
);

const streamStatusLabel = computed(() => {
  switch (streamState.value.status) {
    case 'starting':
      return 'Starting';
    case 'streaming':
      return 'Streaming';
    case 'reconnecting':
      return 'Reconnecting';
    case 'error':
      return 'Failed';
    default:
      return 'Idle';
  }
});

const streamStatusColor = computed(() => {
  switch (streamState.value.status) {
    case 'starting':
    case 'streaming':
    case 'reconnecting':
      return 'info';
    case 'error':
      return 'error';
    default:
      return 'neutral';
  }
});

const streamStatusIcon = computed(() => {
  switch (streamState.value.status) {
    case 'starting':
    case 'streaming':
    case 'reconnecting':
      return 'i-lucide-loader-circle';
    case 'error':
      return 'i-lucide-triangle-alert';
    default:
      return 'i-lucide-circle-dot';
  }
});

const streamStatusSpinning = computed(() => {
  return ['starting', 'streaming', 'reconnecting'].includes(streamState.value.status);
});

const recentRunEntries = computed(() => recentRuns.value.slice().reverse());

const hasPrefix = computed(
  () => command.value.startsWith('console') || command.value.startsWith('docker'),
);
const hasPlaceholder = computed(() => command.value && command.value.match(/\[.*]/));
const hasRunnableCommand = computed(() => Boolean(command.value.trim()));
const allEnabled = ref<boolean>(false);

const historyCardUi = {
  body: 'space-y-3 p-4',
};

const focusCommandInput = (): void => {
  commandInput.value?.inputRef?.focus({ preventScroll: true });
};

const isRecentRunFailed = (item: RecentRunState): boolean => {
  return 'completed' === item.status && null !== item.exitCode && 0 !== item.exitCode;
};

const recentRunStatusLabel = (item: RecentRunState): string => {
  if (isRecentRunFailed(item)) {
    return 'Failed';
  }

  switch (item.status) {
    case 'queued':
      return 'Queued';
    case 'running':
      return 'Running';
    default:
      return 'Completed';
  }
};

const recentRunStatusColor = (item: RecentRunState): 'error' | 'info' | 'neutral' => {
  if (isRecentRunFailed(item)) {
    return 'error';
  }

  switch (item.status) {
    case 'queued':
    case 'running':
      return 'info';
    default:
      return 'neutral';
  }
};

const recentRunStatusIcon = (item: RecentRunState): string => {
  if (isRecentRunFailed(item)) {
    return 'i-lucide-triangle-alert';
  }

  switch (item.status) {
    case 'queued':
    case 'running':
      return 'i-lucide-loader-circle';
    default:
      return 'i-lucide-circle-dot';
  }
};

const recentRunStatusSpinning = (item: RecentRunState): boolean => {
  return ['queued', 'running'].includes(item.status);
};

const scheduleTerminalFit = (): void => {
  if (!terminal.value || !terminalFit.value) {
    return;
  }

  if (fitFrame) {
    return;
  }

  fitFrame = window.requestAnimationFrame(() => {
    fitFrame = null;

    if (!terminal.value || !terminalFit.value) {
      return;
    }

    terminalFit.value.fit();
  });
};

const restoreBufferedTerminalOutput = (): void => {
  if (!terminal.value) {
    return;
  }

  terminal.value.reset();
  renderedChunkCount = 0;

  if (streamState.value.chunks.length < 1) {
    didInitialRender = true;
    scheduleTerminalFit();
    return;
  }

  terminal.value.write(bufferedChunks.value.join(''));
  renderedChunkCount = bufferedChunks.value.length;
  didInitialRender = true;
  scheduleTerminalFit();

  window.requestAnimationFrame(() => {
    scheduleTerminalFit();
  });
};

const bindTerminalResizeObserver = (): void => {
  if (!outputConsole.value || 'undefined' === typeof ResizeObserver) {
    return;
  }

  terminalResizeObserver?.disconnect();
  terminalResizeObserver = new ResizeObserver(() => {
    scheduleTerminalFit();
  });
  terminalResizeObserver.observe(outputConsole.value);
};

const flushTerminal = (): void => {
  if (!terminal.value) {
    return;
  }

  if (!didInitialRender) {
    didInitialRender = true;
  }

  if (bufferedChunks.value.length < renderedChunkCount) {
    restoreBufferedTerminalOutput();
    return;
  }

  if (bufferedChunks.value.length === renderedChunkCount) {
    return;
  }

  const text = bufferedChunks.value.slice(renderedChunkCount).join('');

  if (!text) {
    return;
  }

  terminal.value.write(text);
  renderedChunkCount = bufferedChunks.value.length;
};

const scheduleFlush = (): void => {
  if (flushFrame) {
    return;
  }

  flushFrame = window.requestAnimationFrame(() => {
    flushFrame = null;
    flushTerminal();
  });
};

const writePrompt = (value: string): void => {
  appendOutput(
    formatCommandEcho(streamState.value.chunks.at(-1), streamState.value.exitCode, value),
  );
};

const writeFooter = (): void => {
  appendOutput(`\n(${streamState.value.exitCode}) ~ `);
};

const RunCommand = async (): Promise<void> => {
  let userCommand: string = command.value;

  if (userCommand.startsWith('console') || userCommand.startsWith('docker')) {
    notification('info', 'Warning', 'Removing leading prefix command from the input.', 2000);
    userCommand = userCommand.replace(/^(console|docker exec -ti watchstate)/i, '');
  }

  if (userCommand.match(/\[.*]/)) {
    const { status } = await useDialog().confirmDialog({
      title: 'Confirm command',
      message: 'The command contains placeholders "[...]". Are you sure you want to run as it is?',
    });

    if (true !== status) {
      return;
    }
  }

  if ('clear' === userCommand) {
    command.value = '';
    if (terminal.value) {
      terminal.value.clear();
    }
    clearStreamOutput();
    return;
  }

  if ('clear_ac' === userCommand) {
    clearRecentRuns();
    command.value = '';
    return;
  }

  const commandBody: { command: string } = JSON.parse(JSON.stringify({ command: userCommand }));

  if (userCommand.startsWith('$')) {
    if (!allEnabled.value) {
      notification('error', 'Error', 'The option to execute all commands is disabled.');
      focusCommandInput();
      return;
    }
    userCommand = userCommand.slice(1);
  } else {
    userCommand = `console ${userCommand}`;
  }

  writePrompt(userCommand);

  const result = await startRun(commandBody.command, userCommand);

  if ('error' === result.status) {
    notification('error', 'Error', result.message, 5000);
    focusCommandInput();
    return;
  }

  if ('blocked' === result.status) {
    focusCommandInput();
    return;
  }

  if (route.query?.cmd || route.query?.run) {
    await useRouter().replace({ path: '/console' });
  }

  command.value = commandBody.command;
  await nextTick();

  focusCommandInput();
};

const reSizeTerminal = (): void => {
  if (!terminal.value || !terminalFit.value) {
    return;
  }

  scheduleTerminalFit();
};

const clearOutput = async (): Promise<void> => {
  clearStreamOutput();
  restoreBufferedTerminalOutput();
  focusCommandInput();
};

const showHelp = async (): Promise<void> => {
  if (isLoading.value) {
    return;
  }

  command.value = '';
  await RunCommand();
};

const closeOutput = async (): Promise<void> => {
  await stopCommand();
  focusCommandInput();
};

const replayHistoryItem = async (item: (typeof recentRuns.value)[number]): Promise<void> => {
  const result = await replayRun(item);

  if ('error' === result.status) {
    notification('error', 'Error', result.message, 5000);
    focusCommandInput();
    return;
  }

  command.value = item.command;
  await nextTick();
  focusCommandInput();
};

const loadCommand = async (value: string): Promise<void> => {
  command.value = value;
  await nextTick();
  focusCommandInput();
};

const hideRecentRuns = async (): Promise<void> => {
  if (recentRunEntries.value.length < 1) {
    return;
  }

  const { status } = await useDialog().confirmDialog({
    title: 'Confirm Action',
    message: 'Hide the current recent runs from this browser?',
    confirmText: 'Hide runs',
    confirmColor: 'error',
  });

  if (true !== status) {
    return;
  }

  clearRecentRuns();
  focusCommandInput();
};

const handlePageLeave = (): void => {
  stopStream();
};

onUnmounted(() => {
  window.removeEventListener('resize', reSizeTerminal);
  window.removeEventListener('pagehide', handlePageLeave);
  window.removeEventListener('beforeunload', handlePageLeave);
  terminalResizeObserver?.disconnect();
  terminalResizeObserver = null;
  if (flushFrame) {
    window.cancelAnimationFrame(flushFrame);
    flushFrame = null;
  }
  if (fitFrame) {
    window.cancelAnimationFrame(fitFrame);
    fitFrame = null;
  }
  stopStream();
  enableOpacity();
});

onMounted(async () => {
  disableOpacity();

  window.addEventListener('resize', reSizeTerminal);
  window.addEventListener('pagehide', handlePageLeave);
  window.addEventListener('beforeunload', handlePageLeave);

  focusCommandInput();

  if (!terminal.value && outputConsole.value) {
    terminalFit.value = new FitAddon();
    terminal.value = new Terminal({
      fontSize: 16,
      fontFamily: "'JetBrains Mono', monospace",
      cursorBlink: false,
      disableStdin: true,
      convertEol: true,
      altClickMovesCursor: false,
      theme: xtermTheme.value,
    });
    terminal.value.open(outputConsole.value);
    terminal.value.loadAddon(terminalFit.value);
    bindTerminalResizeObserver();

    await nextTick();
    scheduleTerminalFit();

    if ('fonts' in document) {
      void document.fonts.ready.then(() => {
        scheduleTerminalFit();
      });
    }

    if (streamState.value.chunks.length > 0) {
      restoreBufferedTerminalOutput();
    }
  }

  const restored = await restoreRun();

  if (restored) {
    command.value = streamState.value.command;
    await nextTick();
    restoreBufferedTerminalOutput();
  }

  await fetchRecentRuns();

  try {
    const response = await request('/system/env/WS_CONSOLE_ENABLE_ALL');
    const json = await parse_api_response<EnvVar>(response);

    if (response.ok && 'value' in json) {
      allEnabled.value = Boolean(json.value);
    } else {
      allEnabled.value = false;
    }
  } catch {
    allEnabled.value = false;
  }
});

watch(
  () => bufferedChunks.value.length,
  (length, previousLength) => {
    if (!terminal.value) {
      return;
    }

    if (length < previousLength) {
      restoreBufferedTerminalOutput();
      return;
    }

    scheduleFlush();
  },
);

watch(
  () => streamState.value.error,
  (message) => {
    if (!message) {
      return;
    }

    notification('error', 'Error', message, 5000);
    focusCommandInput();
  },
);

watch(
  () => streamState.value.completedAt,
  (value, previousValue) => {
    if (value > 0 && value !== previousValue) {
      writeFooter();
    }

    if (streamState.value.command) {
      command.value = streamState.value.command;
    }
  },
);

watch(
  () => streamState.value.status,
  () => {
    if (streamState.value.command) {
      command.value = streamState.value.command;
    }
  },
);
</script>
