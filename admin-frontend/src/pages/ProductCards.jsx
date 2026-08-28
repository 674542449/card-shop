import React, { useRef, useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { ProTable, ModalForm, ProFormTextArea } from '@ant-design/pro-components';
import { Button, message, Popconfirm, Tag, Space, Upload } from 'antd';
import { PlusOutlined, ArrowLeftOutlined, UploadOutlined, DeleteOutlined } from '@ant-design/icons';
import { getProductCards, importCards, deleteCard, batchDeleteCards } from '../services/api';

export default function ProductCards() {
  const { productId } = useParams();
  const navigate = useNavigate();
  const actionRef = useRef();
  const [importVisible, setImportVisible] = useState(false);
  const [selectedRowKeys, setSelectedRowKeys] = useState([]);

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
    { title: '创建时间', dataIndex: 'created_at', search: false, width: 180 },
    {
      title: '操作',
      valueType: 'option',
      width: 100,
      render: (_, record) => [
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
        </Popconfirm>,
      ],
    },
  ];

  const handleBatchDelete = async () => {
    if (selectedRowKeys.length === 0) {
      message.warning('请先选择要删除的卡密');
      return;
    }
    try {
      await batchDeleteCards(selectedRowKeys);
      message.success('批量删除成功');
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
          const d = res.data?.data || res.data;
          return {
            data: d.data || d,
            total: d.total || d.length,
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
