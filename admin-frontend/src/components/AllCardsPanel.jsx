import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ProTable, ModalForm, ProFormSelect, ProFormTextArea } from '@ant-design/pro-components';
import { Button, Card, Dropdown, Modal, Popconfirm, Space, Tag, Typography, Upload, message } from 'antd';
import {
  PlusOutlined,
  UploadOutlined,
  DeleteOutlined,
  PauseCircleOutlined,
  PlayCircleOutlined,
  DownloadOutlined,
  DownOutlined,
  ClearOutlined,
} from '@ant-design/icons';
import Link from './PermissionLink';
import {
  getAllCards,
  getCardProductOptions,
  bulkCardAction,
  exportCards,
  importCards,
  deleteCard,
  setCardStatus,
} from '../services/api';

export function downloadBlobResponse(res, fallbackName) {
  const disposition = res.headers?.['content-disposition'] || '';
  const match = disposition.match(/filename="?([^";\n]+)"?/i);
  const filename = match?.[1] || fallbackName;
  const blob = res.data instanceof Blob ? res.data : new Blob([res.data]);
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}

const ACTION_COPY = {
  pause_unsold: {
    title: '一键暂停可售卡密',
    okText: '确认暂停',
    danger: false,
    countKey: 'unsold',
    noun: '可售卡密',
    detail: '暂停后所选范围内的未售卡密将转为「停用」状态，不再分配给新订单或售后换卡，可随时一键恢复；待支付锁定与已售卡密不受影响。',
  },
  resume_disabled: {
    title: '一键恢复停用卡密',
    okText: '确认恢复',
    danger: false,
    countKey: 'disabled',
    noun: '停用卡密',
    detail: '仅恢复无订单关联的「停用」卡密为可售状态，请确认这些卡密仍有效且未曾对外交付；线下已售与订单已售记录不受影响。',
  },
  delete_unsold: {
    title: '一键清空未售卡密',
    okText: '确认永久清空',
    danger: true,
    countKey: 'unsold',
    noun: '未售卡密',
    detail: '此操作将永久删除所选范围内的全部「未售」卡密且不可撤销！待支付锁定、订单已售、线下已售与售后换出卡密均受保护不会被删除。',
  },
  delete_disabled: {
    title: '一键清空停用卡密',
    okText: '确认永久清理',
    danger: true,
    countKey: 'disabled',
    noun: '停用卡密',
    detail: '此操作将永久删除所选范围内的全部「停用」废弃卡密且不可撤销！未售、待支付锁定与已售卡密不受影响。',
  },
};

export default function AllCardsPanel({ canWrite, categoryOptions = [], onInventoryChanged }) {
  const actionRef = useRef();
  const requests = useRef(new Set());
  const [productOptions, setProductOptions] = useState([]);
  const [stats, setStats] = useState(null);
  const [globalStats, setGlobalStats] = useState(null);
  const [activeFilters, setActiveFilters] = useState({});
  const [selectedRowKeys, setSelectedRowKeys] = useState([]);
  const [selectedRowsMap, setSelectedRowsMap] = useState({});
  const [importVisible, setImportVisible] = useState(false);
  const [importProductId, setImportProductId] = useState(undefined);
  const [uploading, setUploading] = useState(false);
  const [exporting, setExporting] = useState(false);
  const [busyAction, setBusyAction] = useState('');

  useEffect(() => () => {
    for (const controller of requests.current) controller.abort();
    requests.current.clear();
  }, []);

  const refreshProductOptions = useCallback(() => {
    const controller = new AbortController();
    requests.current.add(controller);
    getCardProductOptions({ signal: controller.signal })
      .then((res) => {
        if (controller.signal.aborted) return;
        const list = res.data?.data ?? res.data ?? [];
        if (Array.isArray(list)) {
          setProductOptions(list);
        }
      })
      .catch(() => {})
      .finally(() => {
        requests.current.delete(controller);
      });
  }, []);

  useEffect(() => {
    refreshProductOptions();
  }, [refreshProductOptions]);

  const clearSelection = useCallback(() => {
    setSelectedRowKeys([]);
    setSelectedRowsMap({});
  }, []);

  const reloadAll = useCallback(() => {
    clearSelection();
    actionRef.current?.reload();
    refreshProductOptions();
    onInventoryChanged?.();
  }, [clearSelection, refreshProductOptions, onInventoryChanged]);

  const currentScope = useMemo(() => {
    if (activeFilters.product_id) {
      const prod = productOptions.find((p) => Number(p.id) === Number(activeFilters.product_id));
      return {
        scope: 'products',
        product_ids: [Number(activeFilters.product_id)],
        label: prod ? `商品「${prod.name}」` : `商品 #${activeFilters.product_id}`,
      };
    }
    if (activeFilters.category_id) {
      const cat = categoryOptions.find((c) => Number(c.value) === Number(activeFilters.category_id));
      return {
        scope: 'category',
        category_id: Number(activeFilters.category_id),
        label: cat ? `分类「${cat.label}」` : `分类 #${activeFilters.category_id}`,
      };
    }
    return { scope: 'all', label: '全部商品' };
  }, [activeFilters.product_id, activeFilters.category_id, productOptions, categoryOptions]);

  const selectedUnsoldIds = useMemo(
    () => selectedRowKeys.filter((id) => selectedRowsMap[id]?.status === 'unsold'),
    [selectedRowKeys, selectedRowsMap]
  );
  const selectedDisabledIds = useMemo(
    () => selectedRowKeys.filter((id) => selectedRowsMap[id]?.status === 'disabled'),
    [selectedRowKeys, selectedRowsMap]
  );

  const runBulkAction = useCallback(
    async (payload, label) => {
      setBusyAction(payload.action);
      try {
        const res = await bulkCardAction(payload);
        message.success(res.data?.message || `${label}已完成`);
        reloadAll();
      } catch (err) {
        message.error(err.response?.data?.message || `${label}失败`, 6);
      } finally {
        setBusyAction('');
      }
    },
    [reloadAll]
  );

  const confirmScopeAction = (actionKey) => {
    const meta = ACTION_COPY[actionKey];
    if (!meta) return;
    const count = stats?.[meta.countKey] ?? 0;
    if (count === 0) {
      message.info(`${currentScope.label}中当前没有可操作的${meta.noun}`);
      return;
    }
    const payload = {
      action: actionKey,
      scope: currentScope.scope,
      ...(currentScope.category_id ? { category_id: currentScope.category_id } : {}),
      ...(currentScope.product_ids ? { product_ids: currentScope.product_ids } : {}),
    };
    Modal.confirm({
      title: `${meta.title}（${currentScope.label}）`,
      content: (
        <div>
          <p style={{ marginBottom: 8 }}>
            即将对 <strong>{currentScope.label}</strong> 的 <strong>{count}</strong> 张{meta.noun}执行「{meta.title}」。
          </p>
          <Typography.Text type="secondary">{meta.detail}</Typography.Text>
        </div>
      ),
      okText: meta.okText,
      cancelText: '取消',
      okButtonProps: { danger: meta.danger },
      onOk: () => runBulkAction(payload, meta.title),
    });
  };

  const handleSelectedDelete = async () => {
    if (selectedUnsoldIds.length === 0 && selectedDisabledIds.length === 0) return;
    setBusyAction('delete_selected');
    try {
      let totalDeleted = 0;
      if (selectedUnsoldIds.length > 0) {
        const r1 = await bulkCardAction({ action: 'delete_unsold', scope: 'cards', card_ids: selectedUnsoldIds });
        totalDeleted += Number(r1.data?.count || 0);
      }
      if (selectedDisabledIds.length > 0) {
        const r2 = await bulkCardAction({ action: 'delete_disabled', scope: 'cards', card_ids: selectedDisabledIds });
        totalDeleted += Number(r2.data?.count || 0);
      }
      message.success(`已成功删除 ${totalDeleted} 张所选卡密。`);
      reloadAll();
    } catch (err) {
      message.error(err.response?.data?.message || '批量删除卡密失败', 6);
    } finally {
      setBusyAction('');
    }
  };

  const handleExport = async (format, statusOverride) => {
    setExporting(true);
    try {
      const params = {
        format,
        ...(activeFilters.category_id ? { category_id: activeFilters.category_id } : {}),
        ...(activeFilters.product_id ? { product_id: activeFilters.product_id } : {}),
        ...(activeFilters.content ? { content: activeFilters.content } : {}),
        ...(statusOverride !== undefined
          ? statusOverride
            ? { status: statusOverride }
            : {}
          : activeFilters.status
          ? { status: activeFilters.status }
          : {}),
      };
      const res = await exportCards(params);
      downloadBlobResponse(res, `cards_${statusOverride || activeFilters.status || 'all'}.${format}`);
      message.success('卡密导出文件已生成');
    } catch (err) {
      message.error(err.response?.data?.message || '导出卡密失败', 6);
    } finally {
      setExporting(false);
    }
  };

  const handleToggleStatus = async (record, next) => {
    try {
      const res = await setCardStatus(record.id, next);
      message.success(res.data?.message || '卡密库存状态已更新');
      reloadAll();
    } catch (err) {
      message.error(err.response?.data?.message || '状态变更失败', 6);
    }
  };

  const productSelectOptions = useMemo(
    () =>
      productOptions.map((p) => ({
        value: p.id,
        label: `${p.name}${p.category_name ? ` [${p.category_name}]` : ''}（可售 ${p.stock_count}）`,
      })),
    [productOptions]
  );

  const columns = [
    { title: 'ID', dataIndex: 'id', width: 68, search: false },
    {
      title: '所属分类',
      dataIndex: 'category_id',
      hideInTable: true,
      valueType: 'select',
      fieldProps: { options: categoryOptions, allowClear: true, placeholder: '全部商品分类' },
    },
    {
      title: '所属商品',
      dataIndex: 'product_id',
      width: 220,
      valueType: 'select',
      fieldProps: {
        options: productSelectOptions,
        showSearch: true,
        optionFilterProp: 'label',
        allowClear: true,
        placeholder: '搜索或选择指定商品',
      },
      render: (_, record) =>
        record.product ? (
          <Space direction="vertical" size={2}>
            <Link to={`/products/${record.product.id}/cards`}>{record.product.name}</Link>
            {record.product.category_name ? (
              <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                {record.product.category_name}
              </Typography.Text>
            ) : null}
          </Space>
        ) : (
          `商品 #${record.product_id}`
        ),
    },
    {
      title: '卡密内容',
      dataIndex: 'content',
      fieldProps: { placeholder: '输入完整卡密值跨全站精确检索' },
      width: 280,
      render: (_, record) => {
        const content = record.content ?? '';
        if (content === '') return '-';
        return (
          <Typography.Text
            className="admin-mono"
            style={{ maxWidth: 250 }}
            ellipsis={{ tooltip: content }}
            copyable={{ text: content }}
          >
            {content}
          </Typography.Text>
        );
      },
    },
    {
      title: '库存状态',
      dataIndex: 'status',
      width: 112,
      valueType: 'select',
      valueEnum: {
        unsold: { text: '未售', status: 'Default' },
        locked: { text: '锁定中', status: 'Processing' },
        sold: { text: '已售', status: 'Success' },
        disabled: { text: '停用', status: 'Warning' },
      },
      render: (_, record) => {
        const s = record.status;
        if (record.replaced_at) return <Tag color="default">售后已换出</Tag>;
        if (s === 'disabled') return <Tag color="default">停用</Tag>;
        if (s === 'sold') return <Tag color="green">{record.order_id ? '订单已售' : '线下已售'}</Tag>;
        if (s === 'locked') return <Tag color="orange">锁定中</Tag>;
        return <Tag color="blue">未售</Tag>;
      },
    },
    {
      title: '关联订单号',
      dataIndex: ['order', 'order_no'],
      width: 200,
      search: false,
      render: (_, record) =>
        record.order ? (
          <Link className="admin-order-number" to={`/orders/${record.order.id}`}>
            {record.order.order_no}
          </Link>
        ) : (
          '-'
        ),
    },
    { title: '入库时间', dataIndex: 'created_at', valueType: 'dateTime', search: false, width: 170 },
    {
      title: '操作',
      valueType: 'option',
      width: 190,
      fixed: 'right',
      render: (_, record) => {
        if (!canWrite) return [];
        const actions = [];

        if (record.order_id == null && !record.replaced_at) {
          const choices =
            record.status === 'unsold'
              ? [
                  ['disabled', '暂停销售', '停用后该卡密不会分配给新订单或用于售后换卡。'],
                  ['sold', '登记线下售出', '用于记录已在线下渠道交付的卡密，不生成订单且不计入线上销售额。'],
                ]
              : record.status === 'disabled'
              ? [['unsold', '恢复销售', '请确认该卡密仍有效且未曾对外交付，恢复后将重新进入可售库存池。']]
              : record.status === 'sold'
              ? [['unsold', '撤销线下售出', '仅适用于误登记且实际尚未交付的卡密；已交付卡密严禁重新上架。']]
              : [];
          for (const [status, label, description] of choices) {
            actions.push(
              <Popconfirm
                key={status}
                title={label + '？'}
                description={description}
                okText="确认"
                cancelText="取消"
                onConfirm={() => handleToggleStatus(record, status)}
              >
                <Button type="link" htmlType="button" style={{ padding: 0, height: 'auto' }}>
                  {label}
                </Button>
              </Popconfirm>
            );
          }
        }

        if (record.status === 'unsold' && record.order_id == null && !record.replaced_at) {
          actions.push(
            <Popconfirm
              key="delete"
              title="确认删除此条未售卡密？"
              onConfirm={async () => {
                try {
                  await deleteCard(record.id);
                  message.success('卡密已删除');
                  reloadAll();
                } catch (err) {
                  message.error(err.response?.data?.message || '删除失败');
                }
              }}
            >
              <Button type="link" htmlType="button" danger style={{ padding: 0, height: 'auto' }}>
                删除
              </Button>
            </Popconfirm>
          );
        } else if (record.status === 'disabled' && record.order_id == null && !record.replaced_at) {
          actions.push(
            <Popconfirm
              key="deleteDisabled"
              title="确认永久删除此条停用卡密？"
              onConfirm={() =>
                runBulkAction({ action: 'delete_disabled', scope: 'cards', card_ids: [record.id] }, '删除停用卡密')
              }
            >
              <Button type="link" htmlType="button" danger style={{ padding: 0, height: 'auto' }}>
                删除
              </Button>
            </Popconfirm>
          );
        }

        return actions.length ? actions : [<Typography.Text key="protected" type="secondary">受订单保护</Typography.Text>];
      },
    },
  ];

  const clearMenuItems = [
    {
      key: 'delete_unsold',
      danger: true,
      icon: <DeleteOutlined />,
      label: `一键清空未售卡密（${stats?.unsold ?? 0} 张）`,
      onClick: () => confirmScopeAction('delete_unsold'),
    },
    {
      key: 'delete_disabled',
      danger: true,
      icon: <ClearOutlined />,
      label: `一键清空停用卡密（${stats?.disabled ?? 0} 张）`,
      onClick: () => confirmScopeAction('delete_disabled'),
    },
  ];

  const exportMenuItems = [
    {
      key: 'txt-unsold',
      label: '导出当前范围未售卡密（TXT）',
      onClick: () => handleExport('txt', 'unsold'),
    },
    {
      key: 'txt-disabled',
      label: '导出当前范围停用卡密（TXT）',
      onClick: () => handleExport('txt', 'disabled'),
    },
    {
      key: 'csv-all',
      label: '导出当前筛选明细（CSV）',
      onClick: () => handleExport('csv', undefined),
    },
  ];

  return (
    <>
      <Card size="small" style={{ marginBottom: 12 }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 12 }}>
          <Space wrap size={8}>
            <Tag color="processing" style={{ marginInlineEnd: 0 }}>
              当前作用域：{currentScope.label}
            </Tag>
            {stats ? (
              <>
                <Tag>总计 {stats.total}</Tag>
                <Tag color="blue">可售 {stats.unsold}</Tag>
                {stats.locked > 0 ? <Tag color="orange">锁定 {stats.locked}</Tag> : null}
                <Tag color="green">已售 {stats.sold}</Tag>
                <Tag>停用 {stats.disabled ?? 0}</Tag>
              </>
            ) : null}
            {globalStats && currentScope.scope !== 'all' ? (
              <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                全站共 {globalStats.total} 张（可售 {globalStats.unsold} / 停用 {globalStats.disabled}）
              </Typography.Text>
            ) : null}
          </Space>
          <Space wrap size={8}>
            {canWrite ? (
              <>
                <Button
                  icon={<PauseCircleOutlined />}
                  loading={busyAction === 'pause_unsold'}
                  disabled={!stats || stats.unsold === 0}
                  onClick={() => confirmScopeAction('pause_unsold')}
                >
                  一键暂停可售
                </Button>
                <Button
                  icon={<PlayCircleOutlined />}
                  loading={busyAction === 'resume_disabled'}
                  disabled={!stats || stats.disabled === 0}
                  onClick={() => confirmScopeAction('resume_disabled')}
                >
                  一键恢复停用
                </Button>
                <Dropdown menu={{ items: clearMenuItems }} trigger={['click']}>
                  <Button danger icon={<DeleteOutlined />} loading={busyAction === 'delete_unsold' || busyAction === 'delete_disabled'}>
                    一键清空库存 <DownOutlined />
                  </Button>
                </Dropdown>
              </>
            ) : null}
            <Dropdown menu={{ items: exportMenuItems }} trigger={['click']}>
              <Button icon={<DownloadOutlined />} loading={exporting}>
                一键导出卡密 <DownOutlined />
              </Button>
            </Dropdown>
          </Space>
        </div>
      </Card>

      <ProTable
        scroll={{ x: 'max-content' }}
        headerTitle="全部商品卡密库存"
        actionRef={actionRef}
        rowKey="id"
        columns={columns}
        form={{ name: 'all-cards-search' }}
        search={{ labelWidth: 'auto' }}
        pagination={{ defaultPageSize: 50, showSizeChanger: true, pageSizeOptions: [20, 50, 100] }}
        tableAlertRender={false}
        rowSelection={
          canWrite
            ? {
                selectedRowKeys,
                onChange: (keys, rows) => {
                  setSelectedRowKeys(keys);
                  setSelectedRowsMap((prev) => {
                    const next = {};
                    const keySet = new Set(keys);
                    for (const [k, item] of Object.entries(prev)) {
                      if (keySet.has(item.id) || keySet.has(k)) next[item.id] = item;
                    }
                    for (const row of rows || []) {
                      if (row && row.id != null) {
                        next[row.id] = { id: row.id, status: row.status };
                      }
                    }
                    return next;
                  });
                },
                getCheckboxProps: (record) => ({
                  disabled:
                    record.order_id != null ||
                    Boolean(record.replaced_at) ||
                    !['unsold', 'disabled'].includes(record.status),
                }),
              }
            : false
        }
        request={async (params) => {
          const controller = new AbortController();
          requests.current.add(controller);
          const query = {
            page: params.current,
            per_page: params.pageSize,
            ...(params.category_id ? { category_id: params.category_id } : {}),
            ...(params.product_id ? { product_id: params.product_id } : {}),
            ...(params.status ? { status: params.status } : {}),
            ...(params.content ? { content: params.content } : {}),
          };
          setActiveFilters({
            category_id: params.category_id || undefined,
            product_id: params.product_id || undefined,
            status: params.status || undefined,
            content: params.content || undefined,
          });
          let res;
          try {
            res = await getAllCards(query, { signal: controller.signal });
          } catch (err) {
            if (!controller.signal.aborted) {
              message.error(err.response?.data?.message || '加载全站卡密库存失败，请重试');
            }
            return { data: [], total: 0, success: false };
          } finally {
            requests.current.delete(controller);
          }
          if (controller.signal.aborted) return { data: [], total: 0, success: false };
          const body = res.data ?? {};
          const list = Array.isArray(body) ? body : (body.data ?? []);
          setStats(body.stats ?? null);
          setGlobalStats(body.global_stats ?? null);
          return {
            data: list,
            total: Array.isArray(body) ? list.length : (body.total ?? list.length),
            success: true,
          };
        }}
        toolBarRender={() =>
          canWrite
            ? [
                selectedUnsoldIds.length > 0 && (
                  <Popconfirm
                    key="batchPause"
                    title={`确认批量暂停所选 ${selectedUnsoldIds.length} 条可售卡密？`}
                    onConfirm={() =>
                      runBulkAction({ action: 'pause_unsold', scope: 'cards', card_ids: selectedUnsoldIds }, '批量暂停')
                    }
                  >
                    <Button icon={<PauseCircleOutlined />}>批量暂停（{selectedUnsoldIds.length}）</Button>
                  </Popconfirm>
                ),
                selectedDisabledIds.length > 0 && (
                  <Popconfirm
                    key="batchResume"
                    title={`确认批量恢复所选 ${selectedDisabledIds.length} 条停用卡密为可售？`}
                    onConfirm={() =>
                      runBulkAction(
                        { action: 'resume_disabled', scope: 'cards', card_ids: selectedDisabledIds },
                        '批量恢复'
                      )
                    }
                  >
                    <Button icon={<PlayCircleOutlined />}>批量恢复（{selectedDisabledIds.length}）</Button>
                  </Popconfirm>
                ),
                selectedRowKeys.length > 0 && (
                  <Popconfirm
                    key="batchDelete"
                    title={`确认永久删除所选 ${selectedRowKeys.length} 条未售/停用卡密？`}
                    onConfirm={handleSelectedDelete}
                  >
                    <Button danger icon={<DeleteOutlined />} loading={busyAction === 'delete_selected'}>
                      批量删除（{selectedRowKeys.length}）
                    </Button>
                  </Popconfirm>
                ),
                <Button
                  key="quickImport"
                  type="primary"
                  icon={<PlusOutlined />}
                  onClick={() => {
                    setImportProductId(activeFilters.product_id ? Number(activeFilters.product_id) : undefined);
                    setImportVisible(true);
                  }}
                >
                  快速导入卡密
                </Button>,
              ]
            : []
        }
      />

      <ModalForm
        name="all-cards-importer"
        title="快速导入商品卡密"
        open={importVisible}
        onOpenChange={setImportVisible}
        initialValues={{ product_id: importProductId }}
        modalProps={{ destroyOnHidden: true }}
        onFinish={async (values) => {
          try {
            const res = await importCards(values.product_id, { content: values.cards });
            message.success(res.data?.message || '卡密批量导入成功');
            reloadAll();
            return true;
          } catch (err) {
            message.error(err.response?.data?.message || '卡密导入失败');
            return false;
          }
        }}
      >
        <ProFormSelect
          name="product_id"
          label="目标商品"
          options={productSelectOptions}
          showSearch
          fieldProps={{
            optionFilterProp: 'label',
            onChange: (val) => setImportProductId(val),
          }}
          rules={[{ required: true, message: '请选择要补货的目标商品' }]}
        />
        <div style={{ marginBottom: 14 }}>
          <Upload
            accept=".txt,.csv"
            showUploadList={false}
            disabled={!importProductId || uploading}
            beforeUpload={async (file) => {
              if (!importProductId) {
                message.warning('请先选择目标商品后再上传文件');
                return false;
              }
              if (file.size > 10 * 1024 * 1024) {
                message.error('上传文件体积不得超过 10 MB');
                return false;
              }
              setUploading(true);
              try {
                const data = new FormData();
                data.append('file', file);
                const res = await importCards(importProductId, data);
                message.success(res.data?.message || '文件批量导入完成');
                setImportVisible(false);
                reloadAll();
              } catch (err) {
                message.error(err.response?.data?.message || '文件导入失败');
              } finally {
                setUploading(false);
              }
              return false;
            }}
          >
            <Button icon={<UploadOutlined />} loading={uploading} disabled={!importProductId}>
              或直接上传 TXT / CSV 文件导入
            </Button>
          </Upload>
        </div>
        <ProFormTextArea
          name="cards"
          label="卡密文本列表"
          placeholder="每行输入一条卡密内容"
          rules={[{ required: true, message: '请输入需导入的卡密内容' }]}
          fieldProps={{ rows: 9 }}
          extra="系统自动基于密文指纹去重，跳过同批次重复项及该商品历史已入库（含已售出）卡密。"
        />
      </ModalForm>
    </>
  );
}
