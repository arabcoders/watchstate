<template>
  <main class="ws-auth-surface flex min-h-dvh items-center justify-center px-4 py-8 sm:px-6">
    <div class="w-full max-w-md space-y-6">
      <header class="space-y-3 text-center">
        <div
          class="mx-auto inline-flex size-14 items-center justify-center rounded-md border border-default bg-elevated/80 shadow-sm"
        >
          <img src="/images/logo_nobg.png" alt="WatchState" class="size-10 object-contain" />
        </div>
        <h1 class="text-2xl font-semibold text-highlighted">WatchState</h1>
        <p class="text-sm text-muted">Sync watch state across Plex, Jellyfin &amp; Emby.</p>
      </header>

      <UAlert
        v-if="error"
        color="error"
        variant="soft"
        icon="i-lucide-circle-alert"
        title="Authentication error"
        :description="error"
      >
        <template v-if="!hasAuthStatus" #actions>
          <UButton
            type="button"
            color="neutral"
            variant="outline"
            icon="i-lucide-refresh-cw"
            :loading="isInitializing"
            :disabled="isInitializing"
            @click="() => void initializeAuth()"
          >
            Retry
          </UButton>
        </template>
      </UAlert>

      <UCard :ui="cardUi">
        <template #header>
          <div class="flex items-center justify-between gap-3">
            <div class="space-y-1">
              <h2 class="text-base font-semibold text-highlighted">
                {{ signup ? 'Create an account' : 'Sign in' }}
              </h2>
              <p v-if="signup" class="text-sm text-toned">
                Create the first administrator account.
              </p>
            </div>
            <UTooltip :text="`Theme: ${colorMode.preference}`">
              <UButton
                type="button"
                color="neutral"
                variant="ghost"
                size="sm"
                square
                :icon="themeIcon"
                :aria-label="`Change theme (currently ${colorMode.preference})`"
                @click="cycleTheme"
              />
            </UTooltip>
          </div>
        </template>

        <div class="space-y-5">
          <form method="post" @submit.prevent="void form_validate()">
            <fieldset
              class="space-y-5"
              :disabled="isInitializing || isSubmitting || !hasAuthStatus"
            >
              <UFormField label="Username" name="username" required>
                <UInput
                  id="user-id"
                  ref="usernameInput"
                  v-model="user.username"
                  type="text"
                  name="username"
                  required
                  autocomplete="username"
                  icon="i-lucide-user-round"
                  placeholder="Username"
                  autofocus
                  class="w-full"
                />
              </UFormField>

              <UFormField label="Password" name="password" required>
                <UInput
                  id="user-password"
                  v-model="user.password"
                  :type="form_expose ? 'text' : 'password'"
                  name="password"
                  required
                  :autocomplete="signup ? 'new-password' : 'current-password'"
                  icon="i-lucide-lock-keyhole"
                  placeholder="Password"
                  class="w-full"
                >
                  <template #trailing>
                    <UTooltip :text="form_expose ? 'Hide password' : 'Show password'">
                      <UButton
                        type="button"
                        color="neutral"
                        variant="ghost"
                        square
                        size="xs"
                        :icon="form_expose ? 'i-lucide-eye-off' : 'i-lucide-eye'"
                        :aria-label="form_expose ? 'Hide password' : 'Show password'"
                        :aria-pressed="form_expose"
                        @click="form_expose = !form_expose"
                      />
                    </UTooltip>
                  </template>
                </UInput>
              </UFormField>

              <UFormField v-if="signup" label="Confirm password" name="confirmation" required>
                <UInput
                  v-model="confirmation"
                  :type="form_expose ? 'text' : 'password'"
                  name="confirmation"
                  required
                  autocomplete="new-password"
                  icon="i-lucide-lock-keyhole"
                  placeholder="Confirm password"
                  class="w-full"
                />
              </UFormField>

              <div
                class="grid gap-3"
                :class="!signup && oidcAvailable ? 'grid-cols-2' : 'grid-cols-1'"
              >
                <UButton
                  type="submit"
                  color="primary"
                  variant="solid"
                  block
                  size="md"
                  :icon="signup ? 'i-lucide-user-plus' : 'i-lucide-log-in'"
                  :loading="isInitializing || isSubmitting"
                  :disabled="isInitializing || isSubmitting || !hasAuthStatus"
                >
                  {{ signup ? 'Create account' : 'Sign in' }}
                </UButton>
                <UButton
                  v-if="!signup && oidcAvailable"
                  type="button"
                  color="neutral"
                  variant="outline"
                  block
                  size="md"
                  icon="i-lucide-key-round"
                  aria-label="Sign in with OIDC"
                  :disabled="isInitializing || isSubmitting || !hasAuthStatus"
                  @click="auth.startOidcLogin"
                >
                  SSO
                </UButton>
              </div>
            </fieldset>
          </form>

          <UButton
            v-if="!signup"
            type="button"
            color="neutral"
            variant="link"
            block
            :disabled="isInitializing || isSubmitting || !hasAuthStatus"
            @click="forgot_password = true"
          >
            Forgot your credentials?
          </UButton>
        </div>
      </UCard>

      <UModal
        v-model:open="forgot_password"
        title="Reset login credentials"
        description="Run this command on the Docker host, then create a new account."
      >
        <template #body>
          <div class="space-y-4">
            <div class="relative rounded-md border border-default bg-elevated/60 p-3 pr-14">
              <code class="block whitespace-pre-wrap break-all font-mono text-sm text-default">{{
                reset_cmd
              }}</code>
              <UTooltip text="Copy reset command">
                <UButton
                  type="button"
                  color="neutral"
                  variant="ghost"
                  size="sm"
                  icon="i-lucide-copy"
                  class="absolute right-2 top-2"
                  aria-label="Copy reset command"
                  @click="() => copyText(reset_cmd)"
                />
              </UTooltip>
            </div>
            <UAlert
              color="warning"
              variant="soft"
              icon="i-lucide-triangle-alert"
              title="Existing sessions will be signed out"
              description="Other configuration settings are kept."
            />
            <p
              v-if="polling_timer"
              role="status"
              class="inline-flex items-center gap-2 text-sm text-toned"
            >
              <UIcon name="i-lucide-loader-circle" class="size-4 animate-spin" />
              Waiting for credentials to be reset.
            </p>
          </div>
        </template>
      </UModal>
    </div>
  </main>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { navigateTo, useHead } from '#app';
import { definePageMeta, useColorMode } from '#imports';
import { UAlert, UButton, UCard, UFormField, UIcon, UInput, UModal, UTooltip } from '#components';
import { useAuth } from '~/composables/useAuth';
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

const auth = useAuth();
const { authenticated, oidcAvailable } = auth;
const colorMode = useColorMode();
const isInitializing = ref(false);
const hasAuthStatus = ref(false);
const isSubmitting = ref(false);
const forgot_password = ref<boolean>(false);
const polling_timer = ref<ReturnType<typeof setInterval> | null>(null);
let disposed = false;
let checkingReset = false;

const user = ref<UserCredentials>({ username: '', password: '' });
const usernameInput = ref<{ inputRef: HTMLInputElement | null } | null>(null);
const confirmation = ref('');
const reset_cmd = ref<string>('docker exec -ti -- watchstate console system:resetpassword');

const cardUi = {
  root: 'bg-elevated/40 shadow-xl backdrop-blur-sm',
  header: 'px-5 py-4 sm:px-6',
  body: 'p-5 sm:p-6',
};

const themeIcon = computed(() =>
  'light' === colorMode.preference
    ? 'i-lucide-sun'
    : 'dark' === colorMode.preference
      ? 'i-lucide-moon'
      : 'i-lucide-monitor',
);

const cycleTheme = (): void => {
  const preferences = ['system', 'light', 'dark'];
  const current = preferences.indexOf(colorMode.preference);
  colorMode.preference = preferences[(current + 1) % preferences.length] ?? 'system';
};

const startPolling = (): void => {
  if (null !== polling_timer.value) {
    clearInterval(polling_timer.value);
  }

  polling_timer.value = setInterval(async (): Promise<void> => {
    if (disposed || checkingReset || !forgot_password.value) {
      return;
    }

    checkingReset = true;
    try {
      const hasUser: boolean = await auth.has_user(true);
      if (disposed || !forgot_password.value) {
        return;
      }
      if (false === hasUser) {
        stopPolling();
        forgot_password.value = false;
        signup.value = true;
        notification('info', 'Ready', 'No users found. You can now create a new account.');
      }
    } catch (e) {
      if (!disposed && forgot_password.value) {
        console.log('Polling error:', e);
      }
    } finally {
      checkingReset = false;
    }
  }, 3000);
};

const stopPolling = (): void => {
  if (null !== polling_timer.value) {
    clearInterval(polling_timer.value);
    polling_timer.value = null;
  }
};

const initializeAuth = async (): Promise<void> => {
  if (disposed || isInitializing.value) {
    return;
  }

  isInitializing.value = true;
  hasAuthStatus.value = false;
  error.value = '';

  try {
    const hash = window.location.hash;
    const oidcCode = new URLSearchParams(hash.slice(1)).get('oidc_code');
    if (oidcCode) {
      window.history.replaceState(null, '', window.location.pathname + window.location.search);
      try {
        const exchanged = await auth.exchangeOidc(oidcCode);
        if (disposed) {
          return;
        }
        if (exchanged) {
          notification('success', 'Success', 'Login successful. Redirecting...');
          await navigateTo('/');
          return;
        }
        throw new Error('OIDC login failed.');
      } catch (e: unknown) {
        if (disposed) {
          return;
        }
        error.value = e instanceof Error ? e.message : 'OIDC login failed.';
        notification('error', 'Authentication error', error.value);
      }
    }

    const hasUser = await auth.has_user();
    if (disposed) {
      return;
    }
    signup.value = false === hasUser;
    hasAuthStatus.value = true;
    if (authenticated.value) {
      await navigateTo('/');
    }
  } catch (e: unknown) {
    if (!disposed) {
      error.value = e instanceof Error ? e.message : 'Authentication status could not be loaded.';
    }
  } finally {
    isInitializing.value = false;
    await nextTick();
    if (!disposed && hasAuthStatus.value && !forgot_password.value) {
      usernameInput.value?.inputRef?.focus();
    }
  }
};

onMounted(() => void initializeAuth());

onBeforeUnmount(() => {
  disposed = true;
  stopPolling();
});

watch(forgot_password, (newValue: boolean): void => {
  if (false === newValue) {
    stopPolling();
    void initializeAuth();
    return;
  }
  error.value = '';
  startPolling();
});

const form_validate = async (): Promise<boolean> => {
  if (
    disposed ||
    isInitializing.value ||
    isSubmitting.value ||
    !hasAuthStatus.value ||
    forgot_password.value
  ) {
    return false;
  }

  error.value = '';
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

  if (signup.value && user.value.password !== confirmation.value) {
    error.value = "Passwords don't match.";
    return false;
  }

  isSubmitting.value = true;
  try {
    return signup.value ? await do_signup() : await do_login();
  } finally {
    isSubmitting.value = false;
  }
};

const do_login = async (): Promise<boolean> => {
  try {
    await auth.login(user.value.username, user.value.password);
    if (disposed) {
      return false;
    }
    if (authenticated.value) {
      notification('success', 'Success', 'Login successful. Redirecting...');
      await navigateTo('/');
      return true;
    }
    throw new Error('Login failed. Please check your username and password.');
  } catch (e: unknown) {
    if (!disposed) {
      error.value = e instanceof Error ? e.message : 'Login failed.';
    }
    return false;
  }
};

const do_signup = async (): Promise<boolean> => {
  try {
    const state: boolean = await auth.signup(user.value.username, user.value.password);
    if (disposed) {
      return false;
    }
    if (false === state) {
      error.value = 'Failed to create an account.';
      return false;
    }
    return await do_login();
  } catch (e: unknown) {
    if (!disposed) {
      error.value = e instanceof Error ? e.message : 'Failed to create an account.';
    }
    return false;
  }
};
</script>

<style scoped>
.ws-auth-surface {
  background:
    radial-gradient(
      circle at top left,
      color-mix(in oklab, var(--ui-primary) 12%, transparent),
      transparent 28%
    ),
    radial-gradient(
      circle at top right,
      color-mix(in oklab, var(--ui-secondary) 9%, transparent),
      transparent 22%
    ),
    linear-gradient(180deg, var(--ui-bg), var(--ui-bg-elevated));
}
</style>
