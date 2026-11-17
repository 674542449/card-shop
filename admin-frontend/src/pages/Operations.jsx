import React, { useState, useEffect, useRef, useCallback } from 'react';
import {
  Tabs,
  Alert,
  Button,
  Descriptions,
  message,
  Space,
  Popconfirm,
  Progress,
  Typography,
  Tag,
} from 'antd';
import { ReloadOutlined } from '@ant-design/icons';
import { ProTable } from '@ant-design/pro-components';
import api from '../services/api';
import { useOutletContext } from 'react-router-dom';
import Link from '../components/PermissionLink';
import { allows, canCapability } from '../permissions';
import { downloadBlob } from '../utils/orderMeta';

const failure = (e) => e.response?.data?.message || '请求失败，请检查网络连接后重试。';
const bytes = (value) => (value == null ? '未知' : (value / 1024 / 1024).toFixed(1) + ' MB');

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
  const reconciliation = canCapability(admin, 'reconciliation.read');
  const [health, setHealth] = useState(null);
  const [healthError, setHealthError] = useState('');
  const [busy, setBusy] = useState(false);
  const [backupHealth, setBackupHealth] = useState(null);
  const [backupError, setBackupError] = useState('');
  const assetsRef = useRef();
  const backupRef = useRef();
  const backupRunsRef = useRef();
  const mounted = useRef(true);

  useEffect(() => {
    mounted.current = true;
    return () => {
      mounted.current = false;
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
  const refreshBackups = () => {
    backupRef.current?.reload();
    backupRunsRef.current?.reload();
    if (maintenance) load();
  };

  useEffect(() => {
    if (!backupsAllowed || !active) return;
    const timer = setInterval(() => {
      backupRef.current?.reload();
      backupRunsRef.current?.reload();
    }, 5000);
    return () => clearInterval(timer);
  }, [backupsAllowed, active]);

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
      await api.post('/maintenance/health/acknowledge', {
        type,
        observed_at: health.observed_at,
        through_id:
          type === 'notifications' ? health.notification_failure_through_id : health.seo_failure_through_id,
        failure_version: health.reconciliation?.failure_version,
      });
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
                全量备份由独立进程异步打包数据库、上传素材与环境配置。定时备份与保留策略可在{' '}
                <Link to="/settings">系统设置 → 自动备份</Link> 中配置；恢复操作请参照 DEPLOY.md 在独立环境先行演练。
              </span>
            }
            style={{ marginBottom: 16 }}
          />
          {backupError && <Alert type="error" showIcon message={backupError} style={{ marginBottom: 16 }} />}
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
                backupHealth.failed > 0 && '存在未确认的备份或异机同步失败记录',
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
                title: '异机目录同步',
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
                    {(r.status === 'failed' || r.last_error) && (
                      <Button
                        size="small"
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
                    {!r.health_acknowledged_at && (r.status === 'failed' || r.last_error) && (
                      <Popconfirm
                        title="确认消除本次失败告警？任务记录保留，后续新失败仍会触发告警。"
                        onConfirm={() =>
                          run(async () => {
                            await api.post(`/maintenance/backup-runs/${r.id}/acknowledge`);
                            refreshBackups();
                          })
                        }
                      >
                        <Button size="small">确认失败</Button>
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
                  setBackupError('');
                }
                return { data: r.data.data, success: true };
              } catch (e) {
                if (mounted.current) setBackupError(failure(e));
                return { data: [], success: false };
              }
            }}
            toolBarRender={() => [
              <Button key="create" type="primary" loading={busy} disabled={active} onClick={submit}>
                {active ? '备份任务排队或执行中' : '创建完整备份'}
              </Button>,
            ]}
            columns={[
              { title: '归档文件名', dataIndex: 'name', render: (v) => <span className="admin-mono">{v}</span> },
              { title: '归档大小', width: 120, render: (_, r) => bytes(r.size) },
              { title: '生成时间', dataIndex: 'created_at', width: 180 },
              {
                title: '操作',
                width: 150,
                render: (_, r) => (
                  <Space>
                    <Button
                      size="small"
                      onClick={() =>
                        run(async () => {
                          const x = await api.post(`/maintenance/backups/${r.name}/validate`);
                          message.info(x.data.message);
                        }, '')
                      }
                    >
                      校验完整性
                    </Button>
                    <Button
                      size="small"
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
                  </Space>
                ),
              },
            ]}
          />
        </>
      ),
    },
  ].filter((tab) => (tab.key === 'backups' ? backupsAllowed : maintenance));

  return (
    <div className="admin-surface-tabs">
      <Tabs items={items} />
    </div>
  );
}
