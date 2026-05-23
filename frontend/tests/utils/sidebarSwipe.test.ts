import { describe, expect, it } from 'bun:test'

import {
  IOS_NAVIGATION_EDGE_WIDTH,
  MOBILE_SIDEBAR_EDGE_WIDTH,
  canStartSidebarOpenSwipe,
  isAppleMobileTouchNavigator,
} from '~/utils/sidebarSwipe'

describe('sidebarSwipe', () => {
  it('detects apple mobile webkit navigators', () => {
    expect(
      isAppleMobileTouchNavigator({
        userAgent:
          'Mozilla/5.0 (iPhone; CPU iPhone OS 18_4 like Mac OS X) AppleWebKit/605.1.15 Version/18.4 Mobile/15E148 Safari/604.1',
        platform: 'iPhone',
        maxTouchPoints: 5,
      }),
    ).toBe(true)

    expect(
      isAppleMobileTouchNavigator({
        userAgent:
          'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15) AppleWebKit/605.1.15 Version/18.4 Mobile/15E148 Safari/604.1',
        platform: 'MacIntel',
        maxTouchPoints: 5,
      }),
    ).toBe(true)

    expect(
      isAppleMobileTouchNavigator({
        userAgent:
          'Mozilla/5.0 (Linux; Android 15) AppleWebKit/537.36 Chrome/147.0.0.0 Mobile Safari/537.36',
        platform: 'Linux armv8l',
        maxTouchPoints: 5,
      }),
    ).toBe(false)
  })

  it('reserves the ios navigation edge and starts the sidebar swipe just inside it', () => {
    const nav = {
      userAgent:
        'Mozilla/5.0 (iPhone; CPU iPhone OS 18_4 like Mac OS X) AppleWebKit/605.1.15 Version/18.4 Mobile/15E148 Safari/604.1',
      platform: 'iPhone',
      maxTouchPoints: 5,
    }

    expect(canStartSidebarOpenSwipe(IOS_NAVIGATION_EDGE_WIDTH, nav)).toBe(false)
    expect(canStartSidebarOpenSwipe(IOS_NAVIGATION_EDGE_WIDTH + 1, nav)).toBe(true)
    expect(canStartSidebarOpenSwipe(IOS_NAVIGATION_EDGE_WIDTH + MOBILE_SIDEBAR_EDGE_WIDTH, nav)).toBe(true)
    expect(canStartSidebarOpenSwipe(IOS_NAVIGATION_EDGE_WIDTH + MOBILE_SIDEBAR_EDGE_WIDTH + 1, nav)).toBe(
      false,
    )
  })

  it('keeps the original left-edge open band on non-apple mobile browsers', () => {
    const nav = {
      userAgent:
        'Mozilla/5.0 (Linux; Android 15) AppleWebKit/537.36 Chrome/147.0.0.0 Mobile Safari/537.36',
      platform: 'Linux armv8l',
      maxTouchPoints: 5,
    }

    expect(canStartSidebarOpenSwipe(1, nav)).toBe(true)
    expect(canStartSidebarOpenSwipe(MOBILE_SIDEBAR_EDGE_WIDTH, nav)).toBe(true)
    expect(canStartSidebarOpenSwipe(MOBILE_SIDEBAR_EDGE_WIDTH + 1, nav)).toBe(false)
  })
})
