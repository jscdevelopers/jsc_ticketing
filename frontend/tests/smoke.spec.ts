import { test,expect } from '@playwright/test';
test('sign-in is usable by keyboard with no overflow or browser errors',async({page})=>{
  const errors:string[]=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto('/');await expect(page.getByRole('heading',{name:'JSC Ticketing',exact:true})).toBeVisible();
  await expect(page.locator('.login-card')).toHaveCSS('background-color','rgb(255, 255, 255)');
  await page.keyboard.press('Tab');await expect(page.getByRole('link',{name:'Skip to main content'})).toBeFocused();
  await page.keyboard.press('Tab');await expect(page.getByLabel('Username *',{exact:true})).toBeFocused();await page.keyboard.type('unknown-test-user');
  await page.keyboard.press('Tab');await expect(page.getByLabel('Password *',{exact:true})).toBeFocused();await page.keyboard.type('not-a-valid-password');
  await page.keyboard.press('Tab');await expect(page.getByRole('button',{name:'Sign in',exact:true})).toBeFocused();await page.keyboard.press('Enter');
  await expect(page.locator('.error[role=alert]')).toContainText('Username or password is incorrect');
  expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBeTruthy();
  await page.keyboard.press('Tab');
  expect(errors).toEqual([]);
});
