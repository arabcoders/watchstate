<template>
  <div class="flex min-h-screen items-center justify-center px-4 py-8 sm:px-6 lg:px-8">
    <div class="w-full max-w-md space-y-6">
      <div class="space-y-3 text-center">
        <div
          class="mx-auto inline-flex size-14 items-center justify-center rounded-md border border-default bg-elevated/80 shadow-sm"
        >
          <img src="/images/logo_nobg.png" alt="WatchState" class="size-10 object-contain" />
        </div>
        <div>
          <h1 class="text-2xl font-semibold text-highlighted">WatchState</h1>
          <p class="mt-1 text-sm text-toned">
            {{
              forgot_password
                ? 'Reset access from the host first.'
                : signup
                  ? 'Create the first admin account.'
                  : 'Sign in to continue.'
            }}
          </p>
        </div>
      </div>

      <UAlert
        v-if="error"
        color="error"
        variant="soft"
        icon="i-lucide-circle-alert"
        title="Authentication error"
        :description="error"
      />

      <UCard v-if="forgot_password" :ui="cardUi">
        <template #header>
          <div class="flex items-start gap-3">
            <UIcon
              :name="polling_timer ? 'i-lucide-loader-circle' : 'i-lucide-lock-keyhole'"
              class="mt-0.5 size-5 shrink-0 text-primary"
              :class="polling_timer ? 'animate-spin' : ''"
            />
            <div>
              <h2 class="text-base font-semibold text-highlighted">How to reset system login</h2>
              <p class="mt-1 text-sm text-toned">
                Run this command on the docker host, then return here to create a new account.
              </p>
            </div>
          </div>
        </template>

        <div class="space-y-4">
          <div class="relative rounded-md border border-default bg-elevated/60 p-3 pr-14">
            <code class="ws-terminal ws-terminal-panel whitespace-pre-wrap text-sm">{{
              reset_cmd
            }}</code>
            <UButton
              color="neutral"
              variant="ghost"
              size="sm"
              icon="i-lucide-copy"
              class="absolute right-2 top-2"
              aria-label="Copy reset command"
              @click="() => copyText(reset_cmd)"
            />
          </div>

          <UAlert
            color="warning"
            variant="soft"
            icon="i-lucide-triangle-alert"
            title="Important"
            description="You can also reset the password by removing the .env file inside the config directory."
          />

          <UAlert
            v-if="polling_timer"
            color="info"
            variant="soft"
            icon="i-lucide-loader-circle"
            title="Checking for reset completion"
            description="Waiting for the system to report that no users exist."
            :ui="{ icon: 'animate-spin' }"
          />
        </div>

        <template #footer>
          <UButton
            type="button"
            color="neutral"
            variant="outline"
            block
            icon="i-lucide-arrow-left"
            @click="forgot_password = false"
          >
            Back to login
          </UButton>
        </template>
      </UCard>

      <UCard v-else :ui="cardUi">
        <template #header>
          <div class="space-y-1">
            <h2 class="text-base font-semibold text-highlighted">
              {{ signup ? 'Create an account' : 'Login' }}
            </h2>
            <p class="text-sm text-toned" v-if="signup">This is the first user for the instance.</p>
          </div>
        </template>

        <form method="post" class="space-y-4" @submit.prevent="void form_validate()">
          <input
            type="text"
            class="hidden"
            name="username"
            autocomplete="username"
            :value="user.username"
          />

          <UFormField label="Username" name="username" required>
            <UInput
              id="user-id"
              v-model="user.username"
              class="w-full"
              placeholder="Username"
              autocomplete="username"
              autofocus
            />
          </UFormField>

          <UFormField label="Password" name="password" required>
            <div class="flex items-center gap-2">
              <UInput
                id="user-password"
                v-model="user.password"
                class="w-full"
                :type="form_expose ? 'text' : 'password'"
                placeholder="Password"
                autocomplete="current-password"
              />

              <UButton
                type="button"
                color="neutral"
                variant="outline"
                :icon="form_expose ? 'i-lucide-eye-off' : 'i-lucide-eye'"
                :aria-label="form_expose ? 'Hide password' : 'Show password'"
                class="whitespace-nowrap"
                @click="form_expose = !form_expose"
              />
            </div>
          </UFormField>

          <div class="space-y-2 pt-1">
            <UButton type="submit" color="primary" block icon="i-lucide-log-in">
              {{ signup ? 'Signup' : 'Login' }}
            </UButton>

            <UButton
              v-if="!signup"
              type="button"
              color="neutral"
              variant="soft"
              block
              icon="i-lucide-key-round"
              @click="forgot_password = true"
            >
              Forgot your credentials?
            </UButton>
          </div>
        </form>
      </UCard>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { navigateTo, useHead } from '#app';
import { useAuthStore } from '~/store/auth';
import { copyText, notification } from '~/utils';

type UserCredentials = {
  username: string;
  password: string;
};

definePageMeta({ name: 'auth', layout: 'guest' });
useHead({ title: 'WatchState: Auth' });

const error = ref<string>('');
const form_expose = ref<boolean>(false);
const signup = ref<boolean>(false);

const auth = useAuthStore();
const forgot_password = ref<boolean>(false);
const polling_timer = ref<NodeJS.Timeout | null>(null);

const user = ref<UserCredentials>({ username: '', password: '' });
const reset_cmd = ref<string>('docker exec -ti -- watchstate console system:resetpassword');

const cardUi = {
  root: 'bg-elevated/40 shadow-xl backdrop-blur-sm',
  header: 'px-5 py-4 sm:px-6',
  body: 'px-5 pb-5 sm:px-6 sm:pb-6',
  footer: 'px-5 py-4 sm:px-6',
};

const startPolling = (): void => {
  if (null !== polling_timer.value) {
    clearInterval(polling_timer.value);
  }

  polling_timer.value = setInterval(async (): Promise<void> => {
    try {
      const hasUser: boolean = await auth.has_user(true);
      if (false === hasUser) {
        stopPolling();
        forgot_password.value = false;
        signup.value = true;
        notification('info', 'Ready', 'No users found. You can now create a new account.');
      }
    } catch (e) {
      console.log('Polling error:', e);
    }
  }, 3000);
};

const stopPolling = (): void => {
  if (null !== polling_timer.value) {
    clearInterval(polling_timer.value);
    polling_timer.value = null;
  }
};

onMounted(async (): Promise<void> => {
  signup.value = false === (await auth.has_user());
  if (auth.authenticated) {
    await navigateTo('/');
  }
});

onBeforeUnmount((): void => stopPolling());

watch(forgot_password, async (newValue: boolean): Promise<void> => {
  if (false === newValue) {
    stopPolling();
    signup.value = false === (await auth.has_user());
    return;
  }
  error.value = '';
  startPolling();
});

const form_validate = async (): Promise<boolean> => {
  if (user.value.username.length < 1) {
    error.value = 'Username is required.';
    return false;
  }

  if (user.value.password.length < 1) {
    error.value = 'Password is required.';
    return false;
  }

  if (false === /^[a-z_0-9]+$/.test(user.value.username)) {
    error.value = 'Username can only contain lowercase letters, numbers and underscores.';
    return false;
  }

  if (signup.value) {
    return await do_signup();
  }

  return await do_login();
};

const do_login = async (): Promise<boolean> => {
  try {
    await auth.login(user.value.username, user.value.password);
    if (auth.authenticated) {
      notification('success', 'Success', 'Login successful. Redirecting...');
      await navigateTo('/');
      return true;
    }
    throw new Error('Login failed. Please check your username and password.');
  } catch (e) {
    console.log(e);
    error.value = (e as Error).message;
    return false;
  }
};

const do_signup = async (): Promise<boolean> => {
  try {
    const state: boolean = await auth.signup(user.value.username, user.value.password);
    if (false === state) {
      error.value = 'Failed to create an account.';
      return false;
    }
    return await do_login();
  } catch (e) {
    console.log(e);
    error.value = (e as Error).message;
    return false;
  }
};
</script>
