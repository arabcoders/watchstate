<template>
  <div class="columns is-multiline">
    <div class="column is-12 is-clearfix">
      <span class="title is-4">
        <NuxtLink href="/backends">Backends</NuxtLink>
        : Edit -
        <NuxtLink :href="'/backends/' + id">{{ id }}</NuxtLink>
      </span>

      <div class="is-pulled-right">
        <div class="field is-grouped"></div>
      </div>
    </div>

    <div class="column is-12">
      <form id="backend_edit_form" @submit.prevent="saveContent">
        <div class="box">

          <div class="field">
            <label class="label">Backend Name</label>
            <div class="control has-icons-left">
              <input class="input" type="text" v-model="backend.name" required readonly disabled>
              <div class="icon is-small is-left">
                <i class="fas fa-user"></i>
              </div>
              <p class="help">
                Choose a unique name for this backend. You cannot change it later. Backend name must be in <code>lower
                case a-z, 0-9 and _</code> only.
              </p>
            </div>
          </div>

          <div class="field">
            <label class="label">Backend Type</label>
            <div class="control has-icons-left">
              <div class="select is-fullwidth" disabled>
                <select v-model="backend.type" disabled class="is-capitalized">
                  <option v-for="(bType, index) in supported" :key="'btype-'+index" :value="bType">
                    {{ bType }}
                  </option>
                </select>
              </div>
              <div class="icon is-small is-left">
                <i class="fas fa-globe"></i>
              </div>
              <p class="help">
                Select the correct backend type.
              </p>
            </div>
          </div>

          <div class="field">
            <label class="label">Backend URL</label>
            <div class="control has-icons-left">
              <input class="input" type="text" v-model="backend.url" required>
              <div class="icon is-small is-left">
                <i class="fas fa-link"></i>
              </div>
              <p class="help">
                Enter the URL of the backend.
                <a v-if="'plex' === backend.type" href="javascript:void(0)">Get associated servers with token.</a>
              </p>
            </div>
          </div>

          <div class="field">
            <label class="label">Backend Token</label>
            <div class="control has-icons-left">
              <input class="input" type="text" v-model="backend.token" required>
              <div class="icon is-small is-left">
                <i class="fas fa-key"></i>
              </div>
              <p class="help">
                Enter the token of the backend.
              </p>
            </div>
          </div>

          <div class="field">
            <label class="label">Backend User ID</label>
            <div class="control has-icons-left">
              <input class="input" type="text" v-model="backend.user" required>
              <div class="icon is-small is-left">
                <i class="fas fa-user-tie"></i>
              </div>
              <p class="help">
                The user ID of the backend. <a href="javascript:void(0)">Pull User ids from backend.</a>
              </p>
            </div>
          </div>

          <div class="field">
            <label class="label">Backend Unique ID</label>
            <div class="control has-icons-left">
              <input class="input" type="text" v-model="backend.uuid" required>
              <div class="icon is-small is-left">
                <i class="fas fa-server"></i>
              </div>
              <p class="help">
                The Unique identifier for the backend.
                <a href="javascript:void(0)">Pull from the backend</a>
              </p>
            </div>
          </div>


        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
const id = useRoute().params.backend
const backend = ref({})
const supported = ref([])

const loadContent = async () => {
  let content = await request('/system/supported')
  let json = await content.json()
  supported.value = json.supported

  content = await request(`/backend/${id}`)
  json = await content.json()
  backend.value = json.backend
}

onMounted(() => loadContent())

</script>
