export const allows = (admin, area, action = 'read') => admin?.role === 'owner' ||
  (admin?.permissions || []).includes(`${area}:${action}`) ||
  (action === 'read' && (admin?.permissions || []).includes(`${area}:write`));

export const canCapability = (admin, capability) =>
  (admin?.permission_definition?.capabilities || []).includes(capability);

function matchesPage(page, path) {
  const normalized = path === '/' ? '/' : path.replace(/\/+$/, '');
  const pattern = page.path.split('/').map(segment => /^\{[^}]+\}$/.test(segment)
    ? '[^/]+' : segment.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('/');
  return new RegExp(`^${pattern}${page.children ? '(?:/.*)?' : ''}$`).test(normalized);
}

export function canVisit(admin, path) {
  const page = admin?.permission_definition?.pages?.find(item => matchesPage(item, path));
  if (!page) return false;
  return page.any ? page.any.some(capability => canCapability(admin, capability)) : canCapability(admin, page.capability);
}

export function permittedMenu(admin, tree) {
  const filter = nodes => nodes.flatMap(n => {
    if (!n.routes) return canVisit(admin, n.path) ? [n] : [];
    const routes = filter(n.routes);
    return routes.length ? [{ ...n, routes }] : [];
  });
  return { route: { ...tree.route, routes: filter(tree.route.routes) } };
}
