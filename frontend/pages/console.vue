<template>
  <div class="columns is-multiline">
    <div class="column is-12 is-clearfix is-unselectable">
      <h1 class="title is-4">
        <span class="icon"><i class="fas fa-terminal"></i></span> Console
      </h1>
      <div class="subtitle">
        You can execute <strong>non-interactive</strong> commands here. This interface is jailed to <code>console</code>
        command.
      </div>
    </div>

    <div class="column is-12">
      <form @submit.prevent="RunCommand">
        <div class="field">
          <div class="field-body">
            <div class="field is-grouped-tablet">
              <p class="control is-expanded has-icons-left">
                <input type="text" class="input" v-model="command" placeholder="system:view" autocomplete="off"
                       autofocus
                       :disabled="isLoading">
                <span class="icon is-left">
                  <i class="fas fa-terminal"></i>
                </span>
              </p>
              <p class="control">
                <button class="button is-primary" type="submit" :disabled="isLoading ||hasPrefix"
                        :class="{'is-loading':isLoading}">
                  <span class="icon-text">
                    <span class="icon">
                      <i class="fa fa-server"></i>
                    </span>
                    <span>Run</span>
                  </span>
                </button>
              </p>
              <p class="control">
                <button class="button is-info" type="button" v-tooltip="'Clear output'"
                        @click="response = []" :disabled="response.length < 1">
                  <span class="icon-text">
                    <span class="icon"><i class="fa fa-broom"></i></span>
                    <span>Clear</span>
                  </span>
                </button>
              </p>
              <p class="control" v-if="isLoading">
                <button class="button is-danger" type="button" @click="finished" v-tooltip="'Close connection.'">
                  <span class="icon-text">
                    <span class="icon"><i class="fa fa-power-off"></i></span>
                    <span>Close Connection</span>
                  </span>
                </button>
              </p>
            </div>
          </div>
          <p class="help" v-if="hasPrefix">
            <span class="icon-text">
              <span class="icon has-text-danger"><i class="fas fa-exclamation-triangle"></i></span>
              <span>Remove the <code>console</code> or <code>docker exec -ti watchstate console</code> from the
                input. You should use the command directly, For example i.e <code>db:list --output yaml</code></span>
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
      </form>
    </div>
    <div class="column is-12">
        <pre ref="outputConsole" style="min-height: 60vh;max-height:70vh; overflow-y: scroll"
        ><code><span v-for="(item, index) in response" :key="'log_line-'+index" class="is-block">{{
            item
          }}</span></code></pre>
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
        </ul>
      </Message>
    </div>
  </div>
</template>

<script setup>

import {useStorage} from '@vueuse/core'
import {notification} from '~/utils/index.js'
import Message from '~/components/Message.vue'

const route = useRoute()

const fromTask = route.query.task || '';
let fromCommand = route.query.cmd || '';
if (fromCommand) {
  // -- decode base64
  fromCommand = atob(fromCommand);
}

useHead({title: `Console`})

let sse;

const response = ref([]);
const command = ref(fromCommand);
const isLoading = ref(false);
const outputConsole = ref();
const hasPrefix = computed(() => command.value.startsWith('console') || command.value.startsWith('docker'));
const hasPlaceholder = computed(() => command.value && command.value.match(/\[.*\]/));
const show_page_tips = useStorage('show_page_tips', true)

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

  const searchParams = new URLSearchParams();
  searchParams.append('apikey', api_token.value);
  searchParams.append('json', btoa(JSON.stringify({command: userCommand})));


  sse = new EventSource(`${api_url.value}${api_path.value}/system/command/?${searchParams.toString()}`);

  isLoading.value = true;

  sse.addEventListener('data', async e => {
    let lines = e.data.split(/\n/g);
    for (let x = 0; x < lines.length; x++) {
      response.value.push(lines[x]);
    }
  });

  sse.addEventListener('close', () => finished());
  sse.onclose = () => finished();
  sse.onerror = () => finished();
}

const finished = () => {
  if (sse) {
    sse.close();
  }

  isLoading.value = false;
}

onUpdated(() => outputConsole.value.scrollTop = outputConsole.value.scrollHeight);

onMounted(async () => {
  if (!fromTask && '' === command.value) {
    await RunCommand();
    return
  }

  if (!fromTask) {
    return
  }

  const response = await request(`/tasks/${fromTask}`);
  const json = await response.json();
  command.value = `${json.command} ${json.args || ''}`;
  await RunCommand();
});

</script>
