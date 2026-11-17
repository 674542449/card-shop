import React, { useRef } from 'react';
import { ProTable } from '@ant-design/pro-components';
import { Tag } from 'antd';
import { getLogs } from '../services/api';
import { formatDateTime, toTablePage } from '../utils/orderMeta';

export default function Logs() {
  const actionRef = useRef();

  const columns = [
    { title: 'ID', dataIndex: 'id', width: 70, search: false },
    {
      title: '操作管理员',
      dataIndex: 'admin',
      width: 140,
      render: (_, record) => record.admin?.username || '-',
    },
    {
      title: '操作动作',
      dataIndex: 'action',
      width: 130,
      render: (_, record) => <Tag color="blue">{record.action}</Tag>,
    },
    { title: '目标资源类型', dataIndex: 'target_type', width: 140 },
    { title: '资源 ID', dataIndex: 'target_id', width: 90, search: false },
    { title: '审计详情摘要', dataIndex: 'detail', ellipsis: true, search: false },
    {
      title: '来源 IP',
      dataIndex: 'ip',
      width: 145,
      search: false,
      render: (_, record) => <span className="admin-mono">{record.ip || '-'}</span>,
    },
    {
      title: '记录时间',
      dataIndex: 'created_at',
      width: 180,
      valueType: 'dateRange',
      render: (_, record) => formatDateTime(record.created_at),
      search: {
        transform: (value) => ({
          start_date: value[0],
          end_date: value[1],
        }),
      },
    },
  ];

  return (
    <ProTable
      actionRef={actionRef}
      rowKey="id"
      columns={columns}
      scroll={{ x: 'max-content' }}
      form={{ name: 'logs-search' }}
      search={{ labelWidth: 'auto' }}
      request={async (params) => {
        const { current, pageSize, ...rest } = params;
        const res = await getLogs({ page: current, per_page: pageSize, ...rest });
        return toTablePage(res);
      }}
    />
  );
}
