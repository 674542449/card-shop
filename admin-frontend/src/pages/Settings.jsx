import useWritePermission from '../hooks/useWritePermission';
import React, { useCallback, useEffect, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import {
  ProForm,
  ProFormText,
  ProFormTextArea,
  ProFormDigit,
  ProFormSelect,
  ProFormSwitch,
} from '@ant-design/pro-components';
import { Card, Tabs, Spin, message, Alert, Button, Checkbox, Input, Select, Space, Tag, Typography, Result } from 'antd';
import {
  SaveOutlined,
  SendOutlined,
  CheckCircleFilled,
  LoadingOutlined,
  ExclamationCircleFilled,
  EyeOutlined,
  EyeInvisibleOutlined,
  CloudUploadOutlined,
} from '@ant-design/icons';
import { getSettings, updateSettings, sendTestEmail, sendTestTelegram, testBackupStorage } from '../services/api';
import ImageUploader from '../components/ImageUploader';
import RichTextEditor from '../components/RichTextEditor';
import { onAdminSessionChange } from '../services/sessionLifecycle';

const THEME_LABELS = {
  default: '默认主题（现代数字工作台）',
  minimal: '极简主题（卡片网格）',
  modern: '经典暖色主题',
};

// Disable browser & password-manager autofill across every Settings input.
const NO_AUTOFILL_PROPS = {
  autoComplete: 'off',
  'data-lpignore': 'true',
  'data-1p-ignore': 'true',
  'data-bwignore': 'true',
  'data-form-type': 'other',
};

const NO_AUTOFILL_IDENTIFIER_PROPS = {
  ...NO_AUTOFILL_PROPS,
  autoCorrect: 'off',
  autoCapitalize: 'none',
  spellCheck: false,
};

// Use a masked text input (-webkit-text-security: disc) instead of type="password"
// so browsers never classify third-party API tokens/SMTP secrets as site login credentials
// and never autofill the admin username/password into adjacent Settings inputs.
function SecretInput({ id, value, onChange, disabled, placeholder }) {
  const [visible, setVisible] = useState(false);
  return (
    <Input
      id={id}
      type="text"
      value={value}
      onChange={onChange}
      disabled={disabled}
      placeholder={placeholder}
      className={visible ? undefined : 'admin-secret-masked'}
      {...NO_AUTOFILL_IDENTIFIER_PROPS}
      suffix={
        <button
          type="button"
          className="admin-secret-toggle"
          disabled={disabled}
          aria-pressed={visible}
          aria-label={visible ? '隐藏密钥内容' : '显示密钥内容'}
          onClick={() => setVisible((prev) => !prev)}
        >
          {visible ? <EyeOutlined /> : <EyeInvisibleOutlined />}
        </button>
      }
    />
  );
}

function SecretField({ name, label, extra, disabled, placeholder }) {
  return (
    <ProForm.Item name={name} label={label} extra={extra}>
      <SecretInput disabled={disabled} placeholder={placeholder} />
    </ProForm.Item>
  );
}

const CLOUD_BACKUP_PROVIDERS = [
  {
    key: 'r2',
    label: 'Cloudflare R2',
    badge: '推荐 · 免费 10 GB / 零出口费',
    endpointRequired: true,
    endpointPlaceholder: 'https://<AccountID>.r2.cloudflarestorage.com',
    endpointExtra: '在 Cloudflare R2 概览页复制 S3 API Endpoint（支持全球与 EU 管辖区）。',
    regionPlaceholder: 'auto',
    regionExtra: '默认填写 auto 即可。',
    bucketPlaceholder: 'cardshop-backups',
    bucketExtra: '仅允许小写字母、数字、连字符和点号（3–63 位）。',
    accessKeyLabel: 'Access Key ID',
    secretKeyLabel: 'Secret Access Key',
    prefixPlaceholder: 'cardshop/backups',
  },
  {
    key: 's3',
    label: 'AWS S3',
    badge: '兼容标准 S3 / B2 / MinIO',
    endpointRequired: false,
    endpointPlaceholder: '留空默认 https://s3.<region>.amazonaws.com（可填自定义 S3 Endpoint）',
    endpointExtra: '使用 AWS 官方 S3 时可留空；连接 Backblaze B2、MinIO 等兼容服务时填写自定义 HTTPS Endpoint。',
    regionPlaceholder: 'us-east-1',
    regionExtra: '必填，例如 us-east-1、ap-southeast-1、ap-northeast-1。',
    bucketPlaceholder: 'my-cardshop-backups',
    bucketExtra: '仅允许小写字母、数字、连字符和点号（3–63 位）。',
    accessKeyLabel: 'Access Key ID',
    secretKeyLabel: 'Secret Access Key',
    prefixPlaceholder: 'cardshop/backups',
    supportsPathStyle: true,
  },
  {
    key: 'oss',
    label: '阿里云 OSS',
    badge: '原生 OSS4-HMAC-SHA256',
    endpointRequired: false,
    endpointPlaceholder: '留空默认 https://oss-<region>.aliyuncs.com',
    endpointExtra: '默认按区域自动拼接公网 HTTPS Endpoint，也可按需填写阿里云内网/加速 Endpoint。',
    regionPlaceholder: 'cn-hangzhou',
    regionExtra: '填写地域 ID（如 cn-hangzhou、cn-hongkong，带 oss- 前缀也会自动规范化）。',
    bucketPlaceholder: 'my-cardshop-oss',
    bucketExtra: 'OSS 强制使用虚拟主机样式访问，存储桶名称不含大写或下划线。',
    accessKeyLabel: 'AccessKey ID',
    secretKeyLabel: 'AccessKey Secret',
    prefixPlaceholder: 'cardshop/backups',
  },
  {
    key: 'cos',
    label: '腾讯云 COS',
    badge: 'S3 兼容虚拟主机',
    endpointRequired: false,
    endpointPlaceholder: '留空默认 https://cos.<region>.myqcloud.com',
    endpointExtra: '默认按区域自动拼接虚拟主机地址 <Bucket>.cos.<region>.myqcloud.com。',
    regionPlaceholder: 'ap-guangzhou',
    regionExtra: '填写地域标识（如 ap-guangzhou、ap-shanghai、ap-hongkong）。',
    bucketPlaceholder: 'mybucket-1250000000',
    bucketExtra: '腾讯云 COS 存储桶名称必须包含 -AppID 后缀（例如 cardshop-1250000000）。',
    accessKeyLabel: 'SecretId',
    secretKeyLabel: 'SecretKey',
    prefixPlaceholder: 'cardshop/backups',
  },
];

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
  'turnstile_enabled',
  'turnstile_site_key',
  'turnstile_secret_key',
  'order_expire_minutes',
  'blacklist_enabled',
  'cross_site_guard_enabled',
  'form_honeypot_enabled',
  'checkout_require_key',
  'order_limit_enabled',
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
  'backup_s3_enabled',
  'backup_s3_endpoint',
  'backup_s3_region',
  'backup_s3_bucket',
  'backup_s3_prefix',
  'backup_s3_access_key',
  'backup_s3_secret_key',
  'backup_s3_path_style',
  'backup_r2_enabled',
  'backup_r2_endpoint',
  'backup_r2_region',
  'backup_r2_bucket',
  'backup_r2_prefix',
  'backup_r2_access_key',
  'backup_r2_secret_key',
  'backup_oss_enabled',
  'backup_oss_endpoint',
  'backup_oss_region',
  'backup_oss_bucket',
  'backup_oss_prefix',
  'backup_oss_access_key',
  'backup_oss_secret_key',
  'backup_cos_enabled',
  'backup_cos_endpoint',
  'backup_cos_region',
  'backup_cos_bucket',
  'backup_cos_prefix',
  'backup_cos_access_key',
  'backup_cos_secret_key',
]);

function valuesEqual(nextValue, savedValue) {
  if (Object.is(nextValue, savedValue)) return true;
  if ((nextValue === '' || nextValue == null) && (savedValue === '' || savedValue == null)) return true;
  if (typeof nextValue === 'boolean' || typeof savedValue === 'boolean') {
    return Boolean(nextValue) === Boolean(savedValue);
  }
  return String(nextValue) === String(savedValue);
}

function SaveStatus({ state, dirtyCount }) {
  if (state.status === 'saving') {
    return (
      <Typography.Text type="secondary" role="status" aria-live="polite">
        <LoadingOutlined style={{ marginRight: 6 }} />
        正在保存设置…
      </Typography.Text>
    );
  }

  if (state.status === 'error') {
    return (
      <Typography.Text type="danger" role="status" aria-live="polite">
        <ExclamationCircleFilled style={{ marginRight: 6 }} />
        {state.error}
      </Typography.Text>
    );
  }

  if (dirtyCount > 0) {
    return (
      <span role="status" aria-live="polite">
        <Tag color="warning" style={{ marginInlineEnd: 0 }}>
          有 {dirtyCount} 项未保存更改
        </Tag>
      </span>
    );
  }

  if (state.status === 'saved') {
    return (
      <Typography.Text type="success" role="status" aria-live="polite">
        <CheckCircleFilled style={{ marginRight: 6 }} />
        更改已保存
      </Typography.Text>
    );
  }

  return (
    <Typography.Text type="secondary" role="status" aria-live="polite">
      修改后请点击「保存设置」生效
    </Typography.Text>
  );
}

const SETTING_TAB_KEYS = new Set(['basic', 'seo', 'payment', 'mail', 'telegram', 'order', 'security', 'backup']);

function SettingsForm({
  form,
  canWrite,
  initialValues,
  tabItems,
  activeTab,
  onTabChange,
  topActions,
  footerActions,
  onValuesChange,
  onFinish,
}) {
  useEffect(() => {
    form.setFieldsValue(initialValues);
  }, [form, initialValues]);

  return (
    <ProForm
      name="system-settings"
      autoComplete="off"
      disabled={!canWrite}
      form={form}
      omitNil={false}
      initialValues={initialValues}
      onValuesChange={onValuesChange}
      onFinish={onFinish}
      submitter={false}
    >
      <Tabs activeKey={activeTab} onChange={onTabChange} items={tabItems} tabBarExtraContent={topActions} />
      {footerActions}
    </ProForm>
  );
}

export default function Settings() {
  const canWrite = useWritePermission('settings');
  const canConfigurePrivate = canWrite;
  const [searchParams, setSearchParams] = useSearchParams();
  const [form] = ProForm.useForm();
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState('');
  const [loadRevision, setLoadRevision] = useState(0);
  const [initialValues, setInitialValues] = useState({});
  const [dirtyValues, setDirtyValues] = useState({});
  const [saveState, setSaveState] = useState({ status: 'idle', error: '' });
  const [testTo, setTestTo] = useState('');
  const [testing, setTesting] = useState(false);
  const [testTelegramScope, setTestTelegramScope] = useState('auto');
  const [testTelegramTarget, setTestTelegramTarget] = useState('');
  const [testingTelegram, setTestingTelegram] = useState(false);
  const [testingBackupProvider, setTestingBackupProvider] = useState('');
  const [themes, setThemes] = useState(['default']);
  const [clientIp, setClientIp] = useState('');
  const [clientSubnet64, setClientSubnet64] = useState('');

  const tabParam = searchParams.get('tab');
  const activeTab = SETTING_TAB_KEYS.has(tabParam) ? tabParam : 'basic';
  const handleTabChange = useCallback(
    (nextKey) => {
      if (!SETTING_TAB_KEYS.has(nextKey)) return;
      if (nextKey === 'basic') {
        setSearchParams({}, { replace: true });
      } else {
        setSearchParams({ tab: nextKey }, { replace: true });
      }
    },
    [setSearchParams]
  );

  const savedValuesRef = useRef({});
  const dirtyValuesRef = useRef({});
  const savingRef = useRef(false);

  const dirtyKeys = Object.keys(dirtyValues);
  const dirtyCount = dirtyKeys.length;
  const saving = saveState.status === 'saving';

  useEffect(() => {
    dirtyValuesRef.current = dirtyValues;
  }, [dirtyValues]);

  useEffect(() => {
    return onAdminSessionChange(() => {
      dirtyValuesRef.current = {};
      setDirtyValues({});
      setSaveState({ status: 'idle', error: '' });
    });
  }, []);

  useEffect(() => {
    const handleBeforeUnload = (event) => {
      if (Object.keys(dirtyValuesRef.current).length === 0) return;
      event.preventDefault();
      event.returnValue = '';
    };
    window.addEventListener('beforeunload', handleBeforeUnload);
    return () => window.removeEventListener('beforeunload', handleBeforeUnload);
  }, []);

  useEffect(() => {
    let current = true;
    setLoading(true);
    setLoadError('');
    getSettings()
      .then((res) => {
        if (!current) return;
        const data = res.data?.data || res.data;
        if (Array.isArray(data._available_themes) && data._available_themes.length) {
          setThemes(data._available_themes);
        }
        setClientIp(typeof data._client_ip === 'string' ? data._client_ip : '');
        setClientSubnet64(typeof data._client_subnet64 === 'string' ? data._client_subnet64 : '');
        const normalised = { ...data };
        for (const [key, defaultValue] of Object.entries({
          telegram_enabled: false,
          telegram_notify_product_sold: false,
          telegram_notify_product_listed: false,
          telegram_target_bot_enabled: false,
          telegram_target_group_enabled: false,
          telegram_target_channel_enabled: false,
          turnstile_enabled: true,
          blacklist_enabled: true,
          cross_site_guard_enabled: true,
          form_honeypot_enabled: true,
          checkout_require_key: false,
          order_limit_enabled: true,
          honeypot_enabled: true,
          honeypot_skip_reserved_ips: true,
          payment_reconciliation_enabled: false,
          refund_enabled: false,
          backup_auto_enabled: false,
          backup_s3_enabled: false,
          backup_s3_path_style: false,
          backup_r2_enabled: false,
          backup_oss_enabled: false,
          backup_cos_enabled: false,
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
        normalised.backup_r2_region ||= 'auto';
        delete normalised._available_themes;
        delete normalised._client_ip;
        delete normalised._client_subnet64;
        savedValuesRef.current = normalised;
        dirtyValuesRef.current = {};
        setInitialValues(normalised);
        setDirtyValues({});
        setSaveState({ status: 'idle', error: '' });
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

  const handleValuesChange = useCallback(
    (changed) => {
      if (!canWrite || loading || loadError) return;
      setDirtyValues((prev) => {
        const next = { ...prev };
        let mutated = false;
        for (const [key, value] of Object.entries(changed)) {
          if (!canConfigurePrivate && OWNER_SETTINGS.has(key)) continue;
          if (valuesEqual(value, savedValuesRef.current[key])) {
            if (Object.prototype.hasOwnProperty.call(next, key)) {
              delete next[key];
              mutated = true;
            }
          } else {
            next[key] = value;
            mutated = true;
          }
        }
        if (mutated) {
          dirtyValuesRef.current = next;
          setSaveState((cur) => (cur.status === 'idle' ? cur : { status: 'idle', error: '' }));
        }
        return mutated ? next : prev;
      });
    },
    [canWrite, canConfigurePrivate, loading, loadError]
  );

  const handleSave = useCallback(async () => {
    if (!canWrite || loading || loadError || savingRef.current) return false;
    const payload = { ...dirtyValuesRef.current };
    if (Object.keys(payload).length === 0) {
      message.info('当前没有未保存的更改');
      return true;
    }

    savingRef.current = true;
    setSaveState({ status: 'saving', error: '' });
    try {
      const res = await updateSettings(payload);
      const nextSaved = { ...savedValuesRef.current, ...payload };
      savedValuesRef.current = nextSaved;
      const remaining = {};
      for (const [key, value] of Object.entries(dirtyValuesRef.current)) {
        if (!valuesEqual(value, nextSaved[key])) {
          remaining[key] = value;
        }
      }
      dirtyValuesRef.current = remaining;
      setInitialValues(nextSaved);
      setDirtyValues(remaining);
      setSaveState({ status: Object.keys(remaining).length === 0 ? 'saved' : 'idle', error: '' });
      message.success(res?.data?.message || '设置已保存');
      return true;
    } catch (err) {
      const errorMsg = err.response?.data?.message || '保存失败，请检查输入内容或网络连接后重试';
      setSaveState({ status: 'error', error: errorMsg });
      message.error(errorMsg, 6);
      return false;
    } finally {
      savingRef.current = false;
    }
  }, [canWrite, loading, loadError]);

  const handleReset = useCallback(() => {
    if (savingRef.current) return;
    form.setFieldsValue(savedValuesRef.current);
    dirtyValuesRef.current = {};
    setDirtyValues({});
    setSaveState({ status: 'idle', error: '' });
    message.info('已恢复为上次保存的设置');
  }, [form]);

  const appendWhitelistEntry = useCallback(
    (entry) => {
      if (!canConfigurePrivate || !entry) return;
      const currentRaw = String(form.getFieldValue('honeypot_whitelist') || '');
      const existing = currentRaw
        .split(/[\r\n,;，；]+/)
        .map((item) => item.trim())
        .filter(Boolean);
      if (existing.some((item) => item.toLowerCase() === entry.toLowerCase())) {
        message.info(`白名单中已包含 ${entry}`);
        return;
      }
      const nextValue = existing.length > 0 ? `${currentRaw.trimEnd()}\n${entry}` : entry;
      form.setFieldValue('honeypot_whitelist', nextValue);
      handleValuesChange({ honeypot_whitelist: nextValue });
      message.success(`已填入 ${entry}，请点击「保存设置」使其生效`);
    },
    [canConfigurePrivate, form, handleValuesChange]
  );

  const handleTestEmail = async () => {
    if (!canConfigurePrivate || loading || loadError) return;
    if (Object.keys(dirtyValuesRef.current).length > 0) {
      message.warning('当前有未保存的设置更改，请先点击「保存设置」后再发送测试邮件');
      return;
    }
    if (!testTo) {
      message.warning('请填写接收测试邮件的目标邮箱地址');
      return;
    }
    setTesting(true);
    try {
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
    if (Object.keys(dirtyValuesRef.current).length > 0) {
      message.warning('当前有未保存的设置更改，请先点击「保存设置」后再发送测试通知');
      return;
    }
    setTestingTelegram(true);
    try {
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

  const selectedCloudBackupProviders = CLOUD_BACKUP_PROVIDERS.filter((item) => {
    const key = `backup_${item.key}_enabled`;
    const active = Object.prototype.hasOwnProperty.call(dirtyValues, key)
      ? dirtyValues[key]
      : initialValues[key];
    return Boolean(active);
  }).map((item) => item.key);

  const handleCloudBackupSelectionChange = useCallback(
    (nextSelected) => {
      if (!canConfigurePrivate || loading || loadError) return;
      const selectedSet = new Set(nextSelected);
      const changed = {};
      for (const item of CLOUD_BACKUP_PROVIDERS) {
        const key = `backup_${item.key}_enabled`;
        const nextVal = selectedSet.has(item.key);
        const curVal = Boolean(
          Object.prototype.hasOwnProperty.call(dirtyValuesRef.current, key)
            ? dirtyValuesRef.current[key]
            : savedValuesRef.current[key]
        );
        if (nextVal !== curVal) {
          form.setFieldValue(key, nextVal);
          changed[key] = nextVal;
        }
      }
      if (Object.keys(changed).length > 0) {
        handleValuesChange(changed);
      }
    },
    [canConfigurePrivate, loading, loadError, form, handleValuesChange]
  );

  const handleTestCloudBackup = async (providerKey) => {
    if (!canConfigurePrivate || loading || loadError || testingBackupProvider) return;
    setTestingBackupProvider(providerKey);
    try {
      const readField = (field) => {
        const key = `backup_${providerKey}_${field}`;
        const live = form.getFieldValue(key);
        if (live !== undefined) return live;
        return Object.prototype.hasOwnProperty.call(dirtyValuesRef.current, key)
          ? dirtyValuesRef.current[key]
          : savedValuesRef.current[key];
      };
      const payload = {
        provider: providerKey,
        endpoint: readField('endpoint') || '',
        region: readField('region') || '',
        bucket: readField('bucket') || '',
        prefix: readField('prefix') || '',
        access_key: readField('access_key') || '',
        secret_key: readField('secret_key') || '',
      };
      if (providerKey === 's3') {
        payload.path_style = Boolean(readField('path_style'));
      }
      const res = await testBackupStorage(payload);
      message.success(res.data?.message || '云端存储连接测试通过');
    } catch (err) {
      message.error(err.response?.data?.message || '云端存储连接测试失败', 8);
    } finally {
      setTestingBackupProvider('');
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
            <ProFormText name="site_name" label="站点名称" fieldProps={NO_AUTOFILL_PROPS} />
            <ProFormSelect
              name="site_theme"
              label="前台视觉主题"
              options={themes.map((t) => ({ label: THEME_LABELS[t] || t, value: t }))}
              allowClear={false}
              extra="保存后立即生效。系统自动加载 resources/views/templates/ 目录下的可用模板。"
            />
          </div>
          <ProFormTextArea name="site_description" label="站点简介" fieldProps={{ rows: 3, ...NO_AUTOFILL_PROPS }} />
          <div className="admin-form-grid-3">
            <ProFormText name="contact_text" label="客服联系说明" placeholder="例如：在线客服 / 工单邮箱" fieldProps={NO_AUTOFILL_PROPS} />
            <ProFormText name="contact_url" label="客服跳转链接" placeholder="例如：https://t.me/example" fieldProps={NO_AUTOFILL_IDENTIFIER_PROPS} />
            <ProFormText
              name="footer_powered_by"
              label="页脚系统标识"
              tooltip="展示在页脚 Powered by 区域；留空则隐藏该标识。"
              placeholder="留空则不展示"
              fieldProps={NO_AUTOFILL_PROPS}
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
            fieldProps={{ precision: 0, ...NO_AUTOFILL_PROPS }}
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
              fieldProps={NO_AUTOFILL_IDENTIFIER_PROPS}
              extra="用于生成规范链接、站点地图及搜索引擎主动推送地址，如 https://shop.example.com"
            />
            <ProFormText
              name="bing_indexnow_key"
              label="IndexNow 验证密钥"
              fieldProps={NO_AUTOFILL_IDENTIFIER_PROPS}
              extra="支持 8–128 位字母、数字或连字符；系统将自动托管对应的密钥校验文件。"
            />
          </div>
          <SecretField name="baidu_push_token" disabled={!canWrite} label="百度搜索资源平台推送 Token" />
          <div className="admin-form-grid-2">
            <ProFormText name="seo_default_title" label="全站默认 SEO 标题" fieldProps={NO_AUTOFILL_PROPS} />
            <ProFormText name="seo_default_keywords" label="全站默认 SEO 关键词" placeholder="多个关键词用英文逗号分隔" fieldProps={NO_AUTOFILL_PROPS} />
          </div>
          <ProFormTextArea name="seo_default_description" label="全站默认 SEO 描述" fieldProps={{ rows: 3, ...NO_AUTOFILL_PROPS }} />
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
            <ProFormText name="epay_api_url" disabled={!canConfigurePrivate} label="EPay 网关接口地址" placeholder="如 https://pay.example.com" fieldProps={NO_AUTOFILL_IDENTIFIER_PROPS} />
            <ProFormText name="epay_merchant_id" disabled={!canConfigurePrivate} label="EPay 商户 ID" fieldProps={NO_AUTOFILL_IDENTIFIER_PROPS} />
          </div>
          <ProFormText name="epay_merchant_key" disabled={!canConfigurePrivate} label="EPay 商户通信密钥" fieldProps={NO_AUTOFILL_IDENTIFIER_PROPS} />

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
            <ProFormText name="epusdt_api_url" disabled={!canConfigurePrivate} label="USDT 网关地址" placeholder="如 https://usdt.example.com" fieldProps={NO_AUTOFILL_IDENTIFIER_PROPS} />
          </div>
          <ProFormText name="epusdt_api_token" disabled={!canConfigurePrivate} label="USDT 接口通信 Token" fieldProps={NO_AUTOFILL_IDENTIFIER_PROPS} />
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
            description="系统通过此处配置的 SMTP 通道向买家发送卡密交付与退款通知邮件。买家同时可通过前台订单查询页自助提取卡密；修改参数并保存后请执行发信测试以验证连通性。"
          />
          <div className="admin-form-grid-3">
            <ProFormText
              name="mail_host"
              disabled={!canConfigurePrivate}
              label="SMTP 服务器主机"
              placeholder="如 smtp.qq.com / smtp.gmail.com"
              fieldProps={NO_AUTOFILL_IDENTIFIER_PROPS}
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
              fieldProps={{ precision: 0, ...NO_AUTOFILL_PROPS }}
              extra="SSL 常用 465，STARTTLS 常用 587。"
            />
          </div>
          <div className="admin-form-grid-2">
            <ProFormText name="mail_username" disabled={!canConfigurePrivate} label="SMTP 认证账号" placeholder="通常为完整发件邮箱地址" fieldProps={NO_AUTOFILL_IDENTIFIER_PROPS} />
            <SecretField
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
              fieldProps={NO_AUTOFILL_IDENTIFIER_PROPS}
              extra="多数邮件服务商要求发件地址与认证账号一致。"
            />
            <ProFormText name="mail_from_name" disabled={!canConfigurePrivate} label="发件人显示名称" placeholder="留空默认采用站点名称" fieldProps={NO_AUTOFILL_PROPS} />
          </div>

          <Card size="small" title="SMTP 连通性测试" style={{ marginTop: 8, marginBottom: 20 }}>
            <Typography.Paragraph type="secondary" style={{ marginBottom: 12 }}>
              将使用已保存的 SMTP 邮件参数投递测试邮件；修改参数后请先点击「保存设置」。正式业务通知若投递失败将自动转入任务中心重试队列，不影响订单履约。
            </Typography.Paragraph>
            <Space.Compact style={{ width: '100%', maxWidth: 460 }}>
              <Input
                type="text"
                inputMode="email"
                disabled={!canConfigurePrivate}
                value={testTo}
                onChange={(e) => setTestTo(e.target.value)}
                onPressEnter={(e) => {
                  if (e.nativeEvent?.isComposing) return;
                  e.preventDefault();
                  handleTestEmail();
                }}
                {...NO_AUTOFILL_IDENTIFIER_PROPS}
                placeholder="输入接收测试邮件的邮箱地址"
                allowClear
              />
              <Button
                type="primary"
                htmlType="button"
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
            fieldProps={NO_AUTOFILL_PROPS}
          />
          <ProFormTextArea
            name="email_template_body"
            disabled={!canConfigurePrivate}
            label="交付邮件正文"
            fieldProps={{ rows: 12, ...NO_AUTOFILL_PROPS }}
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
            <SecretField
              name="telegram_bot_token"
              disabled={!canConfigurePrivate}
              label="默认机器人 Token（Bot Token）"
              extra="从 @BotFather 获取的完整 Token；已保存的 Token 以掩码显示，未变更请保持原样。"
            />
            <ProFormText
              name="telegram_chat_id"
              disabled={!canConfigurePrivate}
              label="站长默认接收会话 ID（Chat ID）"
              placeholder="如 123456789 或 -1001234567890#话题ID"
              fieldProps={NO_AUTOFILL_IDENTIFIER_PROPS}
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
          <SecretField
            name="telegram_broadcast_bot_token"
            disabled={!canConfigurePrivate}
            label="指定播报机器人 Token（可选）"
            placeholder="留空则默认共用上方「默认机器人 Token」"
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
              fieldProps={{ rows: 2, ...NO_AUTOFILL_IDENTIFIER_PROPS }}
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
              fieldProps={{ rows: 2, ...NO_AUTOFILL_IDENTIFIER_PROPS }}
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
              fieldProps={{ rows: 2, ...NO_AUTOFILL_IDENTIFIER_PROPS }}
              extra="必须先将机器人添加为目标频道的管理员，并开启「发布消息（Post Messages）」权限。支持公开频道 @用户名、t.me 链接或私有频道 -100 开头的数字 ID，多个频道用逗号或换行分隔。"
            />
          </Card>

          <Card size="small" title="Telegram 推送连通性测试" style={{ marginTop: 8, marginBottom: 8 }}>
            <Typography.Paragraph type="secondary" style={{ marginBottom: 12 }}>
              将使用已保存的 Telegram 配置推送测试消息；修改配置后请先点击「保存设置」。留空右侧临时目标时，将按所选范围测试当前已开启的接收端；正式业务通知若遇到网络波动将自动转入任务中心重试队列。
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
                  {...NO_AUTOFILL_IDENTIFIER_PROPS}
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
            fieldProps={{ precision: 0, ...NO_AUTOFILL_PROPS }}
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
          <div className="admin-form-section-title">基础请求与黑名单防护（均可独立开关）</div>
          <div className="admin-form-grid-3">
            <ProFormSwitch
              name="blacklist_enabled"
              disabled={!canConfigurePrivate}
              label="启用 IP 与邮箱黑名单拦截"
              extra="关闭后暂不拦截黑名单中的来源，已有黑名单记录会保留，重新开启后立即恢复生效。"
            />
            <ProFormSwitch
              name="cross_site_guard_enabled"
              disabled={!canConfigurePrivate}
              label="启用跨站提交拦截（Sec-Fetch-Site）"
              extra="拦截浏览器标记为 cross-site 的非安全表单/接口提交，关闭后仅保留标准 CSRF 校验。"
            />
            <ProFormSwitch
              name="form_honeypot_enabled"
              disabled={!canConfigurePrivate}
              label="启用表单隐藏蜜罐防机器人"
              extra="下单与查单表单中隐藏诱饵字段若被自动脚本填写则直接拦截；做接口调试或安全测试时可临时关闭。"
            />
          </div>

          <div className="admin-form-section-title">Cloudflare Turnstile 人机校验</div>
          <ProFormSwitch
            name="turnstile_enabled"
            disabled={!canConfigurePrivate}
            label="启用 Cloudflare Turnstile 人机校验"
            extra="开启且下方 Site Key 与 Secret Key 均已填写时在前台生效；临时关闭可跳过验证且保留已填密钥，无需清空重填。"
          />
          <div className="admin-form-grid-2">
            <ProFormText name="turnstile_site_key" disabled={!canConfigurePrivate} label="Turnstile Site Key" fieldProps={NO_AUTOFILL_IDENTIFIER_PROPS} />
            <SecretField name="turnstile_secret_key" disabled={!canConfigurePrivate} label="Turnstile Secret Key" />
          </div>

          <div className="admin-form-section-title">下单防刷与恶意锁库存限额</div>
          <div className="admin-form-grid-2">
            <ProFormSwitch
              name="checkout_require_key"
              disabled={!canConfigurePrivate}
              label="强制校验页面下单令牌（checkout_key）"
              extra="开启后下单必须携带当前会话签发的页面令牌，直接阻断未加载商品页的无状态脚本裸发 POST 请求。"
            />
            <ProFormSwitch
              name="order_limit_enabled"
              disabled={!canConfigurePrivate}
              label="启用下单频次与未付锁库限额保护"
              extra="控制下方单 IP、单邮箱最大待支付单数及单商品未付锁库上限；压测或安全测试时可一键临时关闭并保留已设配额。"
            />
          </div>
          <div className="admin-form-grid-2">
            <ProFormDigit
              name="order_max_pending_per_ip"
              disabled={!canConfigurePrivate}
              label="单 IP / IPv6 子网最大待支付单数"
              min={1}
              max={50}
              fieldProps={{ precision: 0, ...NO_AUTOFILL_PROPS }}
              extra="默认 3 笔；同一来源未支付订单达到上限后将拒绝继续锁库存。"
            />
            <ProFormDigit
              name="order_max_pending_per_email"
              disabled={!canConfigurePrivate}
              label="单邮箱最大待支付单数"
              min={1}
              max={50}
              fieldProps={{ precision: 0, ...NO_AUTOFILL_PROPS }}
              extra="默认 3 笔；防止秒换代理 IP 使用同一邮箱批量占空库存。"
            />
          </div>
          <ProFormDigit
            name="order_max_locked_percent"
            disabled={!canConfigurePrivate}
            label="单商品未付锁库保护上限（%）"
            min={20}
            max={100}
            fieldProps={{ precision: 0, ...NO_AUTOFILL_PROPS }}
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
            fieldProps={{ precision: 0, ...NO_AUTOFILL_PROPS }}
            extra="默认 10080 分钟（7 天）；填 0 表示永久封禁。"
          />
          {clientIp && (
            <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 8, marginBottom: 10 }}>
              <Typography.Text type="secondary">
                当前识别到您的访问 IP：<span className="admin-mono">{clientIp}</span>
              </Typography.Text>
              <Button
                size="small"
                htmlType="button"
                disabled={!canConfigurePrivate}
                onClick={() => appendWhitelistEntry(clientIp)}
              >
                一键加入当前 IP
              </Button>
              {clientSubnet64 && (
                <Button
                  size="small"
                  htmlType="button"
                  disabled={!canConfigurePrivate}
                  onClick={() => appendWhitelistEntry(clientSubnet64)}
                >
                  加入当前 IPv6 /64 网段
                </Button>
              )}
            </div>
          )}
          <ProFormTextArea
            name="honeypot_whitelist"
            disabled={!canConfigurePrivate}
            label="蜜罐扫描防护豁免 IP 白名单（支持单 IP 与 CIDR 网段）"
            placeholder={'例如：\n203.0.113.10\n198.51.100.0/24\n2001:db8::/64'}
            fieldProps={{ rows: 4, ...NO_AUTOFILL_IDENTIFIER_PROPS }}
            extra="支持单个 IPv4 / IPv6 地址及 CIDR 网段（如 203.0.113.0/24、2001:db8::/64），多个条目可用英文逗号或换行分隔。命中白名单的来源做安全扫描测试时不会被蜜罐拉黑（仍正常返回 404）；保存后会自动解除该白名单内已有的蜜罐封禁记录，不影响管理员手动添加的黑名单规则。"
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
              fieldProps={NO_AUTOFILL_IDENTIFIER_PROPS}
              rules={[{ pattern: /^([01]\d|2[0-3]):[0-5]\d$/, message: '请按 HH:mm 格式输入时间' }]}
            />
          </div>
          <div className="admin-form-grid-2">
            <ProFormDigit name="backup_retention_count" disabled={!canConfigurePrivate} label="本地最大保留份数" min={1} max={365} fieldProps={{ precision: 0, ...NO_AUTOFILL_PROPS }} />
            <ProFormDigit name="backup_retention_days" disabled={!canConfigurePrivate} label="本地最大保留天数" min={1} max={3650} fieldProps={{ precision: 0, ...NO_AUTOFILL_PROPS }} />
          </div>
          <div className="admin-form-grid-2">
            <ProFormDigit name="backup_stale_hours" disabled={!canConfigurePrivate} label="备份过期告警阈值（小时）" min={1} max={8760} fieldProps={{ precision: 0, ...NO_AUTOFILL_PROPS }} />
            <ProFormDigit name="backup_min_free_mb" disabled={!canConfigurePrivate} label="磁盘最低可用空间告警阈值（MB）" min={0} max={1048576} fieldProps={{ precision: 0, ...NO_AUTOFILL_PROPS }} />
          </div>
          <ProFormText
            name="backup_sync_directory"
            disabled={!canConfigurePrivate}
            label="异机挂载同步目录（可选）"
            fieldProps={NO_AUTOFILL_IDENTIFIER_PROPS}
            extra="请填写宿主机或容器内已挂载且具备写权限的绝对目录路径（不可填写 URL 或应用根目录）；可与下方云端对象存储同时启用。"
          />

          <Card
            size="small"
            style={{ marginTop: 16 }}
            title={
              <Space size={8} wrap>
                <CloudUploadOutlined />
                <span>云端对象存储异地备份</span>
                <Tag color={selectedCloudBackupProviders.length > 0 ? 'green' : 'default'}>
                  已选 {selectedCloudBackupProviders.length} 个目标
                </Tag>
              </Space>
            }
          >
            <Alert
              type="info"
              showIcon
              message="支持单选或多选同时备份，各云端目标相互独立容错"
              description="勾选一个或多个对象存储后，每次自动/手动备份校验通过将依次流式上传至全部已勾选的云端（单文件上限 5 GB，上传校验 Content-MD5）。独立密钥文件 shop-keyring.json 不会随备份上传，请务必单独离线妥善保存。"
              style={{ marginBottom: 16 }}
            />
            <div style={{ marginBottom: 16 }}>
              <Typography.Text strong style={{ display: 'block', marginBottom: 8 }}>
                选择云端备份目标（可单选、多选或全选）：
              </Typography.Text>
              <Checkbox.Group
                disabled={!canConfigurePrivate}
                value={selectedCloudBackupProviders}
                onChange={handleCloudBackupSelectionChange}
              >
                <Space wrap size={[16, 8]}>
                  {CLOUD_BACKUP_PROVIDERS.map((provider) => (
                    <Checkbox key={provider.key} value={provider.key}>
                      <span>{provider.label}</span>
                      <Typography.Text type="secondary" style={{ marginLeft: 6, fontSize: 12 }}>
                        （{provider.badge}）
                      </Typography.Text>
                    </Checkbox>
                  ))}
                </Space>
              </Checkbox.Group>
            </div>

            {selectedCloudBackupProviders.length === 0 ? (
              <Typography.Text type="secondary">
                未勾选云端目标；勾选上方任意一个或多个对象存储即可展开对应配置项。修改后请点击「保存设置」生效。
              </Typography.Text>
            ) : (
              <Space direction="vertical" size={14} style={{ width: '100%' }}>
                {CLOUD_BACKUP_PROVIDERS.filter((provider) =>
                  selectedCloudBackupProviders.includes(provider.key)
                ).map((provider) => (
                  <Card
                    key={provider.key}
                    size="small"
                    type="inner"
                    title={
                      <Space size={8} wrap>
                        <strong>{provider.label}</strong>
                        <Tag color="blue">{provider.badge}</Tag>
                      </Space>
                    }
                    extra={
                      <Button
                        size="small"
                        htmlType="button"
                        disabled={!canConfigurePrivate || Boolean(testingBackupProvider)}
                        loading={testingBackupProvider === provider.key}
                        onClick={() => handleTestCloudBackup(provider.key)}
                      >
                        测试连接
                      </Button>
                    }
                  >
                    <div className="admin-form-grid-2">
                      <ProFormText
                        name={`backup_${provider.key}_bucket`}
                        disabled={!canConfigurePrivate}
                        label="存储桶名称（Bucket）"
                        placeholder={provider.bucketPlaceholder}
                        fieldProps={NO_AUTOFILL_IDENTIFIER_PROPS}
                        extra={provider.bucketExtra}
                      />
                      <ProFormText
                        name={`backup_${provider.key}_region`}
                        disabled={!canConfigurePrivate}
                        label="区域（Region）"
                        placeholder={provider.regionPlaceholder}
                        fieldProps={NO_AUTOFILL_IDENTIFIER_PROPS}
                        extra={provider.regionExtra}
                      />
                    </div>
                    <div className="admin-form-grid-2">
                      <ProFormText
                        name={`backup_${provider.key}_endpoint`}
                        disabled={!canConfigurePrivate}
                        label={provider.endpointRequired ? 'Endpoint 地址（必填）' : 'Endpoint 地址（可选）'}
                        placeholder={provider.endpointPlaceholder}
                        fieldProps={NO_AUTOFILL_IDENTIFIER_PROPS}
                        extra={provider.endpointExtra}
                      />
                      <ProFormText
                        name={`backup_${provider.key}_prefix`}
                        disabled={!canConfigurePrivate}
                        label="远端目录前缀（可选）"
                        placeholder={provider.prefixPlaceholder}
                        fieldProps={NO_AUTOFILL_IDENTIFIER_PROPS}
                        extra="留空则直接上传至存储桶根目录；例如填写 cardshop/backups。"
                      />
                    </div>
                    <div className="admin-form-grid-2">
                      <ProFormText
                        name={`backup_${provider.key}_access_key`}
                        disabled={!canConfigurePrivate}
                        label={provider.accessKeyLabel}
                        placeholder={`请输入 ${provider.accessKeyLabel}`}
                        fieldProps={NO_AUTOFILL_IDENTIFIER_PROPS}
                      />
                      <SecretField
                        name={`backup_${provider.key}_secret_key`}
                        disabled={!canConfigurePrivate}
                        label={provider.secretKeyLabel}
                        placeholder={`请输入 ${provider.secretKeyLabel}，留空或保持 ******** 不变`}
                        extra="服务端使用独立密钥加密保存；测试连接时将优先使用当前表单中填写的值。"
                      />
                    </div>
                    {provider.supportsPathStyle && (
                      <ProFormSwitch
                        name="backup_s3_path_style"
                        disabled={!canConfigurePrivate}
                        label="使用 Path-Style 路径寻址（仅 MinIO 等自建兼容 S3 需要，AWS 官方 S3 保持关闭）"
                      />
                    )}
                  </Card>
                ))}
              </Space>
            )}
          </Card>
        </>
      ),
    },
  ];

  const topActions = canWrite ? (
    <Space size={10} wrap>
      <SaveStatus state={saveState} dirtyCount={dirtyCount} />
      {dirtyCount > 0 && (
        <Button size="small" htmlType="button" disabled={saving} onClick={handleReset}>
          重置
        </Button>
      )}
      <Button type="primary" size="small" htmlType="submit" icon={<SaveOutlined />} loading={saving}>
        保存设置
      </Button>
    </Space>
  ) : (
    <Typography.Text type="secondary">当前为只读权限</Typography.Text>
  );

  const footerActions = canWrite ? (
    <div className="admin-settings-footer">
      <SaveStatus state={saveState} dirtyCount={dirtyCount} />
      <Space size={10} wrap>
        <Button htmlType="button" disabled={dirtyCount === 0 || saving} onClick={handleReset}>
          重置未保存更改
        </Button>
        <Button type="primary" htmlType="submit" icon={<SaveOutlined />} loading={saving}>
          保存设置
        </Button>
      </Space>
    </div>
  ) : null;

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
        form={form}
        canWrite={canWrite}
        initialValues={initialValues}
        tabItems={tabItems}
        activeTab={activeTab}
        onTabChange={handleTabChange}
        topActions={topActions}
        footerActions={footerActions}
        onValuesChange={handleValuesChange}
        onFinish={handleSave}
      />
    </div>
  );
}
