# 备份、恢复与校验

Customer Suite 将客户、权限、业务事件、操作回执和已上传的签收附件一同保存在 MySQL。签收文件在 `delivery_acceptances` 保存正文、字节数与 SHA-256。Webhook 参数、数据库连接配置和应用版本由部署侧保管。

## 备份和恢复到隔离库

以下使用配置好的 MySQL 客户端登录路径。备份期间避免运行数据迁移。InnoDB 事务快照会包含附件二进制内容及同一时点的业务关联。

```sh
mysqldump --login-path=crm-backup --single-transaction --hex-blob \
  --no-tablespaces --set-gtid-purged=OFF --result-file=customer-suite.sql customer_suite
sha256sum customer-suite.sql
```

在隔离 MySQL 实例创建恢复库，导入备份。恢复账号需要导入权限，应用运行账号按原部署的职责授权。

```sh
mysql --login-path=crm-recovery -e 'CREATE DATABASE crm_test_recovery CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci'
mysql --login-path=crm-recovery crm_test_recovery < customer-suite.sql
```

用备份时对应的应用版本连接恢复库并运行 `php bin/crm.php health`。确认数据库就绪后，按实际发布的迁移顺序验证新版本。如果要验证渠道回放，在隔离环境选用 `CRM_OUTREACH_MODE=replay`。

## 用合成数据比对恢复结果

先在原始隔离库生成联测记录并保存清单。清单只引用编号和断言结果。

```sh
CRM_TEST_DATABASE=1 php tests/management-reports.php --evidence > group.jsonl
CRM_TEST_DATABASE=1 php tests/recovery-check.php group.jsonl > before.json
```

将该库备份并导入一个空的隔离库，修改连接参数后运行以下命令。

```sh
CRM_TEST_DATABASE=1 php tests/recovery-check.php group.jsonl > after.json
cmp before.json after.json
```

检查脚本只读取业务记录和迁移历史。前后结果可逐字节比对。输出包括三家合同与报价来源、甲公司预测及商机版本、乙公司唯一续约、丙公司原事件与 SLA 时钟、报表源阶段和汇率版本，以及全部签收附件的引用和摘要。

## 本地实测

在 MySQL 8.4.13 中将合成测试库导入另一个新建库。原库和恢复库业务清单一致。恢复后仍有同一报价的三份合同、60 万元的预测加权值、一份乙公司续约和一份丙公司来单。工单响应截止保持为 `2026-11-23 01:20:00 UTC`。4 份签收附件的字节数和 SHA-256 均与数据库中的归档记录相符。
