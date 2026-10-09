# 安全部署说明

1. `config.local.php` 只用于传统虚拟主机或本地 PHP 环境，不要上传到公开 GitHub，也不要复制进容器镜像。
2. Zeabur 部署优先使用同一项目内 PostgreSQL 服务注入的 `PGSQL_HOST`、`PGSQL_PORT`、`PGSQL_USERNAME`、`PGSQL_PASSWORD` 和 `PGSQL_DATABASE`（也兼容 `DB_*` 别名与 `DATABASE_URL`）。
3. 公开仓库只保留 `config.example.php`；其中只能包含占位符和非敏感默认值。
4. 网页与 PHP API 同域部署时，不设置 `APP_ALLOWED_ORIGINS`。跨域部署时填入逗号分隔的完整 HTTPS Origin，禁止使用 `*`。
5. `db_api.php`、`room_api.php` 和 `library_api.php` 会在首次访问时自动创建数据表与索引，因此数据库账号首次部署时需要 `CREATE`（含 `CREATE INDEX`）权限。
6. 曾经进入聊天记录、公开仓库或公开文件的数据库密码和 API Key 必须在服务商后台轮换。
7. 前端不内置 AI Key 或生图 Token。用户自行填写的服务地址、Key 和模型信息只保存在其浏览器中。
8. 正式上线后必须启用 HTTPS、PostgreSQL 定期备份和恢复演练；不要为 PostgreSQL 服务绑定不必要的公网域名。
9. `/health.php` 只返回通用健康状态，不返回数据库地址、账号、库名或错误详情。
