# 同伴（好友）功能 任务 / 设计文档

> 状态：**挂起（SUSPENDED）** — 仅存档设计，暂不实现。
> 版本：v0.1 ｜ 生成日期：2026-10-09
> 适用范围：`z-coc` 主 SPA（`index.html`）+ PHP 8.3 后端（`*.php` / `lib/`）。
> 行号为当前快照，重构后会漂移，以符号名/注释为准。相关约定见 [`PROJECT_GUIDE.md`](./PROJECT_GUIDE.md)。

---

## 1. 背景与现状

应用 `z-coc` 已有一个「收音机调频 / 同伴名录」的好友 UI，但该功能**整体处于桩（stub）状态**，没有任何真实实现。

### 1.1 前端 UI 已存在

- 好友视图标记：`index.html:3029–3047`（视图标题「同伴名录」）。
- 自身卡片：显示并复制自己的 UUID，`index.html:3034–3042`（`UID: {{ user.uuid }}`，复制按钮 `copyToClipboard(user.uuid)`）。
- 添加好友输入框：`friendInput` + 「添加」按钮调用 `addFriend`，`index.html:3042`（placeholder「输入同伴 ID」）。
- 好友请求列表：`v-for="req in friendRequests"`，接受/拒绝按钮调用 `acceptFriend(req)` / `rejectFriend(req)`，`index.html:3044`。
- 好友列表：`v-for="friend in friends"`，含头像、在线徽章 `friend.isOnline`、在线/离线文案，以及「邀请」按钮调用 `inviteFriend(friend)`，`index.html:3045`。
- 空态文案：「孤独是理智的最后防线...」，`index.html:3045`。

### 1.2 前端状态与持久化已存在

- 响应式状态：`const friends = ref([]); const friendRequests = ref([]); const friendInput = ref('');`，`index.html:5774`。
- 持久化：`localStorage.setItem('coc_friends', JSON.stringify(friends.value));`，`index.html:6136`。
- 加载：`const lFriends = readStoredJson('coc_friends'); if (lFriends) friends.value = lFriends;`，`index.html:6380`。
- 键 `coc_friends` 已在 `PROJECT_GUIDE.md` §5.2 登记为「本地数据」。

### 1.3 处理函数全是桩

- `index.html:9449–9451`：

  - `addFriend` = `() => { /* ... */ }`（空实现）
  - `acceptFriend` = `(req) => { /* ... */ }`（空实现）
  - `inviteFriend` = `(f) => { /* ... */ }`（空实现）

- `rejectFriend` **完全没有定义**（模板 `index.html:3044` 已引用，运行时点「拒绝」会报错）。

### 1.4 后端完全无支持

现有 PHP 入口只有：

| 入口 | 职责 |
|---|---|
| `db_api.php` | 账号注册/登录、云存档 pull/sync/logout |
| `room_api.php` | 多人房间 create/join/push/pull |
| `library_api.php` | 模组库 list/detail/create/delete/increment_downloads |
| `health.php` | 健康检查 |
| `dl/download.php` | 角色卡 JSON 导出 |

**没有任何入口提供「用户搜索 / 好友请求 / 好友关系」能力。** 因此当前 UI 无论输入什么 ID 都查不到人、也加不上人——这是预期行为，不是 bug。

### 1.5 用户标识方式

用户以 UUID 标识（`user.uuid`），UI 直接展示并允许复制（`index.html:3036–3039`，提示「输入同伴 ID」）。登录/注册由 `db_api.php` 处理，`users` 表含 `user_uuid VARCHAR(64) UNIQUE`（`db_api.php:11–23`）。

### 1.6 结论

> 「无法查询到其它用户，也无法添加」是**当前实现的必然结果**：前端只有桩函数，后端没有任何对应用户检索/好友关系接口。要让它真正可用，需要前端接线 + 后端新增入口与表结构。

---

## 2. 目标

把好友功能做成端到端可用，至少包含：

1. **搜索用户**：按已知标识（UUID，可选 email/昵称）精确查找其它用户。
2. **发送好友请求**：向目标用户发出请求。
3. **接受 / 拒绝好友请求**：处理「收到的信号」列表。
4. **好友列表**：展示已建立的好友关系。
5. **在线状态**：展示好友在线/离线（驱动 `friend.isOnline` 与在线徽章）。
6. **邀请入房**：把在线好友邀请进发起者所在的多人房间。

非目标（本轮不做）：群组、备注/分组、拉黑、消息聊天（房间内已有聊天）。

---

## 3. 后端设计

### 3.1 入口方案：新增独立 `friend_api.php`（推荐）

**推荐：新建 `friend_api.php`，而不是往 `db_api.php` 里塞 action。**

理由：

- `db_api.php` 当前职责聚焦「账号 + 云存档」，且其 `require_user_token()`（`db_api.php:89–112`）是账号专用鉴权。好友逻辑需要更频繁的读写与轮询，混入会让 `db_api.php` 继续膨胀。
- 项目已有「按领域拆入口」的先例：账号 `db_api.php`、房间 `room_api.php`、模组库 `library_api.php`。好友是新领域，独立入口最符合现有约定。
- 独立入口便于对「搜索」单独限流（防枚举），而不影响登录/同步的限流预算。
- 需在 `api_bootstrap.php` 同目录放置，并遵循 `PROJECT_GUIDE.md` §7.2 的入口骨架：`require api_bootstrap.php` → `app_send_cors_headers()` → `app_db()` → 读 `$_GET['action']` → 分发 → 404 兜底。

> 若追求最小改动，也可在 `db_api.php` 增加 `friend_*` action，但需复用其鉴权；不推荐。

### 3.2 鉴权

- 复用账号体系：`Authorization: Bearer <token>`，token 为 `db_api.php` 登录返回的 `auth_token`（前端存于 `localStorage['coc_auth_token']`，见 `PROJECT_GUIDE.md` §5.2）。
- 服务端校验方式参考 `db_api.php:89–112`：`app_get_bearer_token()`（`lib/Http.php:64–80`）→ 对 `users.auth_token` 做 sha256 比对。建议把该逻辑抽成共享函数供 `friend_api.php` 调用，避免复制。
- 所有涉及「我是谁」的操作，**服务端以 token 解析出的 `user_uuid` 为准**，绝不信任前端传来的「我是谁」。

### 3.3 Action 与请求/响应形状

统一响应信封（`PROJECT_GUIDE.md` §5.1）：成功 `{ "status": "success", ... }`；失败 `{ "status": "error", "message": "…" }`。统一用 `app_json_response()`（`lib/Http.php:27–32`）、读体用 `app_read_json_body()`（`lib/Http.php:34–47`）、限流用 `app_rate_limit()`（`lib/RateLimit.php:3`）。

| action | 方法 | 认证 | 主要参数 | 成功返回 |
|---|---|---|---|---|
| `search` | GET | Bearer | `q`（精确 UUID 或精确 email） | `{status, user:{uuid,name,avatar}}` 或 `{status:'error',message:'未找到'}` |
| `request` | POST | Bearer | `target_uuid` | `{status:'success'}`（已发送/已是好友时给出明确 message） |
| `requests` | GET | Bearer | — | `{status, requests:[{id,uuid,name,avatar,created_at}]}` |
| `accept` | POST | Bearer | `request_id`（或 `from_uuid`） | `{status:'success'}` |
| `reject` | POST | Bearer | `request_id`（或 `from_uuid`） | `{status:'success'}` |
| `list` | GET | Bearer | — | `{status, friends:[{uuid,name,avatar,is_online,last_seen}]}` |
| `invite` | POST | Bearer | `friend_uuid`, `room_id` | `{status:'success'}` 或错误 |
| `heartbeat`（可选） | POST | Bearer | — | `{status:'success'}`（更新本人 `last_seen`） |

设计要点：

- `search` 只接受**精确匹配**（UUID 全等或 email 全等），不做模糊/前缀搜索（见 3.5 隐私）。
- `request` 需处理幂等与冲突：重复请求、反向已请求、已是好友，均返回明确 `message`，不静默。
- `accept` / `reject` 只能操作**发给当前用户**的请求（服务端校验接收方）。
- `list` 返回的 `is_online` 由 `last_seen` 与阈值计算（见 3.6），不落库为布尔值。

### 3.4 数据库表设计

表结构按 `PROJECT_GUIDE.md` §7.2 / §5.4：写在 `friend_api.php` 的 `ensure_friend_schema()` 中，用 `CREATE TABLE IF NOT EXISTS` 与 `CREATE INDEX IF NOT EXISTS`，幂等，**无独立 SQL 迁移文件**。数据库为 PostgreSQL（PDO `pgsql`）。

建议两张表：

**`coc_friendships`（已建立的双向好友关系）**

- `user_a VARCHAR(64) NOT NULL` — 规范化后较小的 uuid
- `user_b VARCHAR(64) NOT NULL` — 较大的 uuid
- `created_at TIMESTAMP NOT NULL DEFAULT NOW()`
- `PRIMARY KEY (user_a, user_b)`
- 约定 `user_a < user_b`，保证无向关系唯一，避免存两条。
- 索引：主键已覆盖 `(user_a, user_b)`；另建 `CREATE INDEX IF NOT EXISTS idx_coc_friendships_b ON coc_friendships(user_b)` 以便按任一端查询。

**`coc_friend_requests`（待处理请求）**

- `id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY`
- `from_uuid VARCHAR(64) NOT NULL`
- `to_uuid VARCHAR(64) NOT NULL`
- `status VARCHAR(16) NOT NULL DEFAULT 'pending'` — `pending` / `accepted` / `rejected`
- `created_at TIMESTAMP NOT NULL DEFAULT NOW()`
- `updated_at TIMESTAMP NOT NULL DEFAULT NOW()`
- 唯一约束：`CREATE UNIQUE INDEX IF NOT EXISTS idx_coc_friend_requests_pair ON coc_friend_requests(from_uuid, to_uuid)`（或对 pending 状态做部分唯一索引），防止刷重复请求。
- 查询索引：`CREATE INDEX IF NOT EXISTS idx_coc_friend_requests_to ON coc_friend_requests(to_uuid, status)`。

**在线状态表 `coc_user_presence`**

- `user_uuid VARCHAR(64) NOT NULL PRIMARY KEY`
- `last_seen TIMESTAMP NOT NULL DEFAULT NOW()`
- 由任意带 Bearer 的已认证请求顺带更新（见 3.6）。

**替代方案（不推荐）：存进 `user_data` JSON**

- 做法：在 `user_data`（`db_api.php:11–38`）新增一列或在现有 JSON 里挂 `friends` 数组。
- 缺点：无法建立关系唯一性约束、无法按目标端高效查询「谁请求了我」、并发写会与云存档 `revision` 乐观锁（`db_api.php` sync）互相踩踏、跨用户关系本质上是「用户×用户」的边，不适合塞进单用户文档。
- 结论：**采用独立关系表**；仅在「只是想快速原型、不做跨设备持久化」时才考虑 JSON，且应明确它不满足目标 3/4。

### 3.5 用户搜索与隐私

- 搜索**只允许精确匹配**：
  - UUID：`q` 与 `users.user_uuid` **全等**（UUID 足够长、不可枚举）。
  - email：`q` 与 `users.email` **全等**（规范化小写后比较）。
- **禁止**：模糊搜索、`LIKE '%q%'`、按昵称列出用户、返回任何用户列表分页。否则等于提供「遍历全体用户」的接口。
- 昵称搜索（若未来需要）必须附加限制（如必须同时提供精确 email 或 UUID），否则不开放。
- 返回体只暴露最小字段：`uuid`、`name`、`avatar`。**绝不返回 email、token、`users.id`**。
- 对 `search` 单独限流（如 `app_rate_limit($conn, 'friend_search:' . $ip, N, 60)`），并在未命中时统一返回「未找到」，不区分「不存在」与「无权查看」，避免枚举探测。

### 3.6 在线状态定义

- 定义：用户**最近一次通过有效 Bearer 认证的请求**时间即 `last_seen`。可在 `friend_api.php` 每个已认证 action 入口处顺带 `UPSERT` 更新 `coc_user_presence`。
- 判定在线：`now() - last_seen < 阈值`（建议 120 秒，与 `room_api.php` 轮询节奏一致；`coc_room_presence` 见 `room_api.php:60–65`）。
- 存储：独立 `coc_user_presence` 表（全局），与房间级的 `coc_room_presence`（`room_api.php:60–65`，主键 `room_id,user_id`）区分开——前者表示「整个人在线」，后者表示「某人在某房间内」。
- 清理：可用 `app_run_periodically()`（`lib/Scheduler.php`）定期删除过期行，或按需惰性忽略过期数据，不必实时删。

---

## 4. 前端设计

### 4.1 需要接线的函数

把 `index.html:9449–9451` 的桩替换为真实实现，并**补齐 `rejectFriend`**：

- `addFriend()` — 读 `friendInput.value`，先调 `search`（精确 UUID），命中后调 `request`；成功后清空输入、`showToast` 提示。
- `acceptFriend(req)` — 调 `accept`，成功后从 `friendRequests` 移除并把对方加入 `friends`。
- `rejectFriend(req)` — **新增**，调 `reject`，成功后从 `friendRequests` 移除。
- `inviteFriend(f)` — 调 `invite`（带当前 `room_id`）；若当前不在房间，提示「先进入房间」。

### 4.2 需要接入的渲染

- 好友列表 `index.html:3045`：绑定服务端返回的 `friends`，`friend.isOnline` 驱动在线徽章与「在线/离线」文案；「邀请」按钮已按 `v-if="friend.isOnline"` 显示，无需改模板。
- 请求列表 `index.html:3044`：`friendRequests` 由 `requests` 轮询填充；`req.id` / `req.name` 字段需与后端返回一致（当前模板用 `:key="req.id"` 与 `{{ req.name }}`）。
- 自身卡片 `index.html:3036–3039`：UUID 展示/复制逻辑已可用，无需改。

### 4.3 网络层

- 所有请求经 `ZCOC_RUNTIME.apiUrl(...)`（`PROJECT_GUIDE.md` §7.1），勿硬编码域名。
- 带 `Authorization: Bearer <localStorage['coc_auth_token']>`；未登录时按钮应提示先登录，而非静默失败。

### 4.4 轮询与状态刷新

- 打开 `friends` 视图时拉取 `requests` 与 `list`；关闭视图时停止轮询（参考 `roomPollTimer` 模式，`index.html:5770–5772`）。
- 建议轮询间隔 10–30 秒；仅刷新 `requests` 与好友 `isOnline`，避免整页重渲染。
- 每次已认证请求顺带触发 `heartbeat`（或由后端在其它 action 里更新 `last_seen`），保证自己在线状态新鲜。

### 4.5 错误与提示

- 复用 `showToast`（`index.html:6449`）显示成功/失败（如「未找到该同伴」「已发送信号」「对方已是好友」「邀请已发出」）。
- `search` 未命中统一显示「未找到该同伴」，不泄露存在性细节。
- 网络/鉴权失败给出明确文案（如「登录已过期，请重新登录」）。

---

## 5. 开放问题 / 待确认

1. **搜索维度**：仅支持 UUID，还是同时支持 email？是否要做昵称搜索（存在隐私风险，需额外约束）？
2. **关系持久化范围**：好友关系是否要跨设备、服务端持久化？（本设计默认「是」，需产品确认；若否，则退回 localStorage，功能价值大打折扣。）
3. **在线状态语义**：`last_seen` 阈值取多少秒？是否区分「在线 / 离开 / 离线」三态，还是二态即可？是否需要「正在游戏中」？
4. **邀请语义**：点「邀请」是**直接把好友拉进我的房间**（需处理房间密码/观战/满员），还是只发一条通知、由对方点击后自己加入？邀请是否需要对方确认？
5. **删除好友**：本轮是否提供「移除好友」入口？（当前 UI 无删除按钮，需决定是否补充。）
6. **请求数量与防滥用**：单用户待处理请求上限、请求频率上限、被拒绝后冷却时间？
7. **匿名/未登录用户**：未登录用户（无 `coc_auth_token`）能否使用好友功能？若不能，UI 如何引导登录？
8. **昵称与头像来源**：`name`/`avatar` 从 `users` 还是 `user_data.settings` 取？需确认权威来源与更新时机。

---

## 6. 验收标准

实现完成后应满足：

- [ ] 输入一个**存在的**同伴 UUID，点击「添加」，对方能在「收到的信号」中看到该请求。
- [ ] 输入**不存在的** UUID，给出「未找到该同伴」，且不泄露其它信息。
- [ ] 接受请求后，双方**都**在好友列表看到对方。
- [ ] 拒绝请求后，请求从列表消失，且不会自动成为好友。
- [ ] 重复发送、反向已请求、已是好友等边界均给出明确提示，不产生重复数据。
- [ ] 好友在线时显示在线徽章与「在线」，离线时显示「离线」；状态随轮询更新。
- [ ] 点击在线好友「邀请」，对方能进入（或收到并接受加入）发起者的房间。
- [ ] 好友关系在**换设备/清缓存后**仍存在（服务端持久化生效）。
- [ ] `search` 接口无法枚举用户（仅精确匹配 + 限流），返回体不含 email/token/内部 id。
- [ ] 刷新页面后 `coc_friends`（`index.html:6136` / `6380`）与服务端数据一致，不出现「幽灵好友」。
- [ ] 未登录/登录过期时，操作给出明确引导而非静默失败。
- [ ] `rejectFriend` 不再缺失，点击「拒绝」不再报错。

---

## 附：涉及文件一览

| 文件 | 角色 |
|---|---|
| `index.html:3029–3047` | 好友视图标记 |
| `index.html:5774` | `friends` / `friendRequests` / `friendInput` 状态 |
| `index.html:6136` / `6380` | `coc_friends` 持久化 / 加载 |
| `index.html:9449–9451` | 待替换的桩函数（含缺失的 `rejectFriend`） |
| `friend_api.php`（**待新建**） | 好友后端入口 |
| `lib/Http.php` / `lib/Validation.php` / `lib/RateLimit.php` | 复用共享工具 |
| `db_api.php:11–23` / `89–112` | `users` 表与 token 校验参考 |
| `room_api.php:60–65` | `coc_room_presence` 在线状态参考 |
| `docs/PROJECT_GUIDE.md` | 约定总纲（§5.1 / §5.2 / §5.4 / §7.2） |
