import React from 'react';
import { Link, useOutletContext } from 'react-router-dom';
import { canVisit } from '../permissions';

export default function PermissionLink({ to, children, ...props }) {
  const admin = useOutletContext();
  const path = typeof to === 'string' ? to.split('?')[0] : to.pathname;
  return canVisit(admin, path) ? <Link to={to} {...props}>{children}</Link> : <span className={props.className}>{children}</span>;
}
