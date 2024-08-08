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
            The console defaults to <code>console</code> command, if you want to run a different command, prefix
            the command with <code>$</code>. For example <code>$ ls</code>.
          </template>
          <template v-else>
            This interface is jailed to <code>console</code> command.
          </template>
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
            <div ref="outputConsole" style="min-height: 60vh;max-height:70vh;"/>
          </section>
          <section class="card-content p-1 m-1">
            <div class="field">
              <div class="field-body">
                <div class="field is-grouped-tablet">
                  <p class="control is-expanded has-icons-left">
                    <input type="text" class="input is-fullwidth" v-model="command"
                           :placeholder="`system:view ${allEnabled ? 'or $ ls' : ''}`"
                           list="recent_commands"
                           autocomplete="off" ref="command_input" @keydown.enter="RunCommand" :disabled="isLoading">
                    <span class="icon is-left"><i class="fas fa-terminal" :class="{'fa-spin':isLoading}"></i></span>
                  </p>
                  <p class="control" v-if="!isLoading">
                    <button class="button is-primary" type="button" :disabled="hasPrefix" @click="RunCommand">
                      <span class="icon"><i class="fa fa-paper-plane"></i></span>
                    </button>
                  </p>
                  <p class="control" v-if="isLoading">
                    <button class="button is-danger" type="button" @click="finished" v-tooltip="'Close connection.'">
                      <span class="icon"><i class="fa fa-power-off"></i></span>
                    </button>
                  </p>
                </div>
              </div>
              <p class="help" v-if="hasPrefix">
                <span class="icon-text">
                  <span class="icon has-text-danger"><i class="fas fa-exclamation-triangle"></i></span>
                  <span>Remove the <code>console</code> or <code>docker exec -ti watchstate console</code> from the
                    input. You should use the command directly, For example i.e <code>db:list --output
                      yaml</code></span>
                </span>
              </p>
              <p class="help" v-if="hasPlaceholder">
                <span class="icon-text">
                  <span class="icon has-text-warning"><i class="fas fa-exclamation-circle"></i></span>
                  <span>The command contains <code>[...]</code> which are considered a placeholder, So, please replace
                    <code>[...]</code> with the intended value if applicable.</span>
                </span>
              </p>
            </div>
          </section>
        </div>
      </div>

      <div class="column is-12">
        <Message message_class="has-background-info-90 has-text-dark" :toggle="show_page_tips"
                 @toggle="show_page_tips = !show_page_tips" :use-toggle="true" title="Tips" icon="fas fa-info-circle">
          <ul>
            <li>
              You can also run a command from the task page by clicking on the <strong>Run via console</strong>. The
              command will be pre-filled for you.
            </li>
            <li>
              Clicking close connection does not stop the command. It only stops the output from being displayed. The
              command will continue to run until it finishes.
            </li>
            <li>
              The majority of the commands will not show any output unless error has occurred or important information
              needs to be communicated. To see all output, add the <code>-vvv</code> flag.
            </li>
            <li>
              There is no need to write <code>console</code> or <code>docker exec -ti watchstate console</code> Using
              this interface. Use the command followed by the options directly. For example, <code>db:list --output
              yaml</code>.
            </li>
            <li>
              There is an environment variable <code>WS_CONSOLE_ENABLE_ALL</code> that can be set to <code>true</code>
              to enable all commands to be run from the console. This is disabled by default.
            </li>
            <li>To clear the recent commands auto-suggestions, you can use the <code>clear_ac</code> command.</li>
          </ul>
        </Message>
      </div>

      <datalist id="recent_commands">
        <option v-for="item in recentCommands" :key="item" :value="item"/>
      </datalist>
    </div>
  </div>
</template>

<script setup>
import "@xterm/xterm/css/xterm.css"
// noinspection ES6UnusedImports
import {Terminal} from "@xterm/xterm"
// noinspection ES6UnusedImports
import {FitAddon} from "@xterm/addon-fit"
import {useStorage} from '@vueuse/core'
import {notification} from '~/utils/index'
import Message from '~/components/Message'

useHead({title: `Console`})

const route = useRoute()
const fromCommand = route.query.cmd || false ? atob(route.query.cmd) : ''

let sse
const terminal = ref()
const terminalFit = ref()
const response = ref([])
const command = ref(fromCommand)
const isLoading = ref(false)
const outputConsole = ref()
const command_input = ref()
const executedCommands = useStorage('executedCommands', [])

const hasPrefix = computed(() => command.value.startsWith('console') || command.value.startsWith('docker'))
const hasPlaceholder = computed(() => command.value && command.value.match(/\[.*\]/))
const show_page_tips = useStorage('show_page_tips', true)
const allEnabled = ref(false)

const RunCommand = async () => {
  const api_path = useStorage('api_path', '/v1/api')
  const api_url = useStorage('api_url', '')
  const api_token = useStorage('api_token', '')

  let userCommand = command.value

  // -- check if the user command starts with console or docker exec -ti watchstate
  if (userCommand.startsWith('console') || userCommand.startsWith('docker')) {
    notification('error', 'Warning', 'Please remove the [console] or [docker exec -ti watchstate console] from the command.')
    return
  }

  // use regex to check if command contains [...]
  if (userCommand.match(/\[.*\]/)) {
    if (!confirm(`The command contains placeholders '[...]'. Are you sure you want to run as it is?`)) {
      return
    }
  }

  response.value = []

  if (userCommand === 'clear') {
    command.value = ''
    terminal.value.clear()
    return
  }

  if (userCommand === 'clear_ac') {
    executedCommands.value = []
    command.value = ''
    return
  }

  const searchParams = new URLSearchParams()
  searchParams.append('apikey', api_token.value)
  searchParams.append('json', btoa(JSON.stringify({command: userCommand})))


  if (userCommand.startsWith('$')) {
    if (!allEnabled.value) {
      notification('error', 'Error', 'The option to execute all commands is disabled.')
      command_input.value.focus()
      return
    }
    userCommand = userCommand.slice(1)
  } else {
    userCommand = `console ${userCommand}`
  }

  isLoading.value = true

  sse = new EventSource(`${api_url.value}${api_path.value}/system/command/?${searchParams.toString()}`)

  if ('' !== command.value) {
    terminal.value.writeln(`~ ${userCommand}`)
  }
  sse.addEventListener('data', async e => terminal.value.write(JSON.parse(e.data).data))
  sse.addEventListener('close', async () => finished())
  sse.onclose = async () => finished()
  sse.onerror = async () => finished()
}

const finished = async () => {
  if (sse) {
    sse.close()
  }

  isLoading.value = false

  const route = useRoute()

  if (route.query?.cmd || route.query?.run) {
    route.query.cmd = ''
    route.query.run = ''
    await useRouter().push({path: '/console'})
  }

  if (executedCommands.value.includes(command.value)) {
    executedCommands.value.splice(executedCommands.value.indexOf(command.value), 1)
  }

  executedCommands.value.push(command.value)

  if (executedCommands.value.length > 30) {
    executedCommands.value.shift()
  }

  command.value = ''
  await nextTick()

  command_input.value.focus()
}

const recentCommands = computed(() => executedCommands.value.reverse().slice(-10))

const reSizeTerminal = () => {
  if (!terminal.value) {
    return
  }
  terminalFit.value.fit()
}

const clearOutput = async () => {
  if (terminal.value) {
    terminal.value ? terminal.value.clear() : ''
  }
  command_input.value.focus()
}

onUnmounted(() => {
  window.removeEventListener("resize", reSizeTerminal)
  if (sse) {
    sse.close()
  }
})

onMounted(async () => {
  window.addEventListener("resize", reSizeTerminal);
  command_input.value.focus()

  if (!terminal.value) {
    terminalFit.value = new FitAddon()
    terminal.value = new Terminal({
      fontSize: 16,
      fontFamily: "'JetBrains Mono', monospace",
      cursorBlink: false,
      cursorStyle: 'underline',
      cols: 108,
      rows: 10,
      disableStdin: true,
    })
    terminal.value.open(outputConsole.value)
    terminal.value.loadAddon(terminalFit.value)
    terminalFit.value.fit()
  }

  try {
    const response = await request('/system/env/WS_CONSOLE_ENABLE_ALL')
    const json = await response.json()
    if (200 !== response.status) {
      allEnabled.value = false
      return
    }
    allEnabled.value = Boolean(json.value)
  } catch (e) {
    allEnabled.value = false
  }

  if (Boolean(route.query?.run ?? '0') || '' === command.value) {
    await RunCommand()
  }
})
</script>
