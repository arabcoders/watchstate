<template>
  <div class="columns is-multiline">
    <div class="column is-12 is-clearfix">
      <span class="title is-4">
        Console
      </span>
      <div class="subtitle is-6">
        You can execute <strong>non-interactive</strong> commands here. The interface jailed to the <code>console</code>
        command. You do not have to write <code>console</code> or <code>docker exec -ti watchstate console</code> here.
        The majority of the commands will not show any output unless error has occurred or important information needs
        to be communicated. To see all output, add <code>-vvv</code> to command.
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
                <button class="button is-primary" type="submit" :disabled="isLoading" :class="{'is-loading':isLoading}">
                  <span class="icon-text">
                    <span class="icon">
                      <i class="fa fa-server"></i>
                    </span>
                    <span>Run</span>
                  </span>
                </button>
              </p>
              <p class="control">
                <button class="button is-info" type="button" v-tooltip="'Clear output'" @click="response = []">
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
          <p class="help">
            <span class="icon-text">
              <span class="icon"><i class="fa fa-info"></i></span>
              <span>Please beware, clicking close connection does not stop the command. It only stops the output from
                being displayed. The command will continue to run until it finishes.</span>
            </span>
          </p>
        </div>
      </form>
    </div>
    <div class="column is-12">
        <pre ref="outputConsole" style="min-height: 60vh;max-height:80vh; overflow-y: scroll"
        ><code><span v-for="(item, index) in response" :key="'log_line-'+index" class="is-block">{{
            item
          }}</span></code></pre>
    </div>
  </div>
</template>

<script setup>

import {useStorage} from "@vueuse/core";

const fromTask = useRoute().query.task || '';

useHead({title: `Console`})

let sse;

const response = ref([]);
const command = ref('');
const isLoading = ref(false);
const outputConsole = ref();

const RunCommand = async () => {

  response.value = []

  const api_path = useStorage('api_path', '/v1/api')
  const api_url = useStorage('api_url', '')
  const api_token = useStorage('api_token', '')

  const searchParams = new URLSearchParams();
  searchParams.append('apikey', api_token.value);
  searchParams.append('json', btoa(JSON.stringify({command: command.value})));


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
  if (!fromTask) {
    await RunCommand();
    return
  }

  const response = await request(`/tasks/${fromTask}`);
  const json = await response.json();
  command.value = `${json.command} ${json.args || ''}`;
  await RunCommand();
});

</script>
