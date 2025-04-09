<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <span id="env_page_title" class="title is-4">
          <span class="icon"><i class="fas fa-users"/></span>
          Create Sub-users
        </span>
        <div class="is-pulled-right">
          <div class="field is-grouped">
            <p class="control">
              <button class="button is-purple" v-tooltip.bottom="'Export Association.'" @click="exportMapping">
                <span class="icon"><i class="fas fa-file-export"/></span>
              </button>
            </p>
            <p class="control">
              <button class="button is-primary" v-tooltip.bottom="'Create new user assoication.'" @click="addNewUser">
                <span class="icon"><i class="fas fa-plus"></i></span>
              </button>
            </p>
            <p class="control">
              <button class="button is-info" @click="loadContent" :disabled="isLoading"
                      :class="{'is-loading':isLoading}">
                <span class="icon"><i class="fas fa-sync"/></span>
              </button>
            </p>
          </div>
        </div>
        <div class="is-hidden-mobile">
          <span class="subtitle">
            Drag & Drop the relevant users accounts to form association.
          </span>
        </div>
      </div>

      <div class="column is-12">
        <h2 class="title is-4">Matched users</h2>
      </div>

      <div class="column is-12" v-for="(group, index) in matchedUsers" :key="index">
        <div class="card">
          <header class="card-header is-block">
            <div class="control has-icons-left">
              <input type="text" class="input is-fullwidth" v-model="group.user" required>
              <span class="icon is-left"><i class="fas fa-user"/></span>
            </div>
          </header>
          <div class="card-content">
            <draggable v-model="group.matched" :group="{ name: 'shared', pull: true, put: true }" animation="150"
                       item-key="id">
              <template #item="{ element }">
                <div class="draggable-item">
                  <span>{{ element.backend }}@{{ element.username }}</span>
                </div>
              </template>
            </draggable>
          </div>
        </div>
      </div>

      <div class="column is-12">
        <h2 class="title is-4">Users with no association.</h2>
      </div>

      <div class="column is-12">
        <div class="card">
          <header class="card-header is-block">
            <p class="card-header-title is-text-overflow">Users with no association.</p>
          </header>
          <div class="card-content">
            <draggable v-model="unmatched" :group="{ name: 'shared', pull: true, put: true }" animation="150"
                       item-key="id">
              <template #item="{ element }">
                <div class="draggable-item">
                  <span>{{ element.backend }}@{{ element.username }}</span>
                </div>
              </template>
            </draggable>
          </div>
          <div v-if="unmatched?.length <1">
            <Message message_class="has-background-success-90 has-text-dark" icon="fas fa-check-circle">
              <p>
                <span class="icon"><i class="fas fa-check"/></span>
                <span>All users are associated.</span>
              </p>
            </Message>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>

const data = {
  "matched": [
    {
      "user": "user1",
      "matched": [
        {
          "id": "u1a", "backend": "backend_name1", "username": "user_u1a"
        },
        {
          "id": "u1b", "backend": "backend_name2", "username": "user_u1b"
        },
        {
          "id": "u1c", "backend": "backend_name3", "username": "user_u1c"
        }
      ]
    },
    {
      "user": "user2",
      "matched": [
        {
          "id": "u2a", "backend": "backend_name1", "username": "user_u2a"
        },
        {
          "id": "u2b", "backend": "backend_name2", "username": "user_u2b"
        },
        {
          "id": "u2c", "backend": "backend_name3", "username": "user_u2c"
        }
      ]
    },
    {
      "user": "user3",
      "matched": [
        {
          "id": "u3a", "backend": "backend_name1", "username": "user_u3a"
        },
        {
          "id": "u3b", "backend": "backend_name2", "username": "user_u3b"
        },
        {
          "id": "u3c", "backend": "backend_name3", "username": "user_u3c"
        }
      ]
    }
  ],
  "unmatched": [
    {
      "id": "u4a", "backend": "backend_name1", "username": "user_u4a"
    },
    {
      "id": "u4b", "backend": "backend_name2", "username": "user_u4b"
    },
    {
      "id": "u4c", "backend": "backend_name3", "username": "user_u4c"
    }
  ]
}

const matchedUsers = ref(data.matched)
const unmatched = ref(data.unmatched)

// Function to add a new matched group with a default name
const addNewUser = () => {
  const newUserName = 'user ' + (matchedUsers.value.length + 1)
  matchedUsers.value.push({
    user: newUserName,
    matched: []
  })
}

</script>

<style scoped>
table {
  margin-bottom: 1em;
}

th, td {
  padding: 8px;
  text-align: left;
}

/* Make containers flex so items wrap side by side */
.users-list,
.unmatched-container {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  padding: 8px;
  border: 1px dashed #ccc;
  background-color: #fafafa;
}

/* Let draggable items size to content */
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
</style>
