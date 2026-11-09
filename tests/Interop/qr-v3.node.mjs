import { readFileSync } from 'node:fs'
import { createPublicKey, verify, constants } from 'node:crypto'
import assert from 'node:assert/strict'
import test from 'node:test'

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/qr-v3/rs256-vectors.json', import.meta.url), 'utf8'))
const publicKey = createPublicKey(fixture.public_key_pem)
const reject = (reason) => { throw new Error(reason) }
const exact = (object, keys) => object && !Array.isArray(object) &&
  Object.keys(object).length === keys.length && keys.every((key) => Object.hasOwn(object, key))
const decode = (part) => {
  if (!/^[A-Za-z0-9_-]+$/.test(part) || part.length % 4 === 1) reject('FORMAT_INVALID')
  const bytes = Buffer.from(part, 'base64url')
  if (bytes.toString('base64url') !== part) reject('FORMAT_INVALID')
  return bytes
}
function check(entry) {
  const parts = entry.token.split('.')
  if (parts.length !== 3 || entry.token.length > 4096) reject('FORMAT_INVALID')
  const header = JSON.parse(decode(parts[0]).toString('utf8'))
  if (!exact(header, ['alg', 'kid', 'typ']) || header.alg !== 'RS256' ||
      header.typ !== 'eida-entry-qr+jwt') reject('HEADER_INVALID')
  if (header.kid !== fixture.kid) reject('UNKNOWN_KID')
  if (!verify('sha256', Buffer.from(parts[0] + '.' + parts[1], 'ascii'),
      { key: publicKey, padding: constants.RSA_PKCS1_PADDING }, decode(parts[2]))) reject('SIGNATURE_INVALID')
  const payload = JSON.parse(decode(parts[1]).toString('utf8'))
  if (!exact(payload, ['v', 'jti', 'sub', 'exam_id', 'iss', 'aud', 'iat', 'nbf', 'exp']) ||
      payload.v !== 3 || payload.iss !== fixture.issuer || payload.aud !== fixture.audience ||
      ![payload.iat, payload.nbf, payload.exp].every(Number.isSafeInteger)) reject('CLAIMS_INVALID')
  if (payload.sub !== String(entry.student_id) || payload.exam_id !== String(entry.exam_id)) reject('SUBJECT_EXAM_MISMATCH')
  if (payload.iat > entry.now || payload.nbf > entry.now || payload.exp <= entry.now) reject('TIME_INVALID')
  return payload
}
for (const entry of fixture.cases) {
  test('PHP → Node RS256: ' + entry.name, () => {
    if (entry.valid) assert.deepEqual(check(entry), fixture.claims)
    else assert.throws(() => check(entry), { message: entry.reason })
  })
}

