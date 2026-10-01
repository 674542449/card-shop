export const allows = (admin, area, action = 'read') => admin?.role === 'owner' ||
  (admin?.permissions || []).includes(`${area}:${action}`) ||
  (action === 'read' && (admin?.permissions || []).includes(`${area}:write`));

const areas = { '/': 'overview', '/categories': 'catalog', '/products': 'catalog',
  '/orders': 'orders', '/refunds': 'refunds', '/articles': 'content', '/article-categories': 'content',
  '/coupons': 'coupons', '/blacklists': 'blacklists', '/logs': 'logs', '/settings': 'settings',
  '/api-tokens': 'tokens', '/notifications': 'notifications' };

export function canVisit(admin, path) {
  if (path === '/account') return true;
  if (path === '/admins') return admin?.role === 'owner';
  if (path === '/operations') return allows(admin, 'maintenance') || allows(admin, 'content');
  const key = Object.keys(areas).sort((a, b) => b.length - a.length)
    .find(p => path === p || (p !== '/' && path.startsWith(`${p}/`)));
  return !!key && allows(admin, areas[key]);
}

export function permittedMenu(admin, tree) {
  const filter = nodes => nodes.flatMap(n => {
    if (!n.routes) return canVisit(admin, n.path) ? [n] : [];
    const routes = filter(n.routes);
    return routes.length ? [{ ...n, routes }] : [];
  });
  return { route: { ...tree.route, routes: filter(tree.route.routes) } };
}
