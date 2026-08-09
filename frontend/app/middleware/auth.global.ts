import { abortNavigation, defineNuxtRouteMiddleware, navigateTo } from '#app';
import type { RouteLocationNormalized } from 'vue-router';
import { useAuth } from '~/composables/useAuth';

let next_check = 0;

const getNextCheckAt = (expiresAt: string | null): number => {
  const defaultNextCheck = Date.now() + 1000 * 60 * 5;

  if (!expiresAt) {
    return defaultNextCheck;
  }

  const expiresAtMs = Date.parse(expiresAt);
  if (Number.isNaN(expiresAtMs)) {
    return defaultNextCheck;
  }

  return Math.min(defaultNextCheck, expiresAtMs - 60_000);
};

export default defineNuxtRouteMiddleware(async (to: RouteLocationNormalized) => {
  if (to.fullPath.startsWith('/auth') || to.fullPath.startsWith('/v1/api')) {
    return;
  }

  const auth = useAuth();
  const { authenticated, expiresAt, token } = auth;

  if (token.value) {
    if (Date.now() > next_check) {
      console.debug('Validating user token...');
      if (!(await auth.validate())) {
        token.value = null;
        abortNavigation();
        console.error('Token is invalid, redirecting to login page...');
        return navigateTo('/auth');
      }
      console.debug('Token is valid.');
      next_check = getNextCheckAt(expiresAt.value);
    }
    authenticated.value = true;
  }

  if (!token.value && to?.name !== 'auth') {
    abortNavigation();
    return navigateTo('/auth');
  }
});
