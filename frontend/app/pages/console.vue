<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <h1 class="title is-4">
          <span class="icon"><i class="fas fa-terminal"></i></span> Console
        </h1>
        <div class="subtitle">
          You can execute <strong>non-interactive</strong> commands here.
          <template v-if="allEnabled">
            The console defaults to <code>console</code> command, if you want to run a different
            command, prefix the command with <code>$</code>. For example <code>$ ls</code>.
          </template>
          <template v-else> This interface is jailed to <code>console</code> command. </template>
        </div>
      </div>

      <div class="column is-12">
        <div class="card">
          <header class="card-header">
            <p class="card-header-title">
              <span class="icon"><i class="fas fa-terminal"></i>&nbsp;</span> Terminal
            </p>
            <p class="card-header-icon">
              <span class="icon" @click="clearOutput"><i class="fa fa-broom"></i></span>
            </p>
          </header>
          <section class="card-content p-0 m-0">
            <div ref="outputConsole" style="min-height: 60vh; max-height: 70vh" />
          </section>
          <section class="card-content p-1 m-1">
            <div class="field">
              <div class="field-body">
                <div class="field is-grouped-tablet">
                  <p class="control is-expanded has-icons-left">
                    <input
                      type="text"
                      class="input is-fullwidth"
                      v-model="command"
                      :placeholder="`system:view ${allEnabled ? 'or $ ls' : ''}`"
                      list="recent_commands"
                      autocomplete="off"
                      ref="commandInput"
                      @keydown.enter="RunCommand"
                      :disabled="isLoading"
                    />
                    <span class="icon is-left"
                      ><i class="fas fa-terminal" :class="{ 'fa-spin': isLoading }"></i
                    ></span>
                  </p>
                  <p class="control" v-if="!isLoading">
                    <button
                      class="button is-primary"
                      type="button"
                      :disabled="hasPrefix"
                      @click="RunCommand"
                    >
                      <span class="icon"><i class="fa fa-paper-plane"></i></span>
                      <span>Execute</span>
                    </button>
                  </p>
                  <p class="control" v-if="isLoading">
                    <button
                      class="button is-danger"
                      type="button"
                      @click="finished"
                      v-tooltip="'Close connection.'"
                    >
                      <span class="icon"><i class="fa fa-power-off"></i></span>
                      <span>Close</span>
                    </button>
                  </p>
                </div>
              </div>
            </div>
          </section>
        </div>
      </div>

      <div class="column is-12" v-if="hasPrefix || hasPlaceholder">
        <Message
          message_class="has-background-warning-90 has-text-dark"
          title="Warning"
          icon="fas fa-exclamation-triangle"
          v-if="hasPrefix"
        >
          <p>Use the command directly, For example i.e. <code>db:list -o yaml</code></p>
        </Message>

        <Message
          message_class="has-background-warning-90 has-text-dark"
          title="Warning"
          icon="fas fa-exclamation-triangle"
          v-if="hasPlaceholder"
        >
          <span class="icon has-text-warning"><i class="fas fa-exclamation-circle"></i></span>
          <span
            >The command contains <code>[...]</code> which are considered a placeholder, So, please
            replace <code>[...]</code> with the intended value if applicable.</span
          >
        </Message>
      </div>

      <div class="column is-12">
        <Message
          message_class="has-background-info-90 has-text-dark"
          :toggle="show_page_tips"
          @toggle="show_page_tips = !show_page_tips"
          :use-toggle="true"
          title="Tips"
          icon="fas fa-info-circle"
        >
          <ul>
            <li>
              You don’t need to type <code>console</code> or run
              <code>docker exec -ti watchstate console</code> when using this interface. Just enter
              the command and options directly. For example: <code>db:list --output yaml</code>.
            </li>
            <li>
              Clicking <strong>Close Connection</strong> only stops the output from being shown—it
              does <em>not</em>
              stop the command itself. The command will continue running until it finishes.
            </li>
            <li>
              Most commands won’t display anything unless there’s an error or important message. Use
              <code>-v</code> to see more details. If you’re debugging, try
              <code>-vv --context</code> for even more information.
            </li>
            <li>
              There’s an environment variable <code>WS_CONSOLE_ENABLE_ALL</code> that you can set to
              <code>true</code>
              to allow all commands to run from the console. It’s turned off by default.
            </li>
            <li>To clear the recent command suggestions, use the <code>clear_ac</code> command.</li>
            <li>
              The number inside the parentheses is the exit code of the last command. If it’s
              <code>0</code>, the command ran successfully. Any other value usually means something
              went wrong.
            </li>
          </ul>
        </Message>
      </div>

      <datalist id="recent_commands">
        <option v-for="item in recentCommands" :key="item" :value="item" />
      </datalist>
    </div>
  </div>
</template>

<style scoped>
.xterm {
  padding: 0.5rem !important;
}

.xterm-viewport {
  background-color: #1f2229 !important;
}
</style>

<script setup lang="ts">
import '@xterm/xterm/css/xterm.css';
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue';
import { useHead, useRoute, useRouter } from '#app';
import { Terminal } from '@xterm/xterm';
import { FitAddon } from '@xterm/addon-fit';
import { useStorage } from '@vueuse/core';
import { request, disableOpacity, enableOpacity, notification, parse_api_response } from '~/utils';
import Message from '~/components/Message.vue';
import { fetchEventSource } from '@microsoft/fetch-event-source';
import type { EnvVar, GenericError, GenericResponse } from '~/types';
import { useDialog } from '~/composables/useDialog';

useHead({ title: 'Console' });

const route = useRoute();
const fromCommand: string =
  route.query.cmd && 'string' === typeof route.query.cmd ? atob(route.query.cmd) : '';

let sse: (() => void) | null = null;
const terminal = ref<Terminal | null>(null);
const terminalFit = ref<FitAddon | null>(null);
const response = ref<Array<unknown>>([]);
const command = ref<string>(fromCommand);
const isLoading = ref<boolean>(false);
const outputConsole = ref<HTMLElement | null>(null);
const commandInput = ref<HTMLInputElement | null>(null);
const executedCommands = useStorage<Array<string>>('executedCommands', []);
const exitCode = ref<number>(0);

const hasPrefix = computed(
  () => command.value.startsWith('console') || command.value.startsWith('docker'),
);
const hasPlaceholder = computed(() => command.value && command.value.match(/\[.*]/));
const show_page_tips = useStorage<boolean>('show_page_tips', true);
const allEnabled = ref<boolean>(false);
const ctrl = new AbortController();

const RunCommand = async (): Promise<void> => {
  const token = useStorage<string>('token', '');

  let userCommand: string = command.value;

  // -- check if the user command starts with console or docker exec -ti watchstate
  if (userCommand.startsWith('console') || userCommand.startsWith('docker')) {
    notification('info', 'Warning', 'Removing leading prefix command from the input.', 2000);
    userCommand = userCommand.replace(/^(console|docker exec -ti watchstate)/i, '');
  }

  // use regex to check if command contains [...]
  if (userCommand.match(/\[.*]/)) {
    const { status } = await useDialog().confirmDialog({
      title: 'Confirm command',
      message: 'The command contains placeholders "[...]". Are you sure you want to run as it is?',
    });

    if (true !== status) {
      return;
    }
  }

  response.value = [];

  if ('clear' === userCommand) {
    command.value = '';
    if (terminal.value) {
      terminal.value.clear();
    }
    return;
  }

  if ('clear_ac' === userCommand) {
    executedCommands.value = [];
    command.value = '';
    return;
  }

  const commandBody: { command: string } = JSON.parse(JSON.stringify({ command: userCommand }));

  if (userCommand.startsWith('$')) {
    if (!allEnabled.value) {
      notification('error', 'Error', 'The option to execute all commands is disabled.');
      if (commandInput.value) {
        commandInput.value.focus();
      }
      return;
    }
    userCommand = userCommand.slice(1);
  } else {
    userCommand = `console ${userCommand}`;
  }

  isLoading.value = true;
  let commandToken: string;

  try {
    const response = await request('/system/command', {
      method: 'POST',
      body: JSON.stringify(commandBody),
    });

    const json = await parse_api_response<{ token: string }>(response);

    if ('error' in json) {
      await finished();
      notification('error', 'Error', `${json.error.code}: ${json.error.message}`, 5000);
      return;
    }

    if (201 !== response.status) {
      await finished();
      return;
    }

    commandToken = json.token;
  } catch (e: unknown) {
    await finished();
    const errorMessage = e instanceof Error ? e.message : 'Unknown error occurred';
    notification('error', 'Error', errorMessage, 5000);
    return;
  }

  fetchEventSource(`/v1/api/system/command/${commandToken}`, {
    signal: ctrl.signal,
    headers: { Authorization: `Token ${token.value}` },
    onmessage: async (evt: { event: string; data: string }): Promise<void> => {
      switch (evt.event) {
        case 'data':
          if (terminal.value) {
            const eventData = JSON.parse(evt.data) as { data: string };
            terminal.value.write(eventData.data);
          }
          break;
        case 'close':
          await finished();
          break;
        case 'exit_code':
          exitCode.value = parseInt(evt.data);
          break;
        default:
          break;
      }
    },
    onopen: async (response: Response): Promise<void> => {
      if (response.ok) {
        return;
      }

      const json = await parse_api_response<GenericResponse>(response);

      if ('error' in json) {
        const errorJson = json as GenericError;
        if (400 === errorJson.error.code) {
          ctrl.abort();
          return;
        }
        const message = `${errorJson.error.code}: ${errorJson.error.message}`;
        notification('error', 'Error', message, 3000);
        await finished();
        return;
      }

      if (400 === response.status) {
        ctrl.abort();
        return;
      }

      await finished();
    },
    onerror: (e: unknown): void => {
      console.log(e);
    },
  });

  // Store the abort function
  sse = () => ctrl.abort();

  if ('' !== command.value && terminal.value) {
    terminal.value.writeln(`(${exitCode.value}) ~ ${userCommand}`);
  }
};

const finished = async (): Promise<void> => {
  if (sse) {
    sse();
    sse = null;
  }

  isLoading.value = false;

  const route = useRoute();

  if (route.query?.cmd || route.query?.run) {
    route.query.cmd = '';
    route.query.run = '';
    await useRouter().push({ path: '/console' });
  }

  if (executedCommands.value.includes(command.value)) {
    executedCommands.value.splice(executedCommands.value.indexOf(command.value), 1);
  }

  executedCommands.value.push(command.value);

  if (30 < executedCommands.value.length) {
    executedCommands.value.shift();
  }

  if (terminal.value) {
    terminal.value.writeln(`\n(${exitCode.value}) ~ `);
  }

  command.value = '';
  await nextTick();

  if (commandInput.value) {
    commandInput.value.focus();
  }
};

const recentCommands = computed(() => executedCommands.value.slice(-10).reverse());

const reSizeTerminal = (): void => {
  if (!terminal.value || !terminalFit.value) {
    return;
  }
  terminalFit.value.fit();
};

const clearOutput = async (): Promise<void> => {
  if (terminal.value) {
    terminal.value.clear();
  }
  if (commandInput.value) {
    commandInput.value.focus();
  }
};

onUnmounted(() => {
  window.removeEventListener('resize', reSizeTerminal);
  if (sse) {
    sse();
  }
  enableOpacity();
});

onMounted(async () => {
  disableOpacity();

  window.addEventListener('resize', reSizeTerminal);

  if (commandInput.value) {
    commandInput.value.focus();
  }

  if (!terminal.value && outputConsole.value) {
    terminalFit.value = new FitAddon();
    terminal.value = new Terminal({
      fontSize: 16,
      fontFamily: "'JetBrains Mono', monospace",
      cursorBlink: false,
      disableStdin: true,
      convertEol: true,
      altClickMovesCursor: false,
    });
    terminal.value.open(outputConsole.value);
    terminal.value.loadAddon(terminalFit.value);
    terminalFit.value.fit();
  }

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

  const run: boolean = route.query?.run ? Boolean(route.query.run) : false;
  if (true === run && command.value) {
    await RunCommand();
  }
});
</script>
