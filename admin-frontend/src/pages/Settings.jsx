import React, { useEffect, useState } from 'react';
import { ProForm, ProFormText, ProFormTextArea, ProFormDigit } from '@ant-design/pro-components';
import { Card, Tabs, Spin, message, Alert } from 'antd';
import { getSettings, updateSettings, changePassword } from '../services/api';
import ImageUploader from '../components/ImageUploader';
import RichTextEditor from '../components/RichTextEditor';

export default function Settings() {
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [initialValues, setInitialValues] = useState({});
  const [form] = ProForm.useForm();

  useEffect(() => {
    getSettings()
      .then((res) => {
        const data = res.data?.data || res.data;
        setInitialValues(data);
        form.setFieldsValue(data);
      })
      .catch(() => message.error('加载设置失败'))
      .finally(() => setLoading(false));
  }, [form]);

  const handleSave = async (values) => {
    setSaving(true);
    try {
      await updateSettings(values);
      message.success('保存成功');
    } catch (err) {
      message.error(err.response?.data?.message || '保存失败');
    } finally {
      setSaving(false);
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
      label: 'EPUSDT 支付',
      children: (
        <>
          <ProFormText name="epusdt_api_url" label="EPUSDT 网关地址" />
          <ProFormText name="epusdt_api_token" label="EPUSDT Token" />
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
    <Card title="修改密码" style={{ marginBottom: 16 }}>
      <Alert
        type="warning"
        showIcon
        style={{ marginBottom: 16 }}
        message="如果你还在使用安装时的初始密码，请立即修改。"
        description="后台地址是公开可访问的，初始密码写在项目文档里。"
      />
      <ProForm
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

    <Card title="系统设置">
      <ProForm
        form={form}
        // Without this, clearing the logo or the QR code submits no key at all and the
        // old image is kept. Fields on tabs the operator never opened stay unregistered
        // and so remain absent from the payload either way, and SettingController only
        // writes keys the request actually carries — so nothing else gets wiped.
        omitNil={false}
        initialValues={initialValues}
        onFinish={handleSave}
        submitter={{
          searchConfig: { submitText: '保存设置' },
          submitButtonProps: { loading: saving },
          resetButtonProps: false,
        }}
      >
        <Tabs items={tabItems} />
      </ProForm>
    </Card>
    </>
  );
}
