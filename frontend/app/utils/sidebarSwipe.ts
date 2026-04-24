type SidebarSwipeMode = 'open' | 'close';

type NavigatorLike = {
  userAgent?: string;
  platform?: string;
  maxTouchPoints?: number;
};

const MOBILE_SIDEBAR_EDGE_WIDTH = 32;
const IOS_NAVIGATION_EDGE_WIDTH = 24;

const isAppleMobileTouchNavigator = (nav?: NavigatorLike): boolean => {
  if (!nav) {
    return false;
  }

  const userAgent = nav.userAgent ?? '';
  const platform = nav.platform ?? '';
  const maxTouchPoints = Number(nav.maxTouchPoints ?? 0);
  const isiPhoneLike = /(iPhone|iPod|iPad)/i.test(userAgent);
  const isiPadDesktopMode = platform === 'MacIntel' && maxTouchPoints > 1;

  return /AppleWebKit/i.test(userAgent) && (isiPhoneLike || isiPadDesktopMode);
};

const canStartSidebarOpenSwipe = (
  touchX: number,
  nav?: NavigatorLike,
  edgeWidth: number = MOBILE_SIDEBAR_EDGE_WIDTH,
): boolean => {
  const reservedEdge = isAppleMobileTouchNavigator(nav) ? IOS_NAVIGATION_EDGE_WIDTH : 0;
  return touchX > reservedEdge && touchX <= reservedEdge + edgeWidth;
};

const getSidebarSwipeMode = (
  sidebarOpen: boolean,
  touchX: number,
  nav?: NavigatorLike,
): SidebarSwipeMode | null => {
  if (sidebarOpen) {
    return 'close';
  }

  return canStartSidebarOpenSwipe(touchX, nav) ? 'open' : null;
};

export {
  MOBILE_SIDEBAR_EDGE_WIDTH,
  IOS_NAVIGATION_EDGE_WIDTH,
  canStartSidebarOpenSwipe,
  getSidebarSwipeMode,
  isAppleMobileTouchNavigator,
};
export type { NavigatorLike, SidebarSwipeMode };
