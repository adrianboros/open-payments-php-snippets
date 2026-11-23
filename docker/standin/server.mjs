// Stand-in Open Payments wallet address + auth server for local signature checks.
//
// The grant endpoint checks the signature the way Rafiki 1.x does
// (packages/auth/src/signature/middleware.ts): it rebuilds the URL from the
// Host header and the request path (Koa ctx.href), re-serialises the JSON body,
// and calls validateSignature from @interledger/http-signature-utils.
//
// Wallet addresses (host "wallet"):
//   /alice-root    authServer http://auth           (no path: Rafiki 1.x, Rhyza)
//   /alice-tenant  authServer http://auth/tenant-1  (path: Rafiki 2.x, test wallet)
//   /alice-port    authServer http://auth:3006      (no path, own port: Rafiki 1.x localenv)
// Grant routes (host "auth"): POST / and POST /tenant-1. Like fastify (Rhyza),
// a trailing slash is a different route.
import http from 'node:http'
import fs from 'node:fs'
import crypto from 'node:crypto'
import { validateSignature, validateSignatureHeaders } from '@interledger/http-signature-utils'

const KEY_ID = 'standin-key-1'
const keyDir = '/keys'
const pemPath = `${keyDir}/private.pem`
if (!fs.existsSync(pemPath)) {
  const { privateKey } = crypto.generateKeyPairSync('ed25519')
  fs.mkdirSync(keyDir, { recursive: true })
  fs.writeFileSync(pemPath, privateKey.export({ type: 'pkcs8', format: 'pem' }))
}
const pem = fs.readFileSync(pemPath, 'utf8')
fs.writeFileSync(
  `${keyDir}/standin.env`,
  `PRIVATE_KEY=${Buffer.from(pem).toString('base64')}\nKEY_ID=${KEY_ID}\n`
)
const jwk = {
  ...crypto.createPublicKey(crypto.createPrivateKey(pem)).export({ format: 'jwk' }),
  kid: KEY_ID,
  alg: 'EdDSA'
}

const wallets = {
  '/alice-root': 'http://auth',
  '/alice-tenant': 'http://auth/tenant-1',
  '/alice-port': 'http://auth:3006'
}
const grantRoutes = new Set(['/', '/tenant-1'])

function send(res, status, body) {
  res.writeHead(status, { 'content-type': 'application/json' })
  res.end(JSON.stringify(body))
}

async function handle(req, res, raw) {
  const host = (req.headers.host || '').split(':')[0]
  const path = new URL(req.url, 'http://x').pathname

  if (req.method === 'GET' && host === 'wallet') {
    if (path.endsWith('/jwks.json') && wallets[path.slice(0, -'/jwks.json'.length)]) {
      return send(res, 200, { keys: [jwk] })
    }
    const authServer = wallets[path]
    if (!authServer) return send(res, 404, { error: 'unknown wallet' })
    return send(res, 200, {
      id: `http://wallet${path}`,
      publicName: path.slice(1),
      assetCode: 'USD',
      assetScale: 2,
      authServer,
      resourceServer: 'http://wallet'
    })
  }

  if (req.method === 'POST' && host === 'auth') {
    if (!grantRoutes.has(path)) {
      console.log(`POST ${req.headers.host}${path} -> 404 (no route)`)
      return send(res, 404, { error: { code: 'not_found', description: 'no route' } })
    }
    let parsed
    try {
      parsed = raw ? JSON.parse(raw) : undefined
    } catch {
      return send(res, 400, { error: { code: 'invalid_request', description: 'invalid JSON' } })
    }
    const requestLike = {
      url: `http://${req.headers.host}${path}`,
      method: req.method,
      headers: JSON.parse(JSON.stringify(req.headers)),
      body: parsed ? JSON.stringify(parsed) : undefined
    }
    const sigInput = req.headers['signature-input'] || ''
    let ok = false
    let reason = ''
    try {
      if (!validateSignatureHeaders(requestLike)) reason = 'invalid signature headers'
      else ok = await validateSignature(jwk, requestLike)
      if (!ok && !reason) reason = 'invalid signature'
    } catch (e) {
      reason = `error: ${e.message}`
    }
    console.log(
      `POST ${req.headers.host}${path} server url=${requestLike.url} ` +
        `signature=${(req.headers.signature || '').slice(0, 12)}... -> ${ok ? 200 : 401} ${reason}`
    )
    console.log(`  signature-input: ${sigInput}`)
    if (!ok) return send(res, 401, { error: { code: 'invalid_client', description: reason } })
    const access = parsed?.access_token?.access ?? []
    return send(res, 200, {
      access_token: {
        value: crypto.randomUUID(),
        manage: `http://auth/token/${crypto.randomUUID()}`,
        expires_in: 600,
        access
      },
      continue: {
        access_token: { value: crypto.randomUUID() },
        uri: `http://auth/continue/${crypto.randomUUID()}`
      }
    })
  }

  send(res, 404, { error: 'not found' })
}

const server = (req, res) => {
  const chunks = []
  req.on('data', (c) => chunks.push(c))
  req.on('end', () =>
    handle(req, res, Buffer.concat(chunks).toString('utf8')).catch((e) =>
      send(res, 500, { error: e.message })
    )
  )
}
for (const port of [80, 3006]) http.createServer(server).listen(port)
console.log('standin listening on :80 and :3006')
