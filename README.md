# Customer Suite · 企业 CRM 与客户服务管理系统

自托管的 PHP 与 MySQL 应用。按公司和岗位管理客户主档、销售、合同和交付。

## 本地启动

需要 Docker Compose。将 `.env.example` 复制为 `.env`，为 `CRM_DB_PASSWORD` 和 `CRM_MYSQL_ROOT_PASSWORD` 设置不同的长密码。

```sh
docker compose up --build --wait
docker compose exec -T web php bin/crm.php health
```

从宿主机传入 `CRM_ADMIN_PASSWORD`，初始化管理员。密码至少 12 字节。

```sh
docker compose exec -T -e CRM_ADMIN_PASSWORD web php bin/crm.php init-admin admin@example.test 管理员
```

访问 `http://127.0.0.1:8080`。进入「组织与权限」新增公司，建立人员并分配公司岗位。用不同人员登录可以核对各自可见的公司和业务记录。

容器默认只对本机开放。原生运行需要 PHP 8.2+、`mbstring`、`pdo_mysql`、MySQL 8.4，设置 `CRM_DSN`（含 `charset=utf8mb4`）、`CRM_DB_USER` 和 `CRM_DB_PASSWORD` 后运行 `php bin/crm.php migrate`。Web 根目录指向 `public/`。

## 业务流程

| 业务域 | 可验证流程 |
| --- | --- |
| 客户档案 | 独立公司、集团关系、主体归并、档案来源、联系人职位及按用途撤回的联络授权 |
| 营销与线索 | 多活动归因、按邮箱和电话去重、冲突线索复核、并发认领、归属回收和资格确认 |
| 商机与预测 | 线索转商机、阶段校验、产品版本与金额、加权预测、只读历史快照与差异 |
| 报价与合同 | 报价修订、折扣审批、集团分签、签署凭据及保留原件的补充协议 |
| 交付交接 | 双岗位确认范围、变更客户代表与实施人员、跟踪延期和待确认事项、上传并核验合同版本的签收附件 |

[报价、签约与交付操作说明](docs/sales-delivery.md)

## 兼容与验收

- 七份 [产品需求](docs/requirements/01-产品总览-prd.md) 和 [验收标准](docs/requirements/04-验收与运营-prd.md) 随仓库分发。
- 集成测试使用名字以 `crm_test` 开头的独立 MySQL 数据库。显式设置 `CRM_TEST_DATABASE=1`，按 [CI 工作流](.github/workflows/mysql.yml) 执行 MySQL 和 HTTP 测试。
- 授权和业务记录以 MySQL 为准。管理入口和 JSON API 复用同一权限检查及恢复回执。

Apache-2.0 开源许可。参见 [LICENSE](LICENSE) 和 [NOTICE](NOTICE)。
