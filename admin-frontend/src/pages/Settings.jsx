import useWritePermission from '../hooks/useWritePermission';
import React, { useCallback, useEffect, useState } from 'react';
import {
  ProForm,
  ProFormText,
  ProFormTextArea,
  ProFormDigit,
  ProFormSelect,
  ProFormSwitch,
} from '@ant-design/pro-components';
import { Card, Tabs, Spin, message, Alert, Button, Input, Select, Space, Typography, Result } from 'antd';
import {
  SendOutlined,
  CheckCircleFilled,
  LoadingOutlined,
  ExclamationCircleFilled,
} from '@ant-design/icons';
import { getSettings, updateSettings, sendTestEmail, sendTestTelegram } from '../services/api';
import ImageUploader from '../components/ImageUploader';
import RichTextEditor from '../components/RichTextEditor';
import { createSerialSaveQueue } from '../utils/serialSaveQueue';
import { onAdminSessionChange } from '../services/sessionLifecycle';

const THEME_LABELS = {
  default: '默认主题（现代数字工作台）',
  minimal: '极简主题（卡片网格）',
  modern: '经典暖色主题',
};

const AUTO_SAVE_DELAY = 800;
const OWNER_SETTINGS = new Set([
  'epay_api_url',
  'epay_merchant_id',
  'epay_merchant_key',
  'epusdt_api_url',
  'epusdt_api_token',
  'usdt_gateway',
  'payment_reconciliation_enabled',
  'email_template_subject',
  'email_template_body',
  'mail_host',
  'mail_port',
  'mail_username',
  'mail_password',
  'mail_encryption',
  'mail_from_address',
  'mail_from_name',
  'telegram_bot_token',
  'telegram_chat_id',
  'telegram_enabled',
  'telegram_broadcast_bot_token',
  'telegram_notify_product_sold',
  'telegram_notify_product_listed',
  'telegram_target_bot_enabled',
  'telegram_target_bot_chat_ids',
  'telegram_target_group_enabled',
  'telegram_target_group_ids',
  'telegram_target_channel_enabled',
  'telegram_target_channel_ids',
  'turnstile_site_key',
  'turnstile_secret_key',
  'order_expire_minutes',
  'checkout_require_key',
  'order_max_pending_per_ip',
  'order_max_pending_per_email',
  'order_max_locked_percent',
  'honeypot_enabled',
  'honeypot_ban_minutes',
  'honeypot_whitelist',
  'honeypot_skip_reserved_ips',
  'refund_enabled',
  'backup_auto_enabled',
  'backup_schedule_time',
  'backup_retention_count',
  'backup_retention_days',
  'backup_sync_directory',
  'backup_stale_hours',
  'backup_min_free_mb',
]);

const settingsSaver = createSerialSaveQueue(updateSettings, AUTO_SAVE_DELAY);
onAdminSessionChange(() => settingsSaver.reset());

window.addEventListener('beforeunload', (event) => {
  if (!settingsSaver.pending()) return;
  event.preventDefault();
  event.returnValue = '';
});

function AutoSaveStatus({ state, onRetry }) {
  if (state.status === 'saving') {
    return (
      <Typography.Text type="secondary">
        <LoadingOutlined style={{ marginRight: 6 }} />
        正在保存更改…
      </Typography.Text>
    );
  }

  if (state.status === 'saved') {
    return (
      <Typography.Text type="success">
        <CheckCircleFilled style={{ marginRight: 6 }} />
        更改已保存
      </Typography.Text>
    );
  }

  if (state.status === 'error') {
    return (
      <Space size={8}>
        <Typography.Text type="danger">
          <ExclamationCircleFilled style={{ marginRight: 6 }} />
          {state.error}
        </Typography.Text>
        <Button size="small" onClick={onRetry}>
          重试
        </Button>
      </Space>
    );
  }

  return <Typography.Text type="secondary">配置项变更后将自动保存</Typography.Text>;
}

function SettingsForm({ canWrite, initialValues, tabItems, saveIndicator, onValuesChange }) {
  const [form] = ProForm.useForm();
  useEffect(() => {
    form.setFieldsValue(initialValues);
  }, [form, initialValues]);

  return (
    <ProForm
      name="system-settings"
      disabled={!canWrite}
      form={form}
      omitNil={false}
      initialValues={initialValues}
      onValuesChange={onValuesChange}
      submitter={false}
    >
      <Tabs items={tabItems} tabBarExtraContent={saveIndicator} />
    </ProForm>
  );
}

export default function Settings() {
  const canWrite = useWritePermission('settings');
  const canConfigurePrivate = canWrite;
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState('');
  const [loadRevision, setLoadRevision] = useState(0);
  const [initialValues, setInitialValues] = useState({});
  const [testTo, setTestTo] = useState('');
  const [testing, setTesting] = useState(false);
  const [testTelegramScope, setTestTelegramScope] = useState('auto');
  const [testTelegramTarget, setTestTelegramTarget] = useState('');
  const [testingTelegram, setTestingTelegram] = useState(false);
  const [themes, setThemes] = useState(['default']);
  const [autoSave, setAutoSave] = useState({ status: 'idle', error: '' });

  useEffect(() => settingsSaver.subscribe(setAutoSave), []);

  useEffect(() => {
    let current = true;
    setLoading(true);
    setLoadError('');
    (canWrite ? settingsSaver.flush().catch(() => {}) : Promise.resolve())
      .then(() => getSettings())
      .then((res) => {
        if (!current) return;
        const data = res.data?.data || res.data;
        if (Array.isArray(data._available_themes) && data._available_themes.length) {
          setThemes(data._available_themes);
        }
        const retained = canWrite
          ? Object.fromEntries(
              Object.entries(settingsSaver.unsaved()).filter(
                ([key]) => canConfigurePrivate || !OWNER_SETTINGS.has(key)
              )
            )
          : {};
        const normalised = { ...data, ...retained };
        for (const [key, defaultValue] of Object.entries({
          telegram_enabled: false,
          telegram_notify_product_sold: false,
          telegram_notify_product_listed: false,
          telegram_target_bot_enabled: false,
          telegram_target_group_enabled: false,
          telegram_target_channel_enabled: false,
          checkout_require_key: false,
          honeypot_enabled: true,
          honeypot_skip_reserved_ips: true,
          payment_reconciliation_enabled: false,
          refund_enabled: false,
          backup_auto_enabled: false,
        })) {
          normalised[key] =
            normalised[key] == null ? defaultValue : normalised[key] === '1' || normalised[key] === true;
        }
        normalised.order_max_pending_per_ip ??= 3;
        normalised.order_max_pending_per_email ??= 3;
        normalised.order_max_locked_percent ??= 80;
        normalised.honeypot_ban_minutes ??= 10080;
        normalised.backup_schedule_time ||= '03:00';
        normalised.backup_retention_count ||= 14;
        normalised.backup_retention_days ||= 30;
        normalised.backup_stale_hours ||= 30;
        normalised.backup_min_free_mb ??= 1024;
        delete normalised._available_themes;
        setInitialValues(normalised);
      })
      .catch(() => {
        if (current) setLoadError('加载设置失败，请检查网络连接后重试。');
      })
      .finally(() => {
        if (current) setLoading(false);
      });
    return () => {
      current = false;
    };
  }, [canWrite, canConfigurePrivate, loadRevision]);

  const flush = useCallback(
    () => (canWrite && !loading && !loadError ? settingsSaver.flush().catch(() => {}) : Promise.resolve()),
    [canWrite, loading, loadError]
  );

  const handleValuesChange = useCallback(
    (changed) => {
      if (canWrite && !loading && !loadError) {
        settingsSaver.enqueue(
          Object.fromEntries(
            Object.entries(changed).filter(([key]) => canConfigurePrivate || !OWNER_SETTINGS.has(key))
          )
        );
      }
    },
    [canWrite, canConfigurePrivate, loading, loadError]
  );

  useEffect(() => {
    return () => {
      if (canWrite) settingsSaver.flush().catch(() => {});
    };
  }, [canWrite]);

  const handleTestEmail = async () => {
    if (!canConfigurePrivate || loading || loadError) return;
    if (!testTo) {
      message.warning('请填写接收测试邮件的目标邮箱地址');
      return;
    }
    setTesting(true);
    try {
      await settingsSaver.flush();
      const res = await sendTestEmail(testTo);
      message.success(res.data?.message || '测试邮件已提交发送');
    } catch (err) {
      message.error(err.response?.data?.message || '测试邮件发送失败', 8);
    } finally {
      setTesting(false);
    }
  };

  const handleTestTelegram = async () => {
    if (!canConfigurePrivate || loading || loadError) return;
    setTestingTelegram(true);
    try {
      await settingsSaver.flush();
      const payload = { scope: testTelegramScope };
      if (testTelegramTarget.trim()) {
        payload.target = testTelegramTarget.trim();
      }
      const res = await sendTestTelegram(payload);
      message.success(res.data?.message || 'Telegram 测试通知已发送');
    } catch (err) {
      message.error(err.response?.data?.message || 'Telegram 测试通知发送失败', 8);
    } finally {
      setTestingTelegram(false);
    }
  };

  if (loading) {
    return (
      <div style={{ textAlign: 'center', padding: 100 }}>
        <Spin size="large" />
      </div>
    );
  }

  if (loadError) {
    return (
      <Result
        status="error"
        title={loadError}
        extra={<Button type="primary" onClick={() => setLoadRevision((value) => value + 1)}>重新加载设置</Button>}
      />
    );
  }

  const tabItems = [
    {
      key: 'basic',
      label: '基本设置',
      children: (
        <>
          <div className="admin-form-grid-2">
            <ProFormText name="site_name" label="站点名称" />
            <ProFormSelect
              name="site_theme"
              label="前台视觉主题"
              options={themes.map((t) => ({ label: THEME_LABELS[t] || t, value: t }))}
              allowClear={false}
              extra="切换后即时生效。系统自动加载 resources/views/templates/ 目录下的可用模板。"
            />
          </div>
          <ProFormTextArea name="site_description" label="站点简介" fieldProps={{ rows: 3 }} />
          <div className="admin-form-grid-3">
            <ProFormText name="contact_text" label="客服联系说明" placeholder="例如：在线客服 / 工单邮箱" />
            <ProFormText name="contact_url" label="客服跳转链接" placeholder="例如：https://t.me/example" />
            <ProFormText
              name="footer_powered_by"
              label="页脚系统标识"
              tooltip="展示在页脚 Powered by 区域；留空则隐藏该标识。"
              placeholder="留空则不展示"
            />
          </div>
          <div className="admin-form-grid-3">
            <ProForm.Item name="site_logo" label="站点品牌 Logo" extra="展示于前台导航栏左侧，高度自适应缩放。">
              <ImageUploader disabled={!canWrite} />
            </ProForm.Item>
            <ProForm.Item name="site_favicon" label="浏览器图标（Favicon）" extra="建议使用 .ico 或 32×32 PNG 格式图标。">
              <ImageUploader disabled={!canWrite} />
            </ProForm.Item>
            <ProForm.Item name="contact_qr_image" label="客服二维码图片">
              <ImageUploader disabled={!canWrite} />
            </ProForm.Item>
          </div>
          <ProForm.Item name="site_announcement" label="常驻横幅公告" extra="展示在商城首页与商品详情页顶部通知栏。">
            <RichTextEditor disabled={!canWrite} placeholder="请输入站点常驻公告内容" height={200} />
          </ProForm.Item>
          <ProForm.Item
            name="popup_announcement"
            label="模态弹窗公告"
            extra="留空则关闭弹窗。访客首次进入首页或商品页时弹出，需阅读 5 秒后方可关闭；更新内容后将重新触发提示。"
          >
            <RichTextEditor disabled={!canWrite} placeholder="请输入重要运营通知内容（建议仅用于关键公告）" height={190} />
          </ProForm.Item>
          <ProFormDigit
            name="popup_interval_hours"
            label="弹窗重复提示间隔（小时）"
            min={0}
            fieldProps={{ precision: 0 }}
            extra="设定周期内同一访客不会重复收到相同弹窗公告；填 0 表示每次访问均弹出。"
          />
        </>
      ),
    },
    {
      key: 'seo',
      label: 'SEO 设置',
      children: (
        <>
          <div className="admin-form-grid-2">
            <ProFormText
              name="site_url"
              label="标准站点域名（Canonical URL）"
              extra="用于生成规范链接、站点地图及搜索引擎主动推送地址，如 https://shop.example.com"
            />
            <ProFormText
              name="bing_indexnow_key"
              label="IndexNow 验证密钥"
              extra="支持 8–128 位字母、数字或连字符；系统将自动托管对应的密钥校验文件。"
            />
          </div>
          <ProFormText.Password name="baidu_push_token" label="百度搜索资源平台推送 Token" />
          <div className="admin-form-grid-2">
            <ProFormText name="seo_default_title" label="全站默认 SEO 标题" />
            <ProFormText name="seo_default_keywords" label="全站默认 SEO 关键词" placeholder="多个关键词用英文逗号分隔" />
          </div>
          <ProFormTextArea name="seo_default_description" label="全站默认 SEO 描述" fieldProps={{ rows: 3 }} />
        </>
      ),
    },
    {
      key: 'payment',
      label: '支付设置',
      children: (
        <>
          <ProFormSwitch
            name="payment_reconciliation_enabled"
            disabled={!canConfigurePrivate}
            label="启用每 5 分钟自动付款对账"
            extra="总开关：同时作用于 EPay 和 USDT，每 5 分钟核对近期付款。需网关支持 HTTPS 订单查询接口，对账异常可在订单详情与任务中心查看。"
          />
          <div className="admin-form-section-title">易支付（EPay）网关配置</div>
          <div className="admin-form-grid-2">
            <ProFormText name="epay_api_url" disabled={!canConfigurePrivate} label="EPay 网关接口地址" placeholder="如 https://pay.example.com" />
            <ProFormText name="epay_merchant_id" disabled={!canConfigurePrivate} label="EPay 商户 ID" />
          </div>
          <ProFormText name="epay_merchant_key" disabled={!canConfigurePrivate} label="EPay 商户通信密钥" />

          <div className="admin-form-section-title">USDT 加密货币网关配置</div>
          <div className="admin-form-grid-2">
            <ProFormSelect
              name="usdt_gateway"
              disabled={!canConfigurePrivate}
              label="网关协议类型"
              options={[
                { label: 'EPUSDT（标准 API Token + MD5 协议）', value: 'epusdt' },
                { label: 'BEpusdt（支持多链指定收款）', value: 'bepusdt' },
              ]}
              extra="请与实际部署的 USDT 收款服务协议保持一致，协议不匹配将导致回调签名验证失败。"
            />
            <ProFormText name="epusdt_api_url" disabled={!canConfigurePrivate} label="USDT 网关地址" placeholder="如 https://usdt.example.com" />
          </div>
          <ProFormText name="epusdt_api_token" disabled={!canConfigurePrivate} label="USDT 接口通信 Token" />
        </>
      ),
    },
    {
      key: 'mail',
      label: '邮件设置',
      children: (
        <>
          <Alert
            type="info"
            showIcon
            style={{ marginBottom: 16 }}
            message="SMTP 邮件投递服务配置"
            description="系统通过此处配置的 SMTP 通道向买家发送卡密交付与退款通知邮件。买家同时可通过前台订单查询页自助提取卡密；修改参数后请执行发信测试以验证连通性。"
          />
          <div className="admin-form-grid-3">
            <ProFormText
              name="mail_host"
              disabled={!canConfigurePrivate}
              label="SMTP 服务器主机"
              placeholder="如 smtp.qq.com / smtp.gmail.com"
              extra="留空则回退读取服务器环境变量配置。"
            />
            <ProFormSelect
              name="mail_encryption"
              disabled={!canConfigurePrivate}
              label="传输加密协议"
              options={[
                { label: 'SSL / TLS 隐式加密（常用端口 465）', value: 'ssl' },
                { label: 'STARTTLS 显式加密（常用端口 587）', value: 'tls' },
                { label: '明文传输（端口 25，不推荐）', value: 'none' },
              ]}
              extra="请与邮件服务商要求的端口加密协议保持一致。"
            />
            <ProFormDigit
              name="mail_port"
              disabled={!canConfigurePrivate}
              label="SMTP 端口"
              min={1}
              max={65535}
              fieldProps={{ precision: 0 }}
              extra="SSL 常用 465，STARTTLS 常用 587。"
            />
          </div>
          <div className="admin-form-grid-2">
            <ProFormText name="mail_username" disabled={!canConfigurePrivate} label="SMTP 认证账号" placeholder="通常为完整发件邮箱地址" />
            <ProFormText.Password
              name="mail_password"
              disabled={!canConfigurePrivate}
              label="SMTP 认证密码 / 授权码"
              extra="部分邮件服务商需填写客户端专用授权码。已保存密钥以掩码展示，未变更请保持原样。"
            />
          </div>
          <div className="admin-form-grid-2">
            <ProFormText
              name="mail_from_address"
              disabled={!canConfigurePrivate}
              label="发件人邮箱地址"
              extra="多数邮件服务商要求发件地址与认证账号一致。"
            />
            <ProFormText name="mail_from_name" disabled={!canConfigurePrivate} label="发件人显示名称" placeholder="留空默认采用站点名称" />
          </div>

          <Card size="small" title="SMTP 连通性测试" style={{ marginTop: 8, marginBottom: 20 }}>
            <Typography.Paragraph type="secondary" style={{ marginBottom: 12 }}>
              点击发送后将自动保存当前编辑的邮件参数并投递测试邮件。正式业务通知若投递失败将自动转入任务中心重试队列，不影响订单履约。
            </Typography.Paragraph>
            <Space.Compact style={{ width: '100%', maxWidth: 460 }}>
              <Input
                type="email"
                disabled={!canConfigurePrivate}
                value={testTo}
                onChange={(e) => setTestTo(e.target.value)}
                onPressEnter={handleTestEmail}
                placeholder="输入接收测试邮件的邮箱地址"
                allowClear
              />
              <Button
                type="primary"
                icon={<SendOutlined />}
                loading={testing}
                disabled={!canConfigurePrivate}
                onClick={handleTestEmail}
              >
                发送测试
              </Button>
            </Space.Compact>
          </Card>

          <div className="admin-form-section-title">交付邮件正文模板</div>
          <Alert
            type="info"
            showIcon
            style={{ marginBottom: 16 }}
            message="支持的模板占位变量：{{site_name}}、{{order_no}}、{{product_name}}、{{quantity}}、{{total_amount}}、{{cards}}"
            description="{{cards}} 将替换为订单交付的全部卡密内容（每行一条）。请确保变量拼写准确。"
          />
          <ProFormText
            name="email_template_subject"
            disabled={!canConfigurePrivate}
            label="交付邮件主题"
            placeholder="{{site_name}} - 订单 {{order_no}} 卡密交付通知"
          />
          <ProFormTextArea
            name="email_template_body"
            disabled={!canConfigurePrivate}
            label="交付邮件正文"
            fieldProps={{ rows: 12 }}
            extra="支持纯文本与安全 HTML 格式；纯文本换行将自动保留。"
          />
        </>
      ),
    },
    {
      key: 'telegram',
      label: 'Telegram 通知',
      children: (
        <>
          <Alert
            type="info"
            showIcon
            style={{ marginBottom: 16 }}
            message="Telegram 机器人实时通知与多端播报"
            description="支持向站长私聊推送内部订单与库存预警，并支持在商品售出或商品上架/补货时自动向指定的 Telegram 机器人私聊、群组（含超级群话题）及频道推送动态播报。可通过 @BotFather 创建机器人获取 Bot Token，通过 @userinfobot 获取个人/群组 Chat ID。"
          />

          <div className="admin-form-section-title">基础机器人与站长运营告警</div>
          <ProFormSwitch
            name="telegram_enabled"
            disabled={!canConfigurePrivate}
            label="启用站长内部运营告警（新订单详情 / 付款待核对 / 低库存预警）"
            extra="包含订单号与买家联系邮箱等敏感交易信息，建议仅推送至站长个人私聊或内部管理群。"
          />
          <div className="admin-form-grid-2">
            <ProFormText.Password
              name="telegram_bot_token"
              disabled={!canConfigurePrivate}
              label="默认机器人 Token（Bot Token）"
              fieldProps={{ autoCorrect: 'off', autoCapitalize: 'none', spellCheck: false }}
              extra="从 @BotFather 获取的完整 Token；已保存的 Token 以掩码显示，未变更请保持原样。"
            />
            <ProFormText
              name="telegram_chat_id"
              disabled={!canConfigurePrivate}
              label="站长默认接收会话 ID（Chat ID）"
              placeholder="如 123456789 或 -1001234567890#话题ID"
              fieldProps={{ autoCorrect: 'off', autoCapitalize: 'none', spellCheck: false }}
              extra="填写站长个人 Chat ID 或内部管理群 ID（支持超级群 #话题ID）。"
            />
          </div>

          <div className="admin-form-section-title">商品售出与上架播报机器人</div>
          <div className="admin-form-grid-2">
            <ProFormSwitch
              name="telegram_notify_product_sold"
              disabled={!canConfigurePrivate}
              label="开启商品售出通知"
              extra="当订单支付发货或后台登记售出时自动推送；发往群组与频道的播报会自动隐去买家邮箱与订单号。"
            />
            <ProFormSwitch
              name="telegram_notify_product_listed"
              disabled={!canConfigurePrivate}
              label="开启商品上架 / 补货通知"
              extra="当新建上架商品、下架商品重新上架，或为已上架商品导入新卡密补货时，自动推送价格、库存与直达购买链接。"
            />
          </div>
          <ProFormText.Password
            name="telegram_broadcast_bot_token"
            disabled={!canConfigurePrivate}
            label="指定播报机器人 Token（可选）"
            placeholder="留空则默认共用上方「默认机器人 Token」"
            fieldProps={{ autoCorrect: 'off', autoCapitalize: 'none', spellCheck: false }}
            extra="如需使用独立的公开播报机器人向客户群组或频道发送售出/上架通知，可单独填写其 Bot Token；留空则共用默认机器人。"
          />

          <div className="admin-form-section-title">可选发送目标（可同时开启任意一项或多项）</div>
          <Card size="small" style={{ marginBottom: 12 }} title="1. 发送给指定 Telegram 机器人（私聊会话）">
            <ProFormSwitch
              name="telegram_target_bot_enabled"
              disabled={!canConfigurePrivate}
              label="启用推送至机器人私聊"
            />
            <ProFormTextArea
              name="telegram_target_bot_chat_ids"
              disabled={!canConfigurePrivate}
              label="私聊接收人 Chat ID"
              placeholder="如 123456789, 987654321（留空默认回退使用站长接收会话 ID）"
              fieldProps={{ rows: 2, autoCorrect: 'off', autoCapitalize: 'none', spellCheck: false }}
              extra="接收人需先打开对应机器人点击 Start（发送 /start）激活对话；支持填写多个个人数字 Chat ID，用英文逗号或换行分隔。"
            />
          </Card>

          <Card size="small" style={{ marginBottom: 12 }} title="2. 发送给指定 Telegram 群组">
            <ProFormSwitch
              name="telegram_target_group_enabled"
              disabled={!canConfigurePrivate}
              label="启用推送至 Telegram 群组"
            />
            <ProFormTextArea
              name="telegram_target_group_ids"
              disabled={!canConfigurePrivate}
              label="目标群组 Chat ID / @公开群用户名"
              placeholder="如 -1001234567890 或 -1001234567890#12 或 @my_shop_group"
              fieldProps={{ rows: 2, autoCorrect: 'off', autoCapitalize: 'none', spellCheck: false }}
              extra="请先将机器人拉入目标群组并确保允许发言。支持普通群（-987654321）、超级群（-1001234567890）、超级群指定话题（-1001234567890#话题ID）及公开群（@group_name），多个群组用逗号或换行分隔。"
            />
          </Card>

          <Card size="small" style={{ marginBottom: 16 }} title="3. 发送给指定 Telegram 频道">
            <ProFormSwitch
              name="telegram_target_channel_enabled"
              disabled={!canConfigurePrivate}
              label="启用推送至 Telegram 频道"
            />
            <ProFormTextArea
              name="telegram_target_channel_ids"
              disabled={!canConfigurePrivate}
              label="目标频道 @用户名 / 频道 ID"
              placeholder="如 @my_shop_channel 或 https://t.me/my_shop_channel 或 -1001234567890"
              fieldProps={{ rows: 2, autoCorrect: 'off', autoCapitalize: 'none', spellCheck: false }}
              extra="必须先将机器人添加为目标频道的管理员，并开启「发布消息（Post Messages）」权限。支持公开频道 @用户名、t.me 链接或私有频道 -100 开头的数字 ID，多个频道用逗号或换行分隔。"
            />
          </Card>

          <Card size="small" title="Telegram 推送连通性测试" style={{ marginTop: 8, marginBottom: 8 }}>
            <Typography.Paragraph type="secondary" style={{ marginBottom: 12 }}>
              点击发送后将自动保存当前编辑的 Telegram 配置并立即推送测试消息。留空右侧临时目标时，将按所选范围测试当前已开启的接收端；正式业务通知若遇到网络波动将自动转入任务中心重试队列。
            </Typography.Paragraph>
            <Space wrap style={{ width: '100%' }}>
              <Select
                disabled={!canConfigurePrivate}
                value={testTelegramScope}
                onChange={setTestTelegramScope}
                style={{ width: 200 }}
                options={[
                  { label: '全部已启用目标', value: 'auto' },
                  { label: '仅播报目标（私聊/群/频道）', value: 'broadcast' },
                  { label: '仅机器人私聊目标', value: 'bot' },
                  { label: '仅 Telegram 群组', value: 'group' },
                  { label: '仅 Telegram 频道', value: 'channel' },
                  { label: '仅站长告警会话', value: 'admin' },
                ]}
              />
              <Space.Compact style={{ width: 440, maxWidth: '100%' }}>
                <Input
                  type="text"
                  disabled={!canConfigurePrivate}
                  value={testTelegramTarget}
                  onChange={(e) => setTestTelegramTarget(e.target.value)}
                  onPressEnter={(e) => {
                    if (e.nativeEvent?.isComposing) return;
                    e.preventDefault();
                    handleTestTelegram();
                  }}
                  autoCorrect="off"
                  autoCapitalize="none"
                  spellCheck={false}
                  placeholder="可选：临时指定测试 Chat ID 或 @频道"
                  allowClear
                />
                <Button
                  type="primary"
                  htmlType="button"
                  icon={<SendOutlined />}
                  loading={testingTelegram}
                  disabled={!canConfigurePrivate}
                  onClick={handleTestTelegram}
                >
                  发送测试通知
                </Button>
              </Space.Compact>
            </Space>
          </Card>
        </>
      ),
    },
    {
      key: 'order',
      label: '交易与售后',
      children: (
        <>
          <ProFormDigit
            name="order_expire_minutes"
            disabled={!canConfigurePrivate}
            label="未支付订单超时关闭时间（分钟）"
            min={5}
            max={10080}
            fieldProps={{ precision: 0 }}
            extra="订单超过该时长仍未完成支付将自动关闭并释放锁定的卡密库存。"
          />
          <ProFormSwitch
            name="refund_enabled"
            disabled={!canConfigurePrivate}
            label="允许提交退款申请"
            extra="关闭后前台买家与后台订单详情均无法新建退款申请；历史已登记的退款工单仍可在退款管理中正常流转。"
          />
        </>
      ),
    },
    {
      key: 'security',
      label: '安全与风控',
      children: (
        <>
          <div className="admin-form-section-title">Cloudflare Turnstile 人机校验</div>
          <div className="admin-form-grid-2">
            <ProFormText name="turnstile_site_key" disabled={!canConfigurePrivate} label="Turnstile Site Key" />
            <ProFormText.Password name="turnstile_secret_key" disabled={!canConfigurePrivate} label="Turnstile Secret Key" />
          </div>

          <div className="admin-form-section-title">下单防刷与恶意锁库存限额</div>
          <ProFormSwitch
            name="checkout_require_key"
            disabled={!canConfigurePrivate}
            label="强制校验页面下单令牌（checkout_key）"
            extra="开启后下单必须携带当前会话签发的页面令牌，直接阻断未加载商品页的无状态脚本裸发 POST 请求。"
          />
          <div className="admin-form-grid-2">
            <ProFormDigit
              name="order_max_pending_per_ip"
              disabled={!canConfigurePrivate}
              label="单 IP / IPv6 子网最大待支付单数"
              min={1}
              max={50}
              fieldProps={{ precision: 0 }}
              extra="默认 3 笔；同一来源未支付订单达到上限后将拒绝继续锁库存。"
            />
            <ProFormDigit
              name="order_max_pending_per_email"
              disabled={!canConfigurePrivate}
              label="单邮箱最大待支付单数"
              min={1}
              max={50}
              fieldProps={{ precision: 0 }}
              extra="默认 3 笔；防止秒换代理 IP 使用同一邮箱批量占空库存。"
            />
          </div>
          <ProFormDigit
            name="order_max_locked_percent"
            disabled={!canConfigurePrivate}
            label="单商品未付锁库保护上限（%）"
            min={20}
            max={100}
            fieldProps={{ precision: 0 }}
            extra="默认 80%（填 100 表示不限制）；当某商品待支付锁库数已达 10 件且超过该比例时暂停继续占库，为真实付款买家保留现货。"
          />

          <div className="admin-form-section-title">自动化恶意扫描防护（Honeypot）</div>
          <div className="admin-form-grid-2">
            <ProFormSwitch
              name="honeypot_enabled"
              disabled={!canConfigurePrivate}
              label="启用敏感路径探测拦截"
              extra="自动封禁探测高危路径的来源 IP，封禁记录可在风控黑名单中查看或提前解除。"
            />
            <ProFormSwitch
              name="honeypot_skip_reserved_ips"
              disabled={!canConfigurePrivate}
              label="豁免私网与保留地址段"
              extra="建议在本地调测、内网探针及容器健康检查环境中保持开启。"
            />
          </div>
          <ProFormDigit
            name="honeypot_ban_minutes"
            disabled={!canConfigurePrivate}
            label="自动封禁时长（分钟）"
            min={0}
            max={525600}
            fieldProps={{ precision: 0 }}
            extra="默认 10080 分钟（7 天）；填 0 表示永久封禁。"
          />
          <ProFormTextArea
            name="honeypot_whitelist"
            disabled={!canConfigurePrivate}
            label="扫描防护豁免 IP 白名单"
            placeholder="例如：203.0.113.10, 2001:db8::1"
            extra="多个 IPv4 / IPv6 地址请以英文逗号分隔。该白名单仅豁免自动扫描封禁，不影响管理员手动添加的黑名单规则。"
          />
        </>
      ),
    },
    {
      key: 'backup',
      label: '自动备份',
      children: (
        <>
          <Alert
            type="info"
            showIcon
            message="全量数据自动备份策略"
            description="全量备份归档包含数据库、环境配置与加密卡密数据，由独立后台进程异步执行。请妥善管控备份存储目录权限。"
            style={{ marginBottom: 16 }}
          />
          <div className="admin-form-grid-2">
            <ProFormSwitch name="backup_auto_enabled" disabled={!canConfigurePrivate} label="启用每日定时全量备份" />
            <ProFormText
              name="backup_schedule_time"
              disabled={!canConfigurePrivate}
              label="每日执行时间（Asia/Shanghai）"
              rules={[{ pattern: /^([01]\d|2[0-3]):[0-5]\d$/, message: '请按 HH:mm 格式输入时间' }]}
            />
          </div>
          <div className="admin-form-grid-2">
            <ProFormDigit name="backup_retention_count" disabled={!canConfigurePrivate} label="本地最大保留份数" min={1} max={365} fieldProps={{ precision: 0 }} />
            <ProFormDigit name="backup_retention_days" disabled={!canConfigurePrivate} label="本地最大保留天数" min={1} max={3650} fieldProps={{ precision: 0 }} />
          </div>
          <div className="admin-form-grid-2">
            <ProFormDigit name="backup_stale_hours" disabled={!canConfigurePrivate} label="备份过期告警阈值（小时）" min={1} max={8760} fieldProps={{ precision: 0 }} />
            <ProFormDigit name="backup_min_free_mb" disabled={!canConfigurePrivate} label="磁盘最低可用空间告警阈值（MB）" min={0} max={1048576} fieldProps={{ precision: 0 }} />
          </div>
          <ProFormText
            name="backup_sync_directory"
            disabled={!canConfigurePrivate}
            label="异机挂载同步目录（可选）"
            extra="请填写宿主机或容器内已挂载且具备写权限的绝对目录路径（不可填写 URL 或应用根目录）；留空则仅保留本地备份。"
          />
        </>
      ),
    },
  ];

  const saveIndicator = canWrite ? (
    <AutoSaveStatus state={autoSave} onRetry={flush} />
  ) : (
    <Typography.Text type="secondary">当前为只读权限</Typography.Text>
  );

  return (
    <div className="admin-surface-tabs">
      {!canConfigurePrivate && (
        <Alert
          type="info"
          showIcon
          message="支付网关、邮件通知及安全风控配置仅限具备修改权限的管理员变更"
          style={{ marginTop: 12, marginBottom: 8 }}
        />
      )}
      <SettingsForm
        key={loadRevision}
        canWrite={canWrite}
        initialValues={initialValues}
        tabItems={tabItems}
        saveIndicator={saveIndicator}
        onValuesChange={handleValuesChange}
      />
    </div>
  );
}
