import { afterEach, describe, expect, it } from 'bun:test'

import { signBody } from '~/utils/signBody'

const originalCrypto = globalThis.crypto

afterEach(() => {
  globalThis.crypto = originalCrypto
})

describe('signature', () => {
  it('signs request bodies with sha256 hmac', async () => {
    expect(await signBody('{"command":"system:tasks"}', 'secret')).toBe(
      'sha256=b4828cd87b2d7c1cd6cf9d9ab3e8d035e5025559d113ef09476179448165b5f8',
    )
  })

  it('falls back when subtle crypto is unavailable', async () => {
    globalThis.crypto = {} as Crypto

    expect(await signBody('{"command":"system:tasks"}', 'secret')).toBe(
      'sha256=b4828cd87b2d7c1cd6cf9d9ab3e8d035e5025559d113ef09476179448165b5f8',
    )
  })
})
