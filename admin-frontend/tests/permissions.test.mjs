import test from 'node:test';
import assert from 'node:assert/strict';
import { allows, canVisit, canCapability, permittedMenu } from '../src/permissions.js';

// The browser receives these page rules and effective capabilities from /me.
const pages = [{path:'/',capability:'overview:read'}, {path:'/account',capability:'self'},
  {path:'/admins',capability:'accounts'}, {path:'/products/{id}/cards',capability:'cards:read'},
  {path:'/products',capability:'catalog:read',children:true}, {path:'/orders',capability:'orders:read',children:true},
  {path:'/operations',capability:'maintenance:read'},
  {path:'/tasks',any:['notifications:read','reconciliation.read','content:read']}];
const account = (role, permissions = [], capabilities = []) => ({role, permissions, permission_definition:{pages,
  capabilities: role === 'owner' ? ['self','accounts','cards:read','catalog:read','orders:read','overview:read','maintenance:read','content:read']
    : ['self', ...permissions, ...permissions.filter(p=>p.endsWith(':write')).map(p=>p.replace(':write',':read')), ...capabilities]}});

test('staff menus and direct routes match server permission boundaries', () => {
  const admin = account('staff',['catalog:read','content:write']);
  assert.equal(allows(admin,'content'),true);
  assert.equal(allows(admin,'catalog','write'),false);
  assert.equal(canVisit(admin,'/products/12/cards'),false);
  assert.equal(canVisit(admin,'/orders/12'),false);
  assert.equal(canVisit(admin,'/admins'),false);
  assert.equal(canVisit(admin,'/operations'),false);
  assert.equal(canVisit(admin,'/tasks'),true);
  const tree={route:{routes:[{path:'/trade',routes:[{path:'/orders'}]},{path:'/catalog',routes:[{path:'/products'}]},{path:'/account'}]}};
  assert.deepEqual(permittedMenu(admin,tree).route.routes.map(n=>n.path),['/catalog','/account']);
  assert.equal(canVisit(account('owner'),'/admins'),true);
});

test('card content requires its own permission on direct routes', () => {
  const catalogueWriter = account('staff',['catalog:write','orders:write']);
  assert.equal(canVisit(catalogueWriter,'/products/12/cards'),false);
  const cardReader = account('staff',['catalog:read','cards:read']);
  assert.equal(canVisit(cardReader,'/products/12/cards'),true);
  assert.equal(allows(cardReader,'cards','write'),false);
  assert.equal(canVisit(account('staff',['cards:write']),'/products/12/cards/'),true);
  assert.equal(canVisit(account('owner'),'/products/12/cards'),true);
});

test('missing definitions and new pages fail closed until the server grants them', () => {
  assert.equal(canVisit({role:'owner'},'/admins'),false);
  assert.equal(canVisit(account('owner'),'/unknown'),false);
  const admin = account('staff',['catalog:read']);
  admin.permission_definition.pages.unshift({path:'/new-reports',capability:'reports:read'});
  assert.equal(canVisit(admin,'/new-reports'),false);
  admin.permission_definition.capabilities.push('reports:read');
  assert.equal(canVisit(admin,'/new-reports'),true);
  assert.equal(canVisit(admin,'/new-reports-extra'),false);
});

test('compound action capabilities use the effective server decision', () => {
  const admin = account('staff',['orders:write','payments:write']);
  assert.equal(canCapability(admin,'orders.mark_paid'),false);
  admin.permission_definition.capabilities.push('orders.mark_paid');
  assert.equal(canCapability(admin,'orders.mark_paid'),true);
  assert.equal(canCapability(admin,'orders.replace_cards'),false);
});
