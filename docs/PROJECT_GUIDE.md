# z-coc 项目守则（总纲）

> 版本：v1.3.0 ｜ 生成日期：2026-10-09
> 本文件是 z-coc 的「读它就够」入口。**改动任何模块前，先读本文件对应章节**，避免全量扫描源码（主 `index.html` 有 9555 行）。
> 拆分/重构任务清单见同目录 [`SPLIT_PLAN.md`](./SPLIT_PLAN.md)。
> 行号为 v1.3.0 快照，重构后会漂移，以符号名/注释为准。

---

## 0. 一句话概括

z-coc（Zarkto's Table）是一个**《克苏鲁的呼唤》(CoC) TRPG 跑团辅助 Web 应用**：三套单体前端页面 + 一套 PHP/MySQL 后端，外加 Capacitor Android 壳。核心玩法是 AI 主持（KP）跑团、角色卡生成、多人房间同步、模组库共享。

---

## 1. 技术栈

| 层 | 技术 | 关键点 |
|---|---|---|
| 前端 | Vue 3.5.13（**CDN 全局版**，非 SFC） | 三页各自 `createApp({ setup(){…} })`，模板 in-DOM 写死在 HTML 里 |
| 前端工具 | marked / DOMPurify / mammoth / FileSaver / html-docx-js | 均为 CDN 全局变量，无 `import` |
| 样式 | Tailwind 3.4.17 + daisyUI 4.7.2 + 三页各自内联 `<style>` | 构建产物 `assets/css/tailwind.css` **已提交入库** |
| 存储 | localStorage + IndexedDB + 云端(PHP) | 见 §5.2、§5.3 |
| 后端 | PHP 8.3 + Apache + MySQL 8 | `?action=` 路由，无框架、无 composer |
| 移动端 | Capacitor 8（Android） | 本地打包 `www/`，API 仍指向生产 `https://z-coc.zeabur.app` |
| 构建 | pnpm 脚本 | 仅 Tailwind 构建 + Capacitor 打包，**无 JS 打包器** |
| 部署 | Docker（php:8.3-apache）→ Zeabur | 容器端口 8080 |

---

## 2. 快速导航：要改 X，就读 Y

| 我想改… | 去这里 |
|---|---|
| 跑团主界面 / 聊天 / 掷骰 / AI 生成 | `index.html`（主 SPA） |
| 角色卡生成器 | `Workshop/index.html` |
| 模组库 / 藏书 | `Library/index.html` + `library_api.php` |
| 账号 / 云存档同步 | `db_api.php`（前端 `index.html` 5977–6262 段） |
| 多人房间 / 同步 | `room_api.php`（前端 `index.html` 8695–9454 段） |
| 后端公共库（DB/CORS/限流） | `api_bootstrap.php` |
| 共享工具函数 / API 地址解析 | `assets/js/app-runtime.js`、`assets/js/shared/`（拆分后） |
| 全局设计系统 CSS | 三页内联 `<style>`（重复；拆分后 `assets/css/system-ui.css`） |
| 打包手机 App | `tools/build-mobile.mjs` + `android/` |
| 数据库表结构 | 各 `*_api.php` 内的 `ensure_*_schema()`（无独立 SQL 文件） |
| 部署 / 环境变量 | `Dockerfile`、`docker/`、`docker-compose.yml`、`DEPLOY_ZEABUR.md` |
| 安全约定 | `SECURITY_NOTES.md`、`.htaccess` |
| 后端 Go 化 / 迁移方案 | `docs/GO_BACKEND_PLAN.md` |

---

## 3. 架构总览

```
┌────────────────────────── 浏览器 / WebView ──────────────────────────┐
│  index.html (主 SPA, 9555行)                                          │
│    ├─ 内联 CSS 31–1735   ├─ 内联模板 #app 1805–4461                   │
│    └─ 内联 JS   4463–9553（单个 setup() 4640–9543）                    │
│  Workshop/index.html (4549行)  ── 角色卡生成，直连用户配置的 AI 端点    │
│  Library/index.html  (1578行)  ── 模组库，调 library_api.php           │
│  共享: assets/css/tailwind.css, assets/js/app-runtime.js, assets/art/  │
└───────────────┬──────────────────────────────┬──────────────────────┘
                │ fetch (?action=)             │ window.ZCOC_RUNTIME.apiUrl()
                ▼                              ▼
┌──────────────────────── PHP 8.3 + Apache ─────────────────────────────┐
│  api_bootstrap.php  ← 所有入口 require 的公共库                        │
│    db_api.php        (register/login/pull/sync/logout)                │
│    room_api.php      (create/join/push/pull)                          │
│    library_api.php   (list/detail/create/delete/increment_downloads)  │
│    health.php        (SELECT 1 健康检查)                               │
│    dl/download.php   (纯导出角色卡 JSON)                               │
└───────────────────────────────┬───────────────────────────────────────┘
                                ▼
                         MySQL 8（表结构见 §5.4）
```

**页面间跳转**（主页面是枢纽，Workshop 与 Library 互不直达）：
- 主 → Workshop：`openCocCard()`（`index.html:5957`）
- 主 → Library：`openLibrary()`（`index.html:9470`）
- Workshop/Library → 主：返回按钮 → `../index.html`
- Library 借模组 → 主：写 `localStorage['coc_pending_module']` 后跳转，主页面 `checkPendingModule()` 读取（`index.html:9456`）

---

## 4. 目录地图

```
z-coc/
├─ index.html                 主 SPA（最大，见 §6 内部结构）
├─ Workshop/index.html        角色卡生成器
├─ Library/index.html         模组库
├─ 404.html                   主题化错误页（纯静态，无 JS）
├─ api_bootstrap.php          后端公共库（不可直接访问，见 .htaccess）
├─ db_api.php                 账号 + 云存档
├─ room_api.php               多人房间
├─ library_api.php            模组库后端
├─ health.php                 健康检查
├─ config.example.php         配置模板（config.local.php 被 gitignore）
├─ dl/download.php            角色卡 JSON 导出
├─ assets/
│  ├─ css/tailwind.input.css  Tailwind 源（3 行）
│  ├─ css/tailwind.css        构建产物（已提交，勿手改）
│  ├─ js/app-runtime.js       共享运行时（ZCOC_RUNTIME）
│  ├─ art/generated|user-edited|public-domain/   .webp 美术资源
│  └─ audio/雨夜（街巷）.mp3   唯一内置 BGM
├─ tools/
│  ├─ build-mobile.mjs        生成 www/ 手机包
│  └─ optimize_art.py         美术 PNG/TIFF → webp（手动运行）
├─ android/                   Capacitor Android 工程
├─ docker/                    apache 配置 + php.ini
├─ sw.js                      Service Worker（壳缓存）
├─ manifest.json              PWA 清单
├─ capacitor.config.json      Capacitor 配置
├─ tailwind.config.js         仅扫描 4 个 HTML
├─ package.json               构建脚本
├─ Dockerfile / docker-compose.yml
├─ DEPLOY_ZEABUR.md / MOBILE_APP.md / SECURITY_NOTES.md
└─ docs/                      本守则
```

---

## 5. 关键契约（改代码前必须知道）

### 5.1 HTTP API

统一响应信封：成功 `{ "status": "success", … }`；失败 `{ "status": "error", "message": "…" }`。
认证走 `Authorization: Bearer <token>`（三种独立 token，见 §5.5）。限流按 `IP:scope` 固定窗口（表 `api_rate_limits`）。

| 入口 | action | 方法 | 认证 | 主要参数 |
|---|---|---|---|---|
| `db_api.php` | register | POST | 无 | email, password |
| | login | POST | 无 | email, password |
| | pull | GET | Bearer | user_id |
| | sync | POST | Bearer | user_id, settings, investigators, saves, tavern_novels, base_revision |
| | logout | POST | Bearer | user_id |
| `room_api.php` | create | POST | 无 | room_id, user_id → 返回 host_token |
| | join | POST | 无 | room_id |
| | push | POST | 主机类消息需 host_token | room_id, sender_id, payload |
| | pull | GET | 无 | room_id, since, user_id |
| `library_api.php` | list | GET | 受限区需 Bearer owner_token | section(reading/restricted), q, user_id |
| | detail | GET | 私有需 Bearer | id, user_id |
| | create | POST | 无 | title, description, content, author_id, owner_token, type, min/max_players |
| | delete | POST | owner_token | id, author_id, owner_token |
| | increment_downloads | POST | 无 | id |
| `health.php` | — | GET | 无 | 返回 `{"status":"ok"}` |
| `dl/download.php` | — | POST | 无 | data（≤2MB，导出 JSON） |

模组 `type` 白名单：`original` / `reprint` / `ai_adapt` / `ai_adapt_private`。
`sync` 使用乐观锁：`base_revision` 不匹配返回 `409 {status:"conflict", revision}`。

### 5.2 localStorage / sessionStorage 键

| 键 | 用途 |
|---|---|
| `coc_user` | 用户身份（uuid/name/avatar），三页共享 |
| `coc_auth_token` | 账号 Bearer token（**仅主页面**） |
| `coc_is_logged_in` / `coc_remember_me` / `coc_remembered_email` / `coc_remembered_creds` | 登录态与「记住我」 |
| `coc_settings` | 全局设置（API URL/key/model、图像生成等），三页共享 |
| `coc_investigators` / `coc_friends` / `coc_tavern_novels` | 本地数据 |
| `coc_library_owner_token` | 模组库归属凭证（Library 生成） |
| `coc_pending_module` | Library → 主页面的借模组交接 |
| `coc_broadcast_read_version` / `coc_broadcast_shown_version` | 公告已读 |
| `ai_chargen_options_coc` | Workshop 生成选项 |
| sessionStorage `z_coc_room_host_<ROOMID>` | 房间主机会话（可恢复） |

### 5.3 IndexedDB

库名 `CocTrpgDB`，版本 `3`，object store：`saves` / `investigators` / `audio_assets`。
封装函数在 `index.html:4474–4638`（模块作用域，Vue 之外）。

### 5.4 PostgreSQL 表（全部由 PHP 内联 `CREATE TABLE IF NOT EXISTS` 自动创建，无 SQL 迁移文件）

| 表 | 用途 | 定义位置 |
|---|---|---|
| `users` | 账号（email/password_hash/user_uuid/auth_token hash） | `db_api.php` ensure_auth_schema |
| `user_data` | 云存档（settings/investigators/saves/tavern_novels JSON + revision） | `db_api.php` |
| `coc_rooms` | 房间（host_id/host_token_hash/ttl） | `room_api.php` |
| `coc_room_messages` | 房间消息（payload JSON） | `room_api.php` |
| `coc_room_presence` | 在线状态 | `room_api.php` |
| `coc_library_modules` | 模组库 | `library_api.php` |
| `api_rate_limits` | 限流桶 | `lib/RateLimit.php` |

数据库账号首次部署需 `CREATE`（含 `CREATE INDEX`）权限。

### 5.5 认证模型（三套独立，均 token + sha256 存储，无 PHP session）

1. **账号**（`db_api.php`）：email+password（`password_hash`），登录返回一次性明文 `auth_token`，库中只存 sha256；每次登录轮换 token，旧设备失效。
2. **房间主机**（`room_api.php`）：`create` 返回一次性 `host_token`，库中存 sha256；主机类消息校验。
3. **模组归属**（`library_api.php`）：浏览器生成的 `owner_token`，库中存 sha256；私有模组与删除需匹配。

### 5.6 共享全局（无 import，全靠 window）

- `window.ZCOC_RUNTIME`（`assets/js/app-runtime.js`）：`apiOrigin` / `apiUrl()` / `isNative` / `platform`。原生端把相对 API 路径解析到 `https://z-coc.zeabur.app`。
- 自定义事件：`z-coc:mount-app`（启动页请求挂载）、`z-coc:app-mounted`；标志 `window.__zCocAppMounted`、`window.__zCocLaunchMountRequested`。
- CDN 全局库：`Vue` / `marked` / `DOMPurify` / `mammoth` / `saveAs` / `htmlDocx`。

---

## 6. 主 `index.html` 内部结构速查

| 区段 | 行号 | 内容 |
|---|---|---|
| `<head>` | 1–1736 | meta、PWA 注入脚本(11–19)、外链脚本(24–30) |
| 内联 `<style>` | 31–1735 | 启动页(37) / 徽章(322) / Premium UI(334) / System UI 2026(482) / art direction(890) / Occult editorial v3(1212) / mobile(1504) |
| 启动屏控制器 | 1754–1804 | `#z-launch-screen` 挂载门控 |
| 全局浮层/侧栏/头部 | 1807–2205 | Toast、登录框、确认框、结算、侧栏、移动头部 |
| 12 个视图 | 2206–4347 | chat / coc-mode / multiplayer / preparing / saves / friends / settings / tavern / broadcast / char-review / manual |
| 独立弹窗 | 4348–4461 | 模型选择、文件夹管理、删除文件夹 |
| 主脚本 | 4463–9553 | SW 注册(4467)、IndexedDB(4474)、`createApp`(4640)、`setup()`(4641–9543)、返回绑定(9509–9542)、挂载(9545) |

`setup()` 内关键横切函数（拆分前须先服务化）：`saveData`(6134)、`showToast`(6449)、`showConfirm`(4768)、`renderMarkdown`(6889)、`requestChatCompletion`(5296)、`broadcastMessage`(8785)、`applyStCommands`(6754)、`normalizeInventoryData`(6513)、`compressImage`(4685)。

---

## 7. 开发规范

### 7.1 前端
- **Vue 只用 Composition API**，当前是单个 `setup()`。新增逻辑就近放在对应函数簇，勿新建第二个 app 实例。
- 三页共用 `assets/css/tailwind.css` 与 `assets/js/app-runtime.js`；新增共享工具放 `assets/js/shared/`（拆分后）。
- 模板是 in-DOM（写在 HTML 内），**没有 SFC/构建步骤**；改模板直接改 HTML。
- CSS 段落沿用现有中文注释分区风格（`/* Zarkto System UI 2026 */` 等）。
- 所有网络地址一律经 `ZCOC_RUNTIME.apiUrl(...)`，勿硬编码域名。

### 7.2 后端
- `api_bootstrap.php` 仅作为薄加载器，按序 `require_once` `lib/` 下的 `Config`、`Http`、`Database`、`Validation`、`Scheduler`、`RateLimit`；全局函数名保持 `app_*`。
- 数据库为 PostgreSQL（PDO `pgsql`）。入口头部：`require api_bootstrap.php` → `app_send_cors_headers()` → `app_db()`（或 `app_query` / `app_fetch_one` / `app_fetch_all` / `app_execute`）→ 读 `$_GET['action']` → 分发 → 404 兜底。
- 响应统一用 `app_json_response()`；读 body 用 `app_read_json_body()`；限流用 `app_rate_limit()`。
- 新增表/列：写在对应 `ensure_*_schema()` / `library_ensure_schema()`，用 `CREATE TABLE IF NOT EXISTS` 与 `CREATE INDEX IF NOT EXISTS`，保持幂等；不再使用 `INFORMATION_SCHEMA` 升级列。
- 用户输入一律经校验函数（`app_normalize_user_id` / `library_text` 等）；token 只存 sha256。
- 错误消息当前中英混用，新增请统一为「前端可见中文 + 日志英文」。

### 7.3 通用
- **不要提交** `config.local.php`、`.env`、任何密钥；`.env` 已 gitignore。
- `assets/css/tailwind.css` 是构建产物，改动请改 `tailwind.input.css` / `tailwind.config.js` 后运行 `pnpm build:css`。
- 美术只引用 `.webp`；PNG/TIFF 母版被 gitignore，放 `assets/art/` 对应子目录。
- **本项目不加行内注释**（沿用现状），说明写进本守则或对应文档。

---

## 8. 构建与部署

### 8.1 本地命令（`package.json`）

```bash
pnpm install
pnpm build:css          # Tailwind → assets/css/tailwind.css
pnpm build:mobile-web   # build:css + 生成 www/
pnpm mobile:sync        # build:mobile-web + cap sync android
pnpm mobile:open        # 打开 Android Studio
pnpm mobile:build:debug # 完整 debug APK → android/app/build/outputs/apk/debug/
```

### 8.2 部署
- Docker：`php:8.3-apache-bookworm`，Apache 监听 **8080**，文档根 `/var/www/html`，`AllowOverride All`（`.htaccess` 生效），健康检查 `/health.php`。
- Zeabur：一个项目 = PostgreSQL 服务 + 从 GitHub 部署的网站服务；网站读注入的 `PGSQL_*`（也兼容 `DB_*` 与 `DATABASE_URL`），**环境变量优先于配置文件**。
- 同域部署**不要**设 `APP_ALLOWED_ORIGINS`；跨域/原生端才设，值为逗号分隔完整 Origin，**禁用 `*`**。
- 关键提醒：Docker 构建**不**编译 CSS，故 `assets/css/tailwind.css` 必须预先构建并提交，否则线上样式丢失。

### 8.3 移动端（Capacitor）
- 打包产物 `www/`：`tools/build-mobile.mjs` 复制 `assets/dl/Library/Workshop` + 顶层文件，并把 8 个 CDN 库改为本地 `assets/vendor/`。
- WebView 源 `https://localhost`；原生端**不注册 SW**，API 走 `ZCOC_RUNTIME.apiUrl` 到生产域名。
- 测试云端功能前，服务器需设 `APP_ALLOWED_ORIGINS=https://localhost,capacitor://localhost`。
- 工具链：Node 22+、JDK 21、Android SDK API 24+（建议 36）。

---

## 9. 已知问题 / 注意事项（拆分前的技术债）

1. **严重重复**：三页各自复制了整套设计系统 CSS 与工具函数（`IMAGE_GEN_ARTIST_PRESETS`、`showToast`、`sanitizeHtml`、`normalizeApiBaseUrl`、`sanitizeFilename`、`crc32`/`injectPngChunk`）。
2. **单 `setup()` 闭包**：主页面 ~250 个模板绑定、大量模块级可变状态，难以拆分。
3. **构建产物入库**：`tailwind.css` 提交进仓库，且镜像不构建 CSS。
4. **无 SQL 迁移**：表结构散在 PHP 内联 DDL，改结构风险高。
5. **文档漂移**：`assets/art/README.md` 列的文件名与磁盘不一致。
6. **CORS 说明重复**：`MOBILE_APP.md` / `SECURITY_NOTES.md` / `DEPLOY_ZEABUR.md` / `docker-compose.yml` / `config.example.php` 各写一遍。
7. **API 契约不一致**：`db_api pull` 无数据返回裸 `null`，其余返回 `{status:…}`。
8. **密钥风险**：`.env` 含疑似真实凭证，建议轮换（见 `SECURITY_NOTES.md`）。

---

## 10. 相关文档

| 文件 | 内容 |
|---|---|
| `docs/SPLIT_PLAN.md` | 模块拆分/重构任务清单 |
| `docs/GO_BACKEND_PLAN.md` | 后端 Go 化：技术选型 + 迁移计划 + 后续需求路线图 |
| `docs/GO_BACKEND_TASKS.md` | Go 后端实现任务清单（可勾选跟踪） |
| `docs/FRIENDS_FEATURE_TASK.md` | 好友功能设计（挂起，待实现） |
| `DEPLOY_ZEABUR.md` | Zeabur 部署步骤与上线检查 |
| `MOBILE_APP.md` | 移动端构建环境与命令 |
| `SECURITY_NOTES.md` | 安全约定 |
| `assets/art/README.md` / `PROMPTS.md` | 美术资源来源与提示词 |

---

## 11. 维护约定

- 本守则随架构变更同步更新；行号快照以「版本号 + 日期」标注。
- 新增模块/契约（API、localStorage 键、DB 表、共享全局）必须登记到 §5。
- 拆分执行时，请更新 `SPLIT_PLAN.md` 的进度勾选。
