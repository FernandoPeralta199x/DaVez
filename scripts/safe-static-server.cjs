const fs = require("node:fs");
const http = require("node:http");
const path = require("node:path");

const projectRoot = path.resolve(__dirname, "..");
const host = "127.0.0.1";
const port = Number.parseInt(process.env.DAVEZ_STATIC_PORT || "4174", 10);

const publicFiles = new Map([
  ["/", "index.html"],
  ["/index.html", "index.html"],
  ["/manifest.json", "manifest.json"],
  ["/service-worker.js", "service-worker.js"],
  ["/icons/icon-192.png", "icons/icon-192.png"],
  ["/icons/icon-512.png", "icons/icon-512.png"],
  ["/icons/icon-512-v2.png", "icons/icon-512-v2.png"],
  ["/img/logo.png", "img/logo.png"],
]);

const contentTypes = new Map([
  [".html", "text/html; charset=utf-8"],
  [".json", "application/manifest+json; charset=utf-8"],
  [".js", "text/javascript; charset=utf-8"],
  [".png", "image/png"],
]);

const server = http.createServer((request, response) => {
  const pathname = new URL(request.url, `http://${host}:${port}`).pathname;
  const relativePath = publicFiles.get(pathname);

  response.setHeader("X-Content-Type-Options", "nosniff");
  response.setHeader("Referrer-Policy", "no-referrer");
  response.setHeader("Cache-Control", "no-store");

  if (!relativePath) {
    response.writeHead(503, { "Content-Type": "application/json; charset=utf-8" });
    response.end(JSON.stringify({ ok: false, error: "Endpoint desativado no servidor estático de QA." }));
    return;
  }

  const filePath = path.join(projectRoot, relativePath);
  const extension = path.extname(filePath);

  if (pathname === "/service-worker.js") {
    response.setHeader("Service-Worker-Allowed", "/");
  }

  response.writeHead(200, {
    "Content-Type": contentTypes.get(extension) || "application/octet-stream",
  });
  fs.createReadStream(filePath).pipe(response);
});

server.listen(port, host, () => {
  console.log(`DaVez static QA server: http://${host}:${port}/`);
});
