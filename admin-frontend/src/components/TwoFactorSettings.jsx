import React, { useState } from 'react';
import { Alert, Button, Card, Form, Input, QRCode, Space, Typography, message } from 'antd';
import { setupTwoFactor, confirmTwoFactor, disableTwoFactor } from '../services/api';

export default function TwoFactorSettings({ admin, onChange }) {
  const [setup, setSetup] = useState(null);
  const [codes, setCodes] = useState(null);
  const [busy, setBusy] = useState(false);
  const [form] = Form.useForm();

  const finish = async (values) => {
    setBusy(true);
    try {
      if (admin.two_factor_enabled) {
        await disableTwoFactor(values);
        message.success('双重身份验证已停用');
      } else if (setup) {
        const res = await confirmTwoFactor(values.code);
        setCodes(res.data.recovery_codes);
        setSetup(null);
        message.success('双重身份验证已启用，请立即备份恢复码');
      } else {
        const res = await setupTwoFactor(values.current_password);
        setSetup(res.data);
        form.resetFields();
        return;
      }
      form.resetFields();
      await onChange();
    } catch (err) {
      message.error(err.response?.data?.message || '操作失败，请核对输入后重试');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Card title="TOTP 双重身份验证（2FA）" style={{ marginTop: 20 }}>
      <Typography.Paragraph type="secondary">
        通过标准身份验证器应用生成 6 位动态口令。启用或停用双重验证后，其余终端会话将自动失效；每枚动态口令仅限单次核验。如遗失验证设备，可使用紧急恢复码登录或通过服务器命令行重置。
      </Typography.Paragraph>
      <Alert
        type={admin.two_factor_enabled ? 'success' : 'info'}
        showIcon
        message={
          admin.two_factor_enabled
            ? `已启用双重身份验证 · 剩余可用恢复码 ${admin.recovery_codes_remaining} 个`
            : '当前尚未启用双重身份验证'
        }
        style={{ marginBottom: 16 }}
      />
      {codes && (
        <Alert
          type="warning"
          showIcon
          message="紧急恢复码仅在本次绑定完成时展示一次，请离线妥善保存（每个恢复码仅限使用一次）"
          description={
            <>
              <div className="admin-secret-item" style={{ marginTop: 8, marginBottom: 12 }}>
                <Typography.Paragraph copyable={{ text: codes.join('\n') }} style={{ marginBottom: 0 }}>
                  <pre style={{ margin: 0 }}>{codes.join('\n')}</pre>
                </Typography.Paragraph>
              </div>
              <Button onClick={() => setCodes(null)}>已安全备份，隐藏恢复码</Button>
            </>
          }
          style={{ marginBottom: 16 }}
        />
      )}
      {setup && (
        <Space direction="vertical" style={{ marginBottom: 16 }}>
          <Typography.Text>
            请使用身份验证器扫描下方二维码（或手动录入密钥），并在 5 分钟内输入当前生成的 6 位验证码完成绑定：
          </Typography.Text>
          <QRCode value={setup.uri} />
          <Typography.Text code copyable>
            {setup.secret}
          </Typography.Text>
        </Space>
      )}
      <Form form={form} layout="vertical" onFinish={finish} disabled={busy}>
        {!setup && (
          <Form.Item
            name="current_password"
            label="当前登录密码"
            rules={[{ required: true, message: '请输入当前登录密码' }]}
          >
            <Input.Password autoComplete="current-password" />
          </Form.Item>
        )}
        {(setup || admin.two_factor_enabled) && (
          <Form.Item
            name="code"
            label={setup ? '6 位动态验证码' : '动态验证码或紧急恢复码'}
            rules={[{ required: true, message: '请输入验证码或恢复码' }]}
          >
            <Input autoComplete="one-time-code" maxLength={32} />
          </Form.Item>
        )}
        <Space>
          <Button type="primary" danger={admin.two_factor_enabled} htmlType="submit" loading={busy}>
            {admin.two_factor_enabled ? '停用双重验证' : setup ? '确认验证码并启用' : '配置身份验证器'}
          </Button>
          {setup && (
            <Button
              onClick={() => {
                setSetup(null);
                form.resetFields();
              }}
            >
              取消绑定
            </Button>
          )}
        </Space>
      </Form>
    </Card>
  );
}
