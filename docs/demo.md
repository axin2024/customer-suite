# 三公司演示

星桥企业服务为同一工业客户开展集团议价，甲、乙、丙各自签署服务合同。甲完成一份培训交付并形成下一份 100 万元商机，乙跟进即将到期的运维合同，丙用工作日历受理设备工单。所有客户、来单、签收附件及联系方式均为合成数据。

## 从空库启动

复制项目根目录的 `.env.example` 为 `.env.demo`，设置两个独立的数据库密码。将 `CRM_DB_NAME` 改为 `crm_demo`，`CRM_HTTP_PORT` 改为 `8081`，保留 `CRM_OUTREACH_MODE=replay`。使用独立的 Compose 项目名与数据卷。

```sh
docker compose --env-file .env.demo -p crm-demo up --build --wait
docker compose --env-file .env.demo -p crm-demo exec -T web php bin/crm.php health
```

从宿主机环境传入 `CRM_ADMIN_PASSWORD` 和 `CRM_DEMO_PASSWORD`。使用不同的长密码，至少 12 字节。第二个密码用于五个本地演示岗位。

```sh
docker compose --env-file .env.demo -p crm-demo exec -T -e CRM_ADMIN_PASSWORD web \
  php bin/crm.php init-admin demo-admin@example.test 演示管理员
docker compose --env-file .env.demo -p crm-demo exec -T -e CRM_DEMO_PASSWORD web \
  php bin/crm.php demo-install demo-admin@example.test
```

原生安装可在空的 `crm_demo` 数据库设置与 [README](../README.md) 相同的连接变量，依次执行 `migrate`、`init-admin`、`demo-install`，然后启动 PHP。导入仅在名为 `crm_demo` 或 `crm_demo_` 开头的回放库中可用。第一次导入要求尚未创建业务公司。再次运行 `demo-install` 会返回相同的对象编号、工作日历时限和签收摘要。重置案例可另起一个 Compose 项目和空数据卷。

## 界面验收

访问 `http://127.0.0.1:8081`，使用下表邮箱和 `CRM_DEMO_PASSWORD` 登录。导入命令返回本次编号，供查询和演示使用。

| 登录邮箱 | 公司与页面 | 核对结果 |
| --- | --- | --- |
| `manager@demo.example.test` | 甲 → 预测快照 → 甲分公司销售预测 | 100 万元商机，谈判阶段胜率 60%，加权 60 万元。 |
| `a-sales@demo.example.test` | 甲 → 线索分配 → 已转化 | 自动化设备扩容线索有展会和官网两个触点，链接到对应商机。另有一条待分派咨询可操作。 |
| `a-delivery@demo.example.test` | 甲 → 交付交接 → 已验收 | 可下载合成文件「设备培训签收.txt」，文件摘要与导入回执的 `handover.sha256` 一致。 |
| `b-success@demo.example.test` | 乙 → 客户成功、续约工作台、交付交接 | 一份续约和对应商机，触达标注「回放完成」。乙的独立交付待实施确认。 |
| `c-support@demo.example.test` | 丙 → 服务工单 | 周五 17:50 的 P2 首次响应截止为周一 09:20（上海时间，页面另列 UTC）。过期合同来单进入资格待复核。 |
| `manager@demo.example.test` | 总部 → 经营分析 | 集团三公司快照可按甲、乙、丙下钻到独立合同。 |
| `c-support@demo.example.test` | 丙 → 经营分析 → 导出当前范围 CSV | CSV 只含丙的合同。直接访问甲合同、签收附件或用丙导出接口筛选甲公司均返回 404。 |

## 再办理一次

- 用集团经理切至乙并扫描未来 30 天。重复扫描沿用已创建的续约周期，现有样例仍只有一份续约。
- 用甲销售打开「批量任务」，勾选巡检咨询并提交。用输出中的 `companies.a` 和 `users.manager.id` 执行 `php bin/crm.php batch-run <公司编号> <经理编号>`。刷新任务页，检查原记录与回执。
- 用集团经理切至丙，在资格待复核工单上重查过期合同。仍无法受理时，保留来单和来源编号以便后续人工复核。

在独立的已安装演示库执行 `CRM_TEST_DATABASE=1 php tests/demo-verify.php demo-admin@example.test` 可自动核对上述关键业务数据。该命令需传入导入时的 `CRM_DEMO_PASSWORD`，并运行一次重复续约扫描。

## 丙公司的两期账单

在上述演示库执行下列命令。导入会在丙公司的设备服务合同上开通一个月费 100 元、含 100 次巡检调用的套餐，登记 120 次调用，形成原账，再通过客服争议纠正其中 5 次。

```sh
docker compose --env-file .env.demo -p crm-demo exec -T web php bin/crm.php demo-subscriptions demo-admin@example.test
```

登录经理或丙公司客服账号，在演示地址后加上回执中的 `workbench_path`。查看当前账单的 −10 元调整、原始用量号和争议号，再打开上一期查看 100 元固定费和 40 元超量费。两期合计分别为 140 元和 90 元。重复导入返回相同的业务编号。
