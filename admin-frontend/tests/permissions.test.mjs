import test from 'node:test';
import assert from 'node:assert/strict';
import { allows, canVisit, permittedMenu } from '../src/permissions.js';

test('staff menus and direct routes match server permission boundaries', () => {
  const admin = {role:'staff',permissions:['catalog:read','content:write']};
  assert.equal(allows(admin,'content'),true);
  assert.equal(allows(admin,'catalog','write'),false);
  assert.equal(canVisit(admin,'/products/12/cards'),false);
  assert.equal(canVisit(admin,'/orders/12'),false);
  assert.equal(canVisit(admin,'/admins'),false);
  assert.equal(canVisit(admin,'/operations'),true);
  const tree={route:{routes:[{path:'/trade',routes:[{path:'/orders'}]},{path:'/catalog',routes:[{path:'/products'}]},{path:'/account'}]}};
  assert.deepEqual(permittedMenu(admin,tree).route.routes.map(n=>n.path),['/catalog','/account']);
  assert.equal(canVisit({role:'owner'},'/admins'),true);
});

test('card content requires its own permission on direct routes', () => {
  const catalogueWriter = {role:'staff',permissions:['catalog:write','orders:write']};
  assert.equal(canVisit(catalogueWriter,'/products/12/cards'),false);
  const cardReader = {role:'staff',permissions:['catalog:read','cards:read']};
  assert.equal(canVisit(cardReader,'/products/12/cards'),true);
  assert.equal(allows(cardReader,'cards','write'),false);
  assert.equal(canVisit({role:'staff',permissions:['cards:write']},'/products/12/cards/'),true);
  assert.equal(canVisit({role:'owner'},'/products/12/cards'),true);
});
