import React, { useCallback, useEffect, useRef, useState } from 'react';
import { ProForm, ProFormText, ProFormTextArea, ProFormDigit, ProFormSelect, ProFormSwitch } from '@ant-design/pro-components';
import { Card, Tabs, Spin, message, Alert, Button, Input, Space, Typography } from 'antd';
import { SendOutlined, CheckCircleFilled, LoadingOutlined, ExclamationCircleFilled } from '@ant-design/icons';
import { getSettings, updateSettings, changePassword, sendTestEmail } from '../services/api';
import ImageUploader from '../components/ImageUploader';
import RichTextEditor from '../components/RichTextEditor';

/**
 * 模板目录名 -> 显示名。没登记的模板直接显示目录名，所以别人加了模板不改这里也能用。
 */
const THEME_LABELS = {
  default: '默认（表格式）',
  minimal: '极简卡片流',
  modern: '现代（仿独角数卡新版·含深色模式）',
};

/**
 * 改动到落库之间等多久。
 *
 * 太短：富文本编辑器每敲一个字都发一次请求，而后端每次保存都写一条操作日志。
 * 太长：用户改完就切走，改动还没发出去。800ms 大约是「停下来想一下」的间隔。
 */
const AUTO_SAVE_DELAY = 800;

/**
 * 自动保存的状态指示。
 *
 * 放在卡片标题栏右侧而不是弹 message：设置页是一个会连续改很多项的地方，每改一次
 * 弹一条 toast 会盖住表单本身；标题栏是一个固定位置，用户想确认时看一眼就行。
 * 失败态是唯一会一直停留的状态，并且带一个重试按钮 —— 失败的字段还在队列里，
 * 表单里的值也没被动过，点一下就重发。
 */
function AutoSaveStatus({ state, onRetry }) {
  if (state.status === 'saving') {
    return (
      <Typography.Text type="secondary">
        <LoadingOutlined style={{ marginRight: 6 }} />
        保存中…
      </Typography.Text>
    );
  }

  if (state.status === 'saved') {
    return (
      <Typography.Text type="success">
        <CheckCircleFilled style={{ marginRight: 6 }} />
        已保存
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

  return <Typography.Text type="secondary">修改后自动保存</Typography.Text>;
}

export default function Settings() {
  const [loading, setLoading] = useState(true);
  const [initialValues, setInitialValues] = useState({});
  const [testTo, setTestTo] = useState('');
  const [testing, setTesting] = useState(false);
  const [themes, setThemes] = useState(['default']);
  const [form] = ProForm.useForm();

  // 自动保存状态：idle（没有待办）/ saving / saved / error
  const [autoSave, setAutoSave] = useState({ status: 'idle', error: '' });
  // 待保存的字段。只装「改过的」，不是整份表单——后端 update() 对请求里没有的 key
  // 直接跳过，所以增量提交是天然支持的，也避免了「只保存打开过的 tab」那种隐式语义。
  const queueRef = useRef({});
  const timerRef = useRef(null);
  const inFlightRef = useRef(false);

  useEffect(() => {
    getSettings()
      .then((res) => {
        const data = res.data?.data || res.data;
        // Switch fields are stored as the strings '0'/'1'; antd's Switch needs a real
        // boolean or it renders "0" as checked, since a non-empty string is truthy.
        // 接口把磁盘上真实存在的模板一起带回来了（下划线开头，不是设置项）。
        // 拿它填下拉框，而不是在前端写死一份列表——否则加了模板要改两处，
        // 删了模板下拉框里还留着一个选了会回落 default 的死选项。
        if (Array.isArray(data._available_themes) && data._available_themes.length) {
          setThemes(data._available_themes);
        }
        const normalised = { ...data, telegram_enabled: data.telegram_enabled === '1' || data.telegram_enabled === true };
        delete normalised._available_themes;
        setInitialValues(normalised);
        form.setFieldsValue(normalised);
      })
      .catch(() => message.error('加载设置失败'))
      .finally(() => setLoading(false));
  }, [form]);

  /**
   * 把队列里攒的改动发出去。
   *
   * 失败时把这批原样放回队列，而不是丢掉：表单里的值一个字都不动（用户输入不会丢），
   * 「重试」按钮再调一次这个函数就能把它们重新发一遍。不做自动重试——网关挂了的话
   * 自动重试只会变成每 800ms 打一次。
   */
  const flush = useCallback(async () => {
    if (inFlightRef.current) {
      // 上一批还在路上。重新排一次，等它回来再发，避免同一个 key 并发写。
      timerRef.current = setTimeout(flush, AUTO_SAVE_DELAY);
      return;
    }

    const payload = queueRef.current;
    queueRef.current = {};

    if (Object.keys(payload).length === 0) {
      return;
    }

    inFlightRef.current = true;
    setAutoSave({ status: 'saving', error: '' });

    try {
      await updateSettings(payload);
      // 期间又攒了新的改动就别急着说「已保存」，让下一轮去报。
      setAutoSave(
        Object.keys(queueRef.current).length ? { status: 'saving', error: '' } : { status: 'saved', error: '' }
      );
    } catch (err) {
      queueRef.current = { ...payload, ...queueRef.current };
      setAutoSave({
        status: 'error',
        error: err.response?.data?.message || '保存失败，请检查网络或重新登录',
      });
    } finally {
      inFlightRef.current = false;
    }
  }, []);

  /** ProForm 的 onValuesChange：只把改动的字段入队，然后防抖。 */
  const handleValuesChange = useCallback(
    (changed) => {
      queueRef.current = { ...queueRef.current, ...changed };
      clearTimeout(timerRef.current);
      timerRef.current = setTimeout(flush, AUTO_SAVE_DELAY);
    },
    [flush]
  );

  // 离开页面时把没发出去的改动补发一次，否则「改完立刻点别的菜单」会丢掉最后 800ms
  // 内的编辑。
  useEffect(() => {
    return () => {
      clearTimeout(timerRef.current);
      if (Object.keys(queueRef.current).length) {
        updateSettings(queueRef.current).catch(() => {});
      }
    };
  }, []);

  // 还有没保存成功的东西时，关标签页要拦一下。这是「不能静默丢失」的最后一道。
  useEffect(() => {
    const onBeforeUnload = (e) => {
      if (Object.keys(queueRef.current).length === 0) return;
      e.preventDefault();
      e.returnValue = '';
    };
    window.addEventListener('beforeunload', onBeforeUnload);
    return () => window.removeEventListener('beforeunload', onBeforeUnload);
  }, []);

  // The test sends through the SAVED settings, not the values sitting in the form,
  // because the server reads them from the database. Saving first is therefore part
  // of the operation rather than a separate thing to remember, so the button does it.
  const handleTestEmail = async () => {
    if (!testTo) {
      message.warning('请填写接收测试邮件的地址');
      return;
    }
    setTesting(true);
    try {
      await updateSettings(form.getFieldsValue());
      const res = await sendTestEmail(testTo);
      message.success(res.data?.message || '测试邮件已发送');
    } catch (err) {
      // The transport's own message is the useful part: "Connection refused" and
      // "535 authentication failed" need completely different fixes.
      message.error(err.response?.data?.message || '发送失败', 8);
    } finally {
      setTesting(false);
    }
  };

  if (loading) {
    return (
      <div style={{ textAlign: 'center', padding: 100 }}>
        <Spin size="large" />
      </div>
    );
  }

  const tabItems = [
    {
      key: 'basic',
      label: '基本设置',
      children: (
        <>
          <ProFormText name="site_name" label="站点名称" />
          <ProFormSelect
            name="site_theme"
            label="前台模板"
            options={themes.map((t) => ({ label: THEME_LABELS[t] || t, value: t }))}
            allowClear={false}
            extra="切换后立刻生效，不用重启。模板放在 resources/views/templates/ 下，一个目录一套；新增目录后这里会自动出现。"
          />
          <ProFormTextArea name="site_description" label="站点描述" fieldProps={{ rows: 3 }} />
          <ProForm.Item name="site_announcement" label="站点公告" extra="显示在首页和商品详情页顶部。">
            <RichTextEditor placeholder="支持加粗、颜色、链接、图片等" height={220} />
          </ProForm.Item>
          <ProForm.Item
            name="popup_announcement"
            label="弹窗公告"
            extra="留空则不弹窗。访客打开首页或商品详情页时弹出，5 秒后才能关闭。修改内容后，已看过旧公告的访客会重新看到一次。"
          >
            <RichTextEditor placeholder="重要通知才用弹窗，频繁弹窗会赶走访客" height={200} />
          </ProForm.Item>
          <ProFormDigit
            name="popup_interval_hours"
            label="弹窗间隔（小时）"
            min={0}
            fieldProps={{ precision: 0 }}
            extra="同一位访客在这段时间内不会再次看到同一条弹窗公告。填 0 表示每次访问都弹。"
          />
          <ProFormText name="contact_text" label="联系方式文字" />
          <ProFormText name="contact_url" label="联系方式链接" />
          <ProForm.Item name="site_logo" label="站点 Logo" extra="显示在页面左上角，高度自动缩放到 30px。">
            <ImageUploader />
          </ProForm.Item>
          <ProForm.Item name="site_favicon" label="浏览器图标 (Favicon)" extra="显示在浏览器标签页，建议 .ico 或 32x32 的 .png。">
            <ImageUploader />
          </ProForm.Item>
          <ProForm.Item name="contact_qr_image" label="联系二维码图片">
            <ImageUploader />
          </ProForm.Item>
        </>
      ),
    },
    {
      key: 'seo',
      label: 'SEO 设置',
      children: (
        <>
          <ProFormText name="seo_default_title" label="默认 SEO 标题" />
          <ProFormTextArea name="seo_default_description" label="默认 SEO 描述" fieldProps={{ rows: 3 }} />
          <ProFormText name="seo_default_keywords" label="默认 SEO 关键词" />
        </>
      ),
    },
    {
      key: 'epay',
      label: 'EPay 支付',
      children: (
        <>
          <ProFormText name="epay_api_url" label="EPay 网关地址" />
          <ProFormText name="epay_merchant_id" label="EPay 商户ID" />
          <ProFormText name="epay_merchant_key" label="EPay 商户密钥" />
        </>
      ),
    },
    {
      key: 'epusdt',
      label: 'USDT 支付',
      children: (
        <>
          <ProFormSelect
            name="usdt_gateway"
            label="网关类型"
            options={[
              { label: 'epusdt（原版）', value: 'epusdt' },
              { label: 'BEpusdt（v03413/BEpusdt）', value: 'bepusdt' },
            ]}
            extra="两者接口地址和签名算法相同，但只有 BEpusdt 支持指定收款链。选错会导致签名校验失败、所有 USDT 支付无法创建，请按你实际部署的版本选择。"
          />
          <ProFormText name="epusdt_api_url" label="网关地址" placeholder="如 https://pay.example.com" />
          <ProFormText name="epusdt_api_token" label="接口 Token" />
        </>
      ),
    },
    {
      key: 'security',
      label: '安全设置',
      children: (
        <>
          <ProFormText name="turnstile_site_key" label="Turnstile Site Key" />
          <ProFormText name="turnstile_secret_key" label="Turnstile Secret Key" />
        </>
      ),
    },
    {
      key: 'mail',
      label: '邮件发送',
      children: (
        <>
          <Alert
            type="info"
            showIcon
            style={{ marginBottom: 16 }}
            message="卡密是通过这里配置的邮箱发给买家的。"
            description="没配好的话，买家付款后收不到卡密邮件——他们仍可以在订单查询页自己取，但大部分人不会想到。配置后请务必用下面的测试按钮验证一次。"
          />
          <ProFormText
            name="mail_host"
            label="SMTP 服务器"
            placeholder="如 smtp.qq.com / smtp.gmail.com"
            extra="留空则使用服务器 .env 里的配置。"
          />
          <ProFormSelect
            name="mail_encryption"
            label="加密方式"
            options={[
              { label: 'SSL（端口 465，最常用）', value: 'ssl' },
              { label: 'TLS / STARTTLS（端口 587）', value: 'tls' },
              { label: '不加密（端口 25，不建议）', value: 'none' },
            ]}
            extra="选错会连不上。国内邮箱服务商基本都用 SSL + 465。"
          />
          <ProFormDigit
            name="mail_port"
            label="端口"
            min={1}
            max={65535}
            fieldProps={{ precision: 0 }}
            extra="SSL 填 465，TLS 填 587。"
          />
          <ProFormText
            name="mail_username"
            label="SMTP 用户名"
            placeholder="通常就是完整邮箱地址"
          />
          <ProFormText.Password
            name="mail_password"
            label="SMTP 密码"
            extra="QQ 邮箱、163 等要填「授权码」，不是登录密码。已保存的密码显示为 ******** ，不改就别动它。"
          />
          <ProFormText
            name="mail_from_address"
            label="发件人地址"
            placeholder="买家看到的发件邮箱"
            extra="多数服务商要求这里和 SMTP 用户名一致，否则会拒发。"
          />
          <ProFormText
            name="mail_from_name"
            label="发件人名称"
            placeholder="留空则用站点名称"
          />

          <Card size="small" title="发送测试邮件" style={{ marginTop: 8 }}>
            <Typography.Paragraph type="secondary" style={{ marginBottom: 12 }}>
              点击后会先保存当前设置，再用它发一封测试邮件。这是唯一能确认邮件配置是否可用的方式——
              正式发货时如果发送失败，系统只会记录日志，不会打断订单。
            </Typography.Paragraph>
            <Space.Compact style={{ width: '100%', maxWidth: 460 }}>
              <Input
                type="email"
                value={testTo}
                onChange={(e) => setTestTo(e.target.value)}
                onPressEnter={handleTestEmail}
                placeholder="接收测试邮件的地址"
                allowClear
              />
              <Button
                type="primary"
                icon={<SendOutlined />}
                loading={testing}
                onClick={handleTestEmail}
              >
                发送测试
              </Button>
            </Space.Compact>
          </Card>
        </>
      ),
    },
    {
      key: 'mail-template',
      label: '邮件模板',
      children: (
        <>
          <Alert
            type="info"
            showIcon
            style={{ marginBottom: 16 }}
            message="可用变量：{{site_name}}、{{order_no}}、{{product_name}}、{{quantity}}、{{total_amount}}、{{cards}}"
            description="{{cards}} 会替换成买家买到的全部卡密，一行一条。变量名写错不会报错，只会原样出现在邮件里。"
          />
          <ProFormText
            name="email_template_subject"
            label="邮件标题"
            placeholder="{{site_name}} - 订单 {{order_no}} 卡密信息"
          />
          <ProFormTextArea
            name="email_template_body"
            label="邮件正文"
            fieldProps={{ rows: 14 }}
            extra="纯文本和 HTML 都支持。写纯文本时换行会自动保留，不用写 <br>。"
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
            message="有新订单成交时给你发 Telegram 消息。"
            description="向 @BotFather 申请机器人拿到 Token，再向 @userinfobot 发一条消息拿到你的 Chat ID。不填不影响任何功能。"
          />
          <ProFormSwitch name="telegram_enabled" label="启用通知" />
          <ProFormText.Password
            name="telegram_bot_token"
            label="Bot Token"
            extra="已保存的 Token 显示为 ******** ，不改就别动它。"
          />
          <ProFormText name="telegram_chat_id" label="Chat ID" />
        </>
      ),
    },
    {
      key: 'order',
      label: '订单设置',
      children: (
        <>
          <ProFormDigit name="order_expire_minutes" label="订单过期时间（分钟）" min={1} />
        </>
      ),
    },
  ];

  const handlePasswordChange = async (values) => {
    try {
      await changePassword(values);
      message.success('密码已更新');
      return true;
    } catch (err) {
      message.error(err.response?.data?.message || '密码修改失败');
      return false;
    }
  };

  return (
    <>
    <Card title="系统设置" extra={<AutoSaveStatus state={autoSave} onRetry={flush} />}>
      <ProForm
        form={form}
        // Without this, clearing the logo or the QR code submits no key at all and the
        // old image is kept. Fields on tabs the operator never opened stay unregistered
        // and so remain absent from the payload either way, and SettingController only
        // writes keys the request actually carries — so nothing else gets wiped.
        omitNil={false}
        initialValues={initialValues}
        // 改完即存，不再有「保存设置」按钮。onValuesChange 只给出这次改动的字段，
        // 正好就是要发的增量。
        onValuesChange={handleValuesChange}
        submitter={false}
      >
        <Tabs items={tabItems} />
      </ProForm>
    </Card>
    <Card title="修改密码" style={{ marginTop: 16 }}>
      <Alert
        type="warning"
        showIcon
        style={{ marginBottom: 16 }}
        message="如果你还在使用安装时的初始密码，请立即修改。"
        description="后台地址是公开可访问的，初始密码写在项目文档里。"
      />
      <ProForm
        // 这一行是「一进系统设置页就被弹到修改密码区」的正解。
        //
        // ProForm 的 autoFocusFirstInput 默认为 true，BaseForm 会把 autoFocus:true
        // cloneElement 到第 0 个子元素上。上面那张设置表单的第 0 个子元素是 <Tabs>
        // （渲染成 div，React 只对 button/input/select/textarea 调 focus()，所以空转），
        // 而这张表单的第 0 个子元素正好是「当前密码」输入框 —— autoFocus 一路透传到
        // 真实 <input>，React 在 commit 阶段调 focus()，浏览器为了让它可见就把页面
        // 滚下去了。页面又是先渲染 Spin、数据回来后才挂载表单，所以表现为「先看到
        // 顶部，随即被弹到底部」。
        //
        // 密码框本来也不该自动获得焦点：它是一个破坏性操作的入口，不是这一页的主任务。
        autoFocusFirstInput={false}
        onFinish={handlePasswordChange}
        submitter={{
          searchConfig: { submitText: '修改密码' },
          resetButtonProps: false,
        }}
      >
        <ProFormText.Password
          name="current_password"
          label="当前密码"
          rules={[{ required: true, message: '请输入当前密码' }]}
        />
        <ProFormText.Password
          name="new_password"
          label="新密码"
          rules={[
            { required: true, message: '请输入新密码' },
            { min: 12, message: '新密码至少 12 个字符' },
          ]}
        />
        <ProFormText.Password
          name="new_password_confirmation"
          label="确认新密码"
          dependencies={['new_password']}
          rules={[
            { required: true, message: '请再次输入新密码' },
            ({ getFieldValue }) => ({
              validator: (_, value) =>
                !value || getFieldValue('new_password') === value
                  ? Promise.resolve()
                  : Promise.reject(new Error('两次输入的密码不一致')),
            }),
          ]}
        />
      </ProForm>
    </Card>

    </>
  );
}
