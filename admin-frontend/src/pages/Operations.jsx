import React, { useState, useEffect, useRef, useCallback } from 'react';
import {
  Tabs,
  Alert,
  Button,
  Card,
  Checkbox,
  Descriptions,
  Input,
  Modal,
  Select,
  Table,
  message,
  Space,
  Popconfirm,
  Progress,
  Typography,
  Tag,
} from 'antd';
import {
  ReloadOutlined,
  UploadOutlined,
  CloudDownloadOutlined,
  HistoryOutlined,
  EyeOutlined,
  EyeInvisibleOutlined,
} from '@ant-design/icons';
import { ProTable } from '@ant-design/pro-components';
import api from '../services/api';
import { useOutletContext } from 'react-router-dom';
import Link from '../components/PermissionLink';
import { allows, canCapability } from '../permissions';
import { downloadBlob } from '../utils/orderMeta';

const failure = (e) => {
  const errors = e?.response?.data?.errors;
  if (errors && typeof errors === 'object') {
    const first = Object.values(errors).flat().find(Boolean);
    if (first) return String(first);
  }
  return e?.response?.data?.message || '请求失败，请检查网络连接后重试。';
};
const bytes = (value) => {
  if (value == null) return '未知';
  if (value < 1024 * 1024) return (value / 1024).toFixed(1) + ' KB';
  return (value / 1024 / 1024).toFixed(1) + ' MB';
};

const NO_AUTOFILL_SECRET_PROPS = {
  autoComplete: 'off',
  autoCorrect: 'off',
  autoCapitalize: 'none',
  spellCheck: false,
  'data-lpignore': 'true',
  'data-1p-ignore': 'true',
  'data-bwignore': 'true',
  'data-form-type': 'other',
};

const RESTORE_SOURCE_LABELS = {
  local: '本地备份',
  upload: '浏览器上传',
  'cloud:s3': 'AWS S3 云端拉取',
  'cloud:r2': 'Cloudflare R2 云端拉取',
  'cloud:oss': '阿里云 OSS 云端拉取',
  'cloud:cos': '腾讯云 COS 云端拉取',
};

function ConfirmPasswordInput({ id, value, onChange, visible, onToggleVisible, disabled, placeholder }) {
  return (
    <Input
      id={id}
      type="text"
      value={value}
      onChange={onChange}
      disabled={disabled}
      placeholder={placeholder || '请输入当前管理员登录密码'}
      className={visible ? undefined : 'admin-secret-masked'}
      {...NO_AUTOFILL_SECRET_PROPS}
      suffix={
        <button
          type="button"
          className="admin-secret-toggle"
          disabled={disabled}
          aria-pressed={visible}
          aria-label={visible ? '隐藏密码' : '显示密码'}
          onClick={onToggleVisible}
        >
          {visible ? <EyeOutlined /> : <EyeInvisibleOutlined />}
        </button>
      }
    />
  );
}

const WORKER_LABELS = {
  notifications: '通知投递进程',
  scheduler: '定时调度进程',
  reconciliation: '自动对账进程',
  seo: '搜索推送进程',
};

export default function Operations() {
  const admin = useOutletContext();
  const maintenance = allows(admin, 'maintenance');
  const write = allows(admin, 'maintenance', 'write');
  const contentWrite = allows(admin, 'content', 'write');
  const backupsAllowed = canCapability(admin, 'backups.read');
  const backupsWrite = canCapability(admin, 'backups.write');
  const reconciliation = canCapability(admin, 'reconciliation.read');
  const [health, setHealth] = useState(null);
  const [healthError, setHealthError] = useState('');
  const [busy, setBusy] = useState(false);
  const [backupHealth, setBackupHealth] = useState(null);
  const [backupError, setBackupError] = useState('');
  const [restoreState, setRestoreState] = useState(null);
  const [restoreInMaintenance, setRestoreInMaintenance] = useState(false);
  const [cloudProviders, setCloudProviders] = useState([]);
  const [selectedCloudProvider, setSelectedCloudProvider] = useState('');
  const [cloudBackups, setCloudBackups] = useState([]);
  const [cloudLoading, setCloudLoading] = useState(false);
  const [cloudError, setCloudError] = useState('');
  const [pullingKey, setPullingKey] = useState('');
  const [restoreModal, setRestoreModal] = useState(null); // { mode: 'local'|'cloud', filename, provider?, providerLabel?, key? }
  const [restorePassword, setRestorePassword] = useState('');
  const [restorePasswordVisible, setRestorePasswordVisible] = useState(false);
  const [restoreSubmitting, setRestoreSubmitting] = useState(false);
  const [uploadModalOpen, setUploadModalOpen] = useState(false);
  const [uploadFile, setUploadFile] = useState(null);
  const [uploadRestoreNow, setUploadRestoreNow] = useState(false);
  const [uploadPassword, setUploadPassword] = useState('');
  const [uploadPasswordVisible, setUploadPasswordVisible] = useState(false);
  const [uploadSubmitting, setUploadSubmitting] = useState(false);
  const assetsRef = useRef();
  const backupRef = useRef();
  const backupRunsRef = useRef();
  const uploadInputRef = useRef();
  const cloudRequestRef = useRef(null);
  const mounted = useRef(true);

  useEffect(() => {
    mounted.current = true;
    return () => {
      mounted.current = false;
      cloudRequestRef.current?.abort();
    };
  }, []);

  const load = useCallback(async () => {
    try {
      const r = await api.get('/maintenance/health');
      if (mounted.current) {
        setHealth(r.data);
        setHealthError('');
      }
    } catch (e) {
      if (mounted.current) setHealthError(failure(e));
    }
  }, []);

  useEffect(() => {
    if (!maintenance) return;
    load();
    const timer = setInterval(load, 30000);
    return () => clearInterval(timer);
  }, [maintenance, load]);

  const active = (backupHealth?.pending || 0) > 0;
  const restoreActive =
    restoreInMaintenance || restoreState?.status === 'pending' || restoreState?.status === 'running';

  const refreshBackups = useCallback(() => {
    backupRef.current?.reload();
    backupRunsRef.current?.reload();
    if (maintenance) load();
  }, [maintenance, load]);

  useEffect(() => {
    if (!backupsAllowed || !active) return;
    const timer = setInterval(() => {
      backupRef.current?.reload();
      backupRunsRef.current?.reload();
    }, 5000);
    return () => clearInterval(timer);
  }, [backupsAllowed, active]);

  useEffect(() => {
    if (!backupsAllowed || !restoreActive) return;
    const timer = setInterval(async () => {
      try {
        const r = await api.get('/maintenance/restore-status');
        if (!mounted.current) return;
        const next = r.data?.restore ?? null;
        setRestoreState(next);
        setRestoreInMaintenance(false);
        if (next?.status === 'completed') {
          refreshBackups();
          message.success('全量恢复已完成，页面数据已刷新。');
        } else if (next?.status === 'failed') {
          message.error(next.error || '全量恢复失败，请查看状态说明。');
        }
      } catch (e) {
        if (!mounted.current) return;
        const status = e?.response?.status;
        if (status === 503 || !status) {
          setRestoreInMaintenance(true);
        }
      }
    }, 3000);
    return () => clearInterval(timer);
  }, [backupsAllowed, restoreActive, refreshBackups]);

  const loadCloudBackups = useCallback(async (provider) => {
    cloudRequestRef.current?.abort();
    if (!provider) {
      cloudRequestRef.current = null;
      setCloudBackups([]);
      setCloudError('');
      setCloudLoading(false);
      return;
    }
    const controller = new AbortController();
    cloudRequestRef.current = controller;
    setCloudLoading(true);
    setCloudError('');
    try {
      const r = await api.get('/maintenance/cloud-backups', { params: { provider }, signal: controller.signal });
      if (!mounted.current || controller.signal.aborted || cloudRequestRef.current !== controller) return;
      setCloudBackups(Array.isArray(r.data?.data) ? r.data.data : []);
    } catch (e) {
      if (!mounted.current || controller.signal.aborted || cloudRequestRef.current !== controller) return;
      setCloudBackups([]);
      setCloudError(failure(e));
    } finally {
      if (mounted.current && cloudRequestRef.current === controller) setCloudLoading(false);
    }
  }, []);

  useEffect(() => {
    if (!backupsAllowed || !selectedCloudProvider) return;
    loadCloudBackups(selectedCloudProvider);
  }, [backupsAllowed, selectedCloudProvider, loadCloudBackups]);

  const openRestoreModal = (config) => {
    setRestoreModal(config);
    setRestorePassword('');
    setRestorePasswordVisible(false);
  };

  const closeRestoreModal = () => {
    if (restoreSubmitting) return;
    setRestoreModal(null);
    setRestorePassword('');
    setRestorePasswordVisible(false);
  };

  const submitRestore = async () => {
    if (!restoreModal) return;
    if (!restorePassword.trim()) {
      message.warning('请输入当前管理员登录密码以确认全量恢复。');
      return;
    }
    setRestoreSubmitting(true);
    try {
      const res =
        restoreModal.mode === 'cloud'
          ? await api.post('/maintenance/cloud-backups/pull', {
              provider: restoreModal.provider,
              key: restoreModal.key,
              restore_now: true,
              password: restorePassword,
              confirm: 'RESTORE',
            })
          : await api.post(`/maintenance/backups/${encodeURIComponent(restoreModal.filename)}/restore`, {
              password: restorePassword,
              confirm: 'RESTORE',
            });
      if (!mounted.current) return;
      if (res.data?.restore) {
        setRestoreState(res.data.restore);
      }
      setRestoreModal(null);
      setRestorePassword('');
      setRestorePasswordVisible(false);
      message.success(res.data?.message || '全量恢复任务已提交。');
      if (restoreModal.mode === 'cloud') {
        backupRef.current?.reload();
      }
    } catch (e) {
      if (mounted.current) message.error(failure(e));
    } finally {
      if (mounted.current) setRestoreSubmitting(false);
    }
  };

  const handlePullCloudBackup = async (provider, key) => {
    setPullingKey(key);
    try {
      const res = await api.post('/maintenance/cloud-backups/pull', {
        provider,
        key,
        restore_now: false,
      });
      if (!mounted.current) return;
      message.success(res.data?.message || '云端备份已拉取到本地并校验通过。');
      backupRef.current?.reload();
    } catch (e) {
      if (mounted.current) message.error(failure(e));
    } finally {
      if (mounted.current) setPullingKey('');
    }
  };

  const openUploadModal = () => {
    setUploadModalOpen(true);
    setUploadFile(null);
    setUploadRestoreNow(false);
    setUploadPassword('');
    setUploadPasswordVisible(false);
    if (uploadInputRef.current) uploadInputRef.current.value = '';
  };

  const closeUploadModal = () => {
    if (uploadSubmitting) return;
    setUploadModalOpen(false);
    setUploadFile(null);
    setUploadRestoreNow(false);
    setUploadPassword('');
    setUploadPasswordVisible(false);
    if (uploadInputRef.current) uploadInputRef.current.value = '';
  };

  const submitUpload = async () => {
    if (!uploadFile) {
      message.warning('请先选择要上传的 .tar.gz 完整备份归档文件。');
      return;
    }
    if (uploadRestoreNow && !uploadPassword.trim()) {
      message.warning('勾选立即还原时，请输入当前管理员登录密码确认。');
      return;
    }
    setUploadSubmitting(true);
    try {
      const formData = new FormData();
      formData.append('file', uploadFile);
      if (uploadRestoreNow) {
        formData.append('restore_now', '1');
        formData.append('password', uploadPassword);
        formData.append('confirm', 'RESTORE');
      }
      const res = await api.post('/maintenance/backups/upload', formData);
      if (!mounted.current) return;
      if (res.data?.restore) {
        setRestoreState(res.data.restore);
      }
      setUploadModalOpen(false);
      setUploadFile(null);
      setUploadRestoreNow(false);
      setUploadPassword('');
      setUploadPasswordVisible(false);
      if (uploadInputRef.current) uploadInputRef.current.value = '';
      message.success(res.data?.message || '备份包上传并校验通过。');
      backupRef.current?.reload();
    } catch (e) {
      if (mounted.current) message.error(failure(e));
    } finally {
      if (mounted.current) setUploadSubmitting(false);
    }
  };

  const run = async (action, success = '操作已完成') => {
    try {
      await action();
      if (success) message.success(success);
    } catch (e) {
      message.error(failure(e));
    }
  };

  const ack = (type) =>
    run(async () => {
      const payload =
        type === 'reconciliation'
          ? {
              type,
              failure_version: health.reconciliation?.failure_version ?? 0,
            }
          : {
              type,
              observed_at: health.observed_at,
              through_id:
                type === 'notifications'
                  ? health.notification_failure_through_id
                  : health.seo_failure_through_id,
            };
      await api.post('/maintenance/health/acknowledge', payload);
      await load();
    }, '当前历史失败告警已确认；后续新产生的失败仍会正常告警。');

  const submit = async () => {
    setBusy(true);
    await run(async () => {
      await api.post('/maintenance/backups');
      if (mounted.current) setBackupHealth((v) => ({ ...v, pending: 1 }));
      refreshBackups();
    }, '全量备份任务已提交，请在下方任务列表查看执行进度。');
    if (mounted.current) setBusy(false);
  };

  const ackButton = (type, count) =>
    write &&
    (type !== 'notifications' || allows(admin, 'notifications', 'write')) &&
    (type !== 'seo' || contentWrite) &&
    count > 0 ? (
      <Popconfirm
        title="确认消除当前失败告警？失败任务记录本身保留，后续新产生的失败仍会触发告警。"
        onConfirm={() => ack(type)}
      >
        <Button size="small">确认当前失败</Button>
      </Popconfirm>
    ) : null;

  const items = [
    {
      key: 'health',
      label: '运行健康',
      children: (
        <>
          <div className="admin-action-bar" style={{ marginBottom: 16 }}>
            <Space wrap size={12}>
              <strong>后台进程心跳与任务积压监控</strong>
              {health && (
                <Tag color={health.healthy ? 'green' : 'orange'}>
                  {health.healthy ? '运行正常' : '存在待处理异常'}
                </Tag>
              )}
            </Space>
            <Space wrap>
              {allows(admin, 'notifications') && (
                <Link to="/tasks?tab=notifications&status=failed">失败通知队列</Link>
              )}
              {reconciliation && <Link to="/tasks?tab=reconciliation&status=failed">失败对账队列</Link>}
              {allows(admin, 'content') && <Link to="/tasks?tab=seo&status=failed">失败推送队列</Link>}
              <Button icon={<ReloadOutlined />} onClick={load}>
                刷新状态
              </Button>
            </Space>
          </div>

          {healthError && <Alert type="error" showIcon message={healthError} style={{ marginBottom: 16 }} />}

          {health && (
            <>
              {!health.healthy && (
                <Alert
                  type="warning"
                  showIcon
                  style={{ marginBottom: 16 }}
                  message="检测到后台任务积压、失败告警或备份过期，请核查常驻进程与网关配置（服务器可执行 php artisan shop:health --alert 诊断）。"
                />
              )}

              <div className="admin-health-grid">
                {['notifications', 'scheduler', 'reconciliation', 'seo'].map((k) => {
                  const node = health[k] || {};
                  const optionalDisabled = ['reconciliation', 'seo'].includes(k) && !node.enabled;
                  return (
                    <div className="admin-health-tile" key={k}>
                      <div className="admin-health-tile-top">
                        <span>{WORKER_LABELS[k]}</span>
                        {optionalDisabled ? (
                          <Tag>未启用</Tag>
                        ) : node.healthy ? (
                          <Tag color="green">正常</Tag>
                        ) : (
                          <Tag color="red">心跳超时</Tag>
                        )}
                      </div>
                      <div className="admin-health-tile-meta">
                        最近心跳：{node.last_seen_at || '尚未运行'}
                      </div>
                    </div>
                  );
                })}
              </div>

              <Descriptions bordered size="small" column={{ xs: 1, md: 2 }}>
                <Descriptions.Item label="待发送通知积压">
                  {health.notification_pending} 条 · 最早入队：{health.oldest_notification_at || '无积压'}
                </Descriptions.Item>
                <Descriptions.Item label="未确认通知失败">
                  <Space>
                    <span>{health.notification_failed} 条</span>
                    {ackButton('notifications', health.notification_failed)}
                  </Space>
                </Descriptions.Item>
                <Descriptions.Item label="待执行搜索推送">
                  {health.seo_pending} 条 · 最早入队：{health.oldest_seo_at || '无积压'}
                </Descriptions.Item>
                <Descriptions.Item label="未确认推送失败">
                  <Space>
                    <span>{health.seo_failed} 条</span>
                    {ackButton('seo', health.seo_failed)}
                  </Space>
                </Descriptions.Item>
                <Descriptions.Item label="自动对账队列">
                  等待 / 处理中：{health.reconciliation?.pending || 0} · 失败：{health.reconciliation?.failed || 0} · 最早等待：
                  {health.reconciliation?.oldest_pending_at || '无积压'}
                  {health.reconciliation?.backlog_warning ? '（等待超过 15 分钟）' : ''}
                </Descriptions.Item>
                <Descriptions.Item label="对账连续失败次数">
                  <Space>
                    <span>{health.reconciliation?.consecutive_failures || 0} 次</span>
                    {ackButton('reconciliation', health.reconciliation?.failure_warning ? 1 : 0)}
                  </Space>
                </Descriptions.Item>
                <Descriptions.Item label="逾期未释放锁库订单">{health.overdue_orders} 笔</Descriptions.Item>
                <Descriptions.Item label="全量数据备份状态">
                  <Tag color={health.backups?.healthy ? 'green' : 'orange'}>
                    {health.backups?.healthy ? '正常' : '需关注'}
                  </Tag>{' '}
                  最近成功：{health.backups?.last_success_at || '尚无备份'} · 可用空间：{bytes(health.backups?.free_bytes)}
                </Descriptions.Item>
              </Descriptions>
            </>
          )}
        </>
      ),
    },
    {
      key: 'assets',
      label: '上传素材',
      children: (
        <>
          <Alert
            type="info"
            showIcon
            message="仅支持归档未被商品、分类、文章或系统设置引用且上传满 7 天的静态素材；归档后如有需要可随时恢复。"
            style={{ marginBottom: 16 }}
          />
          <ProTable
            actionRef={assetsRef}
            rowKey="path"
            scroll={{ x: 'max-content' }}
            search={false}
            pagination={{ pageSize: 20 }}
            request={async () => {
              try {
                const r = await api.get('/maintenance/assets');
                return { data: r.data.data, success: true };
              } catch (e) {
                message.error(failure(e));
                return { data: [], success: false };
              }
            }}
            columns={[
              { title: '文件路径', dataIndex: 'path', render: (v) => <span className="admin-mono">{v}</span> },
              { title: '文件体积', width: 120, render: (_, r) => bytes(r.size) },
              {
                title: '引用状态',
                width: 180,
                render: (_, r) =>
                  r.quarantined ? (
                    <Tag>已归档</Tag>
                  ) : r.referenced ? (
                    <Tag color="green">使用中</Tag>
                  ) : r.can_quarantine ? (
                    <Tag color="blue">可归档</Tag>
                  ) : (
                    <Tag color="default">{r.quarantine_reason}</Tag>
                  ),
              },
              {
                title: '操作',
                width: 110,
                render: (_, r) =>
                  !write ? null : r.quarantined ? (
                    <Button
                      size="small"
                      onClick={() =>
                        run(async () => {
                          await api.post('/maintenance/assets', { path: r.path, action: 'restore' });
                          assetsRef.current?.reload();
                        })
                      }
                    >
                      恢复
                    </Button>
                  ) : r.can_quarantine ? (
                    <Popconfirm
                      title="确认归档该未引用素材？归档后仍可在此处恢复。"
                      onConfirm={() =>
                        run(async () => {
                          await api.post('/maintenance/assets', { path: r.path, action: 'quarantine' });
                          assetsRef.current?.reload();
                        })
                      }
                    >
                      <Button size="small">归档</Button>
                    </Popconfirm>
                  ) : (
                    <Button size="small" disabled title={r.quarantine_reason}>
                      归档
                    </Button>
                  ),
              },
            ]}
          />
        </>
      ),
    },
    {
      key: 'backups',
      label: '备份与恢复',
      children: (
        <>
          <Alert
            type="info"
            showIcon
            message={
              <span>
                全量备份由独立进程异步打包数据库、上传素材与环境配置。定时备份与云端多目标同步可在{' '}
                <Link to="/settings?tab=backup">系统设置 → 自动备份</Link>{' '}
                中配置；同机回滚或云端恢复可直接在本页一键还原，换新服务器迁移请执行{' '}
                <span className="admin-mono">scripts/restore.sh</span> 一行命令自动恢复。
              </span>
            }
            style={{ marginBottom: 16 }}
          />
          {backupError && <Alert type="error" showIcon message={backupError} style={{ marginBottom: 16 }} />}
          {(restoreInMaintenance || restoreState) && (
            <Alert
              type={
                restoreActive
                  ? 'warning'
                  : restoreState?.status === 'completed'
                    ? 'success'
                    : restoreState?.status === 'failed'
                      ? 'error'
                      : 'info'
              }
              showIcon
              closable={!restoreActive}
              onClose={() => setRestoreState(null)}
              style={{ marginBottom: 16 }}
              message={
                restoreInMaintenance
                  ? '站点正在维护模式中执行全量恢复，请稍候…'
                  : restoreState?.status === 'running'
                    ? '正在执行全量恢复'
                    : restoreState?.status === 'pending'
                      ? '全量恢复任务已入队，即将进入维护模式执行'
                      : restoreState?.status === 'completed'
                        ? `全量恢复已完成（${restoreState.filename || '未知归档'}）`
                        : `全量恢复失败（${restoreState?.filename || '未知归档'}）`
              }
              description={
                <div>
                  {restoreState && (
                    <div style={{ marginBottom: restoreActive ? 8 : 0 }}>
                      来源：{RESTORE_SOURCE_LABELS[restoreState.source] || restoreState.source || '本地备份'}
                      {restoreState.filename ? ` · 归档：${restoreState.filename}` : ''}
                      {restoreState.phase ? ` · 阶段：${restoreState.phase}` : ''}
                      {restoreState.finished_at && !restoreActive ? ` · 完成于 ${restoreState.finished_at}` : ''}
                    </div>
                  )}
                  {restoreActive && (
                    <>
                      <Progress
                        percent={restoreState?.progress ?? 40}
                        size="small"
                        status="active"
                        style={{ marginBottom: 4 }}
                      />
                      <Typography.Text type="secondary">
                        恢复期间前台与后台会短暂返回 503（约 10–30 秒），完成后页面会自动检测并刷新；若恢复改变了管理员登录态，会自动跳转登录页。
                      </Typography.Text>
                    </>
                  )}
                  {restoreState?.status === 'failed' && restoreState.error && (
                    <div style={{ marginTop: 4 }}>{restoreState.error}</div>
                  )}
                  {restoreState?.maintenance_retained && (
                    <div style={{ marginTop: 4 }}>
                      当前站点保持在维护模式以防止数据不一致，请确认恢复状态或排查后在服务器执行{' '}
                      <span className="admin-mono">php artisan up</span>。
                    </div>
                  )}
                </div>
              }
            />
          )}
          {backupHealth && (
            <Descriptions bordered size="small" column={{ xs: 1, sm: 2, lg: 4 }} style={{ marginBottom: 16 }}>
              <Descriptions.Item label="每日自动备份">
                {backupHealth.enabled ? <Tag color="green">已启用</Tag> : <Tag>未启用</Tag>}
              </Descriptions.Item>
              <Descriptions.Item label="备份进程状态">
                {backupHealth.worker_required
                  ? backupHealth.worker_healthy
                    ? '运行正常'
                    : '未收到近期心跳'
                  : '空闲待命'}
              </Descriptions.Item>
              <Descriptions.Item label="最近成功时间">{backupHealth.last_success_at || '尚无备份'}</Descriptions.Item>
              <Descriptions.Item label="存储可用空间">{bytes(backupHealth.free_bytes)}</Descriptions.Item>
            </Descriptions>
          )}
          {backupHealth && !backupHealth.healthy && (
            <Alert
              type="warning"
              showIcon
              message={[
                backupHealth.stale_warning && '全量备份已超过告警时限',
                backupHealth.space_warning && '备份目录剩余磁盘空间不足或不可读',
                backupHealth.failed > 0 && '存在未确认的备份或远端同步失败记录',
                !backupHealth.worker_healthy && '备份进程未上报近期心跳',
                backupHealth.backlog_warning && '备份任务排队等待超过 15 分钟',
              ]
                .filter(Boolean)
                .join('；')}
              style={{ marginBottom: 16 }}
            />
          )}

          <Typography.Title level={5} style={{ marginTop: 4 }}>
            备份执行记录
          </Typography.Title>
          <ProTable
            actionRef={backupRunsRef}
            rowKey="id"
            size="small"
            pagination={{ defaultPageSize: 20, showSizeChanger: true, pageSizeOptions: [20, 50, 100, 200] }}
            scroll={{ x: 860 }}
            request={async (p) => {
              try {
                const r = await api.get('/maintenance/backup-runs', {
                  params: {
                    page: p.current,
                    per_page: p.pageSize,
                    status: p.status,
                    needs_attention: p.needs_attention,
                  },
                });
                return { data: r.data.data, total: r.data.total, success: true };
              } catch (e) {
                message.error(failure(e));
                return { data: [], total: 0, success: false };
              }
            }}
            columns={[
              {
                title: '告警筛选',
                dataIndex: 'needs_attention',
                hideInTable: true,
                valueType: 'select',
                valueEnum: { 1: '仅未确认失败', 0: '全部记录' },
              },
              { title: '任务 ID', dataIndex: 'id', search: false, width: 80 },
              {
                title: '触发来源',
                dataIndex: 'source',
                search: false,
                width: 110,
                render: (_, r) =>
                  ({ manual: '控制台手动', scheduled: '每日定时', cli: '命令行触发', retry: '失败重试' }[r.source] ||
                    r.source),
              },
              {
                title: '状态',
                dataIndex: 'status',
                width: 100,
                valueType: 'select',
                valueEnum: { pending: '等待中', running: '执行中', completed: '已完成', failed: '失败' },
              },
              {
                title: '执行进度',
                search: false,
                width: 190,
                render: (_, r) => (
                  <>
                    <Progress percent={r.progress} size="small" status={r.status === 'failed' ? 'exception' : undefined} />
                    <span>{r.phase}</span>
                  </>
                ),
              },
              {
                title: '远端备份同步',
                dataIndex: 'sync_status',
                search: false,
                width: 120,
                render: (_, r) =>
                  ({ disabled: '未启用', pending: '等待同步', synced: '已校验同步', failed: '同步失败' }[r.sync_status] ||
                    r.sync_status),
              },
              { title: '输出文件 / 错误说明', search: false, ellipsis: true, render: (_, r) => r.last_error || r.filename || '-' },
              {
                title: '操作',
                search: false,
                width: 170,
                render: (_, r) => (
                  <Space>
                    {backupsWrite && (r.status === 'failed' || r.last_error) && (
                      <Button
                        size="small"
                        htmlType="button"
                        onClick={() =>
                          run(async () => {
                            await api.post(`/maintenance/backup-runs/${r.id}/retry`);
                            refreshBackups();
                          }, '已重新提交备份任务')
                        }
                      >
                        {r.status === 'completed' ? '重新备份并同步' : '重试'}
                      </Button>
                    )}
                    {backupsWrite && !r.health_acknowledged_at && (r.status === 'failed' || r.last_error) && (
                      <Popconfirm
                        title="确认消除本次失败告警？任务记录保留，后续新失败仍会触发告警。"
                        onConfirm={() =>
                          run(async () => {
                            await api.post(`/maintenance/backup-runs/${r.id}/acknowledge`);
                            refreshBackups();
                          })
                        }
                      >
                        <Button size="small" htmlType="button">确认失败</Button>
                      </Popconfirm>
                    )}
                  </Space>
                ),
              },
            ]}
          />

          <Typography.Title level={5} style={{ marginTop: 20 }}>
            完整备份文件
          </Typography.Title>
          <ProTable
            actionRef={backupRef}
            rowKey="name"
            scroll={{ x: 'max-content' }}
            search={false}
            request={async () => {
              try {
                const r = await api.get('/maintenance/backups');
                if (mounted.current) {
                  setBackupHealth(r.data.health);
                  setRestoreState(r.data.restore ?? null);
                  const providers = Array.isArray(r.data.enabled_cloud_providers)
                    ? r.data.enabled_cloud_providers
                    : [];
                  setCloudProviders(providers);
                  setSelectedCloudProvider((prev) =>
                    prev && providers.some((item) => item.key === prev) ? prev : providers[0]?.key || ''
                  );
                  setBackupError('');
                }
                return { data: r.data.data, success: true };
              } catch (e) {
                if (mounted.current) setBackupError(failure(e));
                return { data: [], success: false };
              }
            }}
            toolBarRender={() => [
              backupsWrite && (
                <Button
                  key="upload"
                  htmlType="button"
                  icon={<UploadOutlined />}
                  disabled={restoreActive}
                  onClick={openUploadModal}
                >
                  上传备份包
                </Button>
              ),
              backupsWrite && (
                <Button
                  key="create"
                  htmlType="button"
                  type="primary"
                  loading={busy}
                  disabled={active || restoreActive}
                  onClick={submit}
                >
                  {active ? '备份任务排队或执行中' : '创建完整备份'}
                </Button>
              ),
            ].filter(Boolean)}
            columns={[
              { title: '归档文件名', dataIndex: 'name', render: (v) => <span className="admin-mono">{v}</span> },
              { title: '归档大小', width: 120, render: (_, r) => bytes(r.size) },
              { title: '生成时间', dataIndex: 'created_at', width: 180 },
              {
                title: '操作',
                width: backupsWrite ? 240 : 100,
                render: (_, r) => (
                  <Space>
                    {backupsWrite && (
                      <Button
                        size="small"
                        htmlType="button"
                        onClick={() =>
                          run(async () => {
                            const x = await api.post(`/maintenance/backups/${r.name}/validate`);
                            message.info(x.data.message);
                          }, '')
                        }
                      >
                        校验完整性
                      </Button>
                    )}
                    <Button
                      size="small"
                      htmlType="button"
                      onClick={() =>
                        run(async () => {
                          const x = await api.get(`/maintenance/backups/${r.name}/download`, {
                            responseType: 'blob',
                          });
                          downloadBlob(x.data, r.name);
                        }, '')
                      }
                    >
                      下载
                    </Button>
                    {backupsWrite && (
                      <Button
                        size="small"
                        danger
                        htmlType="button"
                        icon={<HistoryOutlined />}
                        disabled={restoreActive}
                        onClick={() => openRestoreModal({ mode: 'local', filename: r.name })}
                      >
                        一键还原
                      </Button>
                    )}
                  </Space>
                ),
              },
            ]}
          />

          <Card
            size="small"
            style={{ marginTop: 20 }}
            title="云端对象存储备份"
            extra={
              cloudProviders.length > 0 ? (
                <Space wrap>
                  <Select
                    size="small"
                    style={{ minWidth: 160 }}
                    value={selectedCloudProvider || undefined}
                    onChange={(value) => setSelectedCloudProvider(value)}
                    options={cloudProviders.map((item) => ({ value: item.key, label: item.label }))}
                    aria-label="选择云端对象存储平台"
                  />
                  <Button
                    size="small"
                    htmlType="button"
                    icon={<ReloadOutlined />}
                    loading={cloudLoading}
                    onClick={() => loadCloudBackups(selectedCloudProvider)}
                  >
                    刷新云端列表
                  </Button>
                </Space>
              ) : null
            }
          >
            {cloudProviders.length === 0 ? (
              <Typography.Text type="secondary">
                当前未启用云端对象存储同步；如需直接从 AWS S3 / Cloudflare R2 / 阿里云 OSS / 腾讯云 COS 列出并一键拉取还原备份，请先在{' '}
                <Link to="/settings?tab=backup">系统设置 → 自动备份</Link> 中勾选并保存对应存储配置。
              </Typography.Text>
            ) : (
              <>
                {cloudError && <Alert type="error" showIcon message={cloudError} style={{ marginBottom: 12 }} />}
                <Table
                  rowKey="key"
                  size="small"
                  loading={cloudLoading}
                  dataSource={cloudBackups}
                  locale={{ emptyText: '该存储桶前缀下暂无 CardShop 完整备份归档' }}
                  pagination={{ pageSize: 10, hideOnSinglePage: true }}
                  scroll={{ x: 'max-content' }}
                  columns={[
                    { title: '归档文件名', dataIndex: 'name', render: (v) => <span className="admin-mono">{v}</span> },
                    { title: '归档大小', width: 120, render: (_, r) => bytes(r.size) },
                    { title: '云端更新时间', dataIndex: 'last_modified', width: 200, render: (v) => v || '-' },
                    {
                      title: '操作',
                      width: backupsWrite ? 240 : 120,
                      render: (_, r) =>
                        backupsWrite ? (
                          <Space>
                            <Button
                              size="small"
                              htmlType="button"
                              icon={<CloudDownloadOutlined />}
                              loading={pullingKey === r.key}
                              disabled={restoreActive || (Boolean(pullingKey) && pullingKey !== r.key)}
                              onClick={() => handlePullCloudBackup(selectedCloudProvider, r.key)}
                            >
                              拉取到本地
                            </Button>
                            <Button
                              size="small"
                              danger
                              htmlType="button"
                              icon={<HistoryOutlined />}
                              disabled={restoreActive || Boolean(pullingKey)}
                              onClick={() =>
                                openRestoreModal({
                                  mode: 'cloud',
                                  filename: r.name,
                                  key: r.key,
                                  provider: selectedCloudProvider,
                                  providerLabel:
                                    cloudProviders.find((p) => p.key === selectedCloudProvider)?.label ||
                                    selectedCloudProvider,
                                })
                              }
                            >
                              一键拉取并还原
                            </Button>
                          </Space>
                        ) : (
                          <Typography.Text type="secondary">仅查看权限</Typography.Text>
                        ),
                    },
                  ]}
                />
              </>
            )}
          </Card>
        </>
      ),
    },
  ].filter((tab) => (tab.key === 'backups' ? backupsAllowed : maintenance));

  return (
    <div className="admin-surface-tabs">
      <Tabs items={items} />

      <Modal
        open={Boolean(restoreModal)}
        title={restoreModal?.mode === 'cloud' ? '确认从云端拉取并全量还原' : '确认全量还原备份'}
        onCancel={closeRestoreModal}
        destroyOnHidden
        footer={[
          <Button key="cancel" htmlType="button" disabled={restoreSubmitting} onClick={closeRestoreModal}>
            取消
          </Button>,
          <Button
            key="confirm"
            htmlType="button"
            type="primary"
            danger
            loading={restoreSubmitting}
            disabled={!restorePassword.trim()}
            onClick={submitRestore}
          >
            {restoreModal?.mode === 'cloud' ? '确认拉取并还原' : '确认立即还原'}
          </Button>,
        ]}
      >
        <Alert
          type="warning"
          showIcon
          style={{ marginBottom: 16 }}
          message="此操作将用所选备份完整覆盖当前数据库、商品素材与私钥目录"
          description="站点将短暂进入维护模式（约 10–30 秒）自动完成恢复。恢复前系统会先校验归档完整性与密钥环匹配性；若为换新服务器迁移且密钥不同，请直接使用 scripts/restore.sh 一行命令恢复。"
        />
        <Descriptions bordered size="small" column={1} style={{ marginBottom: 16 }}>
          <Descriptions.Item label="目标归档">
            <span className="admin-mono">{restoreModal?.filename}</span>
          </Descriptions.Item>
          <Descriptions.Item label="恢复来源">
            {restoreModal?.mode === 'cloud'
              ? `云端拉取（${restoreModal.providerLabel || restoreModal.provider}）`
              : '服务器本地完整备份'}
          </Descriptions.Item>
        </Descriptions>
        <div>
          <label
            htmlFor="restore-confirm-password"
            style={{ display: 'block', marginBottom: 6, fontWeight: 500 }}
          >
            请输入当前管理员登录密码以确认
          </label>
          <ConfirmPasswordInput
            id="restore-confirm-password"
            value={restorePassword}
            onChange={(e) => setRestorePassword(e.target.value)}
            visible={restorePasswordVisible}
            onToggleVisible={() => setRestorePasswordVisible((prev) => !prev)}
            disabled={restoreSubmitting}
          />
        </div>
      </Modal>

      <Modal
        open={uploadModalOpen}
        title="上传完整备份包（.tar.gz）"
        onCancel={closeUploadModal}
        destroyOnHidden
        footer={[
          <Button key="cancel" htmlType="button" disabled={uploadSubmitting} onClick={closeUploadModal}>
            取消
          </Button>,
          <Button
            key="upload"
            htmlType="button"
            type="primary"
            danger={uploadRestoreNow}
            loading={uploadSubmitting}
            disabled={!uploadFile || (uploadRestoreNow && !uploadPassword.trim())}
            onClick={submitUpload}
          >
            {uploadRestoreNow ? '上传校验并立即还原' : '上传并校验'}
          </Button>,
        ]}
      >
        <Alert
          type="info"
          showIcon
          style={{ marginBottom: 16 }}
          message="支持上传 CardShop 导出的 .tar.gz 完整备份归档（单次上限 100 MB）"
          description="上传后会立即校验归档清单、SHA-256 哈希与当前密钥环兼容性；超过 100 MB 的备份请优先通过云端对象存储拉取或使用 scripts/restore.sh。"
        />
        <div style={{ marginBottom: 16 }}>
          <label
            htmlFor="backup-archive-upload-input"
            style={{ display: 'block', marginBottom: 6, fontWeight: 500 }}
          >
            选择备份归档文件（.tar.gz）
          </label>
          <input
            ref={uploadInputRef}
            id="backup-archive-upload-input"
            type="file"
            accept=".tar.gz,application/gzip,application/x-gzip"
            disabled={uploadSubmitting}
            onChange={(e) => setUploadFile(e.target.files?.[0] || null)}
          />
          {uploadFile && (
            <div style={{ marginTop: 6, fontSize: 12, color: '#595959' }}>
              已选：<span className="admin-mono">{uploadFile.name}</span>（{bytes(uploadFile.size)}）
            </div>
          )}
        </div>
        <div style={{ marginBottom: uploadRestoreNow ? 12 : 0 }}>
          <Checkbox
            checked={uploadRestoreNow}
            disabled={uploadSubmitting}
            onChange={(e) => {
              setUploadRestoreNow(e.target.checked);
              if (!e.target.checked) {
                setUploadPassword('');
                setUploadPasswordVisible(false);
              }
            }}
          >
            上传并校验通过后立即执行全量还原（将短暂进入维护模式覆盖当前数据）
          </Checkbox>
        </div>
        {uploadRestoreNow && (
          <div>
            <label
              htmlFor="upload-restore-confirm-password"
              style={{ display: 'block', marginBottom: 6, fontWeight: 500 }}
            >
              请输入当前管理员登录密码以确认立即还原
            </label>
            <ConfirmPasswordInput
              id="upload-restore-confirm-password"
              value={uploadPassword}
              onChange={(e) => setUploadPassword(e.target.value)}
              visible={uploadPasswordVisible}
              onToggleVisible={() => setUploadPasswordVisible((prev) => !prev)}
              disabled={uploadSubmitting}
            />
          </div>
        )}
      </Modal>
    </div>
  );
}
