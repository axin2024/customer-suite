# 订阅业务与续约运营

## 体验两期账单

先安装[三公司演示](demo.md)，再运行 `php bin/crm.php demo-subscriptions demo-admin@example.test`。经理与丙公司客服均可沿回执中的地址查看原账、争议和抵扣。开发者可在隔离的 `crm_test` 库设置 `CRM_TEST_DATABASE=1` 后执行下表中的用例。部署及数据库连接参数见[项目首页](../README.md)。

## 从签约到收款

1. 在经理工作台发布与已签报价明细同产品编码、同币种的套餐，填写生效日、月费、服务等级、指标、月额度和超量单价。签约日期决定冻结的价格版本。
2. 为付款主体选择合同和行号，分配席位给客户本身或其直属子客户。跨公司开通还要求操作人具有目标公司的管理权限。订阅首日含在服务期内，截止日为首个停止服务日。试用转付费时指定生效日。
3. 以计量岗位登录，在同一登录会话先获取 CSRF，再上传用量。相同来源和事件号可重试。工作台「计量接收记录」可查原始用量、请求号和差异结论。
4. 用合同约定的时区归集自然月。月固定费按每个价格段的服务天数加权后一次舍入到分；超量费用按事件归集。同一份订阅的月费、免费额度按套餐计一次，席位数控制合同分配上限。变更预览展示本月原额、新额与差值。
5. 财务生成草账、核对来源和费率、按版本和摘要锁期。客服可从超量行发起争议。财务可以冻结、驳回或抵扣，冻结金额从可收余额中扣除。已锁行保留原额，追溯变更和贷项进入后续账期。
6. 财务在账单工作区导入[回执样例](../examples/subscription-payment-receipts.csv)，将样例中的账单号、修订号、银行参考号与金额替换为实际值。每次最多 500 行，一行失败则整批回滚。可导出已复核账单供发票系统使用。系统登记外部到账，实际收款与开票由对接方办理。
7. 为合同分派客户成功负责人，再按到期月份扫描续约。默认预测取最后服务日的有效付费月费乘 12，未来用量单列考虑。负责人登记挽留行动，赢单要关联已签后续合同，流失要填原因。看板按原币种显示。

## 计量接口

用登录后的会话访问 `GET /api.php?resource=session` 读取当前 CSRF。发送 [JSON 样例](../examples/subscription-usage.json) 前先改为本订阅的指标和服务期内的时间，包括时区偏移。下列环境变量分别对应服务地址、订阅编号和持有账本的公司编号。

```sh
curl --fail-with-body -b cookies.txt \
  -H "X-CSRF-Token: $CRM_CSRF" -H "Idempotency-Key: inspection-delivery-121" \
  -H "Content-Type: application/json" --data-binary @examples/subscription-usage.json \
  "$CRM_URL/subscriptions/$SUBSCRIPTION_ID/usage-events?company=$COMPANY_ID"
```

同步事务提交后返回 `usage_id`、`billing_period`、`disposition` 和 `attempt_id`。内容一致的重送可沿原业务编号查账，变更已用请求键或事件号的内容返回 409 并登记差异；字段错误返回 422 并指明字段。客户管理员需要逐订阅授权，客户与纯计量岗位的可见范围不同。

## 从 v1.1.0 升级与退出试点

先在历史库的隔离备份上运行新版 CLI。以下命令中 COMPANY、MANAGER 和 VERSION 用实际公司编号、具有该公司管理权限的操作者编号和当前试点版本替换。

```sh
php bin/crm.php subscription-preflight COMPANY
php bin/crm.php migrate
php bin/crm.php subscription-backfill COMPANY MANAGER 50
php bin/crm.php subscription-pilot COMPANY MANAGER 0 active
```

预检输出合同、修订、工单、附件数量与摘要。反复回填直至返回的 `pending` 为 0，按合同号核查问题并修正归属后重试。回填保留游标和已查合同版本。启用公司后仍须由销售明确开通订阅。正式升级前保留与所用代码版本对应的完整备份。[备份与恢复](backup-and-restore.md)

退出试点可运行 `php bin/crm.php subscription-pilot COMPANY MANAGER VERSION draining`。停止受理新开通和新计量后，重放已受理请求、处理差异和未决账单，从工作台导出未决 CSV。隔离恢复后按原业务号核对银行回执，再恢复写入。Windows 可用 `./tests/subscription-recovery.ps1 -MysqlBin 'C:/mysql/bin'` 重放中断演练，需可用的 PHP、Git、MySQL 8.4 客户端和服务器以及本地 v1.1.0 标签。[恢复实测](subscription-recovery.json)

## 验收记录

| 编号 | 可复现结果 | 入口 |
| --- | --- | --- |
| A1、A2 | 末个服务日额度 100，截止日额度为零；发布新价后原合约维持原价 | [套餐](../tests/subscription-plans.php)、[订阅](../tests/subscriptions.php) |
| A3、A4 | 同一合同给两家子公司分配 6 席与 4 席；两条独立 MySQL 连接并发开通得到同一订阅号 | [并发用例](../tests/subscription-race.php)、[业务断言](../tests/subscriptions.php) |
| A5、A6 | 10 单位重复三次仍只耗用 10；重送 12 返回 409，接收人能查询并复核差异 | [HTTP 工作台](../tests/subscription-http.php) |
| A7、A8 | 4 月 16 日月费增加 60 元，本期增加 30 元；锁期后补录的变更在后一账期读回 | [变更与重算](../tests/subscriptions.php) |
| A9、A10 | 120 次减免 100 次后产生 40 元；冻结其中 10 元时只允许收取未冻结部分，重复操作保留原账 | [财务与客服联验](../tests/subscription-http.php) |
| A11、A12 | 重跑到期扫描只产生一个案件；无原因无法关闭流失，流失不计入成功分子 | [续约用例](../tests/subscriptions.php) |
| A13 | 三种账号读取同一调整号。3 月账单保留 40 元超量费，4 月冲减 10 元，调整可追溯到原用量与账单行 | [输入、回执和工作台读回](subscription-a13.json) |
| A14 | 1,000 份订阅、100,000 条用量，三轮分别在 8.85、7.91、7.03 秒生成 1,000 份草账，每轮 51,000 行且摘要相同 | [原始报告](subscription-capacity.json)、[复测程序](../tests/subscription-capacity.php) |

容量测试在一台 Windows AMD64 主机执行，PHP 8.2.30，MySQL 8.4.13，InnoDB 缓冲池 128 MiB。程序直接准备合成用量后调用正式批次出账入口，计时包括账单落库。预热 7.95 秒；三轮采样均为零拒绝、零超时。该测试测量本机批量出账时间，网络计量吞吐与多租户同时出账需在部署环境另测。

恢复演练从旧标签建库，保留历史合同、工单及签收附件。首笔计量提交后、返回响应前中断 PHP 并重启 MySQL，按原身份重试。恢复到另一个库后，原事件仍只有一条，计量水位为 120 单位，草账 140 元，附件内容摘要一致。[完整需求](requirements/08-订阅业务与客户续约运营.md)
