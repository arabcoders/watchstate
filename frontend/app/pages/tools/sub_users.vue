<template>
  <div>
    <div class="columns is-multiline is-mobile">
      <div class="column is-12 is-clearfix is-unselectable">
        <span id="env_page_title" class="title is-4">
          <span class="icon"><i class="fas fa-users"/></span>
          Sub Users
        </span>
        <div class="is-pulled-right">
          <div class="field is-grouped">
            <p class="control">
              <button class="button is-purple" v-tooltip.bottom="'Export to mapper.yaml file.'" @click="generateFile"
                      :disabled="userWithNoPin.length > 0">
                <span class="icon"><i class="fas fa-file-export"/></span>
              </button>
            </p>
            <p class="control">
              <button class="button is-primary" v-tooltip.bottom="'Create new user association.'" @click="addNewUser">
                <span class="icon"><i class="fas fa-plus"/></span>
              </button>
            </p>
            <p class="control">
              <button class="button is-info" @click="loadContent(true)" :disabled="isLoading"
                      :class="{ 'is-loading': isLoading }">
                <span class="icon"><i class="fas fa-sync"/></span>
              </button>
            </p>
          </div>
        </div>
        <div class="is-hidden-mobile">
          <span class="subtitle">
            Drag & Drop the relevant users accounts to form association. Read the information section.
            <template v-if="expires">The cached users list will expire {{ moment(expires).fromNow() }}</template>
          </span>
        </div>
      </div>

      <div class="column is-12" v-if="isLoading">
        <Message v-if="isLoading" message_class="is-background-info-90 has-text-dark" icon="fas fa-spinner fa-spin"
                 title="Loading" message="Loading data. Please wait..."/>
      </div>

      <div class="column is-12" v-if="!isLoading && userWithNoPin.length > 0">
        <Message message_class="has-background-warning-80 has-text-dark" icon="fas fa-exclamation-triangle"
                 title="User/s missing PIN">
          <p>
            <span>
              The following users
              <span v-for="(user, index) in userWithNoPin" :key="index" class="tag pr-1">
                {{ user }}
              </span>
              are missing a PIN. Click on <span class="icon"><i class="fas fa-lock-open"/></span> to set the user PIN.
              Otherwise you will not be able to proceed.
            </span>
          </p>
        </Message>
      </div>

      <div class="column is-12" v-if="matched?.length < 1 && !isLoading">
        <Message message_class="has-background-danger-90 has-text-dark" icon="fas fa-exclamation-triangle"
                 title="No matched users.">
          <p>
            <span class="icon"><i class="fas fa-exclamation-triangle"/></span>
            <span>Click on the add button to user group</span>
          </p>
        </Message>
      </div>

      <div class="column is-6-tablet is-12-mobile" v-for="(group, index) in matched" :key="index">
        <div class="card" :class="{ 'is-success': group.matched.length >= 2, 'is-warning': group.matched.length <= 1 }">
          <header class="card-header">
            <p class="card-header-title is-centered is-text-overflow">{{ group.user }}</p>
            <span class="card-header-icon">
              <span class="icon" @click="deleteGroup(index)"><i class="fas fa-trash-can"/></span>
            </span>
          </header>
          <div class="card-content">
            <draggable v-model="group.matched" :group="{ name: 'shared', pull: true, put: true }" animation="150"
                       :move="checkBackend" item-key="id">
              <template #item="{ element }">
                <div class="draggable-item" :class="setClass(element)">
                  <span v-if="element?.protected" class="icon has-text-danger"
                        v-tooltip.bottom="'Click to set/view user PIN'" @click="setUserPin(element)">
                    <i class="fas"
                       :class="{'fa-lock': element?.options?.PLEX_USER_PIN, 'fa-lock-open': !element?.options?.PLEX_USER_PIN}"/>
                  </span>
                  <span>
                    {{ element.backend }}@{{ element.username }}
                    <span v-if="!isSameName(element.real_name, element.username)">
                      ( <u>{{ element.real_name }}</u> )
                    </span>
                  </span>
                </div>
              </template>
            </draggable>
          </div>
        </div>
      </div>

      <div class="column is-12" v-if="!isLoading">
        <div class="card is-danger">
          <header class="card-header is-block">
            <p class="card-header-title is-centered is-text-overflow has-text-danger">
              <span class="icon"><i class="fas fa-exclamation-triangle"/></span>
              Unmatched Users
            </p>
          </header>
          <div class="card-content">
            <draggable v-model="unmatched" :group="{ name: 'shared', pull: true, put: true }" animation="150"
                       :move="checkBackend" item-key="id">
              <template #item="{ element }">
                <div class="draggable-item" :class="setClass(element)">
                  <span v-if="element?.protected" class="icon has-text-danger"
                        v-tooltip.bottom="'Click to set/view user PIN'">
                    <i class="fas"
                       :class="{'fa-lock': element?.options?.PLEX_USER_PIN, 'fa-lock-open': !element?.options?.PLEX_USER_PIN}"/>
                  </span>
                  <span>
                    {{ element.backend }}@{{ element.username }}
                    <span v-if="!isSameName(element.real_name, element.username)">
                      ( <u>{{ element.real_name }}</u> )
                    </span>
                  </span>
                </div>
              </template>
            </draggable>
          </div>
          <div v-if="unmatched?.length < 1">
            <Message message_class="has-background-success-90 has-text-dark" icon="fas fa-check-circle">
              <p>
                <span class="icon"><i class="fas fa-check"/></span>
                <span>All users are associated.</span>
              </p>
            </Message>
          </div>
        </div>
      </div>

      <div class="column is-12" v-if="!isLoading">
        <div class="box">
          <h1 class="title is-4">
            Action form
          </h1>

          <div class="field" v-if="hasUsers">
            <div class="control">
              <input id="recreate" type="checkbox" class="switch is-success" v-model="recreate">
              <label for="recreate" class="has-text-danger">
                Delete current local sub-users data, and re-create them.
              </label>
            </div>
          </div>

          <div class="field">
            <div class="control">
              <input id="backup" type="checkbox" class="switch is-success" v-model="backup">
              <label for="backup">Create initial backup for each sub-user remote backend data.</label>
            </div>
          </div>

          <div class="field">
            <div class="control">
              <input id="no_save" type="checkbox" class="switch is-danger" v-model="noSave">
              <label for="no_save">Do not save mapper.</label>
            </div>
          </div>

          <div class="field">
            <div class="control">
              <input id="verbose" type="checkbox" class="switch is-info" v-model="verbose">
              <label for="verbose">Show more indepth logs.</label>
            </div>
          </div>

          <div class="field">
            <div class="control">
              <input id="dry_run" type="checkbox" class="switch is-info" v-model="dryRun">
              <label for="dry_run">Dry-run do not make changes.</label>
            </div>
          </div>

          <div class="field is-fullwidth is-grouped">

            <div class="control is-expanded">
              <button class="button is-fullwidth is-warning" @click="saveMap" :disabled="userWithNoPin.length > 0">
                <span class="icon"><i class="fas fa-save"/></span>
                <span>Save mapping</span>
              </button>
            </div>

            <div class="control is-expanded">
              <button class="button is-fullwidth" @click="createUsers" :disabled="userWithNoPin.length > 0"
                      :class="{'is-primary': !dryRun && !recreate, 'is-info':dryRun, 'is-danger': !dryRun && recreate}">
                <span class="icon"><i class="fas fa-users"/></span>
                <span v-if="!dryRun">
                  <span v-if="recreate || !hasUsers">
                    {{ recreate ? 'Re-create' : 'Create' }} sub-users
                  </span>
                  <span v-if="!recreate && hasUsers">Update sub-users</span>
                </span>
                <span v-else>
                  Test create sub-users
                  <span v-if="hasUsers">(Safe operation)</span>
                </span>
              </button>
            </div>

          </div>
        </div>

      </div>

      <div class="column is-12">
        <Message message_class="has-background-info-90 has-text-dark" title="Information" icon="fas fa-info-circle">
          <ul>
            <li>This page lets you guide the system in matching sub-users across different backends.</li>
            <li>
              When you click <code>Create sub-users</code>, your mapping will be uploaded—unless you’ve selected <code>Do
              not save mapper</code>. Based on your choice, the system will either delete and recreate the local
              sub-users, or try to update the existing ones.
            </li>
            <li class="has-text-danger is-bold">
              Warning: If you choose not to delete the existing local sub-users and the matching changes for any reason,
              you may end up with duplicate users. We strongly recommend deleting the current local sub-users.
            </li>
            <li>
              Clicking <code>Save mapping</code> will only save your current mapping to the system. It will
              <strong>not</strong> create any sub-users.
            </li>
            <li>
              Clicking the <i class="fas fa-file-export"></i> icon will download the current mapping as a YAML file. You
              can review and manually upload it to the system later if needed.
            </li>
            <li>
              Users in the <b>Not matched</b> group aren’t currently linked to any others and likely won’t be matched
              automatically.
            </li>
            <li>
              Each user group must have at least two users to be considered a valid group.
            </li>
            <li>
              You can drag and drop users from the <b>Not matched</b> group into any other group to manually associate
              them.
            </li>
            <li>
              A user group can only include <b>one</b> user from <b>each</b> backend. If you try to add a second user
              from the same backend, an error will be shown.
            </li>
            <li>
              The display name format is: <code>backend_name@normalized_name (real_username)</code>. The <code>(real_username)</code>
              part only appears if it’s different from the <code>normalized_name</code>.
            </li>
            <li>
              There is a 5-minute cache when retrieving users from the API, so the data you see might be slightly out of
              date. This is to prevent overwhelming external APIs with requests and to have better response times.
            </li>
            <li>
              Users backends with red border and icon of <i class="fas fa-lock-open"></i> are protected by PIN, and you
              need to click on the icon to set the PIN. Otherwise, you will not be able to proceed.
            </li>
          </ul>
        </Message>
      </div>
    </div>
  </div>
</template>

<script setup>
import moment from 'moment'
import {makeConsoleCommand, notification, parse_api_response} from "~/utils/index.js";
import {useStorage} from "@vueuse/core";

const matched = ref([])
const unmatched = ref([])
const isLoading = ref(false)
const toastIsVisible = ref(false)
const recreate = ref(false)
const backup = ref(false)
const noSave = ref(false)
const dryRun = ref(false)
const hasUsers = ref(false)
const verbose = ref(false)
const expires = ref()
const api_user = useStorage('api_user', 'main')

const addNewUser = () => {
  const newUserName = `User group #${matched.value.length + 1}`
  matched.value.push({user: newUserName, matched: []})
}

const loadContent = async (force) => {
  if (matched.value.length > 0) {
    if (!confirm('Reloading will remove all modifications. Are you sure?')) {
      return
    }
  }

  matched.value = []
  unmatched.value = []
  isLoading.value = true

  try {
    const response = await request(`/backends/mapper${force ? '?force=1' : ''}`, {
      method: 'GET',
      headers: {'Accept': 'application/json'}
    })
    const json = await response.json()

    if (useRoute().name !== 'tools-sub_users') {
      return
    }

    matched.value = json.matched
    unmatched.value = json.unmatched
    recreate.value = json.has_users
    backup.value = !json.has_users
    hasUsers.value = json.has_users
    expires.value = json?.expires
  } catch (e) {
    notification('error', 'Error', e.message)
  } finally {
    isLoading.value = false
  }
}

const generateFile = async () => {
  const filename = 'mapper.yaml'
  const data = formatData()

  if (!data.map.length) {
    notification('error', 'Error', 'No data to export.')
    return
  }

  const response = request(`/system/yaml/${filename}`, {
    method: 'POST',
    headers: {'Accept': 'text/yaml'},
    body: JSON.stringify(data)
  })

  if ('showSaveFilePicker' in window) {
    response.then(async res => {
      return res.body.pipeTo(await (await showSaveFilePicker({
        suggestedName: `${filename}`
      })).createWritable())
    })
  }

  response.then(res => res.blob()).then(blob => {
    const fileURL = URL.createObjectURL(blob)
    const fileLink = document.createElement('a')
    fileLink.href = fileURL
    fileLink.download = `${filename}`
    fileLink.click()
  })
}

const checkBackend = e => {
  if (e.draggedContext.list === e.relatedContext.list) {
    return true;
  }

  const isMatchedContainer = matched.value.some(
      group => group.matched === e.relatedContext.list
  );

  if (false === isMatchedContainer) {
    return true;
  }

  const draggedUser = e.draggedContext.element;
  const alreadyExists = e.relatedContext.list.some(item => item.backend === draggedUser.backend)

  if (true === alreadyExists) {
    if (!toastIsVisible.value) {
      toastIsVisible.value = true;
      nextTick(() => {
        notification('error', 'error', `A user from '${draggedUser.backend}' backend, already mapped in this group.`, 3001, {
          onClose: () => toastIsVisible.value = false,
        })
      })
    }
    return false;
  }

  return true;
}

const deleteGroup = i => {
  const group = matched.value[i]
  if (group && group.matched && group.matched.length) {
    if (false === confirm(`Delete user group #${i + 1}?, Users will be moved to unmatched`)) {
      return
    }

    unmatched.value.push(...group.matched)
  }

  nextTick(() => matched.value.splice(i, 1))
}

const saveMap = async (no_toast = false) => {
  const data = formatData()

  if (!data.map.length) {
    if (!no_toast) {
      notification('error', 'Error', 'No mapping data to save.')
    }
    return true
  }

  try {
    const req = await request('/backends/mapper', {
      method: 'PUT',
      body: JSON.stringify(data)
    })

    const response = await parse_api_response(req)
    if (req.status >= 200 && req.status < 300 && !no_toast) {
      notification('success', 'Success', response.info.message)
      return true
    }

    if (!no_toast) {
      notification('error', 'Error', `${req.status}: ${response.error.message}`)
    }

    return false
  } catch (e) {
    notification('error', 'Error', `Error: ${e.message}`)
  }

  return false
}

const formatData = () => {
  const data = {version: "1.5", map: []}

  matched.value.forEach((group, i) => {
    const users = {}
    group?.matched.forEach(u => users[u.backend] = {name: u.username, options: toRaw(u.options)})

    if (Object.keys(users).length < 2) {
      return
    }

    data.map.push(users)
  })

  return toRaw(data)
}


const createUsers = async () => {
  if (!noSave.value) {
    const state = await saveMap()
    if (state === false) {
      return
    }
  }

  const command = ['backend:create']

  command.push(verbose.value ? '-vvv' : '-vv')

  command.push(recreate.value ? '--re-create' : '--run --update')

  if (backup.value) {
    command.push('--generate-backup')
  }

  if (dryRun.value) {
    command.push('--dry-run')
  }

  await navigateTo(makeConsoleCommand(command.join(' '), true))
}

const isSameName = (name1, name2) => name1.toLowerCase() === name2.toLowerCase()

const setUserPin = async user => {
  const pin = prompt(`Enter user PIN for '${user.backend}@${user.username}':`, user?.options?.PLEX_USER_PIN || '')
  if (null === pin) {
    return null;
  }

  if ('' === pin) {
    if (user?.options?.PLEX_USER_PIN) {
      delete user.options.PLEX_USER_PIN
    }
    return
  }

  if (pin === user?.options?.PLEX_USER_PIN) {
    console.log('PIN is the same, no changes made.')
    return
  }

  if (4 !== pin.length) {
    notification('error', 'Error', 'PIN must be at least 4 characters.')
    return
  }

  if (!user?.options) {
    user.options = {}
  }

  user.options.PLEX_USER_PIN = pin
}

const setClass = user => {
  if (!user?.protected) {
    return;
  }

  return user?.options?.PLEX_USER_PIN ? 'is-success' : 'is-danger'
}

const userWithNoPin = computed(() => {
  // -- get all users with protected flag
  const no_pin = []

  if (!matched.value.length) {
    return []
  }

  matched.value.forEach(group => group.matched.forEach(user => {
    if (!user?.protected) {
      return
    }

    console.log(user?.options?.PLEX_USER_PIN, user?.protected, user.username)

    if (!user?.options?.PLEX_USER_PIN) {
      no_pin.push(`${user.backend}@${user.username}`)
    }
  }))

  return no_pin
})
onMounted(async () => {
  if ('main' !== api_user.value) {
    notification('error', 'Error', 'The sub users page is only available for the main user.')
    await navigateTo({name: 'backends'})
    return
  }
  await loadContent()
})

</script>

<style scoped>
.draggable-item {
  display: inline-flex;
  align-items: center;
  padding: 4px 8px;
  background: #f1f1f1;
  cursor: move;
  border: 1px solid #ddd;
  border-radius: 4px;
  margin: .25rem;
}

.draggable-item.is-danger {
  border: var(--bulma-control-border-width) solid var(--bulma-danger);
}

.draggable-item.is-success {
  border: var(--bulma-control-border-width) solid var(--bulma-success);
}
</style>
