import { revealTabbedControl } from './navigation';
import { test, expect } from '../coverage/fixture';
import { insightRoutes } from './insights-fixture';
import { openModule } from './navigation';
test('storage locations live in inventory tab and edits remain guarded',async({page})=>{
 const {errors}=await insightRoutes(page);
 await page.route('**/api/v1/auth/user',r=>r.fulfill({json:{data:{id:'stock-reader',name:'Reader',email:'stock@example.test',permissions:['inventory.manage'],isActive:true}}}));
 let name='Cabinet';const writes:unknown[]=[];
 await page.route('**/api/v1/inventory/**',r=>{
  if(r.request().method()!=='GET'){const body=r.request().postDataJSON();writes.push(body);name=body.name;return r.fulfill({json:{data:{id:'location-one',name,description:null,isArchived:false}}});}
  return r.fulfill({json:{data:r.request().url().includes('/inventory/locations')?[{id:'location-one',name,description:'Fictional stock',isArchived:false}]:[],canManage:true,canEditCatalog:true,meta:{current_page:1,last_page:1,per_page:20,total:1}}});
 });
 await openModule(page,'inventory');
 await page.getByRole('tab',{name:'Lieux de stockage',exact:true}).click();
 await expect(page).toHaveURL(/\/inventory\/storage-locations/);
 await expect(page.getByRole('dialog')).toHaveCount(0);
 await expect(page.getByText('Cabinet',{exact:true})).toBeVisible();
 await page.reload();await expect(page.getByText('Cabinet',{exact:true})).toBeVisible();
 await page.getByRole('button',{name:'Modifier Cabinet',exact:true}).click();
 const dialog=page.getByRole('dialog',{name:'Modifier le lieu de stockage',exact:true});
 await (await revealTabbedControl(dialog.getByLabel('Nom',{exact:true}))).fill('Cupboard');
 await page.keyboard.press('Escape');
 const guard=page.getByRole('dialog',{name:'Modifications non enregistrées',exact:true});
 await guard.getByRole('button',{name:'Continuer à modifier',exact:true}).click();
 await dialog.getByRole('button',{name:'Enregistrer',exact:true}).click();
 await expect(page.getByRole('dialog')).toHaveCount(0);await expect(page.getByText('Cupboard',{exact:true})).toBeVisible();
 expect(writes).toHaveLength(1);expect(errors).toEqual([]);
});
