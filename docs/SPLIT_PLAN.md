# z-coc 模块拆分计划

> 版本：v1.3.0 ｜ 生成日期：2026-10-09 ｜ 状态：**规划中，尚未执行**
> 配套总纲见 [`PROJECT_GUIDE.md`](./PROJECT_GUIDE.md)。
> 行号为 v1.3.0 快照，执行时以符号名/注释定位。

---

## 1. 目标

把三个单体 HTML（主 SPA 9555 行、Workshop 4549 行、Library 1578 行）与 PHP 后端，拆成边界清晰、可独立维护与更新的模块，减少改动时的上下文输入与回归风险。

---

## 2. 原则与硬约束

1. **不改运行方式**：保持 Vue CDN 全局版 + in-DOM 模板，不引入打包器（除非进入档 B）。
2. **行为等价**：每一阶段拆分后功能与线上一致，可独立验证与回滚。
3. **先共享、后专属**：先抽跨页重复，再拆单页内部。
4. **契约不变**：`?action=` URL、localStorage 键、DB 表名、`ZCOC_RUNTIME` 接口保持兼容。
5. **小步提交**：每个子任务可单独回滚。

---

## 3. 两档策略

| 档 | 做法 | 风险 | 适用 |
|---|---|---|---|
| **A 渐进式** | 多文件 + IIFE/`window` 命名空间，保留 CDN Vue | 低 | 推荐先做，收益/风险比最高 |
| **B 深度重构** | 引入 Vite + Vue SFC + Pinia，模板变组件 | 高 | 长期目标，需完整回归 |

当前决定：**先只写守则，暂不拆**。以下清单供后续按序执行。

---

## 4. 阶段任务清单

### 阶段 0 — 基线固化（前置）
- [ ] 确认拆分前后可对照验证：截图关键界面、跑通注册/存档/多人/模组库回归用例。
- [ ] 记录当前 `git` 基线 tag。

### 阶段 1 — 共享层抽取（最高收益）
> 目标：消除三页重复，主页面先瘦身。

- [ ] `assets/css/system-ui.css`：抽三页重复的 `Zarkto System UI 2026` + `Occult editorial v3`。
  - 源：`index.html:482–1503`、`Workshop/index.html:174–660`、`Library/index.html:46–404`
- [ ] `assets/js/shared/ui.js`：`showToast`、`showConfirm`、`escapeHtml`、`sanitizeHtml`、`sanitizeFilename`、`copyToClipboard`。
- [ ] `assets/js/shared/api.js`：`normalizeApiBaseUrl`、`buildOpenAiEndpoint`、`chatCompletionsUrl`。
- [ ] `assets/js/shared/settings.js`：`IMAGE_GEN_ARTIST_PRESETS`（当前主页面 4799–4820 与 Workshop 2053–2074 **逐字重复**）。
- [ ] `assets/js/shared/png.js`：`crc32`、`injectPngChunk`、`extractPngChunk`（角色卡 PNG 嵌入）。
- [ ] 三页改为引用共享文件，删除内联副本。
- 验收：三页功能不变；共享文件被 SW/构建复制清单收录。

### 阶段 2 — 单页 CSS 拆分
- [ ] 主 `index.html` → `assets/css/app/`：`base`(32–36)、`launch`(37–321)、`badges`(322–333)、`premium-ui`(334–481)、`system-ui`(482–889)、`art-direction`(890–1211)、`editorial-v3`(1212–1503)、`mobile`(1504–1735)。
- [ ] Workshop → `assets/css/workshop/`：`base`(14–173)、`system-ui`(174–529)、`editorial`(530–660)、`layout`(661–716)。
- [ ] Library → `assets/css/library/`：`base`(18–45)、`system-ui`(46–298)、`editorial`(299–404)、`archive`(405–594)。
- 验收：视觉零差异（像素对比）。

### 阶段 3 — 单页 JS 拆分（IIFE + `window.ZCOC*` 命名空间）
> 前置：先把横切函数服务化（见 §5）。

- [ ] 主页面拆为：`indexeddb`、`utils`、`api-client`、`bgm`、`storage-manager`、`kp-rules`、`cloud-sync`+`auth`、`data-loader`、`st-commands`、`markdown`、`game-engine`、`character`、`tavern`、`dice-commands`、`chat`、`room`、`bootstrap`。
- [ ] Workshop 拆为：`state`、`settings-presets`、`persistence`、`import`、`avatar`、`coc-rules`(2980–3830，最大纯逻辑块)、`radar`、`ai-generate`、`export`、`diff`、`main`。
- [ ] Library 拆为：`state`、`utils`、`init`、`modules-api`、`ai`、`file-io`、`alchemy`、`main`。
- 说明：模板暂留 in-DOM；ES module 化留待档 B。
- 验收：控制台无未定义引用；功能回归通过。

### 阶段 4 — PHP 后端拆分（保留 `?action=` 契约）
- [ ] `lib/`：`Config`、`Http`、`Database`、`Auth`、`Validation`、`RateLimiter`、`Scheduler`、`Schema`、`Json`。
- [ ] `controllers/`：`AuthController`、`CloudDataController`、`RoomController`、`LibraryController`、`HealthController`、`DownloadController`。
- [ ] `repositories/`：User / UserData / Room / RoomMessage / RoomPresence / LibraryModule。
- [ ] 合并三处 `CREATE TABLE`/`INFORMATION_SCHEMA` 迁移为单一版本化迁移器。
- [ ] 统一校验器与 token 哈希（消除 `normalize_sender_id`/`hash_auth_token`/`library_owner_hash` 等重复）。
- 验收：所有 action 行为与响应字节级一致；`health.php` 正常。

### 阶段 5 — 档 B（可选，长期）
- [ ] 引入 Vite，迁移为 Vue SFC + Pinia。
- [ ] in-DOM 模板拆成组件：`GlobalOverlays`、`Sidebar`、`MobileHeader`、12 个 `*View`、3 个弹窗。
- [ ] `setup()` 拆为 composables。
- [ ] 统一构建产物（不再提交 `tailwind.css`），接 CI。

---

## 5. 拆分前置：横切服务化（阶段 3 之前必须完成）

主页面 `setup()` 是单一闭包，跨模块调用极多。拆分前须先抽出稳定接口：

| 服务 | 现状位置 | 被调用 |
|---|---|---|
| `saveData`（唯一持久化入口，触发云同步） | `index.html:6134` | ~50 处 |
| `showToast` / `showConfirm` | 6449 / 4768 | 大量 |
| `renderMarkdown` | 6889 | 多 |
| `requestChatCompletion` | 5296 | AI 相关 |
| `broadcastMessage` | 8785 | 房间 |
| `applyStCommands` | 6754 | `.st` 引擎 |
| `normalizeInventoryData` | 6513 | 物品 |
| `compressImage` | 4685 | 头像/图片 |

另需先建共享响应式 store（Pinia 或 `provide`/`inject` 单例）：`settings`、`cocState`、`multiplayerState`、`savedGames`、`tavernState`、`charReviewState`、`settlementState`、`authState`、`user`、BGM 引用、云同步标志。

---

## 6. 风险与回滚

| 风险 | 缓解 |
|---|---|
| 模板是 in-DOM，无法直接组件化 | 阶段 3 只拆 JS，模板保留；组件化放档 B |
| 单闭包状态耦合 | 先做 §5 服务化与 store 抽取 |
| SW 缓存旧壳 | 每次结构变更 bump `sw.js` 的 `CACHE_VERSION` |
| 构建复制清单遗漏新文件 | 更新 `tools/build-mobile.mjs` 与 `sw.js` 的 `APP_SHELL` |
| 后端契约漂移 | 保持 `?action=` 与响应信封；字节级对比 |
| 行号漂移 | 用符号名/注释定位，不用行号做锚点 |

回滚：每阶段独立提交，出问题 `git revert` 对应提交即可，不跨阶段耦合。

---

## 7. 验收方式（每阶段通用）

1. 三页可正常加载，控制台无报错。
2. 回归用例：注册 → 登录 → 创建调查员 → 存档 → 退出 → 另一浏览器登录 → 恢复。
3. 多人：建房 → 加入 → 推送 → 拉取同步。
4. 模组库：上传 → 列表 → 详情 → 借阅跳转主页面 → 删除。
5. 移动端：`pnpm mobile:sync` 通过，debug APK 可运行。
6. 视觉：拆分 CSS 前后像素对比一致。

---

## 8. 执行登记

| 阶段 | 状态 | 负责 | 备注 |
|---|---|---|---|
| 0 基线 | 未开始 | | |
| 1 共享层 | 未开始 | | |
| 2 CSS | 未开始 | | |
| 3 JS | 未开始 | | |
| 4 PHP | 未开始 | | |
| 5 档 B | 未开始 | | |
