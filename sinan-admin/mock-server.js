// ============================================================
// 司南珍藏 · 管理后台预览服务器（真实后端联调版）
// - 托管 dist/ 构建产物（静态资源）
// - /api/** 请求反向代理到真实 PHP 后端（http://127.0.0.1:8080），去掉 /api 前缀
// - 用于前端与真实 ThinkPHP 后端联调
// ============================================================
import http from 'node:http'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const DIST = path.join(__dirname, 'dist')
const PORT = process.env.PORT ? Number(process.env.PORT) : 5174
const BACKEND = process.env.VITE_PROXY_TARGET || 'http://127.0.0.1:8080'

const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'application/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.gif': 'image/gif',
  '.svg': 'image/svg+xml',
  '.ico': 'image/x-icon',
  '.woff': 'font/woff',
  '.woff2': 'font/woff2',
  '.ttf': 'font/ttf',
  '.map': 'application/json'
}

const NO_CACHE = { 'Cache-Control': 'no-cache, no-store, must-revalidate', 'Pragma': 'no-cache' }

// ---------- 反向代理 /api/** → 真实后端 ----------
function proxyToBackend(req, res) {
  const backendUrl = new URL(BACKEND)
  // 去掉 /api 前缀：/api/admin/auth/login → /admin/auth/login
  const targetPath = req.url.replace(/^\/api/, '')
  const options = {
    hostname: backendUrl.hostname,
    port: backendUrl.port,
    path: targetPath,
    method: req.method,
    headers: { ...req.headers, host: backendUrl.host }
  }

  const proxyReq = http.request(options, (proxyRes) => {
    res.writeHead(proxyRes.statusCode, proxyRes.headers)
    proxyRes.pipe(res)
  })

  proxyReq.on('error', (err) => {
    console.error('[proxy error]', targetPath, err.message)
    res.writeHead(502, { 'Content-Type': 'application/json; charset=utf-8', ...NO_CACHE })
    res.end(JSON.stringify({ code: -1, message: `后端连接失败：${err.message}`, data: null }))
  })

  req.pipe(proxyReq)
}

// ---------- 静态文件服务 ----------
function serveStatic(req, res) {
  let urlPath = decodeURIComponent(req.url.split('?')[0])
  if (urlPath === '/') urlPath = '/index.html'

  // API 请求由代理处理
  if (urlPath.startsWith('/api/')) return false

  const filePath = path.join(DIST, urlPath)
  if (!filePath.startsWith(DIST)) {
    res.writeHead(403, NO_CACHE); res.end('Forbidden'); return true
  }

  fs.stat(filePath, (err, stat) => {
    if (err || !stat.isFile()) {
      // SPA 回退到 index.html
      const indexPath = path.join(DIST, 'index.html')
      fs.readFile(indexPath, (e, data) => {
        if (e) { res.writeHead(404, NO_CACHE); res.end('Not Found'); return }
        res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', ...NO_CACHE })
        res.end(data)
      })
      return
    }
    const ext = path.extname(filePath).toLowerCase()
    fs.readFile(filePath, (e, data) => {
      if (e) { res.writeHead(500, NO_CACHE); res.end('Internal Error'); return }
      res.writeHead(200, { 'Content-Type': MIME[ext] || 'application/octet-stream', ...NO_CACHE })
      res.end(data)
    })
  })
  return true
}

// ---------- HTTP 服务器 ----------
const server = http.createServer((req, res) => {
  // /api/** → 反向代理到真实后端
  if (req.url.startsWith('/api/')) {
    return proxyToBackend(req, res)
  }
  // 其余 → 静态资源
  serveStatic(req, res)
})

server.listen(PORT, '0.0.0.0', () => {
  console.log(`\n  司南珍藏 · 管理后台（真实后端联调版）`)
  console.log(`  前端预览: http://localhost:${PORT}`)
  console.log(`  后端代理: ${BACKEND}`)
  console.log(`  默认账号: admin / admin123\n`)
})
