import Link from '../components/PermissionLink';
import useWritePermission from '../hooks/useWritePermission';
import React, { useEffect, useRef, useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { ProTable, ModalForm, ProFormTextArea } from '@ant-design/pro-components';
import { Button, message, Popconfirm, Tag, Space, Upload, Typography } from 'antd';
import { PlusOutlined, ArrowLeftOutlined, UploadOutlined, DeleteOutlined } from '@ant-design/icons';
import { getProductCards, importCards, deleteCard, batchDeleteCards, setCardStatus } from '../services/api';

export default function ProductCards() {
  const { productId } = useParams();
  return <ProductCardsPage key={productId} productId={productId} />;
}

function ProductCardsPage({ productId }) {
  const canWrite = useWritePermission('cards');
  const navigate = useNavigate();
  const actionRef = useRef();
  const requests = useRef(new Set());
  useEffect(() => () => {
    for (const controller of requests.current) controller.abort();
    requests.current.clear();
  }, []);
  const [importVisible, setImportVisible] = useState(false);
  const [selectedRowKeys, setSelectedRowKeys] = useState([]);
  const [uploading, setUploading] = useState(false);
  const [stats, setStats] = useState(null);
  const [productName, setProductName] = useState('');

  const handleToggleStatus = async (record, next) => {
    try {
      const res = await setCardStatus(record.id, next);
      message.success(res.data?.message || '卡密库存状态已更新');
      actionRef.current?.reload();
    } catch (err) {
      message.error(err.response?.data?.message || '状态变更失败', 6);
    }
  };

  const handleBatchDelete = async () => {
    if (selectedRowKeys.length === 0) {
      message.warning('请先勾选需要删除的未售卡密');
      return;
    }
    try {
      const res = await batchDeleteCards(selectedRowKeys);
      message.success(res.data?.message || '已批量清理所选卡密');
      setSelectedRowKeys([]);
      actionRef.current?.reload();
    } catch (err) {
      message.error(err.response?.data?.message || '批量删除失败');
    }
  };

  const columns = [
    { title: 'ID', dataIndex: 'id', width: 68, search: false },
    {
      title: '卡密内容',
      dataIndex: 'content',
      fieldProps: { placeholder: '输入完整卡密值进行精确指纹检索' },
      width: 320,
      render: (_, record) => {
        const content = record.content ?? '';
        if (content === '') return '-';
        return (
          <Typography.Text
            className="admin-mono"
            style={{ maxWidth: 280 }}
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
      width: 115,
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
      width: 215,
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
    { title: '入库时间', dataIndex: 'created_at', valueType: 'dateTime', search: false, width: 175 },
    {
      title: '操作',
      valueType: 'option',
      width: 190,
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

        if (record.status === 'unsold') {
          actions.push(
            <Popconfirm
              key="delete"
              title="确认删除此条未售卡密？"
              onConfirm={async () => {
                try {
                  await deleteCard(record.id);
                  message.success('卡密已删除');
                  actionRef.current?.reload();
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
        }

        return actions.length ? actions : [<Typography.Text key="protected" type="secondary">受订单保护</Typography.Text>];
      },
    },
  ];

  return (
    <>
      <ProTable
        scroll={{ x: 'max-content' }}
        headerTitle={
          <Space wrap size={10}>
            <Button icon={<ArrowLeftOutlined />} onClick={() => navigate('/products')}>
              返回商品列表
            </Button>
            <strong>{productName || `商品 #${productId}`}</strong>
            {stats && (
              <Space size={4} wrap>
                <Tag>总计 {stats.total}</Tag>
                <Tag color="blue">可售 {stats.unsold}</Tag>
                {stats.locked > 0 && <Tag color="orange">锁定 {stats.locked}</Tag>}
                <Tag color="green">已售 {stats.sold}</Tag>
                <Tag>停用 {stats.disabled ?? 0}</Tag>
              </Space>
            )}
          </Space>
        }
        actionRef={actionRef}
        rowKey="id"
        columns={columns}
        form={{ name: 'product-cards-search' }}
        search={{ labelWidth: 'auto' }}
        rowSelection={
          canWrite
            ? {
                selectedRowKeys,
                onChange: setSelectedRowKeys,
                getCheckboxProps: (record) => ({ disabled: record.status !== 'unsold' }),
              }
            : false
        }
        request={async (params) => {
          const controller = new AbortController();
          requests.current.add(controller);
          let res;
          try {
            res = await getProductCards(
              productId,
              { page: params.current, per_page: params.pageSize, ...params },
              { signal: controller.signal }
            );
          } catch (err) {
            if (!controller.signal.aborted) message.error(err.response?.data?.message || '加载卡密库存失败，请重试');
            return { data: [], total: 0, success: false };
          } finally {
            requests.current.delete(controller);
          }
          if (controller.signal.aborted) return { data: [], total: 0, success: false };
          const body = res.data ?? {};
          const list = Array.isArray(body) ? body : (body.data ?? []);
          setStats(body.stats ?? null);
          setProductName(body.product?.name || '');
          return {
            data: list,
            total: Array.isArray(body) ? list.length : (body.total ?? list.length),
            success: true,
          };
        }}
        toolBarRender={() =>
          canWrite
            ? [
                <Button key="import" type="primary" icon={<PlusOutlined />} onClick={() => setImportVisible(true)}>
                  导入卡密
                </Button>,
                <Upload
                  key="upload"
                  accept=".txt,.csv"
                  showUploadList={false}
                  beforeUpload={async (file) => {
                    if (file.size > 10 * 1024 * 1024) {
                      message.error('上传文件体积不得超过 10 MB');
                      return false;
                    }
                    setUploading(true);
                    try {
                      const data = new FormData();
                      data.append('file', file);
                      const res = await importCards(productId, data);
                      message.success(res.data?.message || '文件批量导入完成');
                      actionRef.current?.reload();
                    } catch (err) {
                      message.error(err.response?.data?.message || '文件导入失败');
                    } finally {
                      setUploading(false);
                    }
                    return false;
                  }}
                >
                  <Button icon={<UploadOutlined />} loading={uploading}>
                    上传 TXT / CSV
                  </Button>
                </Upload>,
                selectedRowKeys.length > 0 && (
                  <Popconfirm
                    key="batchDelete"
                    title={`确认批量删除已选中的 ${selectedRowKeys.length} 条未售卡密？`}
                    onConfirm={handleBatchDelete}
                  >
                    <Button danger icon={<DeleteOutlined />}>
                      批量删除
                    </Button>
                  </Popconfirm>
                ),
              ]
            : []
        }
      />
      <ModalForm
        name="card-importer"
        title="批量导入卡密"
        open={importVisible}
        onOpenChange={setImportVisible}
        modalProps={{ destroyOnHidden: true }}
        onFinish={async (values) => {
          try {
            const res = await importCards(productId, { content: values.cards });
            message.success(res.data?.message || '卡密批量导入成功');
            actionRef.current?.reload();
            return true;
          } catch (err) {
            message.error(err.response?.data?.message || '卡密导入失败');
            return false;
          }
        }}
      >
        <ProFormTextArea
          name="cards"
          label="卡密文本列表"
          placeholder="每行输入一条卡密内容"
          rules={[{ required: true, message: '请输入需导入的卡密内容' }]}
          fieldProps={{ rows: 10 }}
          extra="请按每行一条的格式粘贴卡密。系统自动基于密文指纹去重，跳过同批次重复项及历史已入库（含已售出）卡密。"
        />
      </ModalForm>
    </>
  );
}
