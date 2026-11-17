import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  UserOutlined,
  LockOutlined,
  ArrowRightOutlined,
  ExportOutlined,
  SafetyCertificateOutlined,
  CheckCircleFilled,
  KeyOutlined,
} from '@ant-design/icons';
import { Button, Form, Input, message } from 'antd';
import { login, loginChallenge } from '../services/api';
import { ShopMark } from '../layouts/AdminLayout';

export default function LoginPage() {
  const navigate = useNavigate();
  const [loading, setLoading] = useState(false);
  const [challenge, setChallenge] = useState(false);
  const [form] = Form.useForm();

  const handleSubmit = async (values) => {
    setLoading(true);
    try {
      if (challenge) {
        await loginChallenge(values.code);
      } else {
        const res = await login(values.username, values.password);
        if (res.data?.two_factor_required) {
          form.resetFields();
          setChallenge(true);
          return;
        }
      }
      message.success('身份验证通过');
      navigate('/', { replace: true });
    } catch (err) {
      if (challenge && err.response?.status === 401) {
        setChallenge(false);
        form.resetFields();
      }
      message.error(err.response?.data?.message || '身份验证失败，请核对账号与密码');
    } finally {
      setLoading(false);
    }
  };

  return (
    <main className="admin-login-shell">
      <header className="admin-login-topbar">
        <a className="admin-login-brand" href="/" aria-label="CardShop 商城首页">
          <ShopMark />
          <span>CardShop</span>
          <span className="admin-login-brand-badge">CONSOLE</span>
        </a>
        <a className="admin-login-backlink" href="/">
          访问商城前台 <ExportOutlined />
        </a>
      </header>

      <div className="admin-login-grid">
        <section className="admin-login-intro">
          <span className="admin-eyebrow">ADMINISTRATION WORKSPACE</span>
          <h1>
            数字商品交易与
            <br />
            <em>自动化履约控制台</em>
          </h1>
          <p>
            集中管理商品目录、加密卡密库存、多网关收款核对、售后工单及系统运维监控，保障全链路交易安全与履约准确。
          </p>

          <div className="admin-login-features" aria-label="核心架构特性">
            <div className="admin-login-feature">
              <CheckCircleFilled className="admin-login-feature-icon" />
              <div>
                <strong>加密卡密库存管理</strong>
                <span>独立密文存储与指纹去重，严格隔离待支付锁定、已交付与停用状态</span>
              </div>
            </div>
            <div className="admin-login-feature">
              <CheckCircleFilled className="admin-login-feature-icon" />
              <div>
                <strong>多渠道收款与自动对账</strong>
                <span>支持网关流水核验、异常收款拦截提示、售后换卡与原路退款登记</span>
              </div>
            </div>
            <div className="admin-login-feature">
              <CheckCircleFilled className="admin-login-feature-icon" />
              <div>
                <strong>会话安全与操作审计</strong>
                <span>会话密码指纹绑定、TOTP 双重验证及全量管理操作审计追踪</span>
              </div>
            </div>
          </div>
        </section>

        <section className="admin-login-panel" aria-labelledby="admin-login-title">
          <span className="admin-eyebrow">{challenge ? 'TWO-FACTOR VERIFICATION' : 'AUTHENTICATION'}</span>
          <h2 id="admin-login-title">{challenge ? '双重身份验证' : '登录管理控制台'}</h2>
          <p className="admin-login-description">
            {challenge
              ? '请输入身份验证器生成的 6 位动态验证码，或输入一枚未使用的紧急恢复码（验证会话有效期 5 分钟）。'
              : '请输入管理员账号与登录密码以进入管理控制台。'}
          </p>

          <Form
            form={form}
            name="admin-login"
            layout="vertical"
            onFinish={handleSubmit}
            requiredMark={false}
            disabled={loading}
            size="large"
          >
            {challenge ? (
              <Form.Item name="code" label="动态验证码 / 恢复码" rules={[{ required: true, message: '请输入验证码或恢复码' }]}>
                <Input
                  prefix={<KeyOutlined />}
                  autoComplete="one-time-code"
                  autoFocus
                  maxLength={32}
                  placeholder="6 位数字验证码或恢复码"
                />
              </Form.Item>
            ) : (
              <>
                <Form.Item name="username" label="管理员账号" rules={[{ required: true, message: '请输入管理员账号' }]}>
                  <Input prefix={<UserOutlined />} placeholder="请输入管理员账号" autoComplete="username" />
                </Form.Item>
                <Form.Item name="password" label="登录密码" rules={[{ required: true, message: '请输入登录密码' }]}>
                  <Input.Password prefix={<LockOutlined />} placeholder="请输入登录密码" autoComplete="current-password" />
                </Form.Item>
              </>
            )}

            <Button className="admin-login-submit" type="primary" htmlType="submit" loading={loading} block icon={<ArrowRightOutlined />}>
              {challenge ? '完成验证并进入' : '登录控制台'}
            </Button>
            {challenge && (
              <Button
                type="link"
                block
                style={{ marginTop: 8 }}
                onClick={() => {
                  setChallenge(false);
                  form.resetFields();
                }}
              >
                返回重新输入账号密码
              </Button>
            )}
          </Form>

          <div className="admin-login-panel-footer">
            <SafetyCertificateOutlined />
            <span>受保护的管理员访问通道 · 所有登录行为均记录审计日志</span>
          </div>
        </section>
      </div>

      <footer className="admin-login-footer">
        <span>CardShop 数字商品管理系统</span>
        <span>请妥善保管管理员凭据与双重验证恢复码</span>
      </footer>
    </main>
  );
}
