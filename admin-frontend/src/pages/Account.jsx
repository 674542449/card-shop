import React, { useCallback, useEffect, useState } from 'react';
import { ProForm, ProFormText } from '@ant-design/pro-components';
import { Alert, Button, Card, Descriptions, message, Spin, Typography } from 'antd';
import { getMe, changePassword } from '../services/api';
import TwoFactorSettings from '../components/TwoFactorSettings';
import { formatDateTime } from '../utils/orderMeta';

export default function Account() {
  const [admin, setAdmin] = useState(null);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
  const [form] = ProForm.useForm();

  const load = useCallback(async () => {
    setLoading(true);
    setFailed(false);
    try {
      const res = await getMe();
      setAdmin(res.data);
    } catch {
      setAdmin(null);
      setFailed(true);
    } finally {
      setLoading(false);
    }
  }, []);
  useEffect(() => {
    load();
  }, [load]);

  if (loading) {
    return (
      <div style={{ padding: 80, textAlign: 'center' }}>
        <Spin size="large" />
      </div>
    );
  }
  if (failed) {
    return (
      <Alert
        type="error"
        showIcon
        message="管理员账户信息加载失败"
        description="暂时无法获取当前会话的账户资料，请检查网络连接后重试。"
        action={<Button size="small" onClick={load}>重试</Button>}
      />
    );
  }

  return (
    <div className="admin-account" style={{ maxWidth: 920 }}>
      <Card title="当前会话与登录记录" style={{ marginBottom: 20 }}>
        <Descriptions column={{ xs: 1, sm: 3 }}>
          <Descriptions.Item label="管理员账号">{admin?.username || '—'}</Descriptions.Item>
          <Descriptions.Item label="最近登录 IP">
            <span className="admin-mono">{admin?.last_login_ip || '—'}</span>
          </Descriptions.Item>
          <Descriptions.Item label="最近登录时间">
            {admin?.last_login_at ? formatDateTime(admin.last_login_at) : '—'}
          </Descriptions.Item>
        </Descriptions>
      </Card>

      <Card title="修改登录密码">
        <Typography.Paragraph type="secondary">
          请设置不少于 12 个字符的高强度密码。更新密码后当前浏览器会话将继续保持有效，其余终端持有的历史会话将立即失效。
        </Typography.Paragraph>
        <ProForm
          name="account-password"
          form={form}
          autoFocusFirstInput={false}
          submitter={{ searchConfig: { submitText: '更新登录密码' }, resetButtonProps: false }}
          onFinish={async (values) => {
            try {
              await changePassword(values);
              form.resetFields();
              message.success('管理员密码已更新');
              return true;
            } catch (err) {
              message.error(err.response?.data?.message || '密码更新失败');
              return false;
            }
          }}
        >
          <ProFormText.Password
            name="current_password"
            label="当前登录密码"
            fieldProps={{ autoComplete: 'current-password' }}
            rules={[{ required: true, message: '请输入当前登录密码' }]}
          />
          <div className="admin-form-grid-2">
            <ProFormText.Password
              name="new_password"
              label="新登录密码"
              fieldProps={{ autoComplete: 'new-password' }}
              rules={[
                { required: true, message: '请输入新登录密码' },
                { min: 12, message: '新密码长度不得少于 12 个字符' },
              ]}
            />
            <ProFormText.Password
              name="new_password_confirmation"
              label="确认新登录密码"
              dependencies={['new_password']}
              fieldProps={{ autoComplete: 'new-password' }}
              rules={[
                { required: true, message: '请再次输入新登录密码' },
                ({ getFieldValue }) => ({
                  validator: (_, value) =>
                    !value || getFieldValue('new_password') === value
                      ? Promise.resolve()
                      : Promise.reject(new Error('两次输入的新密码不一致')),
                }),
              ]}
            />
          </div>
        </ProForm>
      </Card>

      <TwoFactorSettings
        admin={admin}
        onChange={async () => {
          const res = await getMe();
          setAdmin(res.data);
        }}
      />
    </div>
  );
}
