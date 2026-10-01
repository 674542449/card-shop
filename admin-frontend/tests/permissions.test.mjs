import test from 'node:test';
import assert from 'node:assert/strict';
import { allows, canVisit, permittedMenu } from '../src/permissions.js';

test('staff menus and direct routes match server permission boundaries', () => {
  const admin = {role:'staff',permissions:['catalog:read','content:write']};
  assert.equal(allows(admin,'content'),true);
  assert.equal(allows(admin,'catalog','write'),false);
  assert.equal(canVisit(admin,'/products/12/cards'),true);
  assert.equal(canVisit(admin,'/orders/12'),false);
  assert.equal(canVisit(admin,'/admins'),false);
  assert.equal(canVisit(admin,'/operations'),true);
  const tree={route:{routes:[{path:'/trade',routes:[{path:'/orders'}]},{path:'/catalog',routes:[{path:'/products'}]},{path:'/account'}]}};
  assert.deepEqual(permittedMenu(admin,tree).route.routes.map(n=>n.path),['/catalog','/account']);
  assert.equal(canVisit({role:'owner'},'/admins'),true);
});
