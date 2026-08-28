import React, { useRef, useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { ProTable, ModalForm, ProFormTextArea } from '@ant-design/pro-components';
import { Button, message, Popconfirm, Tag, Space, Upload } from 'antd';
import { PlusOutlined, ArrowLeftOutlined, UploadOutlined, DeleteOutlined } from '@ant-design/icons';
import { getProductCards, importCards, deleteCard, batchDeleteCards, setCardStatus } from '../services/api';

export default function ProductCards() {
  const { productId } = useParams();
  const navigate = useNavigate();
  const actionRef = useRef();
  const [importVisible, setImportVisible] = useState(false);
  const [selectedRowKeys, setSelectedRowKeys] = useState([]);
  // The API already counts these on every list request; showing them is what makes
  // that work pay for itself, and 锁定中 explains why some rows offer no status action.
  const [stats, setStats] = useState(null);

  const columns = [
    { title: 'ID', dataIndex: 'id', width: 60, search: false },
    {
      title: '卡密内容',
      dataIndex: 'content',
      search: false,
      ellipsis: true,
      // ProTable's render receives the already-rendered node first, not the raw value.
      // With ellipsis:true that node is a Tooltip element, so calling a string method
      // on it throws. The raw value only ever lives on `record`.
      render: (_, record) => {
        const content = record.content ?? '';
        if (!content) return '-';
        return content.length > 30 ? `${content.slice(0, 30)}…` : content;
      },
    },
    {
      title: '状态',
      dataIndex: 'status',
      width: 100,
      valueType: 'select',
      valueEnum: {
        unsold: { text: '未售', status: 'Default' },
        locked: { text: '锁定中', status: 'Processing' },
        sold: { text: '已售', status: 'Success' },
      },
      render: (_, record) => {
        const s = record.status;
        if (s === 'sold') return <Tag color="green">已售</Tag>;
        if (s === 'locked') return <Tag color="orange">锁定中</Tag>;
        return <Tag color="blue">未售</Tag>;
      },
    },
    {
      title: '订单号',
      dataIndex: ['order', 'order_no'],
      width: 180,
      search: false,
      render: (_, record) => record.order?.order_no || '-',
    },
    // valueType dateTime converts the UTC ISO string the API sends into local time.
    { title: '创建时间', dataIndex: 'created_at', valueType: 'dateTime', search: false, width: 180 },
    {
      title: '操作',
      valueType: 'option',
      width: 160,
      render: (_, record) => {
        const actions = [];

        // 锁定中的卡密由待支付订单持有，只有支付或过期释放才能改变它的状态。
        // 这类行不提供手动操作，而不是让服务端去拒绝。
        if (record.status !== 'locked') {
          const toSold = record.status !== 'sold';
          actions.push(
            <Popconfirm
              key="status"
              title={toSold ? '确认标记为已售？' : '确认标记为未售？'}
              description={
                toSold
                  ? '该卡密将从可售库存中移除，不再发放给新订单。'
                  : '该卡密将重新回到可售库存，可能再次被发货给其他买家。'
              }
              okText="确认"
              cancelText="取消"
              onConfirm={() => handleToggleStatus(record)}
            >
              <a>{toSold ? '标记已售' : '标记未售'}</a>
            </Popconfirm>
          );
        }

        actions.push(
          <Popconfirm
            key="delete"
            title="确认删除此卡密？"
            onConfirm={async () => {
              try {
                await deleteCard(record.id);
                message.success('删除成功');
                actionRef.current?.reload();
              } catch (err) {
                message.error(err.response?.data?.message || '删除失败');
              }
            }}
          >
            <a style={{ color: '#ff4d4f' }}>删除</a>
          </Popconfirm>
        );

        return actions;
      },
    },
  ];

  const handleToggleStatus = async (record) => {
    const next = record.status === 'sold' ? 'unsold' : 'sold';
    try {
      const res = await setCardStatus(record.id, next);
      message.success(res.data?.message || (next === 'sold' ? '已标记为已售' : '已标记为未售'));
      actionRef.current?.reload();
    } catch (err) {
      // A sold card that still belongs to a real order is refused with 422 and a
      // Chinese reason. Show it, and hold it long enough to actually be read.
      message.error(err.response?.data?.message || '状态修改失败', 6);
    }
  };

  const handleBatchDelete = async () => {
    if (selectedRowKeys.length === 0) {
      message.warning('请先选择要删除的卡密');
      return;
    }
    try {
      // Only unsold cards are deletable, so report the server's own count instead of
      // claiming success for rows it refused to touch.
      const res = await batchDeleteCards(selectedRowKeys);
      message.success(res.data?.message || '批量删除成功');
      setSelectedRowKeys([]);
      actionRef.current?.reload();
    } catch (err) {
      message.error(err.response?.data?.message || '批量删除失败');
    }
  };

  return (
    <>
      <ProTable
        headerTitle={
          <Space>
            <Button icon={<ArrowLeftOutlined />} onClick={() => navigate('/admin/products')}>
              返回商品列表
            </Button>
            <span>卡密管理 (商品ID: {productId})</span>
            {stats && (
              <Space size={4}>
                <Tag>共 {stats.total}</Tag>
                <Tag color="blue">未售 {stats.unsold}</Tag>
                {stats.locked > 0 && <Tag color="orange">锁定中 {stats.locked}</Tag>}
                <Tag color="green">已售 {stats.sold}</Tag>
              </Space>
            )}
          </Space>
        }
        actionRef={actionRef}
        rowKey="id"
        columns={columns}
        search={{ labelWidth: 'auto' }}
        rowSelection={{
          selectedRowKeys,
          onChange: setSelectedRowKeys,
        }}
        request={async (params) => {
          const res = await getProductCards(productId, { page: params.current, per_page: params.pageSize, ...params });
          const body = res.data ?? {};
          const list = Array.isArray(body) ? body : (body.data ?? []);
          setStats(body.stats ?? null);
          return {
            data: list,
            total: Array.isArray(body) ? list.length : (body.total ?? list.length),
            success: true,
          };
        }}
        toolBarRender={() => [
          <Button
            key="import"
            type="primary"
            icon={<PlusOutlined />}
            onClick={() => setImportVisible(true)}
          >
            导入卡密
          </Button>,
          selectedRowKeys.length > 0 && (
            <Popconfirm key="batchDelete" title={`确认删除选中的 ${selectedRowKeys.length} 条卡密？`} onConfirm={handleBatchDelete}>
              <Button danger icon={<DeleteOutlined />}>
                批量删除
              </Button>
            </Popconfirm>
          ),
        ]}
      />
      <ModalForm
        title="导入卡密"
        open={importVisible}
        onOpenChange={setImportVisible}
        modalProps={{ destroyOnClose: true }}
        onFinish={async (values) => {
          try {
            // The API validates a `content` field; sending `cards` always 422s.
            await importCards(productId, { content: values.cards });
            message.success('导入成功');
            actionRef.current?.reload();
            return true;
          } catch (err) {
            message.error(err.response?.data?.message || '导入失败');
            return false;
          }
        }}
      >
        <ProFormTextArea
          name="cards"
          label="卡密内容"
          placeholder="每行一个卡密"
          rules={[{ required: true, message: '请输入卡密内容' }]}
          fieldProps={{ rows: 10 }}
          extra="每行输入一个卡密，系统将自动按行拆分导入"
        />
      </ModalForm>
    </>
  );
}
